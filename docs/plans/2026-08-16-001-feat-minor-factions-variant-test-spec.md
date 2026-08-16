---
date: 2026-08-16
plan: 001
source: Direct request
depth: Standard
---

# Test Specification: Minor Factions Variant

## Test Infrastructure

- Framework: PHPUnit 11.5-compatible configuration, with Composer scripts for PHPUnit and ParaTest; Node's built-in `node:test` runner provides the dependency-free executable boundary for pure Minor Factions browser helpers.
- Test directories: `app/` and `data/`, discovered recursively by the `core` and `data` PHPUnit suites.
- Conventions: PHP tests use `*Test.php` names colocated with source; request-handler tests exercise HTTP and rendered-template boundaries; data integrity tests live under `data/`; pure browser logic is exported compatibly from `js/minor-factions.js`; a dependency-free `node:vm` fixture executes the shipped `generate-map.js` and `draft.js` wiring with minimal DOM/jQuery stubs.

## AU-1: Model minor-faction eligibility

### Behaviors Under Test

- **B1.1** When all faction data is loaded: every faction exposes an explicit boolean Minor Factions eligibility value.
- **B1.2** When an eligible faction is loaded: its declared home-system ID resolves to a placeable tile containing at least one planet.
- **B1.3** When Firmament/Obsidian is loaded: it is eligible and resolves to home-system tile `96a`.

### Edge Cases

- **E1.1** When Ghosts of Creuss is loaded with its non-planet gateway home tile: it is reported ineligible.
- **E1.2** When Council Keleres is loaded without one ordinary home-system tile: it is reported ineligible.
- **E1.3** When Crimson Rebellion is loaded with a home tile containing no planets: it is reported ineligible.
- **E1.4** When Ghoti Wayfarers is loaded with a home tile containing no planets: it is reported ineligible.
- **E1.5** When a faction record omits eligibility metadata or supplies a non-boolean value: faction data validation fails rather than assigning an implicit default.

### Failure Modes

- **F1.1** When a faction is marked eligible but its home-system reference is missing, non-placeable, or has no planets: faction data validation fails with the invalid record identifiable.

### Acceptance Criteria

- [ ] **AC1.1** When the complete supported faction catalog is validated: every record has an intentional boolean eligibility value, satisfying R4.
- [ ] **AC1.2** When the four documented exceptional factions are queried: Ghosts of Creuss, Council Keleres, Crimson Rebellion, and Ghoti Wayfarers are all ineligible, satisfying R4.
- [ ] **AC1.3** When Firmament/Obsidian is queried: it is eligible and supplies tile `96a`, satisfying R4.

## AU-2: Persist and validate the mode setting

### Behaviors Under Test

- **B2.1** When Minor Factions is enabled and settings are serialized then restored: the enabled value survives the round trip.
- **B2.2** When Minor Factions is disabled and settings are serialized then restored: the disabled value survives the round trip.
- **B2.3** When settings JSON from an older draft omits the mode field: the restored setting is disabled.

### Edge Cases

- **E2.1** When a six-player Minor Factions draft requests exactly twelve factions: numeric settings validation accepts the lower boundary.
- **E2.2** When a six-player Minor Factions draft requests eleven factions: settings validation rejects the request with the Minor Factions-specific insufficiency error.
- **E2.3** When Minor Factions is disabled: faction-count validation retains the pre-feature lower bound and does not require twice the player count.

### Failure Modes

- **F2.1** When an enabled request violates the numeric lower bound: validation fails before draft generation or persistence and reports an actionable Minor Factions error.

### Acceptance Criteria

- [ ] **AC2.1** When a creator enables the variant: saved and API-facing settings report Minor Factions enabled, satisfying R1 and R9.
- [ ] **AC2.2** When saved settings do not contain the new field: the draft loads successfully with Minor Factions disabled, satisfying R10.
- [ ] **AC2.3** When `numberOfFactions` is less than twice the player count under the enabled mode: draft settings are rejected before play, satisfying R8.

## AU-3: Add creation and configuration controls

### Behaviors Under Test

- **B3.1** When the generation form submits the enabled mode value: the request handler constructs settings with Minor Factions enabled.
- **B3.2** When the generation form omits the mode value: the request handler constructs settings with Minor Factions disabled.
- **B3.3** When the creator changes player count while the mode is enabled: the displayed faction-option minimum updates to twice the player count.
- **B3.4** When the creation template is rendered: the mode toggle, official-rule explanation, reserve requirement, and playable-but-ineligible guidance are present.

### Edge Cases

