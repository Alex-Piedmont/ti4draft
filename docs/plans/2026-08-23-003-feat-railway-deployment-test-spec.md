---
date: 2026-08-23
plan: 003
source: Direct request
depth: Standard
---

# Test Specification: Railway Deployment with Persistent Draft Storage

## Test Infrastructure

- Framework: PHPUnit 11.5-compatible configuration for PHP behavior; Node.js built-in test runner for JavaScript and deployment-tool contracts; Playwright 1.62.1 for deployed browser behavior; POSIX shell and Docker for container and HTTP smoke checks.
- Test directories: PHP tests are colocated with source under `app/` and `data/`; JavaScript tests are under `tests/js/`; browser tests are under `tests/e2e/`; deployment tests and executable verification tools are under `tests/deployment/`.
- Conventions: PHP tests use `*Test.php` and run through `vendor/bin/phpunit`; `app/HelpersTest.php` uses process isolation for bootstrap exit/status contracts; JavaScript contract tests use `*.test.cjs` and run through `node --test`; Playwright tests use `*.spec.cjs` and accept `E2E_BASE_URL`; shell smoke and image-audit checks use `*.sh`, take explicit targets, and exit nonzero on any failed requirement.

## AU-1: Make Application Configuration Railway-Compatible

### Behaviors Under Test

- **B1.1** When a variable has a non-empty `$_ENV` value: `env()` returns that value before consulting the process environment or default.
- **B1.2** When `$_ENV` has no non-empty value and `getenv()` returns a non-empty string: `env()` returns the process-environment value.
- **B1.3** When `URL` has zero, one, or multiple trailing slashes and the route is empty or surrounded by slashes: URL joining returns the normalized origin root or route with exactly one origin/path separator.
- **B1.4** When the error template renders with `VERSION` available only through the process environment: its asset version is resolved without direct `$_ENV` access.
- **B1.5** When `URL` is absent and `RAILWAY_PUBLIC_DOMAIN` contains a valid bare host: generated URLs use the normalized `https://{host}/` origin.

### Edge Cases

- **E1.1** When a variable source is boolean `false` or an empty string: resolution continues to the next source and ultimately uses the declared default if no non-empty value exists.
- **E1.2** When explicit `URL` and `RAILWAY_PUBLIC_DOMAIN` are both present: the normalized explicit `URL` determines generated links.
- **E1.3** When `RAILWAY_PUBLIC_DOMAIN` includes a scheme, path, query, or fragment: the value is rejected rather than normalized into a public URL.

### Failure Modes

- **F1.1** When no valid explicit URL or Railway public domain supplies required public configuration: the process-isolated bootstrap contract in `HelpersTest.php` observes HTTP status 500 before termination.
- **F1.2** When configured local storage is unusable during bootstrap validation: the process-isolated bootstrap contract in `HelpersTest.php` observes HTTP status 500 rather than a healthy response.

### Acceptance Criteria

- [ ] **AC1.1** When Railway supplies PHP configuration exclusively as process variables: the application resolves those values and renders valid existing routes, satisfying R1 and R3.
- [ ] **AC1.2** When `RAILWAY_PUBLIC_DOMAIN=example.up.railway.app` is the only public-origin input: generated links begin with `https://example.up.railway.app/`, satisfying R1.
- [ ] **AC1.3** When URL inputs contain supported slash variations: root, route, and asset URLs contain exactly one origin/path separator, satisfying R3.
- [ ] **AC1.4** When the Railway domain is malformed or required configuration is absent: the application returns HTTP 500, satisfying R6.

## AU-2: Bind the Production Web Server to Railway Networking

### Behaviors Under Test

- **B2.1** When the production container starts with `PORT=8081`: it serves the generator at `/` over plain HTTP on port 8081.
- **B2.2** When the production container starts with `PORT=8081`: it serves `/favicon-32x32.png` over plain HTTP with status 200.
- **B2.3** When the production listener receives an application route not backed by a static file: the existing PHP fallback handles the request.
- **B2.4** When local development addresses `milty.localhost`: the separate local site retains internal TLS behavior.

