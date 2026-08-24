---
date: 2026-08-23
plan: 003
type: feat
source: Direct request
depth: Standard
test-spec: docs/plans/2026-08-23-003-feat-railway-deployment-test-spec.md
status: Approved
---

# Plan: Railway Deployment with Persistent Draft Storage

## Context Summary

The application is a vanilla PHP monolith: Caddy serves static assets and forwards dynamic requests to PHP-FPM, while `LocalDraftRepository` persists each shared draft as a JSON file. The existing production Docker target already packages Caddy, PHP-FPM, Supervisor, Composer dependencies, and the application, so the deployment shall reuse that image rather than introduce a separate backend, database, S3 adapter, or frontend rewrite.

Railway shall host one isolated service behind a generated `up.railway.app` HTTPS domain. A Railway volume mounted at `/data/drafts` shall provide durable storage. Railway injects runtime variables and `PORT`, terminates TLS before the container, and mounts volumes as root; therefore the integration must harden environment access, internal HTTP binding, volume ownership, and deployment health before the live service is provisioned. No relevant infrastructure learnings exist under `docs/solutions/`.

## Requirements Trace

| Requirement | Source | Atomic Unit(s) |
|-------------|--------|-----------------|
| R1: Friends can access the application through a public Railway-provided HTTPS domain | Direct request | AU-1, AU-2, AU-6 |
| R2: Generated `/d/{id}` links remain accessible after application redeploys | Direct request | AU-3, AU-5, AU-6 |
| R3: Existing pages, APIs, draft rules, and response shapes remain unchanged | Direct request | AU-1, AU-2, AU-3, AU-5 |
| R4: Deployment uses local JSON storage on Railway without Vercel, S3, or a database | Direct request | AU-3, AU-6 |
| R5: Builds are reproducible and exclude local secrets and artifacts | Direct request | AU-4 |
| R6: Railway shall reject an unhealthy deployment when PHP configuration or draft storage is unusable | Direct request | AU-1, AU-3, AU-5, AU-6 |

## User Stories

### US-1: Open and share a hosted draft

```gherkin
Feature: Public Railway access
  As a player
  I want to open the draft generator and shared draft links over HTTPS
  So that my friends can participate without installing the application

  Scenario: Open the generator
    Given the Railway deployment is healthy
    When a player opens the generated Railway domain
    Then the generator page loads successfully over HTTPS

  Scenario: Open a shared draft
    Given one player has generated a draft
    When another browser opens its /d/{id} URL
    Then the same draft is displayed and can be claimed or picked according to existing rules
```

Acceptance: R1, R3

### US-2: Preserve drafts across deployments

```gherkin
Feature: Durable Railway draft storage
  As a draft organizer
  I want draft state stored on a persistent Railway volume
  So that a deployment or container restart does not erase the game

  Scenario: Reload after redeployment
    Given a draft has been generated and its URL recorded
    When the Railway service is redeployed
    Then the recorded draft URL loads the same persisted draft

  Scenario: Reject unwritable storage
    Given the configured storage directory is missing or unwritable
    When Railway evaluates service health
    Then the application returns an unsuccessful HTTP status and traffic is not shifted to it
```

Acceptance: R2, R4, R6

### US-3: Produce a safe and repeatable release

```gherkin
Feature: Reproducible Railway build
  As the application operator
  I want Railway to build from pinned PHP dependencies without local secrets or caches
  So that deployments are repeatable and do not leak workstation state

  Scenario: Build from the repository
    Given the repository is uploaded without ignored local artifacts
    When Railway builds the production Docker target
    Then Composer installs the committed lockfile and the image starts on Railway's assigned port
```

Acceptance: R5

## Scope Boundaries

### In Scope

- One new isolated Railway project and one PHP/Caddy service.
- One generated `up.railway.app` public domain.
- One persistent Railway volume mounted at `/data/drafts`.
- Runtime compatibility for Railway environment variables, `PORT`, TLS termination, and root-owned volume mounts.
- Deterministic container packaging and deployment documentation.
- Local automated checks, deployed browser tests, and a persistence check across redeployment.

### Out of Scope

- Vercel hosting or proxying.
- S3, DigitalOcean Spaces, Vercel Blob, or a database.
- A purchased or custom domain.
- Multiple Railway replicas or zero-downtime deployment while a single-writer volume is attached.
- Refactoring draft persistence for locking, transactions, or concurrent writers.
- Changes to draft generation, picking, claiming, map generation, or API payloads.

