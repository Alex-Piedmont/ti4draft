#!/bin/sh

set -eu

IMAGE_ONE="ti4draft-image-audit:first"
IMAGE_TWO="ti4draft-image-audit:second"
CONTAINER_NAME="ti4draft-image-audit-$$"
RESULT_DIR="$(mktemp -d)"
MARKER='.image-audit-local-marker'

cleanup() {
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
    for directory in vendor node_modules tmp data/drafts test-results; do
        rm -f "$directory/$MARKER"
        rmdir "$directory" >/dev/null 2>&1 || true
    done
    rm -rf "$RESULT_DIR"
}

trap cleanup EXIT INT TERM

for directory in vendor node_modules tmp data/drafts test-results; do
    mkdir -p "$directory"
    printf '%s\n' 'must-not-enter-image' >"$directory/$MARKER"
done

docker build --target production -t "$IMAGE_ONE" -f deploy/app/Dockerfile .
docker build --target production -t "$IMAGE_TWO" -f deploy/app/Dockerfile .

package_manifest() {
    image="$1"
    output="$2"
    docker run --rm "$image" composer show --no-dev --format=json \
        | jq --sort-keys --compact-output '[.installed[] | {name, version}] | sort_by(.name)' \
        >"$output"
}

package_manifest "$IMAGE_ONE" "$RESULT_DIR/first-packages.json"
package_manifest "$IMAGE_TWO" "$RESULT_DIR/second-packages.json"
jq --sort-keys --compact-output '[.packages[] | {name, version}] | sort_by(.name)' composer.lock \
    >"$RESULT_DIR/locked-packages.json"

cmp "$RESULT_DIR/first-packages.json" "$RESULT_DIR/second-packages.json"
cmp "$RESULT_DIR/first-packages.json" "$RESULT_DIR/locked-packages.json"

docker run --rm --entrypoint sh "$IMAGE_TWO" -eu -c '
    test -f /code/index.php
    test -f /code/app/routes.php
    test -f /code/favicon-32x32.png
    test -f /code/vendor/autoload.php
    test -f /code/app/ApplicationTest.php
    test -d /code/tests/js
    test ! -e /code/.env
    test ! -e /code/.git
    test ! -e /code/node_modules
    test ! -e /code/tmp
    test ! -e /code/data/drafts
    test ! -e /code/.phpunit.cache
    test ! -e /code/.php-cs-fixer.cache
    test ! -e /code/test-results
    test ! -e /code/playwright-report
    test ! -e /code/supervisord.log
    test ! -e /code/supervisord.pid
    test ! -e /code/vendor/.image-audit-local-marker
    test ! -e /code/vendor/bin/phpunit
'

docker run --rm -d \
    --name "$CONTAINER_NAME" \
    -e PORT=8081 \
    -e URL=https://example.up.railway.app \
    -e STORAGE=local \
    -e STORAGE_PATH=/tmp \
    -e VERSION=audit \
    -p 127.0.0.1::8081 \
    "$IMAGE_TWO" >/dev/null

mapping="$(docker port "$CONTAINER_NAME" 8081/tcp)"
host_port="${mapping##*:}"
attempts=0
until curl --fail --silent --show-error "http://127.0.0.1:$host_port/" >/dev/null; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 30 ]; then
        echo 'Audited production image did not become healthy.' >&2
        exit 1
    fi
    sleep 1
done

curl --fail --silent --show-error "http://127.0.0.1:$host_port/favicon-32x32.png" >/dev/null
api_status="$(curl --silent --output "$RESULT_DIR/api-response" --write-out '%{http_code}' \
    "http://127.0.0.1:$host_port/api/draft/image-audit-missing")"
if [ "$api_status" != '404' ] || ! grep -q 'Draft not found' "$RESULT_DIR/api-response"; then
    echo 'Audited production image did not preserve the PHP API route.' >&2
    exit 1
fi

echo 'Production image determinism and content audit passed.'
