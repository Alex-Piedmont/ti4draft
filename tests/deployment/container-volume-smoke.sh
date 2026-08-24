#!/bin/sh

set -eu

IMAGE_NAME="ti4draft-volume-smoke:local"
FIRST_CONTAINER="ti4draft-volume-first-$$"
SECOND_CONTAINER="ti4draft-volume-second-$$"
VOLUME_DIR="$(mktemp -d)"
FAILURE_CONTAINER="ti4draft-volume-failure-$$"
UNWRITABLE_CONTAINER="ti4draft-volume-unwritable-$$"
MISSING_PATH_DIR="$(mktemp -d)"
UNWRITABLE_DIR="$(mktemp -d)"
RESULT_DIR="$(mktemp -d)"
FIXTURE_ID="$(jq -r '.id' data/test-drafts/draft.november2025.finished.json)"
FIXTURE_NAME="$(jq -r '.config.name' data/test-drafts/draft.november2025.finished.json)"
FIXTURE_FILE="$VOLUME_DIR/draft_$FIXTURE_ID.json"

cleanup() {
    docker rm -f "$FIRST_CONTAINER" "$SECOND_CONTAINER" "$FAILURE_CONTAINER" "$UNWRITABLE_CONTAINER" >/dev/null 2>&1 || true
    chmod 0755 "$UNWRITABLE_DIR" >/dev/null 2>&1 || true
    rm -rf "$VOLUME_DIR" "$MISSING_PATH_DIR" "$UNWRITABLE_DIR" "$RESULT_DIR"
}

trap cleanup EXIT INT TERM

wait_for_http() {
    endpoint="$1"
    attempts=0

    until curl --fail --silent --show-error "$endpoint" >/dev/null; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "Timed out waiting for $endpoint" >&2
            return 1
        fi
        sleep 1
    done
}

host_port() {
    mapping="$(docker port "$1" 8081/tcp)"
    printf '%s\n' "${mapping##*:}"
}

assert_draft_response() {
    response_file="$1"
    expected_id="$2"
    jq --exit-status \
        --arg id "$expected_id" \
        --arg name "$FIXTURE_NAME" \
        '.id == $id
            and .done == true
            and .config.name == $name
            and (.draft.players | length > 0)
            and (.factions | length > 0)
            and (.slices | length > 0)
            and (has("secrets") | not)' \
        "$response_file" >/dev/null
}

start_container() {
    name="$1"
    docker run --rm -d \
        --name "$name" \
        -e PORT=8081 \
        -e URL=https://example.up.railway.app \
        -e STORAGE=local \
        -e STORAGE_PATH=/data/drafts \
        -e VERSION=smoke \
        -v "$VOLUME_DIR:/data/drafts" \
        -p 127.0.0.1::8081 \
        "$IMAGE_NAME" >/dev/null
}

mkdir "$VOLUME_DIR/nested"
cp data/test-drafts/draft.november2025.finished.json "$FIXTURE_FILE"
printf '%s\n' 'ownership-marker' >"$VOLUME_DIR/nested/preexisting.marker"
fixture_checksum="$(shasum -a 256 "$FIXTURE_FILE" | awk '{print $1}')"

docker build --target production -t "$IMAGE_NAME" -f deploy/app/Dockerfile .

# Exercise directory creation independently of the bind-mount root already existing.
docker run --rm \
    -e STORAGE=local \
    -e STORAGE_PATH=/data/new/drafts \
    -v "$MISSING_PATH_DIR:/data" \
    "$IMAGE_NAME" \
    sh -c 'test "$(id -u):$(id -g)" = "1000:1000" && test -d /data/new/drafts && test -w /data/new/drafts && test "$(stat -c "%u:%g" /data/new/drafts)" = "1000:1000"'

docker run --rm --user root --entrypoint chown \
    -v "$VOLUME_DIR:/data/drafts" \
    "$IMAGE_NAME" 234:567 /data/drafts/nested
docker run --rm --user root --entrypoint chown \
    -v "$VOLUME_DIR:/data/drafts" \
    "$IMAGE_NAME" 123:456 /data/drafts/nested/preexisting.marker
nested_directory_owner_before="$(docker run --rm --user root --entrypoint stat \
    -v "$VOLUME_DIR:/data/drafts" \
    "$IMAGE_NAME" -c '%u:%g' /data/drafts/nested)"
nested_owner_before="$(docker run --rm --user root --entrypoint stat \
    -v "$VOLUME_DIR:/data/drafts" \
    "$IMAGE_NAME" -c '%u:%g' /data/drafts/nested/preexisting.marker)"

