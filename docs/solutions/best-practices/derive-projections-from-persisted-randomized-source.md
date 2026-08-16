---
title: Derive deterministic projections from the persisted randomized source
track: knowledge
category: best-practices
date: 2026-08-16
tags: [php, deterministic-assignment, persistence, randomized-pool]
related-files: [app/Draft/MinorFactionAssignments.php, app/Draft/Draft.php]
---

# Derive deterministic projections from the persisted randomized source

**Context:** A later assignment had to remain randomized while reacting correctly to picks, undo, regeneration, and reload. The draft already persisted the exact randomized pool that should govern the result.

**Why It Matters:** Persisting a second assignment snapshot creates stale state after source mutations, while performing another shuffle can disagree with the pool users actually drafted from. A saved randomized ordering is both state and provenance; filtering it preserves the original draw without another mutable record.

**Guidance:** Persist only the setting and source state. At the public serialization boundary, filter the persisted pool in its existing order, remove selected or ineligible entries, sort recipients by their stable domain position, and pair the two sequences. Return explicit `pending`, `resolved`, or `invalid` projections, with no partial assignments, so malformed legacy data remains readable and reversible operations immediately recompute the correct result.

**When to Apply:** Use this for cheap deterministic projections whose complete inputs are already persisted. Persist the result instead when it is independently editable, expensive to reproduce, or must be retained as an immutable historical event.
