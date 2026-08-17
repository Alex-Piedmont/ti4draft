const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const MinorFactions = require('../../js/minor-factions.js');

test('minor factions requires one draftable faction per player', () => {
    assert.equal(MinorFactions.minimumFactionCount(6, true), 6);
});

test('ordinary drafts require one faction per player', () => {
    assert.equal(MinorFactions.minimumFactionCount(6, false), 6);
});

test('invalid player counts do not produce a negative minimum', () => {
    assert.equal(MinorFactions.minimumFactionCount(-2, true), 0);
    assert.equal(MinorFactions.minimumFactionCount('invalid', true), 0);
});

test('minor factions retains the five-system slice defaults', () => {
    assert.deepEqual(MinorFactions.sliceConstraintDefaults(true), {
        minimumInfluence: 4,
        minimumResources: 2.5,
        minimumTotal: 9,
        maximumTotal: 13,
    });
});

test('ordinary drafts retain the five-system slice defaults', () => {
    assert.deepEqual(MinorFactions.sliceConstraintDefaults(false), {
        minimumInfluence: 4,
        minimumResources: 2.5,
        minimumTotal: 9,
        maximumTotal: 13,
    });
});

test('persisted assignments render only at their server-described equidistant index', () => {
    const slice = {
        tiles: ['19', '20', '21', '5', '22'],
        minor_faction: { name: 'The Arborec', tile_id: '5', render_token: '5' },
        equidistant: { index: 3, q: -1, r: 0 },
    };

    assert.deepEqual(MinorFactions.resolveSliceTile(slice, 3), {
        tile: '5',
        label: 'The Arborec',
        minor: true,
    });
    assert.deepEqual(MinorFactions.resolveSliceTile(slice, 4), {
        tile: '22',
        label: null,
        minor: false,
    });
});

test('ordinary slices preserve their original tile', () => {
    assert.deepEqual(MinorFactions.resolveSliceTile({ tiles: ['19', '20', '21', '99', '22'] }, 3), {
        tile: '99', label: null, minor: false,
    });
});

test('enabled payloads missing their persisted assignment fail closed', () => {
    assert.throws(
        () => MinorFactions.resolveSliceTile({ tiles: ['19', '20', '21', '99', '22'] }, 3, true),
        /Invalid persisted Minor Faction slice data/,
    );
});

test('malformed persisted assignments fail closed at the reserved coordinate', () => {
    for (const assignment of [
        { name: 'The Arborec', tile_id: '5' },
        { name: '', tile_id: '5', render_token: '5' },
        { name: 'The Arborec', tile_id: '', render_token: '5' },
        { name: 'The Arborec', tile_id: '5', render_token: '' },
        { name: 'The Arborec', tile_id: '6', render_token: '5' },
    ]) {
        assert.throws(() => MinorFactions.resolveSliceTile({
                tiles: ['19', '20', '21', '5', '22'],
                minor_faction: assignment,
                equidistant: { index: 3, q: -1, r: 0 },
            }, 3),
            /Invalid persisted Minor Faction slice data/,
        );
    }
});

test('Discordant Stars slices use the render token while retaining the canonical tile ID', () => {
    const result = MinorFactions.resolveSliceTile({
        tiles: ['19', '20', '21', '4215', '22'],
        minor_faction: { name: 'The Ilyxum', tile_id: '4215', render_token: 'DS_ilyxum' },
        equidistant: { index: 3, q: -1, r: 0 },
    }, 3);

    assert.deepEqual(result, { tile: 'DS_ilyxum', label: 'The Ilyxum', minor: true });
});

function browserHarness() {
    const state = {
        enabled: true,
        playerCount: '6',
        factionCount: '9',
        factionMinimum: undefined,
        guidanceVisible: undefined,
        constraints: {
            '#min_inf': '4',
            '#min_res': '2.5',
            '#min_total': '9',
            '#max_total': '13',
        },
    };

    function element(selector) {
        return {
            ready() {
                return this;
            },
            is(query) {
                return selector === '#minor_factions_toggle' && query === ':checked'
                    ? state.enabled
                    : false;
            },
            val(value) {
                if (value === undefined) {
                    if (selector === '#num_players') return state.playerCount;
                    if (selector === '#num_factions') return state.factionCount;
                    return state.constraints[selector];
                }

                if (selector === '#num_players') state.playerCount = String(value);
                if (selector === '#num_factions') state.factionCount = String(value);
                if (Object.hasOwn(state.constraints, selector)) state.constraints[selector] = String(value);
                return this;
            },
            attr(name, value) {
                if (selector === '#num_factions' && name === 'min') {
                    state.factionMinimum = value;
                }
                return this;
            },
            toggle(value) {
                if (selector === '.minor_factions_only') state.guidanceVisible = value;
                return this;
            },
        };
    }

    const context = vm.createContext({
        document: {},
        window: { location: { hash: '' } },
        console,
        MinorFactions,
        $: element,
    });
    const mainPath = path.join(__dirname, '../../js/main.js');
    vm.runInContext(fs.readFileSync(mainPath, 'utf8'), context);

    return {
        state,
        update(applySliceDefaults = false) {
            vm.runInContext(`update_minor_factions_mode(${applySliceDefaults})`, context);
        },
    };
}

test('browser control keeps the one-per-player minimum when player count changes', () => {
    const harness = browserHarness();

    harness.update();
    assert.equal(harness.state.factionMinimum, 6);
    assert.equal(harness.state.factionCount, '9');
    assert.equal(harness.state.guidanceVisible, true);

    harness.state.playerCount = '8';
    harness.update();
    assert.equal(harness.state.factionMinimum, 8);
    assert.equal(harness.state.factionCount, '9');
});

test('browser control preserves customized slice constraints across mode toggles', () => {
    const harness = browserHarness();
    harness.state.constraints['#min_inf'] = '6';
    harness.state.constraints['#min_total'] = '11';

    harness.update(true);
    assert.deepEqual(harness.state.constraints, {
        '#min_inf': '6',
        '#min_res': '2.5',
        '#min_total': '11',
        '#max_total': '13',
    });

    harness.state.enabled = false;
    harness.update(true);
    assert.deepEqual(harness.state.constraints, {
        '#min_inf': '6',
        '#min_res': '2.5',
        '#min_total': '11',
        '#max_total': '13',
    });
});

test('player-count updates preserve custom slice constraints', () => {
    const harness = browserHarness();
    harness.state.constraints['#min_total'] = '7.5';

    harness.update();

    assert.equal(harness.state.constraints['#min_total'], '7.5');
});

test('browser control restores the ordinary minimum when minor factions is disabled', () => {
    const harness = browserHarness();
    harness.state.playerCount = '7';
    harness.state.factionCount = '14';

    harness.update();
    harness.state.enabled = false;
    harness.update();

    assert.equal(harness.state.factionMinimum, 7);
    assert.equal(harness.state.guidanceVisible, false);
});
