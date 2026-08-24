---
date: 2026-08-24
plan: 004
type: feat
source: Direct request
depth: Standard
test-spec: docs/plans/2026-08-24-004-feat-minor-faction-alliance-abilities-test-spec.md
status: Approved
---

# Plan: Minor Faction Alliance Abilities

## Context Summary

The draft page currently renders a Minor Factions table with `Slice`, `Faction`, and `Home system` columns. Minor Faction assignments persist only the faction name and home-system tile identifier; when a draft is loaded, the faction object is rebuilt from the current static catalog in `data/factions.json`. Alliance ability text is therefore derived faction metadata: adding it to the faction catalog updates already-started drafts without a draft migration or a public API change.

The linked TI4 Reference publishes Alliance ability text for the base game, Prophecy of Kings, Thunder's Edge, and Discordant Stars factions represented by this fork. Revision `0c2e2b66e8ccfb38c3cc7f1fc1f5f2e82a53ecb7` shall be the transcription source, and a complete identity-keyed reconciliation fixture shall make exact wording—not merely non-empty values—an automated contract. The application shall keep that text locally so draft rendering remains deterministic and does not depend on a third-party request. The catalog's combined `The Firmament / The Obsidian` entry uses Firmament-side home system `96a`; it shall consequently display the Firmament Alliance ability. The source is licensed CC BY 4.0, so the rendered help copy shall credit TI4 Reference and Scott MK, identify the license, and disclose that the text was reformatted for this table.

Existing learnings reinforce the boundary: persist randomized assignments as their canonical identity, derive static projections from that identity, fail closed when derived metadata is missing, and test the final rendered draft representation. Home-system tiles still drive map placement and Minor Faction validation even though their numeric identifiers will no longer appear in this table.

## Requirements Trace

| Requirement | Source | Atomic Unit(s) |
|-------------|--------|-----------------|
| R1: Replace the Minor Factions table's home-system number with the assigned faction's Alliance ability | Direct request | AU-1, AU-2 |
| R2: Every production faction has valid ability metadata, and every eligible Minor Faction exactly matches the pinned reference mapping | Direct request | AU-1 |
| R3: Existing drafts acquire the new display without migration or persisted/public payload changes | Direct request | AU-1, AU-2 |
| R4: Ability text is escaped safely and its CC BY source is attributed | TI4 Reference source and license | AU-2 |
| R5: Home-system tile placement, validation, picking, reload, and undo behavior remain unchanged | Existing Minor Factions behavior | AU-1, AU-2 |

## User Stories

### US-1: Read the ability enabled by a Minor Faction

```gherkin
Feature: Minor Faction Alliance ability reference
  As a player in an active draft
  I want each face-up Minor Faction to show its Alliance ability
  So that I can evaluate the benefit without looking up the faction elsewhere

  Scenario: Open a draft with Minor Factions enabled
    Given a draft has face-up Minor Factions assigned to slices
    When a player opens or reloads the draft page
    Then the Minor Factions table shows Slice, Faction, and Alliance ability
    And each row shows the reference ability for its assigned faction

  Scenario: Open an already-started draft after deployment
    Given the draft was persisted before Alliance ability metadata existed
    When a player opens the same draft URL after deployment
    Then the table derives the ability from the persisted faction identity
    And no draft migration is required
```

Acceptance: R1, R2, R3

### US-2: Preserve the Minor Factions rules and data contracts

```gherkin
Feature: Minor Faction compatibility
  As a draft organizer
  I want the table enhancement to leave draft behavior unchanged
  So that current drafts continue through picks, maps, reload, and undo

  Scenario: Continue a Minor Factions draft
    Given a Minor Faction home-system tile is attached to a slice
    When players pick, reload, or undo the draft
    Then the same faction and home-system tile remain attached to that slice
    And the public Minor Faction payload still contains only name, tile_id, and render_token

  Scenario: Render source text containing HTML-significant characters
    Given an Alliance ability contains characters significant in HTML
    When the draft table is rendered
    Then the ability is displayed as text rather than interpreted as markup
```

Acceptance: R3, R4, R5

## Scope Boundaries

### In Scope