### Edge Cases

- **E2.1** When `PORT` is absent: the production listener binds all interfaces on port 80.
- **E2.2** When the Railway listener and local-development listener are both configured: automatic HTTPS is disabled only for the plain Railway listener.

### Failure Modes

- **F2.1** When the production listener does not bind the supplied port or requires TLS internally: `container-http-smoke.sh` exits nonzero.
- **F2.2** When either `/` or `/favicon-32x32.png` does not return HTTP 200: `container-http-smoke.sh` exits nonzero and does not report networking ready.
- **F2.3** When the no-`PORT` container does not serve `/` on port 80 or `milty.localhost` does not negotiate trusted smoke-run TLS on port 443: `container-http-smoke.sh` exits nonzero.

### Acceptance Criteria

- [ ] **AC2.1** When the production image starts with `PORT=8081` and valid temporary local-storage configuration: `/` returns HTTP 200 over plain HTTP on port 8081, satisfying R1 and R3.
- [ ] **AC2.2** When the same container serves `/favicon-32x32.png`: the asset returns HTTP 200 over the injected port, satisfying R3.
- [ ] **AC2.3** When no `PORT` is injected: the production listener uses port 80.
- [ ] **AC2.4** When local Docker development starts: `milty.localhost` retains its existing internal TLS behavior, satisfying R3.

## AU-3: Initialize Persistent Volume Ownership Safely

### Behaviors Under Test

- **B3.1** When `STORAGE_PATH` does not exist at container startup: the root entrypoint creates the directory and makes its mount root writable by UID/GID 1000.
- **B3.2** When `STORAGE_PATH` is initially root-owned: the entrypoint changes ownership of the configured directory itself without recursively changing draft-file ownership.
- **B3.3** When initialization succeeds: the entrypoint executes Supervisor as `app:app` through `su-exec`.
- **B3.4** When a draft is saved to the mounted path and the repository is reconstructed: loading its ID returns the same persisted draft.
- **B3.5** When a pre-existing draft file is present before startup: initialization preserves its bytes and the application can load the unchanged draft.

### Edge Cases

- **E3.1** When the configured mount root already belongs to UID/GID 1000 and is writable: startup succeeds without altering existing draft contents.
- **E3.2** When nested files or directories have existing ownership metadata: mount-root preparation does not recursively rewrite that metadata.
- **E3.3** When `STORAGE_PATH=/data/drafts`: all draft persistence uses the mounted absolute path rather than the local-development default.

### Failure Modes

- **F3.1** When the configured directory cannot be created or its mount-root ownership cannot be prepared: `container-volume-smoke.sh` observes an unsuccessful container exit before Supervisor starts.
- **F3.2** When `/data/drafts` is not writable by UID/GID 1000 after preparation: `container-volume-smoke.sh` observes an unsuccessful container exit that cannot pass health evaluation.

### Acceptance Criteria

- [ ] **AC3.1** When a root-owned storage mount is supplied: the initialized non-root application can save and reload a draft there, satisfying R2 and R4.
- [ ] **AC3.2** When initialization completes: Supervisor, Caddy, and PHP-FPM run as UID 1000, satisfying the least-privilege process boundary.
- [ ] **AC3.3** When a valid draft file predates startup: its bytes remain unchanged and its draft remains loadable, satisfying R2 and R3.
- [ ] **AC3.4** When storage initialization is impossible: startup exits nonzero and no healthy service is exposed, satisfying R6.

## AU-4: Make Production Builds Deterministic and Secret-Safe

### Behaviors Under Test

