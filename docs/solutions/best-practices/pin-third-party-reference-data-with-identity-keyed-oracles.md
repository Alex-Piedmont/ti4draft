---
title: Pin third-party reference data with an identity-keyed oracle
track: knowledge
category: best-practices
date: 2026-08-24
tags: [php, reference-data, source-pinning, reconciliation]
related-files: [data/factions.json, data/FactionDataTest.php, tests/fixtures/ti4-reference-alliance-abilities-0c2e2b66.json]
---

# Pin third-party reference data with an identity-keyed oracle

**Context:** Alliance ability prose from TI4 Reference had to be copied into the local faction catalog so draft rendering remained deterministic and did not depend on a third-party request.

**Why It Matters:** Checking only that every record has non-empty prose cannot detect transcription errors, swapped faction abilities, missing identities, or upstream wording drift. A fixture without source provenance is also difficult to audit later.

**Guidance:** Pin the upstream commit, store an independent fixture containing the source revision and source path for every faction, and exact-compare the complete catalog map to that fixture by canonical faction identity. Require set equality and exact text equality; do not reconcile by array position. Record deliberate identity exceptions, such as mapping a combined faction record to the source entry matching its selected home-system side.

**When to Apply:** Use this for locally vendored, attribution-sensitive reference data where correctness matters and runtime fetching is undesirable. Do not duplicate an upstream package this way when a stable, versioned machine-readable dependency already provides the required contract.
