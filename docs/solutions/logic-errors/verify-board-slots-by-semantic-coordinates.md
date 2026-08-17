---
title: Verify board slots by semantic coordinates, not collection indices
track: bug
category: logic-errors
date: 2026-08-17
tags: [map-geometry, hex-coordinates, persistence-migration, minor-factions]
related-files: [app/Draft/Slice.php, app/Draft/Draft.php, tests/e2e/minor-factions.spec.cjs]
---

# Verify board slots by semantic coordinates, not collection indices

**Problem:** Minor Factions replaced slice index `4`, which was assumed to be the official left-side second-ring location. In the persisted slice geometry, index `4` maps to `(q=0, r=-1)` while the required left-side location is index `3`, at `(q=-1, r=0)`; both appear in the second ring, so ring-level checks did not expose the mistake.

**Root Cause:** A positional rule was encoded and tested as an array offset without first tracing that offset through the renderer's coordinate mapping. Tests verified consistent substitution at the chosen index, but not the semantic board coordinate required by the rule.

**Solution:** Keep the authoritative index in the domain model, propagate it to map and export consumers, and add browser coverage that asserts the rendered axial coordinates (`data-q="-1"`, `data-r="0"`) as well as the absence of substitutions at the former slot. For already-persisted drafts, use a narrow load-time migration predicate: swap legacy index `4` into index `3` only when the corrected slot is not blue and the known legacy slot is blue, preserving both old data readability and the one-fewer-blue invariant.

**Prevention:** For any rule stated as left/right/top/ring/adjacent, test the canonical coordinate or named semantic slot—not only its collection index; if a wrong index has already been persisted, migrate only when the stored shape positively matches the legacy invariant.