- **B4.1** When `container-image-audit.sh` builds the production image twice from one clean revision: both images expose identical installed package manifests matching `composer.lock`.
- **B4.2** When a local `.env` exists in the repository worktree: the Docker build context and final production image omit it.
- **B4.3** When local draft files exist under temporary or persisted-development paths: the Docker build context and final production image omit them.
- **B4.4** When `container-image-audit.sh` examines a successful production build: required application runtime files, routes, and public assets remain present.

### Edge Cases

- **E4.1** When local `vendor`, `node_modules`, `tmp`, caches, generated reports, test outputs, and Git metadata exist: none are copied from the workstation into the production image.
- **E4.2** When application tests are present in source: the build context retains them while excluding generated test output.

### Failure Modes

- **F4.1** When `composer.json` and `composer.lock` are inconsistent: strict Composer validation or the production build fails instead of resolving an unpinned dependency graph.
- **F4.2** When the image contains an excluded secret, cache, report, dependency directory, temporary file, or local draft: `container-image-audit.sh` exits nonzero.

### Acceptance Criteria

- [ ] **AC4.1** When `tests/deployment/container-image-audit.sh` builds the same repository revision twice: their locked package manifests match each other and `composer.lock`, satisfying R5.
- [ ] **AC4.2** When workstation secrets and artifacts exist before the build: the image audit confirms they are absent from the final image, satisfying R5.
- [ ] **AC4.3** When strict Composer validation runs: it succeeds with the committed lockfile, satisfying R5.
- [ ] **AC4.4** When the audited image runs with valid runtime configuration: required pages, APIs, and assets remain available.

## AU-5: Build local Railway release-verification tooling

**Requirements:** R2, R3, R6

### Behaviors Under Test

- **B5.1** When `railway-smoke.sh` receives a valid HTTPS base URL for a healthy deployment: `/` and `/favicon-32x32.png` each return HTTP 200 and the command exits zero.
- **B5.2** When `railway-smoke.test.cjs` injects a fake curl-compatible executable: it observes URL and HTTP behavior without live requests, while uninjected production use defaults to system `curl`.
- **B5.3** When `railway-persistence.cjs create` receives a valid base URL and caller-provided state path: it records a generated `/d/{id}` URL and normalized public `/api/draft/{id}` snapshot in that state file.
- **B5.4** When `railway-persistence.cjs verify` receives the recorded state file after redeployment: it reloads the shared URL and exits zero only when the normalized public payload matches exactly.
- **B5.5** When `railway-persistence.test.cjs` runs without live Railway mutations: it contract-tests snapshot creation, public-payload normalization, exact comparison, and expected failure outcomes.

### Edge Cases

- **E5.1** When the HTTP smoke base URL is missing, non-HTTPS, or malformed: the command rejects it without targeting a default deployment.
- **E5.2** When volatile or secret-bearing fields differ between otherwise equivalent draft responses: normalization excludes only the explicitly non-public or unstable fields defined by the persistence contract.

### Failure Modes

- **F5.1** When the fake or production HTTP client reports a non-200 stable endpoint: the HTTP smoke command exits nonzero and identifies the failed endpoint.
- **F5.2** When persistence state is missing or malformed, the saved URL cannot reload, or its normalized public payload differs: `verify` exits nonzero without creating replacement state or accepting the redeployment.
- **F5.3** When draft creation, public API retrieval, or caller-provided state-file writing fails: `create` exits nonzero and no incomplete snapshot is accepted as valid state.

### Acceptance Criteria

- [ ] **AC5.1** When a healthy deployed base URL is supplied: the stable root and favicon smoke checks pass, satisfying R3 and R6.
- [ ] **AC5.2** When `node --test tests/deployment/railway-smoke.test.cjs` runs with a fake curl-compatible executable: valid endpoint calls, invalid URL rejection, and HTTP failures are verified without live network access, satisfying R3 and R6.
- [ ] **AC5.3** When persistence `create` runs before redeployment and `verify` runs afterward: the same shared URL and normalized public draft payload are confirmed, satisfying R2.
- [ ] **AC5.4** When `node --test tests/deployment/railway-persistence.test.cjs` runs: creation, normalization, comparison, and failure contracts all pass without a live Railway mutation, satisfying R2 and R3.

