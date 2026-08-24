#!/bin/sh

set -eu

IMAGE_NAME="ti4draft-http-smoke:local"
INJECTED_CONTAINER="ti4draft-http-injected-$$"
FALLBACK_CONTAINER="ti4draft-http-fallback-$$"
TLS_DIR="$(mktemp -d)"

cleanup() {
    docker rm -f "$INJECTED_CONTAINER" "$FALLBACK_CONTAINER" >/dev/null 2>&1 || true
    rm -rf "$TLS_DIR"
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
    container="$1"
    container_port="$2"
    mapping="$(docker port "$container" "$container_port/tcp")"
    printf '%s\n' "${mapping##*:}"
}

docker build --target production -t "$IMAGE_NAME" -f deploy/app/Dockerfile .

docker run --rm -d \
    --name "$INJECTED_CONTAINER" \
    -e PORT=8081 \
    -e URL=https://example.up.railway.app \
    -e STORAGE=local \
    -e STORAGE_PATH=/tmp \
    -e VERSION=smoke \
    -p 127.0.0.1::8081 \
    "$IMAGE_NAME" >/dev/null

injected_port="$(host_port "$INJECTED_CONTAINER" 8081)"
injected_base="http://127.0.0.1:$injected_port"
wait_for_http "$injected_base/"
curl --fail --silent --show-error "$injected_base/favicon-32x32.png" >/dev/null
php_route_body="$TLS_DIR/php-route-body"
php_route_status="$(curl --silent --output "$php_route_body" --write-out '%{http_code}' "$injected_base/d/http-smoke")"
if [ "$php_route_status" != '404' ]; then
    echo "Expected the PHP draft route to return its application-level 404; got $php_route_status" >&2
    exit 1
fi
if ! grep -q 'Draft not found' "$php_route_body"; then
    echo "Expected the PHP draft route to render the application's Draft not found response" >&2
    exit 1
fi

docker run --rm -d \
    --name "$FALLBACK_CONTAINER" \
    -e URL=https://milty.localhost \
    -e STORAGE=local \
    -e STORAGE_PATH=/tmp \
    -e VERSION=smoke \
    -p 127.0.0.1::80 \
    -p 127.0.0.1::443 \
    "$IMAGE_NAME" >/dev/null

fallback_port="$(host_port "$FALLBACK_CONTAINER" 80)"
fallback_base="http://127.0.0.1:$fallback_port"
wait_for_http "$fallback_base/"

tls_port="$(host_port "$FALLBACK_CONTAINER" 443)"
docker cp \
    "$FALLBACK_CONTAINER:/home/app/.local/share/caddy/pki/authorities/local/root.crt" \
    "$TLS_DIR/root.crt" >/dev/null
curl --fail --silent --show-error --cacert "$TLS_DIR/root.crt" \
    --resolve "milty.localhost:$tls_port:127.0.0.1" \
    "https://milty.localhost:$tls_port/" >/dev/null

echo "Container HTTP networking smoke checks passed."
