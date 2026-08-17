---
date: 2026-08-17
plan: 002
source: docs/plans/2026-08-17-002-fix-minor-factions-generation-split-plan.md
requirements: docs/brainstorms/2026-08-17-minor-factions-generation-split-requirements.md
depth: Deep
---

# Test Specification: Correct Minor Factions Generation Split

## Test Infrastructure

- Framework: PHPUnit 11.5 for PHP domain, command, and HTTP tests; Node.js built-in test runner for browser-helper tests; Playwright 1.62.1 for local end-to-end browser tests.
- Test directories: PHP tests are colocated with source under `app/`; JavaScript unit tests are under `tests/js/`; Playwright tests are under `tests/e2e/`.
- Conventions: PHP files use the `*Test.php` suffix and run through `vendor/bin/phpunit`; JavaScript unit and integration files use `*.test.cjs` and run through `node --test`; browser files use `*.spec.cjs` and run through `npm run test:e2e -- tests/e2e/minor-factions.spec.cjs` against `http://localhost:8080` by default.
- Persistence test environment: PHPUnit uses local storage at `tmp/test-drafts`; tests that assert no persistence or byte-equivalent persistence shall isolate their draft fixture and storage path.

## AU-1: Model Persisted Per-Slice Minor Faction State

### Behaviors Under Test

- **B1.1** When corrected Minor Factions state is serialized and loaded: every slice retains the same minor faction name, canonical home-system tile ID, slice order, and faction-to-slice pairing.
- **B1.2** When a corrected slice is exposed to consumers: its assigned home occupies tile index `3`, which maps to axial coordinate `(-1, 0)`.
- **B1.3** When a Discordant Stars minor is persisted: the canonical logical home tile remains the valuation identifier and the exposed presentation token identifies the correct asset/export tile.
- **B1.4** When picks are made, undone, or represented in polling/public serialization: the persisted minor identities, homes, pairings, and slice totals remain unchanged.
- **B1.5** When a mode-enabled saved draft is loaded: exactly one valid persisted assignment is required on every slice and no assignment is derived from player picks.

### Edge Cases

- **E1.1** When Minor Factions is disabled and slices have no assignments: the draft loads and round-trips with ordinary five-system behavior unchanged.
- **E1.2** When corrected state contains a minor whose faction name is unknown to the enabled catalog: loading fails explicitly instead of dropping or replacing the assignment.
- **E1.3** When corrected state contains duplicate minor faction names across slices: loading fails explicitly instead of accepting a non-unique minor pool.
- **E1.4** When mode-enabled state omits an assignment from any slice or provides a home tile that does not match the named faction: loading fails explicitly.
- **E1.5** When a mode-enabled draft contains an ordinary tile rather than the assigned home at index `3`: loading rejects the inconsistent state.

### Failure Modes

- **F1.1** When a corrected assignment has a blank faction name, missing tile ID, or invalid serialized shape: deserialization returns an explicit domain failure and does not partially construct a corrected draft.
- **F1.2** When corrected state references a faction without a canonical home-system mapping: loading fails closed and does not substitute a placeholder or random faction.
- **F1.3** When mode-enabled per-slice state is incomplete or inconsistent: the draft is rejected as malformed rather than repaired or randomized.

### Acceptance Criteria

- [ ] **AC1.1** When a corrected draft is saved and reloaded: faction names, home IDs, ordering, pairings, and calculated totals are identical, satisfying R6.
- [ ] **AC1.2** When any corrected slice is inspected: its minor home is at index `3` and axial `(-1, 0)`, satisfying R3.
- [ ] **AC1.3** When a DS faction is assigned: its logical tile ID and presentation token both resolve to the intended home system, satisfying R3 and R4.
- [ ] **AC1.4** When a player pick is added and then undone: no minor assignment or slice total changes, satisfying R6.
- [ ] **AC1.5** When polling/public serialization is repeated without regeneration: the assignment portion is byte-equivalent, satisfying R6.
- [ ] **AC1.6** When a mode-enabled draft lacks any valid persisted assignment: loading fails explicitly without tile mutation or randomized repair, satisfying R6.
- [ ] **AC1.7** When marked corrected state has a duplicate, missing, mismatched, or unknown assignment: loading fails with an explicit error, satisfying R6.
- [ ] **AC1.8** When Minor Factions is disabled: ordinary slice persistence and serialization are unchanged, satisfying R4.

