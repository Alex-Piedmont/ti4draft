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
    temporaryDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'ti4draft-railway-smoke-'));
    fakeCurl = path.join(temporaryDirectory, 'fake-curl.cjs');
    callsFile = path.join(temporaryDirectory, 'calls.log');
    fs.writeFileSync(fakeCurl, `#!/usr/bin/env node
const fs = require('node:fs');
const url = process.argv.at(-1);
fs.appendFileSync(process.env.FAKE_CURL_CALLS, url + '\\n');
if (process.env.FAKE_CURL_TRANSPORT_FAILURE === '1') process.exit(7);
process.stdout.write(url.endsWith(process.env.FAKE_CURL_FAIL_ENDPOINT || '__never__') ? '503' : '200');
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

test('checks the root and favicon through the injected curl-compatible client', () => {
    const result = run(['https://draft-production.up.railway.app/']);
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(fs.readFileSync(callsFile, 'utf8').trim().split('\n'), [
        'https://draft-production.up.railway.app/',
        'https://draft-production.up.railway.app/favicon-32x32.png',
    ]);
});

test('rejects missing and malformed origins before making any request', () => {
    for (const args of [[], ['http://example.com'], ['https://example.com/path'], ['not-a-url']]) {
        const result = run(args);
        assert.notEqual(result.status, 0);
    }
    assert.equal(fs.existsSync(callsFile), false);
});

test('fails and identifies a stable endpoint that does not return 200', () => {
    const result = run(['https://example.com'], {FAKE_CURL_FAIL_ENDPOINT: '/favicon-32x32.png'});
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /favicon-32x32\.png/);
    assert.match(result.stderr, /503/);
});

test('fails when the HTTP client reports a transport error', () => {
    const result = run(['https://example.com'], {FAKE_CURL_TRANSPORT_FAILURE: '1'});
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Request failed for \//);
});
