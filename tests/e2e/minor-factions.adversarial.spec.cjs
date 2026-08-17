const {test, expect} = require('@playwright/test');

async function generateMinorDraft(page, playerCount, sliceCount = null) {
    await page.goto('/');
    await page.locator('#num_players').fill(String(playerCount));
    const playerNames = page.getByRole('textbox', {name: 'Player Name'});
    for (let index = 0; index < playerCount; index++) {
        await playerNames.nth(index).fill(`Player ${playerCount}-${index}`);
    }
    await page.locator('#minor_factions_toggle').check();
    if (sliceCount !== null) await page.locator('#num_slices').fill(String(sliceCount));
    await page.locator('#num_factions').fill(String(playerCount));
    await page.getByRole('textbox', {name: 'Game Name'}).fill(`Minor Map ${playerCount}p ${Date.now()}`);
    await page.getByRole('button', {name: 'Generate'}).click();
    await expect(page).toHaveURL(/\/d\/[^?]+\?fresh=1$/);
    await page.locator('#session-popup .close-popup').click();
}

test('individual map view shows every face-up generated slice before picks', async ({page}) => {
    await generateMinorDraft(page, 3, 4);

    const assignments = await page.evaluate(() => window.draft.slices.map((slice) => slice.minor_faction));
    expect(assignments).toHaveLength(4);

    await page.getByRole('link', {name: 'Map', exact: true}).click();
    const individualMaps = page.locator('#mapslices-wrap .slice');
    await expect(individualMaps).toHaveCount(4);
    for (const assignment of assignments) {
        await expect(page.locator('#mapslices-wrap')).toContainText(assignment.name);
        const assetToken = assignment.render_token.startsWith('DS')
            ? assignment.render_token
            : `ST_${assignment.render_token}`;
        await expect(page.locator(`#mapslices-wrap img[src$="/${assetToken}.png"]`).first()).toBeAttached();
    }
});

test('fresh browser generation succeeds for every supported player count', async ({page}) => {
    for (let playerCount = 3; playerCount <= 8; playerCount++) {
        await generateMinorDraft(page, playerCount);
        const result = await page.evaluate(() => ({
            playerCount: window.draft.config.players.length,
            slices: window.draft.slices.map((slice) => ({
                minor: slice.minor_faction,
                equidistant: slice.equidistant,
                tile: slice.tiles[3],
            })),
        }));
        expect(result.playerCount).toBe(playerCount);
        expect(result.slices.length).toBeGreaterThanOrEqual(playerCount);
        for (const slice of result.slices) {
            expect(slice.minor).toBeTruthy();
            expect(slice.equidistant).toEqual({index: 3, q: -1, r: 0});
            expect(slice.tile).toBe(slice.minor.tile_id);
        }
        await expect(page.locator('.slice.option .minor-faction-name')).toHaveCount(result.slices.length);
    }
});

test('regeneration reloads the browser with a new authoritative split and clears map cache', async ({page}) => {
    await generateMinorDraft(page, 3, 4);
    const before = await page.evaluate(() => window.draft.slices.map((slice) => slice.minor_faction.name));
    await page.evaluate(() => { window.map_cached = true; });

    await page.getByRole('link', {name: 'Regenerate', exact: true}).click();
    const response = page.waitForResponse((candidate) => (
        candidate.url().includes('/api/regenerate') && candidate.request().method() === 'POST'
    ));
    const navigation = page.waitForNavigation();
    await page.getByRole('button', {name: 'Regenerate'}).click();
    expect((await response).ok()).toBe(true);
    await navigation;

    const after = await page.evaluate(() => ({
        assignments: window.draft.slices.map((slice) => slice.minor_faction.name),
        mapCached: window.map_cached,
    }));
    expect(after.assignments).not.toEqual(before);
    expect(after.mapCached).toBe(false);
});

test('malformed enabled slice metadata aborts browser map output without a placeholder', async ({page}) => {
    await generateMinorDraft(page, 3, 4);

    const result = await page.evaluate(() => {
        document.querySelector('#map-wrap').innerHTML = '';
        document.querySelector('#mapslices-wrap').innerHTML = '';
        document.querySelector('#tile-gather').innerHTML = '';
        document.querySelector('#tts-string').innerHTML = '';
        window.draft.slices[0].equidistant.index = 99;
        window.map_cached = false;
        try {
            window.generate_map();
            return {threw: false};
        } catch (error) {
            return {
                threw: true,
                message: error.message,
                output: document.querySelector('#map-wrap').innerHTML
                    + document.querySelector('#mapslices-wrap').innerHTML
                    + document.querySelector('#tile-gather').innerHTML
                    + document.querySelector('#tts-string').innerHTML,
            };
        }
    });

    expect(result.threw).toBe(true);
    expect(result.message).toContain('Invalid persisted Minor Faction slice data');
    expect(result.output).toBe('');
    expect(result.output).not.toContain('ST_0');
});