## AU-2: Deterministically Partition the Enabled Faction Catalog

### Behaviors Under Test

- **B2.1** When Minor Factions generation requests `N` draftable factions and `S` slices: the result contains exactly `N` ordered draftable factions and exactly `S` ordered minor factions.
- **B2.2** When the two generated pools are compared: names are unique within each pool and no name appears in both pools.
- **B2.3** When custom factions are pinned: every valid pin appears in the draftable pool and no pin appears in the minor pool.
- **B2.4** When identical enabled sets, settings, pins, and seed are generated twice: both ordered faction lists are identical.
- **B2.5** When eligible and ineligible factions coexist in enabled sets: every minor is eligible while an ineligible faction may still be selected as draftable.
- **B2.6** When faction generation runs with Minor Factions disabled: it returns the same typed partition shape as enabled mode, containing the existing ordered draftable selection and an empty minor list.

### Edge Cases

- **E2.1** When player counts range from 3 through 8 and extra slices are requested: the draftable size follows the requested faction count while the minor size follows the full slice count, including extras.
- **E2.3** When Council Keleres is disabled: no Keleres entry appears in either pool; when enabled and otherwise valid, it participates according to its configured eligibility.
- **E2.5** When zero pins are supplied: generation still produces deterministic complete pools.
- **E2.6** When the requested faction count equals the player count: generation accepts the one-draftable-per-player boundary and still reserves one eligible minor per slice.
- **E2.7** When the exact validation predicates are exercised independently: generation accepts only `pinCount <= numberOfFactions`, `enabledCatalogCount >= numberOfFactions + numberOfSlices`, and `eligibleNonPinnedCount >= numberOfSlices`, with equality accepted at every boundary.
- **E2.8** When a fixed catalog and seed are used: generation reserves the first `numberOfSlices` factions from the one seed-shuffled eligible non-pinned list, excludes them from the remaining enabled catalog, fills unpinned draftable slots from the shuffled remainder, and returns deterministically shuffled final draftable and minor orders.

### Failure Modes

- **F2.1** When enabled sources cannot supply the requested draftable pool plus one eligible minor per slice: generation returns a specific actionable catalog-capacity error and no partial partition.
- **F2.2** When pins contain an unknown, disabled, duplicate, or excessive faction: validation fails before random selection and identifies the invalid pin condition.
- **F2.3** When enabled sources contain enough total factions but too few minor-eligible factions: generation returns an eligibility-capacity error rather than using an ineligible or disabled faction.

### Acceptance Criteria

- [ ] **AC2.1** When a supported request is generated: the draftable count exactly matches the requested count, satisfying R1.
- [ ] **AC2.2** When the result is inspected: the minor count exactly matches the generated slice count, satisfying R1.
- [ ] **AC2.3** When both pools are compared: they are internally unique and mutually disjoint, satisfying R1.
- [ ] **AC2.4** When custom factions are pinned: they are exclusively draftable, satisfying R2.
- [ ] **AC2.5** When faction sets or Keleres are disabled: those factions do not appear in either pool, satisfying R2.
- [ ] **AC2.6** When identical inputs and seed are generated twice: both ordered lists are identical, satisfying R2.
- [ ] **AC2.7** When enabled capacity or eligible-minor capacity is insufficient: a specific error is returned before any downstream generation, satisfying R1.
- [ ] **AC2.8** When the requested faction count equals the player count: validation accepts it without applying a `2 x players` minimum, satisfying R5.
- [ ] **AC2.9** When Minor Factions is disabled: faction generation still returns a `FactionPartition` with the ordinary draftable list and zero minors, preserving one uniform return contract.
- [ ] **AC2.10** When exact-capacity and one-below-capacity fixtures exercise each partition predicate: equality succeeds and one-below fails with the matching actionable error, satisfying R1 and R2.
- [ ] **AC2.11** When a fixed ordered catalog and seed are used: the reserved minors and final pool ordering match the specified single faction-seed phase exactly, satisfying R2.

