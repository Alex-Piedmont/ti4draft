#!/bin/sh

set -eu

if [ "$#" -ne 1 ]; then
    echo 'Usage: railway-smoke.sh https://generated-domain' >&2
    exit 2
fi

base_url="$(node -e '
const value = process.argv[1];
if (value !== value.trim()) process.exit(1);
let parsed;
try { parsed = new URL(value); } catch { process.exit(1); }
if (parsed.protocol !== "https:"
    || !parsed.hostname
    || parsed.username
    || parsed.password
    || (parsed.pathname !== "/" && parsed.pathname !== "")
    || parsed.search
    || parsed.hash) process.exit(1);
process.stdout.write(parsed.origin);
' "$1")" || {
    echo 'Base URL must be a valid HTTPS origin without credentials, path, query, or fragment.' >&2
    exit 2
}

curl_bin="${RAILWAY_SMOKE_CURL:-curl}"

for endpoint in / /favicon-32x32.png; do
    status="$("$curl_bin" --silent --show-error --output /dev/null --write-out '%{http_code}' "$base_url$endpoint")" || {
        echo "Request failed for $endpoint" >&2
        exit 1
    }
    if [ "$status" != '200' ]; then
        echo "Expected HTTP 200 from $endpoint; got $status" >&2
        exit 1
    fi
done

echo "Railway HTTP smoke passed for $base_url"