## Atomic Units

### AU-1: Make application configuration Railway-compatible
- [x] **Goal:** PHP resolves Railway runtime configuration and fails closed on invalid startup state without changing application routes or generated URLs.
**Requirements:** R1, R3, R6
**Dependencies:** None
**Files:**
- `app/helpers.php` -- Read runtime variables safely and normalize the public base URL.
- `bootstrap/boot.php` -- Validate configuration through the shared environment accessor and return HTTP 500 on invalid startup state.
- `templates/error.php` -- Remove direct `$_ENV` access for asset versioning.
- `app/HelpersTest.php` -- Cover environment/URL behavior and process-isolated bootstrap HTTP-500 failures.
**Approach:**
- Environment precedence: a non-empty `$_ENV` value -> a non-empty `getenv()` string -> default value; treat `false` and the empty string as absent.
- Public URL: use configured `URL`; IF absent and `RAILWAY_PUBLIC_DOMAIN` is present, require a bare host without scheme, path, query, or fragment and derive its HTTPS origin.
- URL joining: strip leading and trailing slashes from the supplied route segment, normalize the base to exactly one trailing slash, and preserve an empty route as the origin root.
- Invalid required configuration: set HTTP 500 before terminating so Railway health checks fail closed.
**Test Scenarios:**
- When a value exists only in the process environment: `env()` returns it.
- When a variable source is `false` or empty: resolution continues to the next source or default.
- When `URL` contains zero or multiple trailing slashes: generated URLs contain exactly one separator.
- When only `RAILWAY_PUBLIC_DOMAIN` is available: generated URLs use its HTTPS origin.
- When `RAILWAY_PUBLIC_DOMAIN` contains a scheme or path: configuration fails with HTTP 500.
- When required configuration is absent or local storage is unwritable: bootstrap returns HTTP 500.
**Verification:**
- `docker compose exec -T app vendor/bin/phpunit app/HelpersTest.php` -- exits 0
- `docker compose exec -T app vendor/bin/phpunit` -- exits 0

### AU-2: Bind the production web server to Railway networking
- [ ] **Goal:** Caddy accepts Railway traffic over plain internal HTTP on the injected port while local Docker TLS behavior remains available.
**Requirements:** R1, R3
**Dependencies:** None
**Files:**
- `deploy/app/caddy/Caddyfile` -- Separate Railway's dynamic plain-HTTP listener from the local TLS listener.
- `tests/deployment/container-http-smoke.sh` -- Verify injected/fallback ports, plain Railway HTTP, local TLS, routing, and asset delivery.
**Approach:**
- Railway listener: bind all interfaces at `:{$PORT:80}`, disable automatic HTTPS, and retain the current PHP fallback and static file behavior.
- Local listener: keep `milty.localhost` with `tls internal` in a separate site block so TLS is not inherited by the Railway listener.
- Injected-port check: run with `PORT=8081`, publish that port, and assert `/` plus `/favicon-32x32.png` return HTTP 200 over plain HTTP.
- Fallback-port check: run a second container without `PORT`, publish container port 80, and assert `/` returns HTTP 200.
- Local-TLS check: publish container port 443 and use a TLS client that trusts the generated local certificate only for the smoke run to assert `milty.localhost` remains HTTPS-enabled.
**Test Scenarios:**
- When `PORT=8081`: the production container serves the generator over plain HTTP on port 8081.
- When `PORT` is absent: the production container listens on port 80.
- When local development uses `milty.localhost`: its internal TLS configuration remains intact.
**Verification:**
- `sh tests/deployment/container-http-smoke.sh` -- exits 0

