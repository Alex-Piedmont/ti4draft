const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const {afterEach, beforeEach, test} = require('node:test');
const {
    assertIdentity,
    parseArguments: parseStatusArguments,
    readStatus,
    statusIdentity,
} = require('./railway-status-check.cjs');
const {
    capture,
    deploymentOutcome,
    newestDeployment,
    parseArguments: parseWaitArguments,
    readDeployments,
    waitForDeployment,
} = require('./railway-wait.cjs');

let temporaryDirectory;
let stateFile;

beforeEach(async () => {
    temporaryDirectory = await fs.mkdtemp(path.join(os.tmpdir(), 'ti4draft-railway-adversarial-'));
    stateFile = path.join(temporaryDirectory, 'deployment.json');
});

afterEach(async () => fs.rm(temporaryDirectory, {recursive: true, force: true}));

async function fakeCli(stdout, {stderr = '', status = 0} = {}) {
    const executable = path.join(temporaryDirectory, 'fake-railway');
    const argumentFile = path.join(temporaryDirectory, 'arguments.json');
    const source = [
        '#!/bin/sh',
        `printf '%s\\n' \"$@\" > '${argumentFile}'`,
        `printf '%s' '${stdout.replaceAll("'", "'\\''")}'`,
        stderr ? `printf '%s' '${stderr.replaceAll("'", "'\\''")}' >&2` : '',
        `exit ${status}`,
        '',
    ].join('\n');
    await fs.writeFile(executable, source, {mode: 0o700});
    return {argumentFile, executable};
}

test('status parser rejects malformed identities and mismatches every identity field', () => {
    for (const payload of [null, [], {}, {name: 'ti4draft'}, {
        name: 'ti4draft',
        service: {name: ''},
        environment: {name: 'production'},
    }]) {
        assert.throws(() => statusIdentity(payload), /malformed|does not identify/);
    }

    const actual = {project: 'ti4draft', service: 'ti4draft', environment: 'production'};
    for (const key of Object.keys(actual)) {
        assert.throws(() => assertIdentity(actual, {...actual, [key]: 'foreign'}), new RegExp(`${key} mismatch`));
    }
});

test('status parser supports the documented Railway identity shapes', () => {
    assert.deepEqual(statusIdentity({
        project: {name: 'ti4draft'},
        service: {name: 'ti4draft'},
        environment: {name: 'production'},
    }), {project: 'ti4draft', service: 'ti4draft', environment: 'production'});
    assert.deepEqual(statusIdentity({
        projectName: 'ti4draft',
        serviceName: 'ti4draft',
        environmentName: 'production',
    }), {project: 'ti4draft', service: 'ti4draft', environment: 'production'});
});

test('status argument parser rejects missing, duplicate, unknown, and positional arguments', () => {
    const valid = ['--project', 'ti4draft', '--service', 'ti4draft', '--environment', 'production'];
    assert.deepEqual(parseStatusArguments(valid), {
        project: 'ti4draft', service: 'ti4draft', environment: 'production',
    });
    for (const invalid of [
        valid.slice(0, -2),
        [...valid, '--project', 'other'],
        [...valid, '--unknown', 'value'],
        ['project', 'ti4draft', ...valid.slice(2)],
        [...valid, '--service'],
    ]) {
        assert.throws(() => parseStatusArguments(invalid), /Invalid arguments|Usage/);
    }
});

test('status CLI seam invokes exactly railway status --json and rejects bad CLI output', async () => {
    const good = await fakeCli(JSON.stringify({
        name: 'ti4draft', service: {name: 'ti4draft'}, environment: {name: 'production'},
    }));
    assert.equal(readStatus(good.executable).name, 'ti4draft');
    assert.deepEqual((await fs.readFile(good.argumentFile, 'utf8')).trim().split('\n'), ['status', '--json']);

    const malformed = await fakeCli('{not-json');
    assert.throws(() => readStatus(malformed.executable), /malformed JSON/);
    const failed = await fakeCli('', {stderr: 'permission denied', status: 7});
    assert.throws(() => readStatus(failed.executable), /permission denied/);
});

