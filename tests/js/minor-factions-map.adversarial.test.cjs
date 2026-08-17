const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const projectRoot = path.resolve(__dirname, '../..');
const helperSource = fs.readFileSync(path.join(projectRoot, 'js/minor-factions.js'), 'utf8');
const mapSource = fs.readFileSync(path.join(projectRoot, 'js/generate-map.js'), 'utf8');

function loadHelper() {
    const context = {};
    context.window = context;
    vm.createContext(context);
    vm.runInContext(helperSource, context, { filename: 'minor-factions.js' });
    return context.MinorFactions;
}

function jqueryFixture(output) {
    return function $(selector) {
        const chain = {
            attr() { return chain; }, addClass() { return chain; }, removeClass() { return chain; },
            prop() { return chain; }, parents() { return chain; }, find() { return chain; },
            off() { return chain; }, on() { return chain; }, ready() { return chain; },
            show() { return chain; }, hide() { return chain; }, val() { return ''; },
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

function mapHarness(playerCount, sliceCount = playerCount, picked = true) {
    const output = {};
    const slices = Array.from({ length: sliceCount }, (_, index) => ({
        tiles: [`${index}01`, `${index}02`, `${index}03`, `${index}50`, `${index}05`],
        minor_faction: {
            name: `Minor ${index}`,
            tile_id: `${index}50`,
            render_token: index === 1 ? 'DS_distinct' : `R${index}`,
        },
        equidistant: { index: 3, q: -1, r: 0 },
    }));
    const players = {};
    for (let objectIndex = playerCount - 1; objectIndex >= 0; objectIndex -= 1) {
        const selectedSlice = (objectIndex + 1) % sliceCount;
        players[`p${objectIndex}`] = {
            id: `p${objectIndex}`,
            name: `Player ${objectIndex}`,
            position: String(objectIndex),
            faction: `Faction ${objectIndex}`,
            slice: picked ? String(selectedSlice) : null,
        };
    }
    const context = {
        console,
        draft: {
            config: { players: Array.from({ length: playerCount }, (_, index) => `Player ${index}`) },
            draft: { players },
            slices,
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

    return { context, output, slices };
}

test('out-of-range equidistant index fails closed at the semantic slot', () => {
    const helper = loadHelper();
    const slice = {
        tiles: ['1', '2', '3', '5', '6'],
        minor_faction: { name: 'Minor', tile_id: '5', render_token: '5' },
        equidistant: { index: 99, q: -1, r: 0 },
    };

    assert.throws(
        () => helper.resolveSliceTile(slice, 3),
        /Invalid persisted Minor Faction slice data/,
    );
});

test('whitespace-only assignment identifiers fail closed', () => {
    const helper = loadHelper();
    const slice = {
        tiles: ['1', '2', '3', '5', '6'],
        minor_faction: { name: '   ', tile_id: '5', render_token: '   ' },
        equidistant: { index: 3, q: -1, r: 0 },
    };

    assert.throws(
        () => helper.resolveSliceTile(slice, 3),
        /Invalid persisted Minor Faction slice data/,
    );
});

test('selected-slice resolution remains correct for every supported player count', () => {
    for (let playerCount = 3; playerCount <= 8; playerCount += 1) {
        const { context, output, slices } = mapHarness(playerCount);
        for (let position = 0; position < playerCount; position += 1) {
            const selected = slices[(position + 1) % playerCount].minor_faction;
            assert.deepEqual(
                Array.from(context.lookup(position, 3)),
                [selected.render_token, `Player ${position}`, selected.name],
            );
            assert.match(output['#tile-gather'], new RegExp(selected.render_token));
            assert.match(output['#tts-string'], new RegExp(`(?:^| )${selected.render_token}(?: |$)`));
        }
    }
});

test('individual slice maps show every generated slice before picks including extras', () => {
    const { output, slices } = mapHarness(3, 4, false);

    assert.equal((output['#mapslices-wrap'].match(/<div class="slice">/g) || []).length, 4);
    for (const slice of slices) {
        assert.match(output['#mapslices-wrap'], new RegExp(slice.minor_faction.name));
        assert.match(output['#mapslices-wrap'], new RegExp(slice.minor_faction.render_token));
    }
});

test('ordinary disabled slices still pass their persisted tile through unchanged', () => {
    const helper = loadHelper();
    const ordinary = { tiles: ['19', '20', '21', '22', '23'] };

    assert.deepEqual(
        JSON.parse(JSON.stringify(helper.resolveSliceTile(ordinary, 3))),
        { tile: '22', label: null, minor: false },
    );
});
