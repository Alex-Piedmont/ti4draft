const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const MinorFactions = require('../../js/minor-factions.js');

function browserHarness() {
    const state = {
        enabled: true,
        playerCount: '3',
        factionCount: '3',
        factionMinimum: undefined,
        constraints: {
            '#min_inf': '4',
            '#min_res': '2.5',
            '#min_total': '9',
            '#max_total': '13',
        },
    };

    function element(selector) {
        return {
            ready() { return this; },
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
                if (selector === '#num_factions') state.factionCount = String(value);
                if (Object.hasOwn(state.constraints, selector)) state.constraints[selector] = String(value);
                return this;
            },
            attr(name, value) {
                if (selector === '#num_factions' && name === 'min') state.factionMinimum = value;
                return this;
            },
            toggle() { return this; },
        };
    }

    const context = vm.createContext({
        document: {},
        window: { location: { hash: '' } },
        console,
        MinorFactions,
        $: element,
    });
    vm.runInContext(
        fs.readFileSync(path.join(__dirname, '../../js/main.js'), 'utf8'),
        context,
    );

    return {
        state,
        update() { vm.runInContext('update_minor_factions_mode()', context); },
    };
}

test('raising player count raises only the one-per-player faction minimum', () => {
    const harness = browserHarness();
    harness.state.playerCount = '6';

    harness.update();

    assert.equal(harness.state.factionMinimum, 6);
    assert.equal(harness.state.factionCount, '6');
});

test('repeated mode toggles do not drift fractional or customized constraints', () => {
    const harness = browserHarness();
    harness.state.constraints = {
        '#min_inf': '5.5',
        '#min_res': '3.5',
        '#min_total': '10.5',
        '#max_total': '12.5',
    };

    for (let index = 0; index < 10; index += 1) {
        harness.state.enabled = !harness.state.enabled;
        harness.update();
    }

    assert.deepEqual(harness.state.constraints, {
        '#min_inf': '5.5',
        '#min_res': '3.5',
        '#min_total': '10.5',
        '#max_total': '12.5',
    });
});