- **E3.1** When the mode is toggled off after the player count changes: browser constraints return to the ordinary faction minimum.
- **E3.2** When playable but minor-ineligible factions are enabled: the form guidance states that they do not count toward the eligible reserve.

### Failure Modes

- **F3.1** When an invalid enabled request bypasses browser constraints and reaches the server: the server returns HTTP 400 with the Minor Factions-specific validation error and creates no draft.

### Acceptance Criteria

- [ ] **AC3.1** When a creator checks the mode control and submits a valid form: the created draft has Minor Factions enabled, satisfying R1.
- [ ] **AC3.2** When a creator leaves the control unchecked: the created draft has Minor Factions disabled, satisfying R1 and R10.
- [ ] **AC3.3** When an enabled form has `N` players: its client-visible minimum is `2N` faction options and the server independently enforces the same numeric boundary, satisfying R8.

## AU-4: Guarantee an eligible reserve in the persisted faction pool

### Behaviors Under Test

- **B4.1** When a six-player Minor Factions pool is generated from sufficient enabled sources: the persisted pool contains at least twelve eligible factions.
- **B4.2** When valid custom factions are pinned: they remain in the final persisted pool while generation fills enough other slots to satisfy the eligible reserve.
- **B4.3** When the same settings and seed generate the pool repeatedly: faction membership, order, and eligible reserve are identical.
- **B4.4** When Minor Factions is disabled: custom selection, pool membership, order, and seeded behavior match the existing mode-disabled contract.

### Edge Cases

- **E4.1** When a custom pinned faction is minor-ineligible and enough slots and eligible sources remain: the pinned faction remains playable and does not count toward the `2 × player count` eligible reserve.
- **E4.2** When the pool size is exactly the minimum and contains custom choices: generation succeeds only if the final pool still contains `2 × player count` eligible factions.
- **E4.3** When an eligible faction exists globally but is outside the enabled faction sources: it is not added to satisfy the reserve.

### Failure Modes

- **F4.1** When pinned choices leave too few slots for the eligible reserve: generation fails before the draft is saved with the Minor Factions-specific pool-content error.
- **F4.2** When the enabled sources contain too few eligible factions: generation fails before persistence and does not supplement the pool from disabled sources.

### Acceptance Criteria

- [ ] **AC4.1** When a valid mode-enabled pool is persisted for `N` players: it contains at least `2N` eligible factions drawn only from enabled sources and valid custom choices, satisfying R2, R4, and R8.
- [ ] **AC4.2** When generation cannot build the required reserve: no draft is persisted, satisfying R8.
- [ ] **AC4.3** When identical settings and seed are reused: the exact persisted faction order is stable, satisfying R7.

## AU-5: Reserve the equidistant blue slot

### Behaviors Under Test

- **B5.1** When a generated slice is created with Minor Factions enabled: tile index `4` contains a blue system reserved for replacement.
- **B5.2** When effective slice tiles and values are requested in the enabled mode: the reserved index `4` blue system is excluded.
- **B5.3** When effective slice constraints are evaluated in the enabled mode: resources, influence, total, wormholes, and legendary planets on reserved index `4` do not satisfy retained-slice requirements.
- **B5.4** When the same settings and seed regenerate slices: slice IDs, stored five-tile order, and reserved index are identical.
- **B5.5** When Minor Factions is disabled: stored tiles, effective tiles, totals, constraints, and arrangement behavior match the existing slice contract.

### Edge Cases

- **E5.1** When a mode-enabled custom slice contains a blue tile at index `4`: the custom slice is accepted and that tile is treated as reserved.
- **E5.2** When historical saved slices are loaded: each retains its five stored tile IDs without migration or mutation.
- **E5.3** When slices are generated for any supported player count from three through eight: index `4` is consistently the reserved equidistant slot.

### Failure Modes

- **F5.1** When a mode-enabled custom slice has a red or nonstandard tile at index `4`: validation rejects it with a specific reserved-slot error before draft persistence.

### Acceptance Criteria

- [ ] **AC5.1** When a Minor Factions slice is evaluated: exactly one blue system at index `4` is reserved and omitted from effective totals, satisfying R6.
- [ ] **AC5.2** When the mode is disabled or an old draft is loaded: no tile is reserved or removed from existing slice behavior, satisfying R10.
- [ ] **AC5.3** When generated or valid custom slices are persisted: their saved representation remains five tile IDs, satisfying R10.

## AU-6: Derive deterministic minor assignments

### Behaviors Under Test