test('deployment CLI seam invokes the exact read-only list command and rejects bad output', async () => {
    const good = await fakeCli('[{"id":"current","status":"SUCCESS"}]');
    assert.deepEqual(readDeployments('ti4draft', good.executable), [{id: 'current', status: 'SUCCESS'}]);
    assert.deepEqual((await fs.readFile(good.argumentFile, 'utf8')).trim().split('\n'), [
        'deployment', 'list', '--service', 'ti4draft', '--limit', '1', '--json',
    ]);

    const malformed = await fakeCli('not-json');
    assert.throws(() => readDeployments('ti4draft', malformed.executable), /malformed JSON/);
    const failed = await fakeCli('', {stderr: 'not linked', status: 1});
    assert.throws(() => readDeployments('ti4draft', failed.executable), /not linked/);
});

test('wait argument parser rejects invalid modes, duplicates, unknowns, and invalid timeouts', () => {
    assert.deepEqual(parseWaitArguments([
        'wait', '--service', 'ti4draft', '--state-file', stateFile, '--timeout-seconds', '900',
    ]), {mode: 'wait', service: 'ti4draft', stateFile, timeoutSeconds: 900});
    for (const invalid of [
        ['remove', '--service', 'ti4draft', '--state-file', stateFile],
        ['wait', '--service', 'ti4draft', '--service', 'other', '--state-file', stateFile],
        ['wait', '--service', 'ti4draft', '--state-file', stateFile, '--unknown', 'x'],
        ['wait', '--service', 'ti4draft', '--state-file', stateFile, '--timeout-seconds', '0'],
        ['wait', '--service', 'ti4draft', '--state-file', stateFile, '--timeout-seconds', 'NaN'],
        ['capture', '--service', 'ti4draft', '--state-file', stateFile, '--timeout-seconds', '1'],
    ]) {
        assert.throws(() => parseWaitArguments(invalid), /Invalid arguments|Invalid timeout|Usage/);
    }
});

test('capture writes private valid state and preserves an explicit empty baseline', async () => {
    const state = await capture({service: 'ti4draft', stateFile}, () => []);
    assert.equal(state.baselineDeploymentId, null);
    assert.match(state.capturedAt, /^\d{4}-\d{2}-\d{2}T/);
    assert.equal((await fs.stat(stateFile)).mode & 0o777, 0o600);
    assert.deepEqual(JSON.parse(await fs.readFile(stateFile, 'utf8')), state);
});

test('wait rejects missing, malformed, foreign, and semantically invalid capture state', async () => {
    const invalidStates = [
        '{not-json',
        JSON.stringify({schemaVersion: 2, service: 'ti4draft', baselineDeploymentId: null}),
        JSON.stringify({schemaVersion: 1, service: 'other', baselineDeploymentId: null}),
        JSON.stringify({schemaVersion: 1, service: 'ti4draft', baselineDeploymentId: 4}),
        JSON.stringify({schemaVersion: 1, service: 'ti4draft', baselineDeploymentId: ''}),
    ];
    await assert.rejects(
        waitForDeployment(
            {service: 'ti4draft', stateFile, timeoutSeconds: 0.001},
            () => [],
            async () => {},
        ),
        /missing or malformed/,
    );
    for (const state of invalidStates) {
        await fs.writeFile(stateFile, state);
        await assert.rejects(
            waitForDeployment(
                {service: 'ti4draft', stateFile, timeoutSeconds: 0.001},
                () => [],
                async () => {},
            ),
            /missing or malformed/,
        );
    }
});

test('empty baseline still requires an observed deployment and cannot falsely pass on no result', async () => {
    await capture({service: 'ti4draft', stateFile}, () => []);
    let reads = 0;
    const result = await waitForDeployment(
        {service: 'ti4draft', stateFile, timeoutSeconds: 1},
        () => (++reads === 1 ? [] : [{id: 'first', status: 'success'}]),
        async () => {},
    );
    assert.deepEqual(result, {id: 'first', status: 'SUCCESS'});

    await assert.rejects(
        waitForDeployment(
            {service: 'ti4draft', stateFile, timeoutSeconds: 0.001},
            () => [],
            async () => new Promise((resolve) => setImmediate(resolve)),
        ),
        /No new successful Railway deployment/,
    );
});

test('baseline success is ignored and terminal statuses are case-insensitive', async () => {
    assert.equal(deploymentOutcome({id: 'old', status: 'SUCCESS'}, 'old'), 'wait');
    for (const status of ['failed', 'Crashed', 'cancelled', 'removed']) {
        const parsed = newestDeployment([{id: `new-${status}`, status}]);
        assert.equal(deploymentOutcome(parsed, 'old'), 'failure');
    }
});