## AU-3: Generate and Regenerate Complete Valued Slices Atomically

### Behaviors Under Test

- **B3.1** When a corrected Minor Factions slice pool is generated: every slice contains exactly two blue systems, two red systems, and its assigned green home system at index `3`.
- **B3.2** When slice and pool constraints are evaluated: all five visible systems, including the assigned home, contribute their printed resources, influence, optimal values, wormholes, legendary status, anomalies, and adjacency properties.
- **B3.3** When generated twice with identical complete settings and seed: draftable order, minor order, ordinary tile order, slice contents, and minor-to-slice pairings are identical.
- **B3.4** When either faction regeneration or slice regeneration is requested for an unstarted corrected Minor Factions draft: both faction pools and the complete slice pool are rebuilt and saved as one validated state.
- **B3.5** When player-order-only regeneration is requested: faction pools, slice tile lists, and minor pairings remain unchanged.
- **B3.6** When successful regeneration creates a new seed: the new seed is stored in the draft settings and reproduces the regenerated player order/current player, faction partition, slice IDs, and minor pairings.

### Edge Cases

- **E3.1** When custom five-ID slices are used: index `3` must contain a replaceable blue input, that blue ID is absent from the final slice, and the assigned green home occupies index `3`.
- **E3.5** When either half of an unstarted mode-enabled draft is regenerated: the replacement state contains a complete valid partition and one persisted assignment per slice.
- **E3.6** When custom Minor Factions input is used: its row count must equal `numberOfSlices`; exact equality succeeds, while either fewer or more rows fails before faction partitioning.
- **E3.7** When Minor-mode tile capacity is evaluated: the maximum slice count is `min(floor(blueTileCount / 2), floor(redTileCount / 2))`; exact capacity succeeds and one slice above capacity fails.
- **E3.8** When blue tiles exist only in an uneven mix of high, middle, and low tiers: Minor mode combines the enabled blue tiers and draws any `2 x numberOfSlices` unique blue IDs, while disabled mode retains its existing tier requirements.
- **E3.9** When high, middle, and low tier arrays are concatenated: the combined blue list is deterministically shuffled before slicing, so the draw is not a high-tier prefix; the base-game five-slice hard cap applies only to ordinary mode and Minor mode reaches its calculated two-blue/two-red capacity.

### Failure Modes

- **F3.1** When initial partitioning, system selection, arrangement, custom replacement, or final validation fails: no draft is saved.
- **F3.2** When coupled regeneration fails after any replacement setting, seed, player order/current player, faction partition, or partial slice candidate has been staged: every in-memory field and the saved file remain equivalent to their pre-request state.
- **F3.3** When a custom slice is not five IDs, has a non-blue replacement tile at index `3`, or becomes invalid after replacement: generation returns an actionable domain error and saves nothing.
- **F3.4** When the catalog lacks sufficient ordinary blue or red systems for the `2 blue / 2 red` composition: generation reports the relevant capacity failure and does not fall back to hidden, duplicated, or wrong-color tiles.

### Acceptance Criteria