## AU-6: Provision and Validate the Isolated Railway Deployment

### Behaviors Under Test

- **B6.1** When `railway init` and `railway add` complete: `railway-status-check.cjs --project ti4draft --service ti4draft --environment production` permits later mutations only when all three status identities match.
- **B6.2** When `railway-wait.cjs capture` runs before a trigger and `wait` runs afterward: capture stores the newest ID or an empty baseline, while wait runs `railway deployment list --service ti4draft --limit 1 --json` every five seconds for at most 900 seconds and accepts `SUCCESS` only from a different ID.
- **B6.3** When `RAILWAY_DOCKERFILE_PATH=deploy/app/Dockerfile`, `STORAGE=local`, `STORAGE_PATH=/data/drafts`, `DEBUG=false`, and `VERSION` are configured with the mounted volume: the generated `up.railway.app` domain serves the generator over HTTPS.
- **B6.4** When persistence state is created, the current deployment is captured, the service is redeployed, a different deployment succeeds, and verification uses the same persistence state: the pre-deployment public payload matches exactly.

### Edge Cases

- **E6.1** When capture records an empty baseline or polling still returns the captured ID: wait accepts the baseline state but requires a newly observed deployment ID before success.
- **E6.2** When CLI 4.30.5 cannot configure the health path: the handoff identifies `/` as the required dashboard-only `Deploy > Healthcheck Path` setting before acceptance redeploy.
- **E6.3** When representative status and deployment-list JSON is supplied without live mutations: `railway-commands.test.cjs` identifies all three status identities, capture state, new deployment identity, and terminal state correctly.

### Failure Modes

- **F6.1** When status after project and service creation has a mismatched project, service, or environment, or cannot be parsed: provisioning stops before variables, volume, domain, or deployment mutations occur.
- **F6.2** When the newly observed deployment reaches `FAILED`, `CRASHED`, `CANCELLED`, or `REMOVED`, or no different ID succeeds within 900 seconds: wait exits nonzero and no later verification begins.
- **F6.3** When storage health, HTTP smoke, Playwright, persistence creation, redeploy, or persistence verification fails: logs are inspected and the service is not declared successfully released.

### Acceptance Criteria

- [ ] **AC6.1** When `node --test tests/deployment/railway-commands.test.cjs` runs: status and deployment-state parsing contracts pass without live mutations.
- [ ] **AC6.2** When identity is checked immediately after project and service creation with `--environment production`: variables, volume, domain, and deploy operations proceed only when project, service, and environment all match.
- [ ] **AC6.3** When either deployment is triggered: its pre-trigger baseline is captured and public or persistence verification begins only after a different deployment ID reaches `SUCCESS`.
- [ ] **AC6.4** When the new deployment fails terminally or no different deployment succeeds within 900 seconds: wait exits nonzero and the release sequence stops.
- [ ] **AC6.5** When the isolated Railway service reaches successful deployment: its generated domain serves the generator over HTTPS without Vercel or a purchased domain, satisfying R1 and R4.
- [ ] **AC6.6** When a shared draft is opened in another clean browser: the same public draft appears without leaking creator authority, satisfying R1 and R3.
- [ ] **AC6.7** When the service is redeployed against its existing volume: the recorded draft URL and normalized public payload remain unchanged, satisfying R2.
- [ ] **AC6.8** When the dashboard health path is `/` and application or storage startup is invalid: Railway does not report the candidate deployment healthy, satisfying R6.
- [ ] **AC6.9** When provisioning completes: the handoff records the project, service, public domain, volume identifier, dashboard health setting, verification results, and absence of automated volume backups.
- [ ] **AC6.10** When Railway service variables are configured: the operation does not print existing project secrets.
- [ ] **AC6.11** When an operator follows `README.md` from a clean clone: it identifies the deployment root, Dockerfile, identity gate, variables, volume, generated domain, health path, capture/wait sequence, persistence gate, and rollback-safe operations without requiring S3 or a database.

