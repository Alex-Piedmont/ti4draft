#!/usr/bin/env node

const {spawnSync} = require('node:child_process');

function parseArguments(argv) {
    const expected = {};
    const allowed = new Set(['project', 'service', 'environment']);
    for (let index = 0; index < argv.length; index += 2) {
        const option = argv[index];
        const value = argv[index + 1];
        if (!option?.startsWith('--') || value === undefined) throw new Error('Invalid arguments.');
        const key = option.slice(2);
        if (!allowed.has(key) || Object.hasOwn(expected, key)) throw new Error('Invalid arguments.');
        expected[key] = value;
    }
    if (!expected.project || !expected.service || !expected.environment) {
        throw new Error('Usage: railway-status-check.cjs --project <name> --service <name> --environment <name>');
    }
    return expected;
}

function statusIdentity(status) {
    if (!status || typeof status !== 'object') throw new Error('Railway status JSON is malformed.');
    const environmentNodes = status.environments?.edges?.map((edge) => edge?.node).filter(Boolean) ?? [];
    const serviceNodes = status.services?.edges?.map((edge) => edge?.node).filter(Boolean) ?? [];
    if (environmentNodes.length > 1 || serviceNodes.length > 1) {
        throw new Error('Railway status JSON is ambiguous; expected one linked environment and service.');
    }
    const project = status.project?.name ?? status.name ?? status.projectName;
    const service = status.service?.name ?? status.serviceName ?? serviceNodes[0]?.name;
    const environment = status.environment?.name ?? status.environmentName ?? environmentNodes[0]?.name;
    if (![project, service, environment].every((value) => typeof value === 'string' && value)) {
        throw new Error('Railway status JSON does not identify project, service, and environment.');
    }
    return {project, service, environment};
}

function assertIdentity(actual, expected) {
    for (const key of ['project', 'service', 'environment']) {
        if (actual[key] !== expected[key]) {
            throw new Error(`Railway ${key} mismatch: expected ${expected[key]}, got ${actual[key]}`);
        }
    }
}

function readStatus(cli = process.env.RAILWAY_CLI_BIN || 'railway') {
    const result = spawnSync(cli, ['status', '--json'], {encoding: 'utf8'});
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(result.stderr.trim() || 'railway status failed');
    try {
        return JSON.parse(result.stdout);
    } catch {
        throw new Error('railway status returned malformed JSON.');
    }
}

function main() {
    const expected = parseArguments(process.argv.slice(2));
    const actual = statusIdentity(readStatus());
    assertIdentity(actual, expected);
    console.log(`Railway identity confirmed: ${actual.project}/${actual.environment}/${actual.service}`);
}

if (require.main === module) {
    try {
        main();
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}

module.exports = {assertIdentity, parseArguments, readStatus, statusIdentity};
