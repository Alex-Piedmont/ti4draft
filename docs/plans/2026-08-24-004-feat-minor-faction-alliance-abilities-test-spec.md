---
date: 2026-08-24
plan: 004
source: docs/plans/2026-08-24-004-feat-minor-faction-alliance-abilities-plan.md
depth: Standard
---

# Test Specification: Minor Faction Alliance Abilities

## Test Infrastructure

- Framework: PHPUnit 11.5-compatible configuration for PHP domain, data-integrity, and request-rendering tests; Playwright 1.62 for browser-level acceptance tests.
- Test directories: `app/` and `data/` are discovered recursively by the PHPUnit `core` and `data` suites; browser tests live in `tests/e2e/`.
- Conventions: PHP tests use colocated `*Test.php` files and PHPUnit attributes; catalog contracts live in `data/FactionDataTest.php`; rendered HTML is exercised through request-handler tests; browser journeys use `*.spec.cjs` and the configured `E2E_BASE_URL`.

## AU-1: Add validated Alliance ability metadata to factions

### Behaviors Under Test

- **B1.1** When a faction record supplies valid Alliance ability text: `Faction::fromJson()` exposes the exact stored wording and punctuation as immutable faction metadata.
- **B1.2** When the production faction catalog is loaded: all 64 records expose a non-empty Alliance ability, and the complete eligible name-to-ability map exactly equals the reconciliation fixture extracted from pinned TI4 Reference revision `0c2e2b66e8ccfb38c3cc7f1fc1f5f2e82a53ecb7`.
- **B1.3** When a saved Minor Faction containing only faction name and home-system tile ID is restored: the faction resolves its current Alliance ability from the catalog without requiring stored ability text.
- **B1.4** When a Minor Faction is serialized: saved draft state has exactly `name` and `tile_id`, while the public payload has exactly `name`, `tile_id`, and `render_token`; Alliance ability text is added to neither.

### Edge Cases

- **E1.1** When `The Firmament / The Obsidian` is loaded: with home-system tile `96a` it exposes exactly “You may treat planets in systems that contain your ships as if you controlled them for the purpose of scoring secret objectives.”, not the Obsidian ability or a combined value.
- **E1.2** When valid ability text contains apostrophes, quotation marks, colons, or HTML-significant characters: hydration preserves those characters exactly rather than normalizing or truncating the text.
- **E1.3** When eligible catalog identities are compared with the pinned reconciliation fixture: both identity sets and every identity-keyed ability value match exactly, independent of JSON array position.
- **E1.4** When an ineligible production faction record is hydrated: the same required metadata contract applies and existing faction identity, edition, eligibility, and home-system behavior remain unchanged.

### Failure Modes

- **F1.1** When Alliance ability metadata is missing: faction hydration fails with an error that identifies the required Alliance ability field instead of constructing incomplete metadata.
- **F1.2** When Alliance ability metadata is not a string: faction hydration fails before the faction can enter draft generation or rendering.
- **F1.3** When Alliance ability metadata is empty or whitespace-only: faction hydration fails rather than allowing a blank Alliance ability cell.

### Acceptance Criteria

- [ ] **AC1.1** When the complete production catalog is validated: all 64 records have valid ability metadata and the entire eligible map exactly matches the pinned, identity-keyed source fixture, satisfying R2.
- [ ] **AC1.2** When a valid faction JSON record is hydrated: the resulting faction exposes the source text unchanged, satisfying R1 and R2.
- [ ] **AC1.3** When missing, non-string, empty, and whitespace-only ability values are each supplied: every malformed record is rejected before use, satisfying R2.
- [ ] **AC1.4** When the combined Firmament/Obsidian record is inspected: it exposes the exact Firmament text specified by E1.1 and retains home-system tile `96a`, satisfying R2 and R5.
- [ ] **AC1.5** When an existing two-key draft assignment is loaded and serialized again: persisted output remains exactly `name` and `tile_id`, public output remains exactly `name`, `tile_id`, and `render_token`, and neither gains an ability field, satisfying R3 and R5.

## AU-2: Render and verify the Alliance ability table

### Behaviors Under Test