start_container "$FIRST_CONTAINER"
first_port="$(host_port "$FIRST_CONTAINER")"
wait_for_http "http://127.0.0.1:$first_port/"

root_owner="$(docker exec -u 0 "$FIRST_CONTAINER" stat -c '%u:%g' /data/drafts)"
if [ "$root_owner" != '1000:1000' ]; then
    echo "Expected storage root ownership 1000:1000; got $root_owner" >&2
    exit 1
fi

processes="$(docker top "$FIRST_CONTAINER" -eo uid,pid,args)"
for process in supervisord caddy php-fpm; do
    if ! printf '%s\n' "$processes" | awk -v process="$process" '$1 == 1000 && index($0, process) { found = 1 } END { exit ! found }'; then
        echo "Expected $process to run as UID 1000" >&2
        printf '%s\n' "$processes" >&2
        exit 1
    fi
done

docker exec "$FIRST_CONTAINER" php -r '
require "/code/vendor/autoload.php";
$raw = json_decode(file_get_contents("/code/data/test-drafts/draft.november2025.finished.json"), true);
$raw["id"] = "volume-smoke";
$draft = App\Draft\Draft::fromJson($raw);
(new App\Draft\Repository\LocalDraftRepository())->save($draft);
$loaded = (new App\Draft\Repository\LocalDraftRepository())->load("volume-smoke");
if ($loaded->id !== "volume-smoke") { exit(1); }
'

docker stop "$FIRST_CONTAINER" >/dev/null
start_container "$SECOND_CONTAINER"
second_port="$(host_port "$SECOND_CONTAINER")"
wait_for_http "http://127.0.0.1:$second_port/"
fixture_response="$RESULT_DIR/fixture-response.json"
saved_response="$RESULT_DIR/saved-response.json"
curl --fail --silent --show-error \
    --output "$fixture_response" \
    "http://127.0.0.1:$second_port/api/draft/$FIXTURE_ID"
curl --fail --silent --show-error \
    --output "$saved_response" \
    "http://127.0.0.1:$second_port/api/draft/volume-smoke"
assert_draft_response "$fixture_response" "$FIXTURE_ID"
assert_draft_response "$saved_response" 'volume-smoke'

fixture_checksum_after="$(shasum -a 256 "$FIXTURE_FILE" | awk '{print $1}')"
if [ "$fixture_checksum_after" != "$fixture_checksum" ]; then
    echo 'Pre-existing draft bytes changed during volume initialization.' >&2
    exit 1
fi

nested_owner_after="$(docker exec -u 0 "$SECOND_CONTAINER" stat -c '%u:%g' /data/drafts/nested/preexisting.marker)"
if [ "$nested_owner_after" != "$nested_owner_before" ]; then
    echo "Nested ownership changed from $nested_owner_before to $nested_owner_after" >&2
    exit 1
fi

nested_directory_owner_after="$(docker exec -u 0 "$SECOND_CONTAINER" stat -c '%u:%g' /data/drafts/nested)"
if [ "$nested_directory_owner_after" != "$nested_directory_owner_before" ]; then
    echo "Nested directory ownership changed from $nested_directory_owner_before to $nested_directory_owner_after" >&2
    exit 1
fi

# Ownership can be correct while directory permissions still deny writes to app:app.
chmod 0555 "$UNWRITABLE_DIR"
if docker run --name "$UNWRITABLE_CONTAINER" \
    -e STORAGE=local \
    -e STORAGE_PATH=/data/drafts \
    -v "$UNWRITABLE_DIR:/data/drafts" \
    "$IMAGE_NAME" true >/dev/null 2>&1; then
    echo 'Container unexpectedly started with storage that app:app cannot write.' >&2
    exit 1
fi
if docker logs "$UNWRITABLE_CONTAINER" 2>&1 | grep -q 'supervisord started'; then
    echo 'Supervisor started despite unwritable storage initialization.' >&2
    exit 1
fi
docker rm "$UNWRITABLE_CONTAINER" >/dev/null 2>&1 || true

if docker run --name "$FAILURE_CONTAINER" \
    -e STORAGE=local \
    -e STORAGE_PATH=/proc/ti4draft-impossible \
    "$IMAGE_NAME" >/dev/null 2>&1; then
    echo 'Container unexpectedly started with an impossible storage path.' >&2
    exit 1
fi
if docker logs "$FAILURE_CONTAINER" 2>&1 | grep -q 'supervisord started'; then
    echo 'Supervisor started despite impossible storage initialization.' >&2
    exit 1
fi
docker rm "$FAILURE_CONTAINER" >/dev/null 2>&1 || true

echo 'Container volume initialization smoke checks passed.'
