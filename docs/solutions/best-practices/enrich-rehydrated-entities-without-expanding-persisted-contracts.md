---
title: Enrich rehydrated entities without expanding persisted contracts
track: knowledge
category: best-practices
date: 2026-08-24
tags: [php, persistence, api-contract, derived-metadata]
related-files: [app/Draft/MinorFaction.php, app/Draft/MinorFactionTest.php, app/TwilightImperium/Faction.php]
---

# Enrich rehydrated entities without expanding persisted contracts

**Context:** Existing drafts persist a Minor Faction's stable name and tile ID, then resolve the full faction from the current catalog during load. New Alliance ability metadata was needed in the rendered draft but was not part of the assignment itself.

**Why It Matters:** Adding derived prose to saved JSON creates migrations and stale duplicated data, while adding it to the public payload silently changes an API contract. Rehydration already provides a safe seam for attaching current static metadata to legacy records.

**Guidance:** Keep persisted identity and assignment fields unchanged, enrich the reconstructed domain object from the validated catalog, and render the derived metadata from that object. Contract-test exact key sets independently for persisted and public serializers, then load a legacy minimal record and assert that it gains the new metadata without changing either serialized shape.

**When to Apply:** Use this when metadata is deterministic from a stable persisted identity and historical snapshots are not required. Persist the value when it is user-editable, affects historical interpretation, or must remain frozen at the time of the event.