- **B6.1** When all players have faction and speaker-position picks: exactly one eligible leftover faction is assigned to every speaker position.
- **B6.2** When assignments are derived: candidates are ordered by their existing position in the persisted faction pool and paired to players ordered by numeric speaker position.
- **B6.3** When the draft is serialized and restored without changing picks or the pool: derived assignments are identical.

### Edge Cases

- **E6.1** When an eligible faction in the persisted pool was selected by a player: it is excluded from minor assignments.
- **E6.2** When an eligible faction exists in enabled editions but is absent from the persisted pool: it is excluded from minor assignments.
- **E6.3** When an ineligible faction remains unselected in the persisted pool: it is skipped.
- **E6.4** When more eligible leftovers exist than players: only the first `player count` candidates in persisted pool order are assigned.
- **E6.5** When any player lacks either a faction pick or a speaker-position pick: the assignment list is empty.
- **E6.6** When a completed draft has a faction or position pick undone and later remade identically: assignments disappear while incomplete and return identically after recompletion.

### Failure Modes

- **F6.1** When a malformed mode-enabled draft has fewer eligible leftovers than players: public derivation returns status `invalid`, code `insufficient_eligible_candidates`, and no partial assignments without throwing through save or request paths.

### Acceptance Criteria

- [ ] **AC6.1** When assignments resolve: every assigned faction is eligible, was available in the exact persisted draft pool, and was not selected by a player, satisfying R2, R3, and R4.
- [ ] **AC6.2** When assignments resolve for `N` players: the payload contains `N` records with unique speaker positions, faction names, and home-system tile IDs, satisfying R5 and R9.
- [ ] **AC6.3** When reload, undo, or identical recompletion occurs: assignment output remains deterministic and never becomes stale, satisfying R7.
- [ ] **AC6.4** When picks are incomplete: no assignment is exposed, satisfying the US-2 unresolved-state contract.
- [ ] **AC6.5** When a draft is persisted: saved JSON contains the source mode setting but omits derived status and assignment snapshots, while public JSON exposes them, satisfying R9.

## AU-7: Enforce faction-pick membership

### Behaviors Under Test

- **B7.1** When the active player submits a faction present in the persisted faction pool during a faction pick: the pick succeeds through the existing command and request paths.
- **B7.2** When the active player submits a faction absent from the persisted faction pool: the pick is rejected.
- **B7.3** When a valid slice or position pick is submitted: the new faction-membership rule does not change its existing behavior.

### Edge Cases

- **E7.1** When a faction exists in the global catalog or an enabled edition but is absent from this draft's persisted pool: it is treated as unavailable and rejected.
- **E7.2** When an out-of-pool faction pick is rejected: player records, the draft log, current turn, and remaining options are unchanged.
- **E7.3** When a faction name differs from an in-pool option by case, spacing, or another unsupported representation: it is rejected under the existing exact option identity contract.

### Failure Modes

- **F7.1** When the request handler receives an out-of-pool faction: it returns the existing invalid-pick HTTP status and response shape with a precise unavailable-faction message.

### Acceptance Criteria

- [ ] **AC7.1** When a faction option belongs to the persisted pool: the server accepts it subject to all existing turn and category validation, satisfying R2.
- [ ] **AC7.2** When a faction option does not belong to the persisted pool: the server rejects it without state mutation, satisfying R11.
- [ ] **AC7.3** When non-faction categories are drafted: their accepted and rejected cases remain unchanged, satisfying R10.

## AU-8: Present assignments and replace map tiles

### Behaviors Under Test

- **B8.1** When a completed Minor Factions draft for any supported three-to-eight-player map is rendered: each speaker position's slice index `4` displays its assigned minor home-system tile.
- **B8.2** When full-map and individual-slice views render the same completed draft: both use the same assigned faction name and home-system tile ID at every equidistant slot.
- **B8.3** When tile-gather output is generated for a completed draft: assigned home-system IDs are included and the reserved blue tile IDs are excluded.
- **B8.4** When a TTS string is generated for a completed draft: assigned home-system IDs occupy the equidistant positions and reserved blue tile IDs are absent.
- **B8.5** When the draft page displays an enabled completed draft: it shows the server-derived assignment records and setup guidance without deriving candidates in the browser.
- **B8.6** When the draft configuration view displays an enabled draft: it identifies Minor Factions as enabled and explains the eligible reserve requirement.
- **B8.7** When the shipped map script runs against resolved and pending fixtures: its real lookup path invokes the shared helper for both full-map and individual-slice output.
- **B8.8** When the shipped draft script processes final-pick, undo, and polling readiness transitions: its real update path invalidates the existing map cache before regeneration.

### Edge Cases