- Store authoritative Alliance ability text for all 64 production faction records so the domain schema is uniform; exact reference reconciliation is release-gated for the eligible Minor Faction set.
- Hydrate and validate the ability as immutable faction metadata.
- Replace the table's `Home system` column with `Alliance ability` and render the corresponding text.
- Add a compact source and CC BY 4.0 attribution near the Minor Factions explanation.
- Update domain, catalog, request-rendering, and browser tests.
- Deploy through the existing GitHub-connected Railway service and verify the public draft experience.

### Out of Scope

- Changing Minor Faction eligibility, selection, tile placement, or game rules.
- Persisting ability text in draft JSON or exposing it through `/api/draft/{id}`.
- Fetching TI4 Reference at request time.
- Displaying Alliance promissory-note artwork or implementing Alliance exchanges.
- Adding a separate faction reference page or modal.
- Showing both Firmament and Obsidian abilities for the combined catalog entry.

## Atomic Units

### AU-1: Add validated Alliance ability metadata to factions
- [ ] **Goal:** Every eligible Minor Faction resolves to authoritative, non-empty Alliance ability text while saved-draft and API contracts remain stable.
**Requirements:** R1, R2, R3, R5
**Dependencies:** None
**Files:**
- `data/factions.json` -- Add locally stored Alliance ability text transcribed from TI4 Reference.
- `app/TwilightImperium/Faction.php` -- Hydrate the ability as immutable faction metadata and reject missing, non-string, or empty values.
- `data/FactionDataTest.php` -- Require valid metadata for all production records and exact agreement with the pinned eligible-faction mapping.
- `app/TwilightImperium/FactionTest.php` -- Cover successful hydration and malformed metadata failures.
- `app/Draft/MinorFactionTest.php` -- Prove a legacy two-key assignment rehydrates current metadata while persisted and public shapes remain exact.
- `app/Draft/SliceAdversarialTest.php` -- Supply explicit synthetic ability metadata to the direct `Faction` constructor fixture.
- `app/Draft/MinorFactionAdversarialTest.php` -- Supply explicit synthetic ability metadata to the direct `Faction` constructor fixture.
- `tests/fixtures/ti4-reference-alliance-abilities-0c2e2b66.json` -- Record the complete eligible name-to-ability reconciliation extracted from the pinned upstream revision, including the upstream source path for each identity.
**Approach:**
- Data ownership: keep ability text in the static faction catalog, keyed by the same faction identity already reconstructed when a persisted draft loads.
- Validation: trim only for the non-empty check; preserve the source wording and punctuation in the stored/displayed value.
- Constructor contract: make the immutable ability explicit and required, then update both direct synthetic `new Faction(...)` test fixtures so production callers cannot silently omit it.
- Catalog coverage: require `alliance_ability` on every production faction record; exact source-mapping acceptance applies to every Minor-Faction-eligible record.
- Source oracle: extract the complete mapping from TI4 Reference commit `0c2e2b66e8ccfb38c3cc7f1fc1f5f2e82a53ecb7` into the identity-keyed fixture, record each raw source path, and exact-compare the catalog's eligible name-to-ability map with that fixture in `FactionDataTest`.
- Source mapping: match records by canonical faction identity and require set equality so a value cannot pass through array position, omission, or assignment to the wrong faction.
- Combined entry: map `The Firmament / The Obsidian` to the Firmament Alliance ability because the catalog uses Firmament home system `96a`.
- Compatibility: keep persisted Minor Faction objects exactly `name` and `tile_id`; keep public Minor Faction objects exactly `name`, `tile_id`, and `render_token`; do not add the ability to either serializer.
**Test Scenarios:**
- When the production faction catalog loads: all 64 records have a non-empty string ability, and the complete eligible map exactly equals the pinned reconciliation fixture.
- When a faction JSON record supplies valid ability text: `Faction::fromJson()` exposes it unchanged.
- When ability metadata is missing, empty, or not a string: hydration fails closed with a clear validation error.
- When the full catalog is compared with the expected eligible identities: no Minor Faction is omitted or assigned an ability through array position.
- When an old draft containing only name and tile ID is loaded: the current catalog supplies the ability, its persisted shape remains exactly `name` and `tile_id`, and its public shape remains exactly `name`, `tile_id`, and `render_token`.
**Verification:**
- `docker compose exec -T app vendor/bin/phpunit data/FactionDataTest.php app/TwilightImperium/FactionTest.php app/Draft/MinorFactionTest.php app/Draft/SliceAdversarialTest.php app/Draft/MinorFactionAdversarialTest.php` -- exits 0
- `docker compose exec -T app composer phpstan` -- exits 0

