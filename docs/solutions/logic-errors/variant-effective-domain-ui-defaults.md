---
title: Recalibrate UI defaults when a variant changes the effective validation domain
track: bug
category: logic-errors
date: 2026-08-17
tags: [javascript, validation-defaults, minor-factions, slice-generation]
related-files: [js/main.js, js/minor-factions.js, app/Draft/Slice.php, tests/e2e/minor-factions.spec.cjs]
---

# Recalibrate UI defaults when a variant changes the effective validation domain

**Problem:** Minor Factions generation failed with “Selection contains no valid slices” for all 20 probed six-player seeds under the form defaults. The variant removes the reserved equidistant system before validating a slice, but the form still submitted thresholds calibrated for five effective systems.

**Root Cause:** The backend correctly changed the validation domain from five systems to four, while its companion UI presets remained `4` influence, `2.5` resources, and a `9`–`13` optimal-total range. Those defaults are part of the feature's operating invariant even though they are not server logic, so changing effective inputs without recalibrating them made otherwise ordinary generation requests effectively impossible.

**Solution:** Centralize mode-specific constraint presets in a pure helper and apply the four-system values (`2`, `1`, `5`, `10`) only on an explicit variant-toggle change. Keep ordinary five-system presets when disabling the variant, and do not reapply presets during player-count or initialization updates so user-customized constraints survive unrelated form changes. Verify both the transition semantics in unit tests and successful generation with untouched variant defaults in real browser E2E coverage.

**Prevention:** Whenever a mode excludes, reserves, or transforms data before validation, audit every default, preset, hint, and fixture against the effective validated domain; apply replacement defaults only on an intentional mode transition, then include an end-to-end test that generates successfully without editing advanced settings.