- **E8.1** When assignments are unresolved: every reserved slot displays a neutral Minor Faction placeholder, each TTS reserved coordinate emits `0`, token count and ordering are preserved, and no prior faction label or home-system ID is shown.
- **E8.2** When Minor Factions is disabled or an old draft is loaded: map HTML, individual slices, tile gather, and TTS output use the existing unsubstituted path.
- **E8.3** When a final pick, undo, or remote polling update changes assignment readiness: cached map and export output is invalidated and regenerated from the current server payload.
- **E8.4** When the server reports an invalid resolution: the page shows the server error and all reserved coordinates remain `0` without partial substitution.
- **E8.5** When resolved, pending, invalid, and disabled draft fixtures are rendered through the view handler: assignment tables, placeholders, errors, or standard unchanged markup appear as appropriate.

### Failure Modes

- **F8.1** When a mode-enabled unresolved draft is exported: the reserved blue tile is omitted and no stale or fabricated home-system assignment is emitted.
- **F8.2** When an assignment payload is inconsistent with a rendered position: presentation fails safely without substituting a different faction or recomputing an assignment client-side.

### Acceptance Criteria

- [ ] **AC8.1** When a completed mode-enabled draft is viewed or exported: every surface substitutes the same assigned home-system ID at slice index `4`, satisfying R5 and R9.
- [ ] **AC8.2** When assignments have not resolved: placeholders communicate the reserved locations and no stale assignments appear, satisfying R9.
- [ ] **AC8.3** When the mode is disabled or absent from saved data: every map and export surface matches pre-feature behavior, satisfying R10.
- [ ] **AC8.4** When full-map, individual-slice, tile-gather, and TTS outputs are compared: each excludes the discarded blue systems and includes exactly one assigned minor home system per player, satisfying R6 and R9.

## Cross-AU Integration Contracts

### Creation through persisted eligible pool

- **Precondition**: AU-1, AU-2, AU-3, and AU-4 are complete.
- **Behavior**: When a creator enables Minor Factions and submits valid settings for `N` players: the request creates and persists a draft whose mode flag is enabled and whose exact faction pool contains at least `2N` eligible factions.
- **Expected outcome**: The saved and API-visible draft reflects the enabled setting, preserves custom playable choices, and draws reserve factions only from enabled sources.
- **Failure mode**: When numeric settings, pinned choices, or enabled sources cannot support the eligible reserve: creation returns the defined validation error and persists no draft.

### Available-but-unselected assignment invariant

- **Precondition**: AU-1, AU-4, AU-6, and AU-7 are complete.
- **Behavior**: When players complete faction and speaker-position picks: assignments are filtered from the exact persisted pool by removing selected and ineligible factions, then paired in pool order to speaker order.
- **Expected outcome**: Every minor faction was available for that draft, remained unselected, is eligible, and appears exactly once among assignments.
- **Failure mode**: When a crafted out-of-pool faction pick is submitted: the pick is rejected without mutation and cannot influence candidate selection.

### Slice reservation through resolved exports

- **Precondition**: AU-5, AU-6, and AU-8 are complete.
- **Behavior**: When a completed mode-enabled draft is rendered and exported: each slice's reserved index `4` is replaced by the home-system ID assigned to that slice's speaker position.
- **Expected outcome**: Full maps, individual slices, tile lists, and TTS strings contain the same `N` minor home systems and omit the `N` discarded blue systems.
- **Failure mode**: When assignments are incomplete: placeholders are rendered, stale assignments are cleared, and exports do not reintroduce reserved blue systems.

### Undo, reload, and recompletion stability

- **Precondition**: AU-2, AU-4, AU-6, and AU-8 are complete.
- **Behavior**: When a completed draft is reloaded, undone to an incomplete state, and then recompleted with the same choices: server assignments and all rendered/exported substitutions track the current state.
- **Expected outcome**: Reload preserves identical assignments, undo removes them and invalidates outputs, and identical recompletion restores identical assignments and outputs.
- **Failure mode**: When polling observes a readiness transition: no cached map, label, tile list, or TTS string retains the previous readiness state.

### Mode-disabled and historical compatibility

- **Precondition**: AU-2, AU-4, AU-5, AU-6, AU-7, and AU-8 are complete.
- **Behavior**: When a mode-disabled draft or saved draft without the mode field is generated, loaded, played, rendered, and exported: it follows the existing standard-draft paths.
- **Expected outcome**: Existing seeded pools, slice totals, five-tile storage, pick flow, payload safety, map HTML, and exports remain unchanged.
- **Failure mode**: When historical JSON omits all new fields: loading defaults the mode to disabled without requiring migration or producing assignment errors.