- [ ] **AC3.1** When a corrected pool is generated: each slice has the exact `2 blue / 2 red / 1 green home` composition, satisfying R1 and R4.
- [ ] **AC3.2** When any slice is inspected: the assigned home is its persisted index `3` tile and maps to axial `(-1, 0)`, satisfying R3.
- [ ] **AC3.3** When constraints and summaries are calculated: they use all five final tiles and can be changed by the home tile, satisfying R4.
- [ ] **AC3.4** When identical generation inputs are repeated: complete pool ordering and pairing are identical, satisfying R2.
- [ ] **AC3.5** When initial generation fails: the repository contains no newly saved draft, satisfying R1 and R6.
- [ ] **AC3.6** When coupled regeneration fails: both in-memory and persisted original state remain unchanged, satisfying R6.
- [ ] **AC3.7** When faction-only or slice-only regeneration succeeds: both halves of the invariant are regenerated and saved together, satisfying R1 and R6.
- [ ] **AC3.8** When order-only regeneration succeeds: the faction/slice invariant is untouched, satisfying R6.
- [ ] **AC3.9** When mode-enabled regeneration succeeds: complete persisted assignments are created atomically with the replacement pools, satisfying R6.
- [ ] **AC3.10** When Minor-mode custom rows are submitted: their count must exactly equal `numberOfSlices`, and count failure occurs before partitioning or persistence, satisfying R1 and R6.
- [ ] **AC3.11** When TilePool and Settings are tested at Minor-mode boundaries: exactly two unique blue and two unique red systems per slice are available at the accepted maximum, and the first count above it is rejected, satisfying R4.
- [ ] **AC3.12** When regeneration succeeds: the persisted new seed reproduces the complete regenerated graph; when regeneration fails: seed, settings, order, current player, pools, slices, object state, and saved bytes all remain unchanged, satisfying R2 and R6.
- [ ] **AC3.13** When controlled tier arrays and base-only settings are used: Minor mode shuffles across tiers and accepts the calculated two-blue/two-red capacity without the ordinary five-slice cap, while ordinary mode retains its current behavior, satisfying R4 and R5.

## AU-4: Restore Ordinary Defaults and Authoritative Request Validation

### Behaviors Under Test

- **B4.1** When Minor Factions is enabled on an untouched form: slice constraints remain `4` minimum influence, `2.5` minimum resources, `9` minimum total, and `13` maximum total.
- **B4.2** When mode or player count changes: the faction field minimum equals player count and does not double in Minor Factions mode.
- **B4.3** When a creator has edited slice constraints and toggles Minor Factions or changes player count: all custom values remain unchanged.
- **B4.4** When a default supported Minor Factions request is submitted: it reaches generation with the requested faction count interpreted as draftable choices and succeeds without advanced-setting edits.
- **B4.5** When a valid regeneration request selects either factions or slices in Minor Factions mode: request semantics trigger coupled faction-and-slice regeneration.
- **B4.6** When generation request settings omit a seed: the handler parses once, validates that same settings instance, dispatches that same instance, and the single generated seed is persisted unchanged.

### Edge Cases

- **E4.1** When the requested faction count equals player count: client, HTML, server settings, and request handling all accept the value.
- **E4.2** When the player count changes after a lower faction count was entered: the faction value is raised only to the new one-per-player minimum while slice constraint values remain unchanged.
- **E4.3** When Minor Factions is toggled on and off repeatedly: default and custom values do not drift, round, or switch to the obsolete four-system preset.
- **E4.4** When Minor Factions is disabled: existing form defaults, minimums, help visibility, and generation request meaning remain unchanged.
- **E4.6** When custom Minor Factions rows are submitted: Settings and the request boundary accept exactly `numberOfSlices` rows and reject both fewer and more rows before command dispatch.
- **E4.7** When Minor-mode tile counts sit at the two-blue/two-red capacity boundary: Settings accepts the exact maximum and rejects one additional requested slice; disabled mode continues to apply its ordinary tier/capacity rules.

### Failure Modes

- **F4.1** When generation receives an impossible catalog or invalid pins: the HTTP response is `400`, identifies the actionable domain problem, dispatch/persistence does not occur, and the user is not shown the generic no-valid-slices message.
- **F4.2** When coupled regeneration cannot produce a valid partition or slice pool: the HTTP response is `400`, identifies the domain problem, and the original draft remains saved unchanged.
- **F4.3** When request parsing, validation, dispatch, or regeneration raises an unexpected non-domain exception: the exception propagates through the application's existing unexpected-error behavior and is not converted into a `400` validation response.

