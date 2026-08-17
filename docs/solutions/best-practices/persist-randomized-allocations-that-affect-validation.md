---
title: Persist randomized allocations that affect aggregate validation
track: knowledge
category: best-practices
date: 2026-08-17
tags: [php, randomized-allocation, aggregate-invariants, atomic-regeneration]
related-files: [app/Draft/FactionPartition.php, app/Draft/MinorFaction.php, app/Draft/Commands/RegenerateDraft.php]
---

# Persist randomized allocations that affect aggregate validation

**Context:** Minor Faction identity was first derived from unpicked factions, but the assigned home system is face-up at generation time and changes slice resources, influence, legality, maps, and exports. The corrected generator partitions the enabled catalog, pairs one minor with each slice, and persists that pairing on the slice.

**Why It Matters:** A randomized value is no longer a cheap projection once users evaluate choices against it or it participates in validation. Re-deriving it from picks makes the aggregate incomplete before drafting and lets undo, polling, or consumer-specific logic disagree with the values originally validated. This is the boundary to the earlier `derive-projections-from-persisted-randomized-source` guidance: derive only when the result has no independent gameplay or validation meaning.

**Guidance:** Return coupled randomized pools as one typed, disjoint result; construct and validate the complete aggregate; then persist canonical identifiers on the owning child object. Reconstruct mode-enabled state strictly instead of repairing or reshuffling it. If regeneration can change either side of the invariant, stage the replacement faction pool, slice pool, settings, and order locally, mutate the aggregate only after every step succeeds, and save once.

**When to Apply:** Use this when a generated allocation affects identity, numeric summaries, eligibility, published choices, or downstream exports. Continue deriving inexpensive views when all inputs are persisted and the result is not independently meaningful.
