const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const draftSource = fs.readFileSync(path.resolve(__dirname, '../../js/draft.js'), 'utf8');

function harness() {
    const handlers = new Map();
    const responses = [];

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
            html() { return chain; },
            text() { return chain; },
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
    $.ajax = (request) => request.success(responses.shift());

    const context = {
        console,
        document: { addEventListener() {} },
        location: { hash: '' },
        localStorage: { getItem() { return null; }, removeItem() {}, setItem() {} },
        draft: {
            id: 'draft',
            done: true,
            config: { players: [], alliance: null },
            draft: { players: {}, current: null, log: [] },
            slices: [],
        },
        routes: { data: '/draft' },
        $,
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(draftSource, context, { filename: 'draft.js' });

    return { context, responses };
}

test('successive polls preserve exact enabled assignments and do not synthesize disabled metadata', () => {
    const { context, responses } = harness();
    const assignment = {
        name: 'Augurs of Ilyxum',
        tile_id: '901',
        render_token: 'DS_ilyxum',
    };
    const enabled = [{
        tiles: ['19', '20', '21', '901', '22'],
        minor_faction: assignment,
        equidistant: { index: 3, q: -1, r: 0 },
        total_resources: 8,
        total_influence: 7,
    }];
    responses.push({ ...context.draft, slices: enabled });
    context.refreshData();

    assert.deepEqual(JSON.parse(JSON.stringify(context.draft.slices)), enabled);
    assert.deepEqual(Object.keys(context.draft.slices[0].minor_faction), [
        'name', 'tile_id', 'render_token',
    ]);

    const disabled = [{ tiles: ['19', '20', '21', '22', '23'] }];
    responses.push({ ...context.draft, slices: disabled });
    context.refreshData();

    assert.deepEqual(JSON.parse(JSON.stringify(context.draft.slices)), disabled);
    assert.equal('minor_faction' in context.draft.slices[0], false);
});