### Acceptance Criteria

- [ ] **AC4.1** When Minor Factions is enabled: the form retains ordinary `4 / 2.5 / 9-13` defaults, satisfying R5.
- [ ] **AC4.2** When player count or mode changes: the faction minimum remains one per player, satisfying R5.
- [ ] **AC4.3** When custom constraints exist: toggles and player-count changes preserve them, satisfying R5.
- [ ] **AC4.4** When a supported default request is submitted: generation succeeds without changing advanced settings, satisfying R5.
- [ ] **AC4.5** When the form explains faction counts: it states that the requested count is draftable and one additional eligible unused faction is required per generated slice, satisfying R1 and R5.
- [ ] **AC4.6** When a domain validation failure occurs during initial generation: the response is actionable `400` and no draft is persisted, satisfying R1.
- [ ] **AC4.7** When a domain validation failure occurs during regeneration: the response is actionable `400` and original state is preserved, satisfying R6.
- [ ] **AC4.8** When Minor Factions is disabled: request validation and ordinary defaults are unchanged, satisfying R4.
- [ ] **AC4.9** When a seed is omitted: the exact parsed and validated Settings object dispatched by the handler carries one generated seed through persistence without reparsing or regeneration, satisfying R2.
- [ ] **AC4.10** When custom row counts or two-blue/two-red tile capacity are invalid: request validation returns actionable `400` before dispatch and persistence, satisfying R1 and R4.
- [ ] **AC4.11** When an unexpected exception is injected: it propagates and no domain-validation response is emitted.

## AU-5: Publish Face-Up Per-Slice State from the Server

### Behaviors Under Test

- **B5.1** When a new corrected draft page opens before the first pick: every generated slice displays its persisted home artwork and visible minor faction name at the left second-ring coordinate.
- **B5.2** When extra unchosen slices exist: each extra slice displays its own distinct persisted minor before any player selection.
- **B5.3** When corrected draft data is published: each slice exposes exactly `minor_faction: {name, tile_id, render_token}` for its persisted assignment, plus its semantic position and totals from the final tile list.
- **B5.4** When the page reloads, polling refreshes, or a pick/undo occurs: the assignment payload, names, homes, pairings, and displayed totals remain unchanged.
- **B5.5** When malformed mode-enabled state is requested for viewing: reconstruction fails explicitly before a partial slice or inferred assignment is rendered.
- **B5.6** When `js/draft.js` receives initial or polled corrected payloads: it renders authoritative per-slice `minor_faction` data and never constructs pending/resolved assignments from player picks.

### Edge Cases

- **E5.1** When the assigned faction is Discordant Stars content: the page uses the presentation token for artwork while exposing totals derived from the canonical logical tile.
- **E5.2** When the draft contains no picks, some picks, or a completed pick sequence: every corrected slice remains face up and named in all three states.
- **E5.3** When Minor Factions is disabled: the public payload and draft page preserve ordinary slice rendering without minor-specific notices or fabricated metadata.
- **E5.4** When a valid mode-enabled draft is picked, undone, polled, saved, and reloaded: its persisted assignment objects remain unchanged.
- **E5.5** When JavaScript integration consumers receive enabled and disabled payloads: enabled payloads retain the exact three-field object, while disabled shapes contain no minor metadata and never synthesize those fields.

### Failure Modes

- **F5.1** When marked corrected state reaches the view layer with missing or inconsistent assignment data: rendering fails closed through the explicit malformed-state path instead of displaying a placeholder as if valid.
- **F5.2** When required presentation metadata is missing from a mode-enabled assignment: the malformed state is rejected rather than partially rendered or inferred.
- **F5.3** When repeated public serialization occurs after player actions: it must not invoke pick-derived assignment behavior; any assignment change is a test failure.

### Acceptance Criteria