### AU-3: Initialize persistent volume ownership safely
- [ ] **Goal:** The Railway process can read and write the mounted draft volume while Caddy and PHP-FPM continue handling requests as the non-root application user.
**Requirements:** R2, R3, R4, R6
**Dependencies:** AU-1, AU-2
**Files:**
- `deploy/app/Dockerfile` -- Install the privilege-drop utility and run the volume initializer before Supervisor.
- `deploy/app/entrypoint.sh` -- Create or take ownership of the configured storage directory, then drop privileges to the application user.
- `tests/deployment/container-volume-smoke.sh` -- Verify root-owned mount preparation, non-root processes, persistence, and initialization failure.
**Approach:**
- Privilege utility: install `su-exec`; run the entrypoint as root, then execute Supervisor through `su-exec app:app`.
- Ownership: create `STORAGE_PATH` if absent, change ownership only on the mount root (not recursively), preserve existing draft-file ownership and contents, and require the resulting directory to be writable by UID/GID 1000.
- Volume target: Railway mounts one volume at `/data/drafts`; the application receives `STORAGE_PATH=/data/drafts`.
- IF the storage directory cannot be created or made writable: startup fails and no healthy deployment is reported.
- Process verification: inspect the running container and require Supervisor, Caddy, and PHP-FPM to run as UID 1000 after initialization.
**Test Scenarios:**
- When the storage directory is root-owned at startup: the initializer makes it writable to the application user before PHP starts.
- When a draft is saved and the application repository is reconstructed: the same draft can be loaded from the mounted path.
- When a pre-existing draft file is present: initialization preserves its bytes and it remains loadable.
- When storage initialization fails: the container exits unsuccessfully.
**Verification:**
- `sh tests/deployment/container-volume-smoke.sh` -- exits 0

### AU-4: Make production builds deterministic and secret-safe
- [ ] **Goal:** Railway builds contain pinned production dependencies and exclude workstation secrets, caches, generated reports, and local draft state.
**Requirements:** R5
**Dependencies:** None
**Files:**
- `.gitignore` -- Stop excluding the Composer lockfile.
- `composer.lock` -- Commit the resolved PHP dependency graph.
- `.dockerignore` -- Exclude secrets, development dependencies, caches, reports, temporary files, and local draft files from the image context.
- `tests/deployment/container-image-audit.sh` -- Build twice, compare locked package manifests, and inspect the final image for excluded workstation content.
**Approach:**
- Dependency resolution: use the committed lockfile with the existing production `composer install --no-dev` build.
- Secret boundary: exclude `.env` independently of Git behavior so local Docker builds cannot bake credentials into an image.
- Build context: exclude `vendor`, `node_modules`, `tmp`, test outputs, Git metadata, and local persisted drafts; retain application tests in source unless image-size verification shows they must be excluded.
**Test Scenarios:**
- When the repository is freshly cloned: the production image installs the versions recorded in `composer.lock`.
- When a local `.env` or draft file exists: it is absent from the Docker build context and final production image.
**Verification:**
- `composer validate --strict` -- exits 0
- `sh tests/deployment/container-image-audit.sh` -- exits 0

### AU-5: Build local Railway release-verification tooling
- [ ] **Goal:** Automated tools verify stable public endpoints and preserve a comparable public draft snapshot across redeployment.
**Requirements:** R2, R3, R6
**Dependencies:** AU-1, AU-2, AU-3, AU-4
**Files:**
- `tests/deployment/railway-smoke.sh` -- Verify stable public HTTP endpoints against a supplied base URL without embedding credentials.
- `tests/deployment/railway-smoke.test.cjs` -- Contract-test URL rejection and HTTP failure behavior through an injected fake HTTP client.
- `tests/deployment/railway-persistence.cjs` -- Create or verify a normalized public draft snapshot across a redeployment.
- `tests/deployment/railway-persistence.test.cjs` -- Contract-test persistence snapshot creation, normalization, comparison, and failure behavior.
**Approach:**
- HTTP smoke contract: accept one base URL and require HTTP 200 from `/` and `/favicon-32x32.png`; reject a missing, non-HTTPS, or malformed URL.
- HTTP test seam: allow the contract test to inject a fake `curl`-compatible executable; production use defaults to the system `curl` command.
- Persistence contract: `create` mode uses the existing generator UI, records the resulting `/d/{id}` URL plus a normalized public `/api/draft/{id}` payload in a caller-provided state file; `verify` mode reloads both and compares the normalized payload exactly.
**Test Scenarios:**
- When the HTTP smoke test targets a healthy deployment: the root page and fixed favicon asset succeed.
- When any required public request fails: the smoke test exits nonzero.
- When persistence `verify` runs after redeployment: the public draft payload equals the pre-deployment snapshot.
**Verification:**
- `node --test tests/deployment/railway-smoke.test.cjs` -- exits 0
- `node --test tests/deployment/railway-persistence.test.cjs` -- exits 0
- `docker compose exec -T app composer phpstan` -- exits 0
- `docker compose exec -T app composer cs:check` -- exits 0
- `node --test tests/js/*.test.cjs` -- exits 0

