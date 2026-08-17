---
title: Validate defaults against the final assembled representation
track: bug
category: logic-errors
date: 2026-08-17
tags: [php, javascript, validation-defaults, slice-generation]
related-files: [app/Draft/Commands/GenerateSlicePool.php, app/Draft/Slice.php, js/minor-factions.js]
---

# Validate defaults against the final assembled representation

**Problem:** Minor Factions initially removed an ordinary tile before validation, making the standard `4 / 2.5 / 9-13` slice defaults nearly impossible and prompting lower four-system presets. The actual rule replaces one blue draw with a valued green home system, so the published and validated slice still has five systems.

**Root Cause:** Capacity and defaults were calibrated against an intermediate pool shape rather than the final domain object. Drawing two blue and two red systems is a generation-capacity concern; validating two blue, two red, and the assigned home is a five-system balance concern. This narrows the earlier `variant-effective-domain-ui-defaults` guidance: the relevant domain is the fully assembled representation, not whichever subset happens to be drawn first.

**Solution:** Build the complete slice before calculating summaries or enforcing constraints. Keep pool-capacity math specific to the ordinary systems being drawn, but run resources, influence, optimal totals, wormholes, legendaries, and arrangement rules over the final persisted five-tile array. Retain ordinary defaults and verify a real untouched-default request end to end.

**Prevention:** Whenever generation reserves or substitutes a slot, separately test pool capacity, final object composition, final-value validation, and successful generation using untouched UI defaults.