- [ ] **AC5.1** When the initial page opens: every corrected slice, including extras, shows a named home before picks, satisfying R3.
- [ ] **AC5.2** When accessible/title text is inspected: it names the assigned minor faction rather than relying only on artwork, satisfying R3.
- [ ] **AC5.3** When displayed totals and special properties are inspected: they include the home system, satisfying R4.
- [ ] **AC5.4** When the page reloads or polls: the same homes remain paired with the same slices, satisfying R3 and R6.
- [ ] **AC5.5** When picks are made and undone: the server-published assignment data remains equivalent, satisfying R6.
- [ ] **AC5.6** When malformed mode-enabled state is viewed: it fails explicitly and invents no assignment, satisfying R6.
- [ ] **AC5.7** When Minor Factions is disabled: ordinary server and page presentation are unchanged, satisfying R4.
- [ ] **AC5.8** When the corrected public payload is inspected: every slice's `minor_faction` object contains exactly `name`, `tile_id`, and `render_token`, with no browser faction-catalog lookup required, satisfying R3, R4, and R6.
- [ ] **AC5.9** When `js/draft.js` processes polling, reload, picks, and undo in `tests/js/minor-factions-integration.test.cjs`: it neither clears nor synthesizes corrected per-slice state, satisfying R6.

## AU-6: Use Persisted Slice Tiles Across Maps and Exports

### Behaviors Under Test

- **B6.1** When a player selects a corrected slice: the full map uses that slice's persisted home tile at axial `(-1, 0)` regardless of the player's faction or speaker position.
- **B6.2** When individual slice maps are displayed before or after picks: every slice shows its own persisted home at the left second-ring coordinate.
- **B6.3** When tile-gather output and TTS strings are generated: they include each selected slice's persisted home token and exclude the blue tile replaced at index `3`.
- **B6.4** When player object order or speaker positions are reversed: a slice's minor identity and map/export tile remain unchanged.
- **B6.5** When a DS minor is assigned: image lookup and TTS output use the correct presentation/export token while server totals continue to use the canonical tile.
- **B6.6** When a fresh supported 3–8 player Minor Factions draft is generated with untouched defaults: the browser reaches a usable draft with face-up complete slices before picks.
- **B6.7** When map and export consumers receive a corrected slice payload: PHP/browser valuation consumes `minor_faction.tile_id`, while artwork, tile gather, and TTS consume `minor_faction.render_token`; all surfaces label it with `minor_faction.name`.

### Edge Cases

- **E6.1** When extra slices remain unchosen: individual-slice presentation includes their assigned homes, while final-map and selected-tile exports include only the homes belonging to selected slices.
- **E6.2** When picks are completed and the last action is undone: existing per-slice minor pairings remain present rather than reverting to placeholders or being reassigned.
- **E6.3** When authoritative draft payload changes through explicit regeneration: any browser cache refreshes to the new persisted assignments; ordinary picks do not invalidate or alter them.
- **E6.4** When an enabled browser payload lacks persisted assignment fields: maps and exports fail closed without random assignment or placeholder substitution.
- **E6.5** When the three payload identifiers intentionally differ in a DS fixture: every consumer selects its designated field and no consumer performs a browser-side faction catalog lookup to recover another identifier.

### Failure Modes

- **F6.1** When a corrected slice lacks a valid persisted home token at an output boundary: the output does not silently substitute by speaker position, player faction, or random catalog choice.
- **F6.2** When canonical logical IDs and DS presentation tokens differ: the browser/export output uses the presentation token and never exposes a broken asset reference or the wrong TTS tile.
- **F6.3** When reload, polling, map navigation, or undo occurs: any change to a corrected slice's assignment, totals, or output tile is treated as a consistency failure rather than a supported pending/resolved transition.

### Acceptance Criteria

