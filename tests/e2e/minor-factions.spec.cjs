const {test, expect} = require('@playwright/test');

async function pickFirstVisible(page, category) {
    const button = page.locator(`button.draft[data-category="${category}"]:visible`).first();
    await expect(button).toBeVisible();
    await button.click();

    const response = page.waitForResponse((candidate) => (
        candidate.url().includes('/api/pick') && candidate.request().method() === 'POST'
    ));
    await page.locator('#confirm').click();
    expect((await response).ok()).toBe(true);
}

async function publicSlices(page) {
    return page.evaluate(() => window.draft.slices.map((slice) => ({
        tiles: slice.tiles,
        minor_faction: slice.minor_faction,
        equidistant: slice.equidistant,
        total_resources: slice.total_resources,
        total_influence: slice.total_influence,
        optimal_resources: slice.optimal_resources,
        optimal_influence: slice.optimal_influence,
        specialties: slice.specialties,
        wormholes: slice.wormholes,
        legendaries: slice.legendaries,
    })));
}

test('face-up Minor Factions remain attached to slices through picks, maps, reload, and undo', async ({page}) => {
    await page.goto('/');

    await page.locator('#num_players').fill('3');
    const names = ['Alice', 'Bob', 'Carol'];
    const playerNames = page.getByRole('textbox', {name: 'Player Name'});
    for (let i = 0; i < names.length; i++) {
        await playerNames.nth(i).fill(names[i]);
    }

    await page.locator('#minor_factions_toggle').check();
    await page.locator('#num_slices').fill('4');
    await page.locator('#num_factions').fill('3');
    await page.getByRole('textbox', {name: 'Game Name'}).fill(`Minor Factions E2E ${Date.now()}`);
    await page.getByRole('link', {name: 'Show', exact: true}).click();
    await expect(page.getByRole('spinbutton', {name: 'Minimum Optimal Influence'})).toHaveValue('4');
    await expect(page.getByRole('spinbutton', {name: 'Minimum Optimal Resources'})).toHaveValue('2.5');
    await expect(page.getByRole('spinbutton', {name: 'Minimum Optimal Total'})).toHaveValue('9');
    await expect(page.getByRole('spinbutton', {name: 'Maximum Optimal Total'})).toHaveValue('13');

    await page.getByRole('button', {name: 'Generate'}).click();
    await expect(page).toHaveURL(/\/d\/[^?]+\?fresh=1$/);
    await page.locator('#session-popup .close-popup').click();

    const initialSlices = await publicSlices(page);
    expect(initialSlices).toHaveLength(4);
    expect(new Set(initialSlices.map((slice) => slice.minor_faction.name)).size).toBe(4);
    for (const slice of initialSlices) {
        expect(Object.keys(slice.minor_faction).sort()).toEqual(['name', 'render_token', 'tile_id']);
        expect(slice.tiles[slice.equidistant.index]).toBe(slice.minor_faction.tile_id);
        expect(slice.equidistant).toEqual({index: 3, q: -1, r: 0});
    }

    await expect(page.locator('#minor-factions')).toHaveAttribute('data-status', 'assigned');
    await expect(page.locator('.minor-factions-assignments tbody tr')).toHaveCount(4);
    await expect(page.locator('.slice.option .minor-faction-name')).toHaveCount(4);
    await expect(page.locator('.slice.option .slice-graph .wrap > img.tile-3.minor-faction-home:not(.zoom)')).toHaveCount(4);
    await expect(page.locator('.minor-faction-placeholder')).toHaveCount(0);

    const cards = page.locator('.slice.option');
    for (let index = 0; index < initialSlices.length; index++) {
        const slice = initialSlices[index];
        const card = cards.nth(index);
        await expect(card.locator('.minor-faction-name')).toContainText(slice.minor_faction.name);
        await expect(card.locator('.resources').first()).toHaveText(String(slice.total_resources));
        await expect(card.locator('.influence').first()).toHaveText(String(slice.total_influence));
        await expect(card.locator('.tech-specialty')).toHaveCount(slice.specialties.length);
        await expect(card.locator('.wormhole')).toHaveCount(slice.wormholes.length);
        await expect(card.locator('.legendary')).toHaveCount(slice.legendaries.length);
    }

    for (const category of ['slice', 'faction', 'position']) {
        for (let i = 0; i < names.length; i++) {
            await pickFirstVisible(page, category);
        }
    }

    expect(await publicSlices(page)).toEqual(initialSlices);
    const selectedByPosition = await page.evaluate(() => Object.values(window.draft.draft.players)
        .sort((left, right) => Number(left.position) - Number(right.position))
        .map((player) => window.draft.slices[Number(player.slice)].minor_faction));

    await page.getByRole('link', {name: 'Map', exact: true}).click();
    const gathered = (await page.locator('#tile-gather').innerText()).split(/,\s*/);
    const tts = (await page.locator('#tts-string').innerText()).trim().split(/\s+/);
    for (const minor of selectedByPosition) {
        expect(gathered).toContain(minor.render_token);
        expect(tts).toContain(minor.render_token);
        await expect(page.locator('#mapslices-wrap')).toContainText(minor.name);
    }

    await page.reload();
    expect(await publicSlices(page)).toEqual(initialSlices);
    await expect(page.locator('.slice.option .minor-faction-name')).toHaveCount(4);

    await page.getByRole('link', {name: 'Log', exact: true}).click();
    const undoResponse = page.waitForResponse((candidate) => (
        candidate.url().includes('/api/undo') && candidate.request().method() === 'POST'
    ));
    await page.getByRole('button', {name: 'Undo last action'}).click();
    expect((await undoResponse).ok()).toBe(true);

    expect(await publicSlices(page)).toEqual(initialSlices);
    await page.getByRole('link', {name: 'Draft', exact: true}).click();
    await expect(page.locator('#minor-factions')).toHaveAttribute('data-status', 'assigned');
    await expect(page.locator('.slice.option .minor-faction-name')).toHaveCount(4);
    await expect(page.locator('.minor-faction-placeholder')).toHaveCount(0);
});
