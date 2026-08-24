# Miltydraft generator

[Visit the app here](https://milty.shenanigans.be/).

An expanded version of miltydraft.com, with saving/sharing drafts across sessions.

## Requirements: 
* make sure you have [docker](https://docs.docker.com/get-started/) installed

## Getting started

To install a local copy of this app you can clone it from the Git Repo: 

`git@github.com:shenanigans-be/miltydraft.git`

Then follow these steps:

1. Add `127.0.0.1 milty.localhost` to your `/etc/hosts` file. This first step is optional though. You can use
   127.0.0.1 directly as well.
2. Run `docker compose up -d --build`. This will first build the image, then start all services.
3. Run `docker compose exec app composer install`. This will install all php dependencies.
4. Create a `.env` file. See `.env.example` for details.
5. Go to [https://milty.localhost](https://milty.localhost) in your browser (or http://localhost if you don't want to go through the hassle of the following steps) 
6. Your browser might give you some scary warnings about untrusted certificates. That's because we're using a self-signed certificate.
If you want to, you can add the certificate to your device's truster certificate.
7. Run `docker compose exec app cat /home/app/.local/share/caddy/pki/authorities/local/root.crt > caddy-cert.crt` to make a copy of the certificate in `caddy-cert.crt` (which you can then import wherever you need it)

### Libraries and Dependencies

Frontend runs on vanilla JS/jQuery (I'm aware jQuery is a bit of a blast from the past at this point; sue me and/or change it and PR me if you want) and the Back-end is vanilla PHP.
As such there's no build-system, or compiling required except for the steps described above.

To make this app as lean and mean (and easy to understand for anyone) as possible, external dependencies, both in the front- and backend should be kept to an absolute minimum.

### Testing

Run the PHP suite with `docker compose exec app composer phpunit`. The Minor Factions browser test uses Playwright as a development-only dependency: run `npm install`, `npx playwright install chromium`, and then `npm run test:e2e` while the Docker app is available at `http://localhost:8080` (or set `E2E_BASE_URL`).

## Railway deployment

Railway hosts the complete PHP/Caddy application and supplies the public `*.up.railway.app` HTTPS domain. Vercel, S3, a database, and a purchased domain are not required. Draft JSON files live on one persistent Railway volume mounted at `/data/drafts`; automated volume backups are not enabled by this initial setup.

Run every command from this repository root. Create and link the isolated project and service:

```sh
railway init --name ti4draft --workspace "Alex Rudd's Projects" --json
railway add --service ti4draft --json
node tests/deployment/railway-status-check.cjs --project ti4draft --service ti4draft --environment production
```

The identity check is mandatory before any later mutation. Configure only the new service, without listing or printing existing variables:

```sh
railway variable set --service ti4draft --skip-deploys --json \
  RAILWAY_DOCKERFILE_PATH=deploy/app/Dockerfile \
  STORAGE=local \
  STORAGE_PATH=/data/drafts \
  DEBUG=false \
  VERSION=<release-id>
railway volume --service <service-id> --environment <environment-id> add --mount-path /data/drafts --json
railway domain --service ti4draft --json
```

Railway CLI 4.30 requires the service and environment UUIDs for volume creation; obtain both from `railway status --json` after the identity check. Do not substitute service names in the volume command.

In the service settings, connect `Alex-Piedmont/ti4draft` as the GitHub source and select the release branch. Select the Dockerfile builder; `RAILWAY_DOCKERFILE_PATH` supplies the nested Dockerfile path. Set `Deploy > Healthcheck Path` to `/`, then confirm the source, branch, health path, variables, domain, and volume with `railway environment config --json` before selecting **Deploy**. GitHub-backed deployment is required for this repository because its tracked game art can exceed the Railway CLI source-upload timeout.

Capture the current deployment before every trigger, then wait for a different deployment to reach `SUCCESS`:

```sh
node tests/deployment/railway-wait.cjs capture --service ti4draft --state-file /tmp/ti4draft-deployment.json
# Push the release branch, then select Deploy in the Railway service settings.
node tests/deployment/railway-wait.cjs wait --service ti4draft --state-file /tmp/ti4draft-deployment.json --timeout-seconds 900
```

Use the domain returned by `railway domain` for public acceptance. Create the persistence baseline before redeployment and verify the same public draft afterward:

```sh
sh tests/deployment/railway-smoke.sh "https://<generated-domain>"
E2E_BASE_URL="https://<generated-domain>" npm run test:e2e
node tests/deployment/railway-persistence.cjs create --base-url "https://<generated-domain>" --state-file /tmp/ti4draft-railway-state.json
node tests/deployment/railway-wait.cjs capture --service ti4draft --state-file /tmp/ti4draft-deployment.json
railway redeploy --service ti4draft --yes
node tests/deployment/railway-wait.cjs wait --service ti4draft --state-file /tmp/ti4draft-deployment.json --timeout-seconds 900
node tests/deployment/railway-persistence.cjs verify --base-url "https://<generated-domain>" --state-file /tmp/ti4draft-railway-state.json
```

If a deployment fails, inspect it with `railway logs --service ti4draft --deployment <deployment-id>` and keep the last successful deployment active. Do not delete or replace the volume during rollback; redeploying an earlier application revision against the existing `/data/drafts` mount preserves shared drafts.

The initial production service is available at `https://ti4draft-production.up.railway.app`. Its Railway resource identifiers are project `2a8fd727-80ad-4eb2-872f-1a5778bd19bc`, service `07075ae0-8aa8-42c9-80eb-43cb3e444dab`, environment `b232cc67-5c5e-47dd-bf6b-0dc912498a62`, and volume `71beaee9-e1c4-4835-8686-621a0844f217`.

### Understanding the App flow

1. Players come in on index.php and choose their options. 
2. A JSON config file is created (either locally or remotely, depending on .env settings) with a unique ID
3. That Draft ID is also the Draft URL: APP_URL/d/{draft-id} (URL rewriting is done via Caddy locally)
4. Players (or the Admin) make draft choices, which updates the draft json file (with very loose security, since we're assuming a very low amount of bad actors)