- [ ] **AC6.1** When any corrected slice is mapped: its home appears at axial `(-1, 0)`, satisfying R3 and R4.
- [ ] **AC6.2** When full maps, individual slices, tile lists, and TTS strings are compared: all use the same persisted home identity, satisfying R4.
- [ ] **AC6.3** When exports are inspected: the replaced blue ID is absent and the persisted home token is present, satisfying R4.
- [ ] **AC6.4** When player order, faction picks, or speaker positions change: slice-to-minor pairing remains unchanged, satisfying R6.
- [ ] **AC6.5** When a DS home is used: artwork, map, tile list, and TTS output identify the intended system, satisfying R4.
- [ ] **AC6.6** When supported 3–8 player drafts use untouched defaults: browser generation succeeds and every slice is complete before picks, satisfying R3 and R5.
- [ ] **AC6.7** When a pick is undone or the page reloads: assignments and totals remain unchanged, satisfying R6.
- [ ] **AC6.8** When malformed enabled payload is presented: browser outputs fail closed and gain no random assignment, satisfying R6.
- [ ] **AC6.9** When `tests/js/minor-factions-integration.test.cjs` exercises draft, map, slice, tile-gather, and TTS consumers: all read the authoritative three-field per-slice payload and agree on identity and placement, satisfying R3, R4, and R6.
- [ ] **AC6.10** When the browser end-to-end contract is run: it passes through `npm run test:e2e -- tests/e2e/minor-factions.spec.cjs` using the repository Playwright configuration.

## Cross-AU Integration Contracts

### Exact Generation-Time Split Produces Complete Slices

- **Precondition**: AU-1, AU-2, and AU-3 are complete; Minor Factions is enabled with sufficient enabled and eligible factions.
- **Behavior**: Generate a draft with requested draftable count `N`, total slice count `S`, and a fixed seed.
- **Expected outcome**: Exactly `N` factions are draftable, exactly `S` distinct eligible factions are assigned one per slice, the pools are disjoint, and each slice contains its assigned home at index `3` as one of five valued tiles.
- **Failure mode**: Any overlap, missing assignment, wrong composition, hidden tile, or count mismatch prevents persistence.

### Deterministic Aggregate Reproduction

- **Precondition**: AU-2 and AU-3 are complete; enabled sets, pins, settings, custom slices, and seed are identical.
- **Behavior**: Generate the complete draft aggregate twice.
- **Expected outcome**: Draftable order, minor order, ordinary tile order, slice order, final tile IDs, and faction-to-slice pairing are identical.
- **Failure mode**: A difference in any ordered output identifies nondeterministic phase interaction and fails the contract.

### Default Form to Face-Up Draft

- **Precondition**: AU-2 through AU-5 are complete; a supported catalog and player configuration are available.
- **Behavior**: Enable Minor Factions on the untouched generation form and submit it before changing advanced constraints.
- **Expected outcome**: The form retains `4 / 2.5 / 9-13`, accepts one draftable faction per player or more, generates successfully, and renders a named persisted minor on every slice before the first pick.
- **Failure mode**: The request returns the generic no-valid-slices error, applies obsolete four-system values, raises faction count to twice players, or renders placeholders.

### Home Tile Drives Validation and Presentation

- **Precondition**: AU-1, AU-3, AU-5, and AU-6 are complete; a fixture home has printed values or special properties that affect a constraint.
- **Behavior**: Generate and display a slice whose validity or total depends on the assigned home system.
- **Expected outcome**: Generation validation, published totals, draft-page summary, map, individual slice, tile list, and TTS output all reflect that same persisted home.
- **Failure mode**: Any consumer omits the home, uses the replaced blue, or calculates a different total.

### Coupled Atomic Regeneration

- **Precondition**: AU-1 through AU-4 are complete; an unstarted corrected Minor Factions draft is persisted.
- **Behavior**: Request faction-only regeneration and separately request slice-only regeneration.
- **Expected outcome**: Each request stages a new Settings object containing the new seed, requested player order/current player, faction partition, and slices, commits them together after validation, and saves once; the persisted seed reproduces the aggregate, while player-order-only regeneration preserves faction/slice state.
- **Failure mode**: A request saves only factions, only slices, a new seed without its graph, a new order/current player without the rest, overlapping pools, mismatched assignments, or any partial staged state.

### Failed Regeneration Preserves Published State

