const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const {afterEach, beforeEach, test} = require('node:test');
const {assertIdentity, statusIdentity} = require('./railway-status-check.cjs');
const {
    capture,
    deploymentOutcome,
    newestDeployment,
    waitForDeployment,
} = require('./railway-wait.cjs');

let temporaryDirectory;
let stateFile;

beforeEach(async () => {
    temporaryDirectory = await fs.mkdtemp(path.join(os.tmpdir(), 'ti4draft-railway-commands-'));
    stateFile = path.join(temporaryDirectory, 'deployment.json');
});

afterEach(async () => fs.rm(temporaryDirectory, {recursive: true, force: true}));

test('status identity requires the intended project, service, and environment', () => {
    const identity = statusIdentity({
        name: 'ti4draft',
        service: {name: 'ti4draft', id: 'service-id'},
        environment: {name: 'production', id: 'environment-id'},
    });
    assert.deepEqual(identity, {project: 'ti4draft', service: 'ti4draft', environment: 'production'});
    assert.doesNotThrow(() => assertIdentity(identity, identity));
    assert.throws(() => assertIdentity(identity, {...identity, service: 'wrong'}), /service mismatch/);
});

test('status identity accepts Railway 4.30 nested graph output for one isolated service', () => {
    const identity = statusIdentity({
        name: 'ti4draft',
        environments: {edges: [{node: {name: 'production'}}]},
        services: {edges: [{node: {name: 'ti4draft'}}]},
    });
    assert.deepEqual(identity, {project: 'ti4draft', service: 'ti4draft', environment: 'production'});
});

test('capture stores an explicit empty or current deployment baseline', async () => {
    const empty = await capture({service: 'ti4draft', stateFile}, () => []);
    assert.equal(empty.baselineDeploymentId, null);

    const current = await capture({service: 'ti4draft', stateFile}, () => [{id: 'old', status: 'SUCCESS'}]);
    assert.equal(current.baselineDeploymentId, 'old');
    assert.equal((JSON.parse(await fs.readFile(stateFile, 'utf8'))).baselineDeploymentId, 'old');
});

test('wait ignores the baseline and accepts only a different successful deployment', async () => {
    await capture({service: 'ti4draft', stateFile}, () => [{id: 'old', status: 'SUCCESS'}]);
    const payloads = [
        [{id: 'old', status: 'SUCCESS'}],
        [{id: 'new', status: 'BUILDING'}],
        [{id: 'new', status: 'SUCCESS'}],
    ];
    const result = await waitForDeployment(
        {service: 'ti4draft', stateFile, timeoutSeconds: 1},
        () => payloads.shift(),
        async () => {},
    );
    assert.deepEqual(result, {id: 'new', status: 'SUCCESS'});
});

test('wait fails immediately for every failed terminal state', async () => {
    for (const status of ['FAILED', 'CRASHED', 'CANCELLED', 'REMOVED']) {
        await capture({service: 'ti4draft', stateFile}, () => []);
        await assert.rejects(
            waitForDeployment(
                {service: 'ti4draft', stateFile, timeoutSeconds: 1},
                () => [{id: `new-${status}`, status}],
                async () => {},
            ),
            new RegExp(status),
        );
    }
});

test('deployment parsing and outcomes reject malformed input and public false positives', () => {
    assert.deepEqual(newestDeployment({deployments: [{id: 'new', status: 'success'}]}), {id: 'new', status: 'SUCCESS'});
    assert.equal(deploymentOutcome({id: 'same', status: 'SUCCESS'}, 'same'), 'wait');
    assert.equal(deploymentOutcome({id: 'new', status: 'DEPLOYING'}, 'same'), 'wait');
    assert.throws(() => newestDeployment({bad: []}), /malformed/);
    assert.throws(() => newestDeployment([{status: 'SUCCESS'}]), /malformed/);
});
