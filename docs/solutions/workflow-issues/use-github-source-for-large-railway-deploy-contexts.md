---
title: Use a GitHub source for large Railway deployment contexts
track: knowledge
category: workflow-issues
date: 2026-08-24
tags: [railway, github, dockerfile, upload-timeout]
related-files: [README.md, deploy/app/Dockerfile, .dockerignore]
---

# Use a GitHub source for large Railway deployment contexts

**Context:** `railway up` stalled during source upload for a repository containing roughly 108 MB of tracked game art. The production Dockerfile is nested at `deploy/app/Dockerfile`, so switching deployment sources also had to preserve the custom build path.

**Why It Matters:** `.dockerignore` reduces the Docker build context but does not make required tracked assets disappear from the Railway CLI source upload. A stalled upload may leave a deployment in `INITIALIZING` without useful build logs, which can be mistaken for a Dockerfile or runtime failure.

**Guidance:** Connect the Railway service directly to the GitHub repository and release branch, select the Dockerfile builder, and set `RAILWAY_DOCKERFILE_PATH=deploy/app/Dockerfile`. Capture the current deployment ID before triggering the GitHub-backed deploy, then require a different deployment to reach `SUCCESS` before acceptance checks.

**When to Apply:** Use this when `railway up` times out or remains in source upload for a repository with large required tracked assets. Keep CLI uploads for repositories whose source bundle completes reliably.
