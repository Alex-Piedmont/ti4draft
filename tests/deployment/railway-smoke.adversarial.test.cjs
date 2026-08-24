const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {afterEach, beforeEach, test} = require('node:test');
const {spawnSync} = require('node:child_process');

const smokeScript = path.join(__dirname, 'railway-smoke.sh');
let temporaryDirectory;
let fakeCurl;
let callsFile;

beforeEach(() => {
    temporaryDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'ti4draft-smoke-adversarial-'));
    fakeCurl = path.join(temporaryDirectory, 'fake-curl.cjs');
    callsFile = path.join(temporaryDirectory, 'calls.log');
    fs.writeFileSync(fakeCurl, `#!/usr/bin/env node
const fs = require('node:fs');
const url = process.argv.at(-1);
fs.appendFileSync(process.env.FAKE_CURL_CALLS, url + '\\n');
process.stdout.write(url.endsWith(process.env.FAKE_CURL_FAIL_ENDPOINT || '__never__') ? '500' : '200');
`);
    fs.chmodSync(fakeCurl, 0o755);
});

afterEach(() => fs.rmSync(temporaryDirectory, {recursive: true, force: true}));

function run(args, overrides = {}) {
    return spawnSync('sh', [smokeScript, ...args], {
        encoding: 'utf8',
        env: {
            ...process.env,
            RAILWAY_SMOKE_CURL: fakeCurl,
            FAKE_CURL_CALLS: callsFile,
            ...overrides,
        },
    });
}

test('rejects credentials, query strings, fragments, and extra positional arguments before requesting', () => {
    const invalidArguments = [
        ['https://user:secret@example.com'],
        ['https://example.com?target=other'],
        ['https://example.com#fragment'],
        ['https://example.com', 'https://other.example.com'],
    ];

    for (const arguments_ of invalidArguments) {
        const result = run(arguments_);
        assert.notEqual(result.status, 0, `unexpected success for ${arguments_.join(' ')}`);
    }
    assert.equal(fs.existsSync(callsFile), false);
});

test('rejects an origin padded with whitespace instead of silently retargeting it', () => {
    const result = run(['  https://example.com\n']);

    assert.notEqual(result.status, 0);
    assert.equal(fs.existsSync(callsFile), false);
});

test('stops after a failed root check and never probes the favicon', () => {
    const result = run(['https://example.com'], {FAKE_CURL_FAIL_ENDPOINT: '/'});

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Expected HTTP 200 from \/; got 500/);
    assert.deepEqual(fs.readFileSync(callsFile, 'utf8').trim().split('\n'), ['https://example.com/']);
});

test('reports a missing injected HTTP client as a request failure', () => {
    const result = run(['https://example.com'], {RAILWAY_SMOKE_CURL: path.join(temporaryDirectory, 'missing-curl')});

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Request failed for \/$/m);
    assert.equal(fs.existsSync(callsFile), false);
});
