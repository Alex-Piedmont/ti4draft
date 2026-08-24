const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const {afterEach, beforeEach, test} = require('node:test');
const {
    createSnapshot,
    normalizePublicPayload,
    parseArguments,
    verifySnapshot,
    writeStateAtomically,
} = require('./railway-persistence.cjs');

const baseUrl = 'https://draft-production.up.railway.app';
let temporaryDirectory;
let stateFile;

beforeEach(async () => {
    temporaryDirectory = await fs.mkdtemp(path.join(os.tmpdir(), 'ti4draft-persistence-adversarial-'));
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
        draft: {current: 'p1', log: [], players: {p1: {id: 'p1', name: 'Alex'}}},
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

async function createBaseline(payload = publicDraft()) {
    return createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => `${baseUrl}/d/draft-123`,
        fetchImpl: fetchFor(payload),
    });
}

test('create rejects a generated draft URL containing credentials and writes no state', async () => {
    await assert.rejects(createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => 'https://user:secret@draft-production.up.railway.app/d/draft-123',
        fetchImpl: fetchFor(publicDraft()),
    }), /without credentials|supplied public origin/);
    await assert.rejects(fs.access(stateFile));
});

test('create rejects a public API identity mismatch and writes no state', async () => {
    await assert.rejects(createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => `${baseUrl}/d/draft-123`,
        fetchImpl: fetchFor(publicDraft({id: 'different-draft'})),
    }), /different draft identity/);
    await assert.rejects(fs.access(stateFile));
});

test('create rejects transport and malformed JSON failures without leaving state', async () => {
    await assert.rejects(createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => `${baseUrl}/d/draft-123`,
        fetchImpl: async () => { throw new Error('connection reset'); },
    }), /connection reset/);
    await assert.rejects(fs.access(stateFile));

    await assert.rejects(createSnapshot({
        baseUrl,
        stateFile,
        createDraft: async () => `${baseUrl}/d/draft-123`,
        fetchImpl: async (url) => url.includes('/api/draft/')
            ? {ok: true, status: 200, async json() { throw new SyntaxError('invalid JSON'); }}
            : response('<html>shared draft</html>'),
    }), /invalid JSON/);
    await assert.rejects(fs.access(stateFile));
});

test('failed verification leaves the recorded baseline byte-for-byte unchanged', async () => {
    await createBaseline();
    const before = await fs.readFile(stateFile);

    await assert.rejects(
        verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor(publicDraft({done: true}))}),
        /changed across redeployment/,
    );

    assert.deepEqual(await fs.readFile(stateFile), before);
});

test('comparison ignores object key order but rejects array order and added public fields', async () => {
    const payload = publicDraft();
    await createBaseline(payload);

    const reordered = {
        slices: payload.slices,
        factions: payload.factions,
        draft: payload.draft,
        config: payload.config,
        done: payload.done,
        id: payload.id,
    };
    await assert.doesNotReject(verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor(reordered)}));
    await assert.rejects(
        verifySnapshot({
            baseUrl,
            stateFile,
            fetchImpl: fetchFor({...payload, factions: [...payload.factions].reverse().concat('The Winnu')}),
        }),
        /changed across redeployment/,
    );
    await assert.rejects(
        verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor({...payload, newPublicField: true})}),
        /changed across redeployment/,
    );
});

test('verify rejects identity corruption and a snapshot containing a private field', async () => {
    const state = await createBaseline();
    const corruptStates = [
        {...state, draftId: 'other-draft'},
        {...state, publicSnapshot: {...state.publicSnapshot, passkey: 'must-not-be-stored'}},
        {...state, schemaVersion: 2},
    ];

    for (const corruptState of corruptStates) {
        await fs.writeFile(stateFile, JSON.stringify(corruptState));
        await assert.rejects(
            verifySnapshot({baseUrl, stateFile, fetchImpl: fetchFor(publicDraft())}),
            /identities do not match|not a normalized public snapshot|missing or malformed/,
        );
    }
});

test('atomic state writing cleans its temporary file when the final rename fails', async () => {
    await fs.mkdir(stateFile);

    await assert.rejects(writeStateAtomically(stateFile, {schemaVersion: 1}));

    const entries = await fs.readdir(temporaryDirectory);
    assert.deepEqual(entries, ['state.json']);
});

test('CLI argument parsing rejects unknown options instead of silently accepting typos', () => {
    assert.throws(
        () => parseArguments(['create', '--base-url', baseUrl, '--state-file', stateFile, '--state-fil', 'wrong.json']),
        /Invalid command arguments|Usage/,
    );
});

test('CLI argument parsing rejects duplicate options instead of silently replacing values', () => {
    assert.throws(
        () => parseArguments(['verify', '--base-url', baseUrl, '--state-file', stateFile, '--state-file', 'other.json']),
        /Invalid command arguments|Usage/,
    );
});

test('private-field normalization is recursive while similarly named public fields remain', () => {
    const normalized = normalizePublicPayload({
        admin_pass: 'remove',
        arrays: [{passkey: 'remove', passkeyHint: 'keep'}],
        secret: 'keep',
        secrets: 'remove',
    });

    assert.deepEqual(normalized, {arrays: [{passkeyHint: 'keep'}], secret: 'keep'});
});
