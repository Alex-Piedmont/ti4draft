const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const MinorFactions = require('../../js/minor-factions.js');

test('minor factions requires twice the player count', () => {
    assert.equal(MinorFactions.minimumFactionCount(6, true), 12);
});

test('ordinary drafts require one faction per player', () => {
    assert.equal(MinorFactions.minimumFactionCount(6, false), 6);
});

test('invalid player counts do not produce a negative minimum', () => {
    assert.equal(MinorFactions.minimumFactionCount(-2, true), 0);
    assert.equal(MinorFactions.minimumFactionCount('invalid', true), 0);
});

test('minor factions uses balanced defaults for four retained systems', () => {
    assert.deepEqual(MinorFactions.sliceConstraintDefaults(true), {
        minimumInfluence: 2,
        minimumResources: 1,
        minimumTotal: 5,
        maximumTotal: 10,
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

test('resolved assignments replace only the server-described equidistant index', () => {
    const mode = {
        enabled: true,
        equidistant_index: 3,
        status: 'resolved',
        assignments: [{ position: 2, faction: 'The Arborec', home_system: '5' }],
    };

    assert.deepEqual(MinorFactions.resolveSliceTile(mode, 2, 3, '99'), {
        tile: '5',
        label: 'The Arborec',
        minor: true,
    });
    assert.deepEqual(MinorFactions.resolveSliceTile(mode, 2, 4, '98'), {
        tile: '98',
        label: null,
        minor: false,
    });
});

test('pending and invalid assignments preserve an empty reserved coordinate', () => {
    for (const status of ['pending', 'invalid']) {
        const result = MinorFactions.resolveSliceTile({
            enabled: true,
            equidistant_index: 3,
            status,
            assignments: [],
        }, 0, 3, '99');

        assert.deepEqual(result, { tile: 0, label: 'Minor Faction', minor: true });
    }
});

test('disabled mode preserves the original tile', () => {
    assert.deepEqual(MinorFactions.resolveSliceTile({
        enabled: false,
        equidistant_index: 3,
        status: 'pending',
        assignments: [],
    }, 0, 3, '99'), { tile: '99', label: null, minor: false });
});

test('malformed resolved assignments fail closed at the reserved coordinate', () => {
    for (const assignment of [
        { position: 0, faction: 'The Arborec' },
        { position: 0, faction: '', home_system: '5' },
        { position: 0, faction: 'The Arborec', home_system: '' },
        { position: 0, faction: 'The Arborec', home_system: null },
    ]) {
        const result = MinorFactions.resolveSliceTile({
            enabled: true,
            equidistant_index: 3,
            status: 'resolved',
            assignments: [assignment],
        }, 0, 3, '905');

        assert.deepEqual(result, { tile: 0, label: 'Minor Faction', minor: true });
    }
});

test('resolved payload without an assignment for the requested position does not leak the reserved tile', () => {
    const result = MinorFactions.resolveSliceTile({
        enabled: true,
        equidistant_index: 3,
        status: 'resolved',
        assignments: [{ position: 1, faction: 'The Arborec', home_system: '5' }],
    }, 0, 3, '905');

    assert.deepEqual(result, { tile: 0, label: 'Minor Faction', minor: true });
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

test('browser control updates the minimum and current value when player count changes', () => {
    const harness = browserHarness();

    harness.update();
    assert.equal(harness.state.factionMinimum, 12);
    assert.equal(harness.state.factionCount, '12');
    assert.equal(harness.state.guidanceVisible, true);

    harness.state.playerCount = '8';
    harness.update();
    assert.equal(harness.state.factionMinimum, 16);
    assert.equal(harness.state.factionCount, '16');
});

test('browser control applies and restores mode-specific slice defaults', () => {
    const harness = browserHarness();

    harness.update(true);
    assert.deepEqual(harness.state.constraints, {
        '#min_inf': '2',
        '#min_res': '1',
        '#min_total': '5',
        '#max_total': '10',
    });

    harness.state.enabled = false;
    harness.update(true);
    assert.deepEqual(harness.state.constraints, {
        '#min_inf': '4',
        '#min_res': '2.5',
        '#min_total': '9',
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