### AU-6: Provision and validate the isolated Railway deployment
- [ ] **Goal:** A new Railway project exposes the application publicly and preserves a real shared draft across redeployment.
**Requirements:** R1, R2, R4, R6
**Dependencies:** AU-5
**Files:**
- `README.md` -- Document exact provisioning, generated-domain access, persistence, verification, and rollback-safe operations.
- `tests/deployment/railway-status-check.cjs` -- Assert the CLI is linked to the intended isolated project and service before mutations.
- `tests/deployment/railway-wait.cjs` -- Poll the newest service deployment to a terminal state with a bounded timeout.
- `tests/deployment/railway-commands.test.cjs` -- Contract-test Railway status and deployment-state parsing without live mutations.
**Approach:**
- Approval boundary: the approved build authorizes creation of one publicly reachable Railway project, one service, and one persistent volume that may incur Railway usage charges; automated volume backups are not enabled in this first deployment.
- Project: run `railway init --name ti4draft --workspace "Alex Rudd's Projects" --json`, then `railway add --service ti4draft --json` from the nested application repository.
- Identity gate: immediately run the status checker with `--project ti4draft --service ti4draft --environment production` before setting variables, adding a volume/domain, or deploying.
- Variables: set `RAILWAY_DOCKERFILE_PATH=deploy/app/Dockerfile`, `STORAGE=local`, `STORAGE_PATH=/data/drafts`, `DEBUG=false`, and `VERSION` on service `ti4draft` without printing existing project secrets.
- Storage: run `railway volume --service ti4draft add --mount-path /data/drafts --json` and record the returned volume identifier in the deployment handoff.
- Networking: run `railway domain --service ti4draft --json`; use the returned Railway domain through the automatically provided `RAILWAY_PUBLIC_DOMAIN` variable.
- Health: configure service setting `Deploy > Healthcheck Path` as `/` before the acceptance redeploy; record this one dashboard-only setting in the handoff because the CLI exposes no health-path mutation.
- Deployment identity: before each trigger, use the wait tool's `capture` mode to store the currently newest deployment ID (or an explicit empty baseline) in a temporary state file.
- Deployment wait: after the trigger, use `wait` mode to poll `railway deployment list --service ti4draft --limit 1 --json` every five seconds for at most 900 seconds; ignore the captured baseline deployment, require a different deployment ID, continue only when that new deployment reaches `SUCCESS`, and fail immediately on its terminal failed, crashed, cancelled, or removed state.
- First deploy: capture the empty/current baseline, run `railway up --service ti4draft --detach`, then wait for a different successful deployment before public verification.
- Persistence gate: run HTTP smoke, Playwright, and persistence `create`; capture the current successful deployment ID; run `railway redeploy --service ti4draft --yes`; wait for a different successful deployment; then run persistence `verify` against the same state file.
- IF deployment verification fails: inspect Railway build/runtime logs and do not report the service as ready.
**Test Scenarios:**
- When the project is deployed with the documented variables and volume: its generated domain returns the generator over HTTPS.
- When a draft is generated in one browser and opened in another: both observe the same server-side draft.
- When the service is redeployed: the pre-existing draft URL remains valid with the same state.
- When storage or startup configuration is invalid: Railway does not promote the deployment as healthy.
- When Railway identity differs from the intended project, service, or environment: no variables, volume, domain, or deployment mutation is performed.
- When a deployment reaches a failed terminal state or does not succeed within 900 seconds: verification stops with a nonzero result.
**Verification:**
- `node --test tests/deployment/railway-commands.test.cjs` -- exits 0
- `node tests/deployment/railway-status-check.cjs --project ti4draft --service ti4draft --environment production` -- exits 0
- `sh tests/deployment/railway-smoke.sh "https://<generated-domain>"` -- exits 0
- `E2E_BASE_URL="https://<generated-domain>" npm run test:e2e` -- exits 0
- `node tests/deployment/railway-persistence.cjs create --base-url "https://<generated-domain>" --state-file /tmp/ti4draft-railway-state.json` -- exits 0
- `node tests/deployment/railway-wait.cjs capture --service ti4draft --state-file /tmp/ti4draft-deployment.json` -- exits 0
- `railway redeploy --service ti4draft --yes` -- exits 0
- `node tests/deployment/railway-wait.cjs wait --service ti4draft --state-file /tmp/ti4draft-deployment.json --timeout-seconds 900` -- exits 0
- `node tests/deployment/railway-persistence.cjs verify --base-url "https://<generated-domain>" --state-file /tmp/ti4draft-railway-state.json` -- exits 0

