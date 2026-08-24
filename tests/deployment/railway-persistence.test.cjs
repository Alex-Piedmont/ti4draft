const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const {afterEach, beforeEach, test} = require('node:test');
const {
    createSnapshot,
    normalizePublicPayload,
    stableJson,
    verifySnapshot,
} = require('./railway-persistence.cjs');

const baseUrl = 'https://draft-production.up.railway.app';
let temporaryDirectory;
let stateFile;

beforeEach(async () => {
    temporaryDirectory = await fs.mkdtemp(path.join(os.tmpdir(), 'ti4draft-persistence-'));
    stateFile = path.join(temporaryDirectory, 'state.json');
});

afterEach(async () => fs.rm(temporaryDirectory, {recursive: true, force: true}));

function response(body, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        async json() { return structuredClone(body); },
        async text() { return String(body); },
    };
}

function publicDraft(overrides = {}) {
    return {
        id: 'draft-123',
        done: false,
        config: {name: 'Persistence Contract'},
        draft: {players: {p1: {id: 'p1', name: 'Alex'}}, log: [], current: 'p1'},
        factions: ['The Arborec'],
        slices: [{tiles: ['1', '2', '3', '4', '5']}],
        ...overrides,
    };
}

function fetchFor(payload, pageStatus = 200, apiStatus = 200) {
    return async (url) => url.includes('/api/draft/')
        ? response(payload, apiStatus)
        : response('<html>shared draft</html>', pageStatus);
}

test('create records the shared URL and normalized public snapshot without credentials', async () => {
    const payload = publicDraft({
        secrets: {admin_pass: 'never-store-this'},
        nested: {passkey: 'also-private', stable: true},
    });
    const state = await createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => `${baseUrl}/d/draft-123?fresh=1`.replace('?fresh=1', ''),
        fetchImpl: fetchFor(payload),
    });

    assert.equal(state.draftUrl, `${baseUrl}/d/draft-123`);
    assert.equal(state.publicSnapshot.nested.stable, true);
    assert.equal('passkey' in state.publicSnapshot.nested, false);
    const fileContent = await fs.readFile(stateFile, 'utf8');
    assert.doesNotMatch(fileContent, /never-store-this|also-private|admin_pass|passkey/);
    assert.equal((await fs.stat(stateFile)).mode & 0o777, 0o600);
});

test('verify reloads the shared page and accepts an exactly equal normalized payload', async () => {
    const payload = publicDraft({secrets: {admin_pass: 'first'}});
    await createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => `${baseUrl}/d/draft-123`,
        fetchImpl: fetchFor(payload),
    });
    const verified = await verifySnapshot({
        baseUrl,
        stateFile,
        fetchImpl: fetchFor({...payload, secrets: {admin_pass: 'changed-but-private'}}),
    });
    assert.equal(verified.draftId, 'draft-123');
});

test('normalization excludes only declared private keys and sorts object keys', () => {
    const normalized = normalizePublicPayload({z: 1, secrets: {token: 'x'}, a: {passkey: 'x', keep: 2}});
    assert.deepEqual(normalized, {a: {keep: 2}, z: 1});
    assert.equal(stableJson(normalized), '{"a":{"keep":2},"z":1}');
});

test('verify rejects changed public content and HTTP failures', async () => {
    const payload = publicDraft();
    await createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => `${baseUrl}/d/draft-123`,
        fetchImpl: fetchFor(payload),
    });

    await assert.rejects(
        verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor(publicDraft({done: true}))}),
        /changed across redeployment/,
    );
    await assert.rejects(
        verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor(payload, 404)}),
        /HTTP 404/,
    );
});

test('verify rejects missing, malformed, or cross-origin state', async () => {
    await assert.rejects(verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor(publicDraft())}), /missing or malformed/);
    await fs.writeFile(stateFile, '{bad json');
    await assert.rejects(verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor(publicDraft())}), /missing or malformed/);
    await fs.writeFile(stateFile, JSON.stringify({
        schemaVersion: 1,
        baseUrl,
        draftUrl: 'https://evil.example/d/draft-123',
        draftId: 'draft-123',
        publicSnapshot: normalizePublicPayload(publicDraft()),
    }));
    await assert.rejects(verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor(publicDraft())}), /supplied public origin/);
});

test('create failure never writes an incomplete state file', async () => {
    await assert.rejects(createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => { throw new Error('browser failed'); },
        fetchImpl: fetchFor(publicDraft()),
    }), /browser failed/);
    await assert.rejects(fs.access(stateFile));

    await assert.rejects(createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => `${baseUrl}/d/draft-123`,
        fetchImpl: fetchFor(publicDraft(), 200, 500),
    }), /HTTP 500/);
    await assert.rejects(fs.access(stateFile));
});
