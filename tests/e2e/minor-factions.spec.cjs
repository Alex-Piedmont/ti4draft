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

test('minor factions resolve across maps and return to placeholders after undo', async ({page}) => {
    await page.goto('/');

    await page.getByRole('spinbutton', {name: 'Number of players'}).fill('6');
    const names = ['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank'];
    const playerNames = page.getByRole('textbox', {name: 'Player Name'});
    for (let i = 0; i < names.length; i++) {
        await playerNames.nth(i).fill(names[i]);
    }

    await page.locator('#minor_factions_toggle').check();
    await page.locator('#num_factions').fill('12');
    await page.getByRole('textbox', {name: 'Game Name'}).fill(`Minor Factions E2E ${Date.now()}`);
    await page.getByRole('link', {name: 'Show', exact: true}).click();
    await expect(page.getByRole('spinbutton', {name: 'Minimum Optimal Influence'})).toHaveValue('2');
    await expect(page.getByRole('spinbutton', {name: 'Minimum Optimal Resources'})).toHaveValue('1');
    await expect(page.getByRole('spinbutton', {name: 'Minimum Optimal Total'})).toHaveValue('5');
    await expect(page.getByRole('spinbutton', {name: 'Maximum Optimal Total'})).toHaveValue('10');

    await page.getByRole('button', {name: 'Generate'}).click();
    await expect(page).toHaveURL(/\/d\/[^?]+\?fresh=1$/);
    await page.locator('#session-popup .close-popup').click();
    await expect(page.locator('.minor-faction-placeholder')).toHaveCount(14);
    await expect(page.locator('#minor-factions')).toHaveAttribute('data-status', 'pending');

    for (const category of ['slice', 'faction', 'position']) {
        for (let i = 0; i < 6; i++) {
            await pickFirstVisible(page, category);
        }
    }

    const rows = page.locator('.minor-factions-assignments tbody tr');
    await expect(rows).toHaveCount(6);
    await expect(page.locator('#minor-factions')).toHaveAttribute('data-status', 'resolved');

    const assignments = await rows.evaluateAll((items) => items.map((row) => {
        const cells = row.querySelectorAll('td');
        return {
            faction: cells[1].textContent.trim(),
            homeSystem: cells[2].textContent.trim(),
        };
    }));
    expect(new Set(assignments.map(({faction}) => faction)).size).toBe(6);
    expect(new Set(assignments.map(({homeSystem}) => homeSystem)).size).toBe(6);

    await page.getByRole('link', {name: 'Map', exact: true}).click();
    const resolvedTts = (await page.locator('#tts-string').innerText()).trim().split(/\s+/);
    const resolvedTiles = (await page.locator('#tile-gather').innerText()).split(/,\s*/);
    const reservedIndexes = [];
    for (const {faction, homeSystem} of assignments) {
        await expect(page.locator('#mapview-hyperlane')).toContainText(faction);
        expect(resolvedTiles).toContain(homeSystem);
        const index = resolvedTts.indexOf(homeSystem);
        expect(index).toBeGreaterThanOrEqual(0);
        reservedIndexes.push(index);
    }
    expect(new Set(reservedIndexes).size).toBe(6);

    await page.getByRole('link', {name: 'Log', exact: true}).click();
    const undoResponse = page.waitForResponse((candidate) => (
        candidate.url().includes('/api/undo') && candidate.request().method() === 'POST'
    ));
    await page.getByRole('button', {name: 'Undo last action'}).click();
    expect((await undoResponse).ok()).toBe(true);

    await page.getByRole('link', {name: 'Draft', exact: true}).click();
    await expect(page.locator('#minor-factions')).toHaveAttribute('data-status', 'pending');
    await expect(page.locator('.minor-factions-pending')).toBeVisible();

    await page.getByRole('link', {name: 'Map', exact: true}).click();
    const pendingTts = (await page.locator('#tts-string').innerText()).trim().split(/\s+/);
    const pendingTiles = (await page.locator('#tile-gather').innerText()).split(/,\s*/);
    for (let i = 0; i < reservedIndexes.length; i++) {
        expect(pendingTts[reservedIndexes[i]]).toBe('0');
        expect(pendingTiles).not.toContain(assignments[i].homeSystem);
    }
    await expect(page.locator('#mapview-hyperlane')).toContainText('Minor Faction');
});
