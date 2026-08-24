#!/usr/bin/env node

const fs = require('node:fs/promises');
const path = require('node:path');

const PRIVATE_FIELDS = new Set(['secrets', 'admin_pass', 'passkey']);

function parseOrigin(value) {
    let parsed;
    try {
        parsed = new URL(value);
    } catch {
        throw new Error('Base URL must be a valid HTTPS origin.');
    }
    if (parsed.protocol !== 'https:'
        || !parsed.hostname
        || parsed.username
        || parsed.password
        || (parsed.pathname !== '/' && parsed.pathname !== '')
        || parsed.search
        || parsed.hash) {
        throw new Error('Base URL must be a valid HTTPS origin.');
    }
    return parsed.origin;
}

function normalizePublicPayload(value) {
    if (Array.isArray(value)) return value.map(normalizePublicPayload);
    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(Object.keys(value)
            .filter((key) => !PRIVATE_FIELDS.has(key))
            .sort()
            .map((key) => [key, normalizePublicPayload(value[key])]));
    }
    return value;
}

function stableJson(value) {
    return JSON.stringify(normalizePublicPayload(value));
}

async function request(fetchImpl, url, type) {
    const response = await fetchImpl(url, {redirect: 'follow'});
    if (!response.ok) throw new Error(`Request failed with HTTP ${response.status}: ${url}`);
    return type === 'json' ? response.json() : response.text();
}

async function createDraftWithBrowser(baseUrl) {
    const {chromium} = require('@playwright/test');
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.goto(`${baseUrl}/`, {waitUntil: 'domcontentloaded'});
        await page.locator('#num_players').fill('3');
        const playerNames = page.getByRole('textbox', {name: 'Player Name'});
        for (let index = 0; index < 3; index++) {
            await playerNames.nth(index).fill(`Railway Player ${index + 1}`);
        }
        await page.locator('#minor_factions_toggle').check();
        await page.locator('#num_slices').fill('4');
        await page.locator('#num_factions').fill('3');
        await page.getByRole('textbox', {name: 'Game Name'}).fill(`Railway Persistence ${Date.now()}`);
        await page.getByRole('button', {name: 'Generate'}).click();
        await page.waitForURL(/\/d\/[^?]+\?fresh=1$/);
        const draftUrl = new URL(page.url());
        draftUrl.search = '';
        draftUrl.hash = '';
        return draftUrl.toString().replace(/\/$/, '');
    } finally {
        await browser.close();
    }
}

function draftIdentity(baseUrl, draftUrl) {
    const parsed = new URL(draftUrl);
    if (parsed.origin !== baseUrl || parsed.username || parsed.password || parsed.search || parsed.hash) {
        throw new Error('Generated draft URL must use the supplied public origin without credentials.');
    }
    const match = parsed.pathname.match(/^\/d\/([^/]+)$/);
    if (!match) throw new Error('Generated draft URL does not contain a valid draft ID.');
    return {draftId: decodeURIComponent(match[1]), draftUrl: parsed.toString().replace(/\/$/, '')};
}

async function writeStateAtomically(stateFile, state) {
    const directory = path.dirname(stateFile);
    await fs.mkdir(directory, {recursive: true});
    const temporaryFile = `${stateFile}.${process.pid}.${Date.now()}.tmp`;
    try {
        await fs.writeFile(temporaryFile, `${JSON.stringify(state, null, 2)}\n`, {flag: 'wx', mode: 0o600});
        await fs.rename(temporaryFile, stateFile);
    } catch (error) {
        await fs.rm(temporaryFile, {force: true});
        throw error;
    }
}

async function createSnapshot({baseUrl, stateFile, createDraft = createDraftWithBrowser, fetchImpl = fetch, writeState = writeStateAtomically}) {
    const origin = parseOrigin(baseUrl);
    const generatedUrl = await createDraft(origin);
    const identity = draftIdentity(origin, generatedUrl);
    await request(fetchImpl, identity.draftUrl, 'text');
    const payload = await request(fetchImpl, `${origin}/api/draft/${encodeURIComponent(identity.draftId)}`, 'json');
    const publicSnapshot = normalizePublicPayload(payload);
    if (publicSnapshot.id !== identity.draftId) throw new Error('Public API returned a different draft identity.');
    const state = {
        schemaVersion: 1,
        baseUrl: origin,
        draftUrl: identity.draftUrl,
        draftId: identity.draftId,
        publicSnapshot,
    };
    await writeState(stateFile, state);
    return state;
}

function validateState(state, origin) {
    if (!state || state.schemaVersion !== 1 || state.baseUrl !== origin
        || typeof state.draftUrl !== 'string' || typeof state.draftId !== 'string'
        || !state.publicSnapshot || typeof state.publicSnapshot !== 'object') {
        throw new Error('Persistence state file is missing or malformed.');
    }
    const identity = draftIdentity(origin, state.draftUrl);
    if (identity.draftId !== state.draftId || state.publicSnapshot.id !== state.draftId) {
        throw new Error('Persistence state identities do not match.');
    }
    if (stableJson(state.publicSnapshot) !== JSON.stringify(state.publicSnapshot)) {
        throw new Error('Persistence state is not a normalized public snapshot.');
    }
    return identity;
}

async function verifySnapshot({baseUrl, stateFile, fetchImpl = fetch}) {
    const origin = parseOrigin(baseUrl);
    let state;
    try {
        state = JSON.parse(await fs.readFile(stateFile, 'utf8'));
    } catch {
        throw new Error('Persistence state file is missing or malformed.');
    }
    const identity = validateState(state, origin);
    await request(fetchImpl, identity.draftUrl, 'text');
    const payload = await request(fetchImpl, `${origin}/api/draft/${encodeURIComponent(identity.draftId)}`, 'json');
    if (stableJson(payload) !== stableJson(state.publicSnapshot)) {
        throw new Error('Persisted public draft payload changed across redeployment.');
    }
    return state;
}

function parseArguments(argv) {
    const mode = argv[0];
    const values = {};
    const allowedKeys = new Set(['base-url', 'state-file']);
    for (let index = 1; index < argv.length; index += 2) {
        const key = argv[index];
        const value = argv[index + 1];
        if (!key?.startsWith('--') || value === undefined) throw new Error('Invalid command arguments.');
        const name = key.slice(2);
        if (!allowedKeys.has(name) || Object.hasOwn(values, name)) throw new Error('Invalid command arguments.');
        values[name] = value;
    }
    if (!['create', 'verify'].includes(mode) || !values['base-url'] || !values['state-file']) {
        throw new Error('Usage: railway-persistence.cjs <create|verify> --base-url <https-origin> --state-file <path>');
    }
    return {mode, baseUrl: values['base-url'], stateFile: values['state-file']};
}

async function main() {
    const arguments_ = parseArguments(process.argv.slice(2));
    const operation = arguments_.mode === 'create' ? createSnapshot : verifySnapshot;
    const state = await operation(arguments_);
    console.log(`${arguments_.mode} persistence check passed for ${state.draftUrl}`);
}

if (require.main === module) {
    main().catch((error) => {
        console.error(error.message);
        process.exitCode = 1;
    });
}

module.exports = {
    createSnapshot,
    draftIdentity,
    normalizePublicPayload,
    parseArguments,
    parseOrigin,
    stableJson,
    validateState,
    verifySnapshot,
    writeStateAtomically,
};
