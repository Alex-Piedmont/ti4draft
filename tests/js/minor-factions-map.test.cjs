const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const projectRoot = path.resolve(__dirname, '../..');
const helperSource = fs.readFileSync(path.join(projectRoot, 'js/minor-factions.js'), 'utf8');
const mapSource = fs.readFileSync(path.join(projectRoot, 'js/generate-map.js'), 'utf8');

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

function mapFixture() {
    const output = {};
    const slices = [
        {
            tiles: ['101', '102', '103', '5', '105'],
            minor_faction: { name: 'Minor zero', tile_id: '5', render_token: '5' },
            equidistant: { index: 3, q: -1, r: 0 },
        },
        {
            tiles: ['201', '202', '203', '4215', '205'],
            minor_faction: { name: 'The Ilyxum', tile_id: '4215', render_token: 'DS_ilyxum' },
            equidistant: { index: 3, q: -1, r: 0 },
        },
        {
            tiles: ['301', '302', '303', '4', '305'],
            minor_faction: { name: 'The Embers of Muaat', tile_id: '4', render_token: '4' },
            equidistant: { index: 3, q: -1, r: 0 },
        },
        {
            tiles: ['401', '402', '403', '55', '405'],
            minor_faction: { name: 'The Titans of Ul', tile_id: '55', render_token: '55' },
            equidistant: { index: 3, q: -1, r: 0 },
        },
    ];
    const context = {
        console,
        draft: {
            config: { players: ['Alice', 'Bob', 'Carol'], minor_factions: true },
            draft: {
                players: {
                    c: { id: 'c', name: 'Carol', position: '2', faction: 'Faction C', slice: '0' },
                    b: { id: 'b', name: 'Bob', position: '1', faction: 'Faction B', slice: '1' },
                    a: { id: 'a', name: 'Alice', position: '0', faction: 'Faction A', slice: '2' },
                },
            },
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

    return { context, output };
}

test('map resolves the persisted minor from the selected slice rather than speaker position', () => {
    const { context, output } = mapFixture();

    assert.deepEqual(Array.from(context.lookup(0, 3)), ['4', 'Alice', 'The Embers of Muaat']);
    assert.deepEqual(Array.from(context.lookup(2, 3)), ['5', 'Carol', 'Minor zero']);
    assert.match(output['#mapslices-wrap'], /The Embers of Muaat/);
    assert.match(output['#mapslices-wrap'], /The Titans of Ul/);
    assert.match(output['#mapslices-wrap'], /Slice 4: Available/);
    assert.match(output['#tile-gather'], /(?:^|, )4(?:,|$)/);
    assert.doesNotMatch(output['#tile-gather'], /(?:^|, )55(?:,|$)/);
    assert.doesNotMatch(output['#tile-gather'], /304/);
    assert.match(output['#tts-string'], /(?:^| )4(?: |$)/);
});

test('Discordant Stars render tokens flow through artwork, tile gather, and TTS', () => {
    const { context, output } = mapFixture();

    assert.deepEqual(Array.from(context.lookup(1, 3)), ['DS_ilyxum', 'Bob', 'The Ilyxum']);
    assert.match(output['#map-wrap'], /src="\/img\/tiles\/DS_ilyxum\.png"/);
    assert.match(output['#tile-gather'], /DS_ilyxum/);
    assert.match(output['#tts-string'], /DS_ilyxum/);
    assert.doesNotMatch(output['#tile-gather'], /4215|204/);
});

test('ordinary slices remain unchanged in every map export', () => {
    const ordinary = { tiles: ['19', '20', '21', '22', '23'] };
    const context = {};
    context.window = context;
    vm.createContext(context);
    vm.runInContext(helperSource, context);

    assert.deepEqual(
        JSON.parse(JSON.stringify(context.MinorFactions.resolveSliceTile(ordinary, 3))),
        { tile: '22', label: null, minor: false },
    );
});
