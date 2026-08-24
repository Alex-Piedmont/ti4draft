---
title: Railway CLI volume creation requires resource UUIDs
track: knowledge
category: workflow-issues
date: 2026-08-24
tags: [railway, railway-cli, volume, uuid]
related-files: [README.md, tests/deployment/railway-status-check.cjs]
---

# Railway CLI volume creation requires resource UUIDs

**Context:** With Railway CLI 4.30, `railway volume --service ti4draft add` panicked instead of creating the persistent draft volume, even though the repository was linked to the intended project, environment, and service.

**Why It Matters:** Name-based service selection works for many Railway commands but is unreliable for volume creation in this CLI version. Retrying the same command can waste time or create uncertainty about whether a billable volume exists.

**Guidance:** First fail closed on project, environment, and service identity using `railway status --json`. Extract the service and environment UUIDs from that confirmed status, then create the volume with both explicit identifiers: `railway volume --service <service-id> --environment <environment-id> add --mount-path <path> --json`. Confirm the returned volume and mount before deploying.

**When to Apply:** Use this for Railway CLI 4.30 volume provisioning or whenever the name-based volume command panics or fails ambiguously; recheck behavior after upgrading the CLI.
