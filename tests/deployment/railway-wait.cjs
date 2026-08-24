#!/usr/bin/env node

const fs = require('node:fs/promises');
const {spawnSync} = require('node:child_process');

const FAILURE_STATES = new Set(['FAILED', 'CRASHED', 'CANCELLED', 'REMOVED']);

function parseArguments(argv) {
    const mode = argv[0];
    const values = {};
    const allowed = new Set(['service', 'state-file', 'timeout-seconds']);
    for (let index = 1; index < argv.length; index += 2) {
        const option = argv[index];
        const value = argv[index + 1];
        if (!option?.startsWith('--') || value === undefined) throw new Error('Invalid arguments.');
        const key = option.slice(2);
        if (!allowed.has(key) || Object.hasOwn(values, key)) throw new Error('Invalid arguments.');
        values[key] = value;
    }
    if (!['capture', 'wait'].includes(mode) || !values.service || !values['state-file']) {
        throw new Error('Usage: railway-wait.cjs <capture|wait> --service <name> --state-file <path> [--timeout-seconds 900]');
    }
    const timeoutSeconds = Number(values['timeout-seconds'] ?? 900);
    if (!Number.isFinite(timeoutSeconds) || timeoutSeconds <= 0 || (mode === 'capture' && values['timeout-seconds'])) {
        throw new Error('Invalid timeout.');
    }
    return {mode, service: values.service, stateFile: values['state-file'], timeoutSeconds};
}

function deploymentList(payload) {
    const deployments = Array.isArray(payload) ? payload : payload?.deployments;
    if (!Array.isArray(deployments)) throw new Error('Railway deployment JSON is malformed.');
    return deployments;
}

function newestDeployment(payload) {
    const deployment = deploymentList(payload)[0] ?? null;
    if (deployment === null) return null;
    const id = deployment.id;
    const status = String(deployment.status ?? '').toUpperCase();
    if (typeof id !== 'string' || !id || !status) throw new Error('Railway deployment entry is malformed.');
    return {id, status};
}

function deploymentOutcome(deployment, baselineId) {
    if (deployment === null || deployment.id === baselineId) return 'wait';
    if (deployment.status === 'SUCCESS') return 'success';
    if (FAILURE_STATES.has(deployment.status)) return 'failure';
    return 'wait';
}

function readDeployments(service, cli = process.env.RAILWAY_CLI_BIN || 'railway') {
    const result = spawnSync(cli, ['deployment', 'list', '--service', service, '--limit', '1', '--json'], {encoding: 'utf8'});
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(result.stderr.trim() || 'railway deployment list failed');
    try {
        return JSON.parse(result.stdout);
    } catch {
        throw new Error('railway deployment list returned malformed JSON.');
    }
}

async function capture({service, stateFile}, read = readDeployments) {
    const deployment = newestDeployment(read(service));
    const state = {
        schemaVersion: 1,
        service,
        baselineDeploymentId: deployment?.id ?? null,
        capturedAt: new Date().toISOString(),
    };
    await fs.writeFile(stateFile, `${JSON.stringify(state, null, 2)}\n`, {mode: 0o600});
    return state;
}

async function waitForDeployment({service, stateFile, timeoutSeconds}, read = readDeployments, sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds))) {
    let state;
    try {
        state = JSON.parse(await fs.readFile(stateFile, 'utf8'));
    } catch {
        throw new Error('Deployment capture state is missing or malformed.');
    }
    if (state.schemaVersion !== 1 || state.service !== service
        || !(state.baselineDeploymentId === null
            || (typeof state.baselineDeploymentId === 'string' && state.baselineDeploymentId.length > 0))) {
        throw new Error('Deployment capture state is missing or malformed.');
    }

    const deadline = Date.now() + timeoutSeconds * 1000;
    while (Date.now() <= deadline) {
        const deployment = newestDeployment(read(service));
        const outcome = deploymentOutcome(deployment, state.baselineDeploymentId);
        if (outcome === 'success') return deployment;
        if (outcome === 'failure') throw new Error(`Railway deployment ${deployment.id} reached ${deployment.status}.`);
        if (Date.now() >= deadline) break;
        await sleep(5000);
    }
    throw new Error(`No new successful Railway deployment appeared within ${timeoutSeconds} seconds.`);
}

async function main() {
    const arguments_ = parseArguments(process.argv.slice(2));
    if (arguments_.mode === 'capture') {
        const state = await capture(arguments_);
        console.log(`Captured deployment baseline: ${state.baselineDeploymentId ?? '(empty)'}`);
    } else {
        const deployment = await waitForDeployment(arguments_);
        console.log(`Railway deployment succeeded: ${deployment.id}`);
    }
}

if (require.main === module) {
    main().catch((error) => {
        console.error(error.message);
        process.exitCode = 1;
    });
}

module.exports = {
    capture,
    deploymentList,
    deploymentOutcome,
    newestDeployment,
    parseArguments,
    readDeployments,
    waitForDeployment,
};
