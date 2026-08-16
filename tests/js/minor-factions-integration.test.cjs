const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const projectRoot = path.resolve(__dirname, '../..');
const helperSource = fs.readFileSync(path.join(projectRoot, 'js/minor-factions.js'), 'utf8');
const mapSource = fs.readFileSync(path.join(projectRoot, 'js/generate-map.js'), 'utf8');
const draftSource = fs.readFileSync(path.join(projectRoot, 'js/draft.js'), 'utf8');

function jqueryFixture(output) {
    return function $(selector) {
        const chain = {
            attr() { return chain; },
            addClass() { return chain; },
            removeClass() { return chain; },
            prop() { return chain; },
            parents() { return chain; },
            find() { return chain; },
            off() { return chain; },
            on() { return chain; },
            ready() { return chain; },
            show() { return chain; },
            hide() { return chain; },
            val() { return ''; },
            is() { return false; },
            data(key) {
                if (key === 'homesystem') return '1';
                if (key === 'te') return true;
                return undefined;
            },
            html(value) {
                if (value === undefined) return output[selector];
                output[selector] = String(value);
                return chain;
            },
            text(value) {
                if (value === undefined) return output[selector];
                output[selector] = String(value);
                return chain;
            },
        };
        return chain;
    };
}

function mapFixture(status, enabled = true, options = {}) {
    const output = {};
    const playerCount = options.playerCount || 3;
    const names = ['Alice', 'Bob', 'Carol', 'Diana', 'Eli', 'Fran', 'Gus', 'Hana'];
    const playerRecords = Array.from({ length: playerCount }, (_, position) => ({
        id: `p${position}`,
        name: names[position],
        position: String(position),
        faction: `Selected faction ${position}`,
        slice: String(position),
    }));
    const insertionOrder = options.reversePlayerInsertion ? [...playerRecords].reverse() : playerRecords;
    const players = Object.fromEntries(insertionOrder.map((player) => [player.id, player]));
    const defaultAssignments = [
        { position: 0, faction: "Sardakk N'orr", home_system: '13' },
        { position: 1, faction: 'The Clan of Saar', home_system: '11' },
        { position: 2, faction: 'The Embers of Muaat', home_system: '4' },
        { position: 3, faction: 'Minor 4', home_system: '54' },
        { position: 4, faction: 'Minor 5', home_system: '55' },
        { position: 5, faction: 'Minor 6', home_system: '56' },
        { position: 6, faction: 'Minor 7', home_system: '57' },
        { position: 7, faction: 'Minor 8', home_system: '58' },
    ];
    const assignments = options.assignments || defaultAssignments.slice(0, playerCount);
    const context = {
        console,
        draft: {
            config: { players: names.slice(0, playerCount) },
            draft: { players },
            slices: Array.from({ length: playerCount }, (_, position) => ({
                tiles: [
                    `${position + 1}01`,
                    `${position + 1}02`,
                    `${position + 1}03`,
                    `${position + 1}04`,
                    `${position + 1}05`,
                ],
            })),
            minor_factions: {
                enabled,
                equidistant_index: 4,
                status,
                assignments: status === 'resolved' ? assignments : [],
            },
        },
        routes: { tile_images: '/img/tiles' },
        ordinal(number) { return `${number}th`; },
    };
    context.window = context;
    context.$ = jqueryFixture(output);
    vm.createContext(context);
    vm.runInContext(helperSource, context, { filename: 'minor-factions.js' });
    vm.runInContext(mapSource, context, { filename: 'generate-map.js' });
    context.generate_map();

    return { output, context };
}