- **B2.1** When a draft with face-up Minor Factions is rendered: the table headers are exactly `Slice`, `Faction`, and `Alliance ability`, and the former `Home system` header is absent.
- **B2.2** When multiple slices have different assigned Minor Factions: each table row displays the Alliance ability belonging to the faction named in that same row.
- **B2.3** When Alliance ability text contains HTML-significant characters: UTF-8-safe `htmlspecialchars` escaping with quote and invalid-sequence substitution displays those characters as text and does not create executable or structural markup.
- **B2.4** When the Minor Factions panel is rendered: adjacent help copy credits TI4 Reference and Scott MK, identifies CC BY 4.0, discloses reformatting, and links to both the designated Alliance reference and license.
- **B2.5** When the table replaces its numeric home-system cells: each slice card and map still renders the assigned Minor Faction's home-system artwork, faction name, and existing slice totals.

### Edge Cases

- **E2.1** After real row content is verified, when Playwright deterministically replaces one ability cell's `textContent` with the catalog's exact longest ability and sets a 390 × 844 viewport: the full text is visible and document scroll width does not exceed viewport width. Random Minor Faction selection is not used to establish this precondition.
- **E2.2** When Minor Factions mode is disabled: the Minor Factions table and its attribution are absent and the ordinary draft presentation remains unchanged.
- **E2.3** When Discordant Stars and official-set Minor Factions appear together: each row uses its own faction's ability while tile assets continue using the edition-appropriate render token.

### Failure Modes

- **F2.1** When a catalog record cannot provide valid Alliance ability metadata: draft rendering fails closed through catalog validation rather than presenting a blank or mismatched table cell.
- **F2.2** When the TI4 Reference site or license page is unavailable at render time: the locally stored abilities and draft page still render because no runtime request is made to the third-party service.

### Acceptance Criteria

- [ ] **AC2.1** When a Minor Factions draft page opens before the first pick: every slice has one row containing its faction name and correct Alliance ability under the renamed header, satisfying R1.
- [ ] **AC2.2** When rendered HTML is inspected for a value containing HTML-significant characters: the value is escaped and no injected element or script is created, satisfying R4.
- [ ] **AC2.3** When the panel's help copy is inspected: it credits TI4 Reference by Scott MK, identifies CC BY 4.0 and reformatting, and contains the correct reference and license destinations, satisfying R4.
- [ ] **AC2.4** When a Minor Factions draft is picked, mapped, reloaded, and undone: faction-to-slice assignments, home-system artwork, slice totals, and public payload keys remain unchanged, satisfying R3 and R5.
- [ ] **AC2.5** When a supported long-form ability is viewed at 390 × 844: its full text remains readable and document scroll width is no wider than the viewport, satisfying R1.
- [ ] **AC2.6** When Minor Factions mode is disabled: no Minor Factions ability table or attribution is rendered, satisfying R5.

## Cross-AU Integration Contracts

### Existing draft derives newly added display metadata

- **Precondition**: AU-1 and AU-2 are complete; a draft persisted before this feature contains a valid Minor Faction name and home-system tile ID but no ability text.
- **Behavior**: Load the existing draft through the normal draft-page request and inspect its Minor Factions table and serialized/public state.
- **Expected outcome**: The page displays the catalog ability for every persisted faction; saved objects remain exactly two-key and public objects exactly three-key.
- **Failure mode**: The draft requires migration, loses its faction-to-slice pairing, exposes a new payload field, or renders a missing or incorrect ability.

### Catalog identity reaches the correct rendered row

- **Precondition**: AU-1 and AU-2 are complete; a draft contains multiple Minor Factions from different supported editions.
- **Behavior**: Compare each rendered table row with the assigned faction and its hydrated catalog metadata.
- **Expected outcome**: Every row pairs one slice, its assigned faction, and that faction's exact Alliance ability regardless of catalog order or edition.
- **Failure mode**: Any ability is assigned positionally, appears beside a different faction, or is altered between hydration and escaped rendering.

### Minor Faction lifecycle remains compatible

- **Precondition**: AU-1 and AU-2 are complete; a newly generated Minor Factions draft is available through the browser flow.
- **Behavior**: Verify the initial table, complete picks, inspect map/export output, reload the draft, and undo the latest action.
- **Expected outcome**: Alliance abilities remain visible and stable while assignment identity, tile placement, map/export render tokens, and the exact `name`, `tile_id`, and `render_token` public keys remain unchanged.
- **Failure mode**: The display enhancement changes persisted assignments, public schema, tile placement, map/export output, reload behavior, or undo behavior.