### AU-2: Render and verify the Alliance ability table
- [ ] **Goal:** Players can read source-attributed Alliance abilities on the draft page while all existing Minor Faction interactions and home-system map behavior continue unchanged.
**Requirements:** R1, R3, R4, R5
**Dependencies:** AU-1
**Files:**
- `templates/draft.php` -- Rename the third column, render escaped Alliance ability text, and add source/license attribution.
- `app/Http/RequestHandlers/HandleViewDraftRequestTest.php` -- Verify headers, row-to-faction mapping, escaping, attribution, and retained home-system tile rendering.
- `tests/e2e/minor-factions.spec.cjs` -- Verify visible ability content and the unchanged public payload through picks, maps, reload, and undo.
- `tests/e2e/minor-factions.adversarial.spec.cjs` -- Extend boundary coverage if generated adversarial cases reveal a missing rendered-state contract.
- `css/style.scss` -- Adjust table wrapping only if browser verification shows long abilities overflow at supported widths.
**Approach:**
- Table contract: render exactly `Slice`, `Faction`, and `Alliance ability`; remove only the numeric home-system cell from this summary table.
- Output safety: pass all catalog ability text through `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` at the template boundary.
- Attribution: use copy equivalent to “Alliance ability text from TI4 Reference by Scott MK, licensed CC BY 4.0; reformatted for this table,” linking the reference and license adjacent to the table.
- Responsive layout: allow natural wrapping first; at a 390 × 844 viewport require document scroll width not to exceed viewport width, and change CSS only if that assertion demonstrates overflow.
- Deterministic layout fixture: after verifying real row-to-faction content, replace one rendered ability cell's `textContent` in the Playwright page with the catalog's exact longest ability solely for the 390 × 844 wrapping assertion; do not rely on random generation selecting that faction.
- Regression boundary: continue rendering the home-system tile image and faction title on each slice map; assert the saved and public Minor Faction schemas independently.
**Test Scenarios:**
- When a face-up Minor Faction draft is rendered: the table contains the Alliance ability header and no Home system header.
- When multiple slices have different Minor Factions: each row contains the ability belonging to that row's faction.
- When ability text includes HTML-significant characters: the response contains escaped output and no injected markup.
- When the draft page is rendered: attribution credits TI4 Reference by Scott MK, identifies CC BY 4.0 and reformatting, and links to both sources.
- When picks, map rendering, reload, and undo occur: faction assignment, home-system tile artwork, and public payload keys stay unchanged.
- When the longest ability is viewed at a 390 × 844 viewport: document scroll width does not exceed viewport width and the full cell text remains visible.
**Verification:**
- `docker compose exec -T app vendor/bin/phpunit app/Http/RequestHandlers/HandleViewDraftRequestTest.php` -- exits 0
- `docker compose exec -T app vendor/bin/phpunit` -- exits 0
- `docker compose exec -T app composer phpstan` -- exits 0
- `docker compose exec -T app composer cs:check` -- exits 0
- `E2E_BASE_URL=http://localhost:8080 npm run test:e2e` -- exits 0

## Release Acceptance

- Before deployment, record a currently persisted `/d/{id}` Minor Factions draft from Railway storage; after the GitHub-connected deployment succeeds, reopen that exact URL and verify its ability table and unchanged public payload.
- After the GitHub-connected Railway deployment succeeds, run `E2E_BASE_URL=https://ti4draft-production.up.railway.app npm run test:e2e` and require exit code 0.

## Dependency Graph

AU-1 -> AU-2

## Key Technical Decisions