## Cross-AU Integration Contracts

### Railway Configuration and Plain-HTTP Listener Compose

- **Precondition**: AU-1 and AU-2 are complete.
- **Behavior**: When the production container receives Railway process variables and an injected port, PHP resolves its public HTTPS origin while Caddy accepts internal plain HTTP on the injected port.
- **Expected outcome**: The root page and fixed favicon return HTTP 200 through Caddy, and generated application URLs use the Railway HTTPS domain.
- **Failure mode**: Invalid PHP configuration returns HTTP 500, while listener or routing failure causes the container HTTP smoke check to exit nonzero.

### Prepared Volume Serves the Existing Application

- **Precondition**: AU-1, AU-2, and AU-3 are complete.
- **Behavior**: When the image starts with a root-owned `/data/drafts` mount, the entrypoint prepares only the mount root and transfers execution to UID 1000 before requests are served.
- **Expected outcome**: Non-root processes serve the application, newly saved drafts reload from the mounted path, and pre-existing draft bytes remain unchanged.
- **Failure mode**: Unwritable storage, recursive mutation of existing contents, root-owned request processes, or lost draft state fails container verification.

### Audited Image Supports Release Tooling

- **Precondition**: AU-2, AU-3, AU-4, and AU-5 are complete.
- **Behavior**: When the pinned and secret-safe production image is exercised by the container smoke, image audit, HTTP smoke, and persistence tools, each tool observes the boundary assigned to it.
- **Expected outcome**: Container checks cover internal networking and volume behavior, public smoke covers only stable HTTP endpoints, and persistence tooling owns draft creation and snapshot comparison.
- **Failure mode**: Missing runtime files, included workstation artifacts, conflated smoke responsibilities, or a failed verification command blocks release readiness.

### Status Gate Protects the Exact Provisioning Sequence

- **Precondition**: AU-5 and AU-6 are complete.
- **Behavior**: Immediately after isolated project and service creation and before later mutations, status verification confirms project `ti4draft`, service `ti4draft`, and environment `production`; provisioning then applies the documented variables, volume, domain, deploy, and redeploy sequence.
- **Expected outcome**: No unrelated Railway project, service, or environment is mutated, and the resulting resource identifiers and dashboard-only health setting are captured in the handoff.
- **Failure mode**: An identity mismatch stops the sequence before mutation, while any provisioning or verification failure prevents a ready report.

### Captured Deployment Identity Gates Verification

- **Precondition**: AU-6 command contracts and an identity-approved Railway service are complete.
- **Behavior**: Before each deploy or redeploy trigger, capture mode stores the current deployment ID or an empty baseline; after the trigger, wait mode polls until it observes a different deployment ID in a terminal state.
- **Expected outcome**: Only `SUCCESS` from the newly observed deployment permits public smoke or persistence verification, and neither a prior success nor the captured baseline can satisfy the wait.
- **Failure mode**: A new failed terminal state, malformed deployment output, or absence of a different successful ID within 900 seconds exits nonzero and stops release verification.

### Shared Draft Survives the Full Railway Lifecycle

- **Precondition**: AU-1 through AU-6 are complete.
- **Behavior**: When persistence `create` records a real shared draft, capture stores the current deployment ID, Railway redeploys against the same volume, wait observes a different successful deployment ID, and persistence `verify` consumes the same state file, a clean browser can still open the recorded draft.
- **Expected outcome**: The shared URL remains on the generated Railway HTTPS origin, its normalized public payload matches exactly, and existing authorization boundaries remain unchanged.
- **Failure mode**: An unreachable domain, changed route or response shape, lost draft, payload mismatch, or leaked private authority fails the release contract.