test('shipped map script substitutes resolved minors in maps, tile gather, and TTS', () => {
    const { output, context } = mapFixture('resolved');

    assert.deepEqual(Array.from(context.lookup(0, 4)), ['13', 'Alice', "Sardakk N'orr"]);
    assert.match(output['#map-wrap'], /ST_13\.png/);
    assert.match(output['#mapslices-wrap'], /Sardakk N'orr/);
    assert.match(output['#tile-gather'], /13/);
    assert.doesNotMatch(output['#tile-gather'], /105|205|305/);
    assert.match(output['#tts-string'], /(?:^| )13(?: |$)/);
    assert.doesNotMatch(output['#tts-string'], /105|205|305/);
});

test('pending map output uses zero placeholders and preserves TTS geometry', () => {
    const resolved = mapFixture('resolved').output['#tts-string'].split(' ');
    const pendingFixture = mapFixture('pending');
    const pending = pendingFixture.output['#tts-string'].split(' ');

    assert.deepEqual(Array.from(pendingFixture.context.lookup(0, 4)), [0, 'Alice', 'Minor Faction']);
    assert.equal(pending.length, resolved.length);
    assert.doesNotMatch(pendingFixture.output['#tile-gather'], /105|205|305/);
    assert.match(pendingFixture.output['#mapslices-wrap'], /Minor Faction/);

    const invalidFixture = mapFixture('invalid');
    assert.deepEqual(Array.from(invalidFixture.context.lookup(0, 4)), [0, 'Alice', 'Minor Faction']);
    assert.doesNotMatch(invalidFixture.output['#tile-gather'], /105|205|305/);
});

test('disabled map output preserves the original reserved tile', () => {
    const { output, context } = mapFixture('pending', false);

    assert.deepEqual(Array.from(context.lookup(0, 4)), ['105', 'Alice', null]);
    assert.match(output['#tile-gather'], /105/);
    assert.match(output['#tts-string'], /(?:^| )105(?: |$)/);
});

test('all supported maps substitute every speaker position regardless of player object order', () => {
    for (let playerCount = 3; playerCount <= 8; playerCount++) {
        const assignments = Array.from({ length: playerCount }, (_, position) => ({
            position,
            faction: `Minor faction ${position}`,
            home_system: position === 0 ? 'DS_ilyxum' : String(9000 + position),
        }));
        const { output, context } = mapFixture('resolved', true, {
            assignments,
            playerCount,
            reversePlayerInsertion: true,
        });

        for (const assignment of assignments) {
            assert.deepEqual(Array.from(context.lookup(assignment.position, 4)), [
                assignment.home_system,
                context.draft.config.players[assignment.position],
                assignment.faction,
            ]);
            assert.match(output['#tile-gather'], new RegExp(`(?:^|, )${assignment.home_system}(?:,|$)`));
            assert.match(output['#tts-string'], new RegExp(`(?:^| )${assignment.home_system}(?: |$)`));
            assert.doesNotMatch(output['#tile-gather'], new RegExp(`${assignment.position + 1}05`));
        }

        assert.match(output['#map-wrap'], /src="\/img\/tiles\/DS_ilyxum\.png"/);
        assert.doesNotMatch(output['#map-wrap'], /ST_DS_ilyxum/);
    }
});

test('pending exports replace the exact resolved assignment coordinates with zero tokens', () => {
    const resolvedFixture = mapFixture('resolved', true, { playerCount: 8 });
    const pendingFixture = mapFixture('pending', true, { playerCount: 8 });
    const resolvedTokens = resolvedFixture.output['#tts-string'].split(' ');
    const pendingTokens = pendingFixture.output['#tts-string'].split(' ');

    assert.equal(pendingTokens.length, resolvedTokens.length);
    for (const assignment of resolvedFixture.context.draft.minor_factions.assignments) {
        const resolvedIndex = resolvedTokens.indexOf(assignment.home_system);
        assert.notEqual(resolvedIndex, -1);
        assert.equal(pendingTokens[resolvedIndex], '0');
    }
});

test('malformed resolved assignments fail closed without reserved or undefined tile output', () => {
    const fixture = mapFixture('resolved', true, {
        assignments: [
            { position: 0, faction: 'Broken minor' },
            { position: 1, faction: 'Valid minor', home_system: '11' },
            { position: 2, faction: 'Valid minor 2', home_system: '4' },
        ],
    });

    assert.deepEqual(Array.from(fixture.context.lookup(0, 4)), [0, 'Alice', 'Minor Faction']);
    assert.doesNotMatch(fixture.output['#map-wrap'], /undefined|ST_105\.png/);
    assert.doesNotMatch(fixture.output['#tile-gather'], /undefined|105/);
    assert.doesNotMatch(fixture.output['#tts-string'], /undefined|(?:^| )105(?: |$)/);
});

test('shipped draft refresh invalidates cached maps across readiness transitions', () => {
    const output = {};
    const context = {
        console,
        document: {},
        location: { hash: '' },
        localStorage: { getItem() { return null; }, removeItem() {}, setItem() {} },
        draft: {
            id: 'draft',
            done: true,
            config: { players: [], alliance: null },
            draft: { players: {}, current: null, log: [] },
            minor_factions: { enabled: true, equidistant_index: 4, status: 'pending', assignments: [] },
        },
    };
    context.window = context;
    context.$ = jqueryFixture(output);
    vm.createContext(context);
    vm.runInContext(draftSource, context, { filename: 'draft.js' });

    for (const status of ['resolved', 'pending', 'invalid']) {
        context.draft.minor_factions.status = status;
        context.map_cached = true;
        context.refresh();
        assert.equal(context.map_cached, false);
    }
});

function draftTransitionFixture() {
    const output = {};
    const handlers = new Map();
    const ajaxResponses = [];

    function $(selector) {
        const key = typeof selector === 'string' ? selector : 'document';
        const chain = {
            length: 0,
            attr() { return chain; },
            addClass() { return chain; },
            removeClass() { return chain; },
            prop() { return chain; },
            parents() { return chain; },
            find() { return chain; },
            off() { return chain; },
            show() { return chain; },
            hide() { return chain; },
            click() { return chain; },
            is() { return false; },
            data() { return undefined; },
            val() { return ''; },
            html(value) {
                if (value === undefined) return output[key];
                output[key] = String(value);
                return chain;
            },
            text(value) {
                if (value === undefined) return output[key];
                output[key] = String(value);
                return chain;
            },
            on(event, callback) {
                handlers.set(`${key}:${event}`, callback);
                return chain;
            },
            ready(callback) {
                callback();
                return chain;
            },
        };
        return chain;
    }
    $.ajax = (request) => {
        assert.notEqual(ajaxResponses.length, 0, `unexpected ajax request to ${request.url}`);
        request.success(ajaxResponses.shift());
    };

    const initialDraft = {
        id: 'draft',
        done: true,
        config: { players: [], alliance: null },
        draft: { players: {}, current: null, log: [] },
        minor_factions: { enabled: true, equidistant_index: 4, status: 'pending', assignments: [] },
    };
    const context = {
        console,
        document: { addEventListener() {} },
        location: { hash: '' },
        localStorage: {
            getItem(key) { return key.startsWith('admin_') ? 'admin-secret' : null; },
            removeItem() {},
            setItem() {},
        },
        draft: initialDraft,
        routes: { pick: '/pick', undo: '/undo', data: '/draft' },
        $,
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(draftSource, context, { filename: 'draft.js' });

    return {
        ajaxResponses,
        context,
        handlers,
        response(status) {
            return {
                ...initialDraft,
                minor_factions: {
                    enabled: true,
                    equidistant_index: 4,
                    status,
                    assignments: status === 'resolved'
                        ? [{ position: 0, faction: 'Minor', home_system: '5' }]
                        : [],
                    ...(status === 'invalid' ? { error: 'insufficient_eligible_candidates' } : {}),
                },
            };
        },
    };
}

test('actual final-pick, undo, and polling callbacks invalidate map output before reuse', () => {
    const fixture = draftTransitionFixture();
    const { ajaxResponses, context, handlers } = fixture;

    context.draft_pick = { id: 'draft' };
    context.map_cached = true;
    ajaxResponses.push({ success: true, draft: fixture.response('resolved') });
    handlers.get('#confirm:click')({ preventDefault() {} });
    assert.equal(context.draft.minor_factions.status, 'resolved');
    assert.equal(context.map_cached, false);

    context.map_cached = true;
    ajaxResponses.push({ success: true, draft: fixture.response('pending') });
    handlers.get('.undo-last-action:click')({ preventDefault() {} });
    assert.equal(context.draft.minor_factions.status, 'pending');
    assert.equal(context.map_cached, false);

    const cacheStateAtGeneration = [];
    context.generate_map = () => {
        cacheStateAtGeneration.push(context.map_cached);
        context.map_cached = true;
    };
    context.location.hash = '#map';
    context.map_cached = true;
    ajaxResponses.push(fixture.response('invalid'));
    context.refreshData();

    assert.equal(context.draft.minor_factions.status, 'invalid');
    assert.deepEqual(cacheStateAtGeneration, [false]);
    assert.equal(context.map_cached, true);
});