## Dependency Graph

AU-1 -> AU-3 -> AU-5 -> AU-6
AU-2 -> AU-3
AU-4 -> AU-5

## Key Technical Decisions

- **Host the full monolith on Railway:** PHP already renders the frontend and implements all APIs, so splitting Vercel and Railway would add proxying and deployment coupling without adding product capability.
- **Use a Railway volume with the existing local repository:** Draft JSON files are the existing persistence contract; mounting `/data/drafts` satisfies it without a new datastore.
- **Retain least-privilege request handling:** A root-only entrypoint prepares the Railway-mounted directory, then hands control to the existing non-root application user.
- **Use the generated Railway domain:** A purchased domain and Vercel are unnecessary for public access during the initial deployment.
- **Keep one replica:** Railway volumes and the current whole-file repository are single-writer infrastructure; horizontal scaling is deferred until persistence supports concurrency.

## Open Questions

### Resolved During Planning

- Hosting surface: the full PHP application shall run on Railway, not Vercel.
- Persistence: drafts shall use a Railway volume, not S3 or a database.
- Public access: friends shall use Railway's generated `up.railway.app` HTTPS domain.
- Project selection: provisioning shall create a new isolated project rather than reuse one of eight unrelated Railway projects.

### Deferred to Implementation

- AU-6: Resolve the final generated Railway domain before asserting public URL behavior.
- AU-6: The Railway healthcheck path requires one dashboard setting because CLI 4.30.5 does not expose a health-path mutation command.

## Unchanged Invariants

- Existing routes in `app/routes.php` and their request/response formats must not change.
- Existing draft IDs, player secrets, admin secrets, and browser `localStorage` keys must remain compatible.
- Local Docker Compose development must continue to work at its current URLs.
- `STORAGE=spaces` support must remain available in code even though Railway shall use `STORAGE=local`.
- Draft JSON filenames and serialized schema must remain unchanged so existing files can be restored or migrated.

## Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Root-owned Railway volume is not writable by the application user | High | High | Prepare only the mount-root ownership in a root entrypoint, drop through `su-exec`, and fail startup if the path remains unwritable. |
| PHP-FPM cannot see Railway runtime variables through `$_ENV` | Medium | High | Add process-environment fallback and automated coverage before deployment. |
| Local Caddy TLS configuration prevents Railway's internal HTTP probe | Medium | High | Separate the Railway listener from local `milty.localhost` TLS and validate the production container locally. |
| Whole-file draft saves lose simultaneous updates | Medium | Medium | Keep one replica for this deployment and defer atomic locking to a persistence-specific follow-up. |
| Volume attachment causes brief deployment downtime | High | Low | Accept single-service downtime for the initial friends-only deployment and document it. |
| Persistence verification depends on generator UI selectors | Medium | Medium | Reuse selectors already exercised by Playwright and unit-test create/verify state-file behavior before the live deployment. |

## Sources & References

- `deploy/app/Dockerfile`: Existing PHP/Caddy production image.
- `deploy/app/caddy/Caddyfile`: Current fixed port and local TLS behavior.
- `app/Draft/Repository/LocalDraftRepository.php`: Existing JSON persistence contract.
- `bootstrap/boot.php` and `app/helpers.php`: Current runtime configuration boundary.
- `playwright.config.cjs`: Existing support for testing a deployed base URL.
- [Railway Dockerfile documentation](https://docs.railway.com/builds/dockerfiles): Custom Dockerfile path behavior.
- [Railway volume documentation](https://docs.railway.com/volumes): Runtime mounts and root ownership.
- [Railway public networking guidance](https://docs.railway.com/guides/vibe-coding-deploy): Injected `PORT`, public domain, and health requirements.