- **Derive display metadata from persisted faction identity:** Existing drafts already rehydrate their faction from the catalog, so this updates active drafts without copying prose into saved state or requiring a migration.
- **Keep a local authoritative snapshot with a pinned oracle:** The app shall not depend on TI4 Reference availability or latency during draft rendering, while the complete reconciliation fixture and pinned commit make transcription correctness reviewable and testable.
- **Do not change the public payload:** The request concerns the server-rendered table, and the existing API shape is contract-tested by browser tests.
- **Use the Firmament ability for the combined entry:** Home system `96a` represents the Firmament side of `The Firmament / The Obsidian`; exposing both abilities would misstate what that Minor Faction enables.
- **Preserve map use of home-system IDs:** Removing the number from the summary table does not remove the tile identity needed to render and validate the draft.

## Open Questions

### Resolved During Planning

- Does an already-started draft require migration? No. It persists the faction name and tile ID, then resolves current faction metadata during load.
- Should ability text be fetched live from the linked site? No. Local catalog data provides deterministic rendering and allows validation before deployment.
- Which ability applies to `The Firmament / The Obsidian`? The Firmament ability, matching the catalog's `96a` home system.
- Does every faction or only eligible factions require the property? Every production record requires it for a uniform domain schema; the exact source-reconciliation gate covers the eligible Minor Faction set.
- Does the API need an `alliance_ability` field? No. The requested display is server-rendered; persisted objects remain exactly two-key and public objects exactly three-key.

### Deferred to Implementation

- Whether the existing table CSS needs a small wrapping adjustment after all long-form abilities are exercised in browser tests.

## Unchanged Invariants

- Minor Faction generation, eligibility, number of face-up factions, and slice assignment remain unchanged.
- Each Minor Faction keeps its persisted faction name and home-system tile identifier.
- Home-system tile artwork still replaces the equidistant system on the rendered slice map.
- Picking, claiming, reload, undo, and completed-draft behavior remain unchanged.
- Persisted draft JSON Minor Faction objects retain exactly `name` and `tile_id`.
- `/api/draft/{id}` Minor Faction objects retain exactly `name`, `tile_id`, and `render_token`.
- Drafts with Minor Factions disabled do not render the Minor Factions table.

## Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Ability text is transcribed or assigned incorrectly among roughly 60 eligible records | Med | High | Pin upstream commit `0c2e2b66`, commit a complete identity-keyed reconciliation fixture with raw source paths, and exact-compare the eligible catalog map |
| Upstream wording changes after the local snapshot is added | Low | Med | Attribute and record the source; future updates remain explicit catalog changes rather than runtime drift |
| Long ability text makes the table hard to read on narrow screens | Med | Med | Exercise browser viewports, rely on wrapping first, and add the smallest scoped CSS change only if required |
| The combined Firmament/Obsidian record creates ambiguous player expectations | Med | Med | Document and test the Firmament mapping that corresponds to home system `96a` |
| Catalog validation breaks non-Minor or historic fixtures unnecessarily | Low | Med | Require the uniform field on all production records, leave historic snapshot schemas unchanged, and update domain fixtures deliberately |

## Sources & References

- [TI4 Reference Alliances](https://scottmk.github.io/ti4-reference/alliances/): User-designated source for Alliance ability wording.
- [Pinned TI4 Reference revision](https://github.com/scottmk/ti4-reference/tree/0c2e2b66e8ccfb38c3cc7f1fc1f5f2e82a53ecb7): Exact repository state used for the complete reconciliation fixture.
- [TI4 Reference license](https://github.com/scottmk/ti4-reference/blob/main/LICENSE): CC BY 4.0 attribution requirement.
- `docs/solutions/best-practices/persist-randomized-allocations-that-affect-validation.md`: Keep the existing persisted faction/tile assignment canonical.
- `docs/solutions/best-practices/derive-projections-from-persisted-randomized-source.md`: Derive static display metadata from persisted identity rather than duplicate it.
- `docs/solutions/best-practices/fail-closed-derived-output-and-cache-invalidation.md`: Validate derived metadata before rendering.
- `docs/solutions/logic-errors/validate-defaults-against-final-representation.md`: Test the final draft-table representation, not only source data.
