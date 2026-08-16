---
title: Fail closed when derived browser output crosses readiness states
track: knowledge
category: best-practices
date: 2026-08-16
tags: [javascript, cache-invalidation, derived-state, fail-closed]
related-files: [js/minor-factions.js, js/generate-map.js, js/draft.js, tests/js/minor-factions-integration.test.cjs]
---

# Fail closed when derived browser output crosses readiness states

**Context:** One server-derived assignment feeds several browser representations: full maps, individual slices, tile lists, and fixed-position export strings. Its readiness can change in either direction after a pick, undo, or remote poll.

**Why It Matters:** Treating `resolved` as sufficient without validating the complete assignment can leak `undefined` or a discarded source tile into exports. Invalidating a map cache only on the forward transition leaves stale resolved output visible after undo or malformed remote state. Fixed-position exports also cannot remove a slot without shifting every downstream coordinate.

**Guidance:** Route every representation through one pure substitution helper. Require a complete valid assignment before substituting; for pending, invalid, missing, or malformed records, emit the same neutral placeholder and preserve the output's shape (for example, a `0` token at the reserved coordinate). Invalidate dependent caches at every boundary that replaces the authoritative server payload—success callbacks for pick, undo, regeneration/polling—not only when a particular status transition is detected.

**When to Apply:** Use this when cached or exported browser output derives from asynchronously refreshed state and consumers rely on stable positions. A revision-keyed cache that includes all authoritative inputs can replace blanket invalidation, but malformed inputs should still fail closed.