- **Precondition**: AU-3 through AU-6 are complete; a valid draft is loaded and the next regeneration attempt is forced to fail domain validation.
- **Behavior**: Submit the failing regeneration and then reload/publicly fetch the draft.
- **Expected outcome**: The response is actionable `400`, and persisted bytes, published assignments, totals, maps, and exports remain equivalent to the pre-request draft.
- **Failure mode**: Any changed faction, slice, assignment, total, timestamp-dependent payload portion representing domain state, or saved partial result violates atomicity.

### Pick, Undo, Poll, Reload, and Export Stability

- **Precondition**: AU-1, AU-5, and AU-6 are complete; a corrected draft begins with no picks.
- **Behavior**: Record every assignment and total, make picks, poll, reload, generate all outputs, undo the last pick, and inspect the draft again.
- **Expected outcome**: The same slice-to-minor pairing and totals persist throughout; outputs depend on selected slices but never reassign their minors.
- **Failure mode**: Any pending/resolved transition, speaker-position substitution, placeholder reappearance, or assignment change fails the contract.

### Strict Persisted-State Contract

- **Precondition**: AU-1, AU-3, AU-5, and AU-6 are complete; a mode-enabled fixture omits or corrupts one persisted assignment.
- **Behavior**: Load, view, poll, map, and export the malformed draft.
- **Expected outcome**: Server reconstruction rejects the malformed state before presentation, and direct browser helpers fail closed without creating an assignment.
- **Failure mode**: Any layer repairs, randomizes, substitutes, or partially renders the malformed state.

### Disabled-Mode Regression Contract

- **Precondition**: All AUs are complete; Minor Factions is disabled.
- **Behavior**: Generate, persist, reload, pick, regenerate, map, and export an ordinary draft.
- **Expected outcome**: Existing five-system composition, defaults, validation, presentation, snake draft, and exports remain unchanged and contain no minor-specific metadata or notices.
- **Failure mode**: Corrected Minor Factions rules alter counts, tile placement, totals, regeneration coupling, or output in disabled mode.

### Custom Cardinality and Tile Capacity Boundary Contract

- **Precondition**: AU-2 through AU-4 are complete; fixtures expose controlled faction, blue-tile, red-tile, and custom-row counts.
- **Behavior**: Exercise equality and one-below/one-above boundaries for custom row count, enabled catalog size, eligible non-pinned size, and `min(floor(blue / 2), floor(red / 2))` slice capacity.
- **Expected outcome**: Every exact boundary succeeds; custom row mismatch and each insufficient capacity fail before partitioning, dispatch, or persistence with the matching actionable domain error; ordinary mode retains its existing tier rules.
- **Failure mode**: Generation accepts an unmatched custom row, consumes a hidden/duplicate tile, applies ordinary blue-tier assumptions in Minor mode, or partially persists a failed boundary request.

### Single Parsed Settings Request Contract

- **Precondition**: AU-3 and AU-4 are complete; a generate request omits its seed.
- **Behavior**: Submit the request and observe the settings used for validation, command dispatch, and persistence.
- **Expected outcome**: One parsed Settings instance receives one generated seed and is reused unchanged through validation, dispatch, generated graph, and persistence.
- **Failure mode**: Reparsing changes the seed or settings, validation and dispatch observe different objects, or an unexpected exception is converted to an actionable `400`.

### Exact Authoritative Payload Consumer Contract

- **Precondition**: AU-1, AU-5, and AU-6 are complete; a corrected DS fixture has distinguishable canonical and presentation identifiers.
- **Behavior**: Publish, poll, render, map, gather, and export the fixture through PHP, `js/draft.js`, and map/export helpers.
- **Expected outcome**: Every slice publishes exactly `minor_faction: {name, tile_id, render_token}`; valuation/reconstruction uses `tile_id`, artwork/tile gather/TTS uses `render_token`, labels use `name`, and picks/undo never rebuild the object.
- **Failure mode**: A consumer derives identity from player picks or speaker position, performs a browser faction-catalog lookup, uses the wrong identifier field, or changes the payload during polling/undo.
