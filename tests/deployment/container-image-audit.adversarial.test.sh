#!/bin/sh

set -eu

CONTEXT_DIR="$(mktemp -d)"
OUTPUT_DIR="$(mktemp -d)"
VALIDATION_DIR="$(mktemp -d)"

cleanup() {
    rm -rf "$CONTEXT_DIR" "$OUTPUT_DIR" "$VALIDATION_DIR"
}

trap cleanup EXIT INT TERM

cp .dockerignore "$CONTEXT_DIR/.dockerignore"

mkdir -p \
    "$CONTEXT_DIR/.git" \
    "$CONTEXT_DIR/app" \
    "$CONTEXT_DIR/vendor" \
    "$CONTEXT_DIR/node_modules" \
    "$CONTEXT_DIR/tmp" \
    "$CONTEXT_DIR/data/drafts" \
    "$CONTEXT_DIR/.phpunit.cache" \
    "$CONTEXT_DIR/.php-cs-fixer.cache" \
    "$CONTEXT_DIR/test-results" \
    "$CONTEXT_DIR/playwright-report" \
    "$CONTEXT_DIR/coverage" \
    "$CONTEXT_DIR/reports" \
    "$CONTEXT_DIR/tests"

printf '%s\n' 'FROM scratch' 'COPY . /context' >"$CONTEXT_DIR/Dockerfile"
printf '%s\n' 'APP_SECRET=must-not-enter-context' >"$CONTEXT_DIR/.env"
printf '%s\n' 'APP_SECRET=must-not-enter-context' >"$CONTEXT_DIR/.env.local"
printf '%s\n' 'APP_SECRET=example-only' >"$CONTEXT_DIR/.env.example"
printf '%s\n' 'runtime-source' >"$CONTEXT_DIR/app/runtime.php"
printf '%s\n' 'source-test' >"$CONTEXT_DIR/tests/source-test.php"

for path in \
    .git/config \
    vendor/local-dependency \
    node_modules/local-dependency \
    tmp/local-temporary-file \
    data/drafts/draft_local.json \
    draft_root.json \
    .phpunit.cache/result \
    .php-cs-fixer.cache/result \
    test-results/result \
    playwright-report/result \
    coverage/result \
    reports/result \
    supervisord.log \
    supervisord.pid; do
    printf '%s\n' 'must-not-enter-context' >"$CONTEXT_DIR/$path"
done

docker build --quiet --output "type=local,dest=$OUTPUT_DIR" "$CONTEXT_DIR" >/dev/null

test -f "$OUTPUT_DIR/context/app/runtime.php"
test -f "$OUTPUT_DIR/context/tests/source-test.php"
test -f "$OUTPUT_DIR/context/.env.example"

for path in \
    .env \
    .env.local \
    .git \
    vendor \
    node_modules \
    tmp \
    data/drafts \
    draft_root.json \
    .phpunit.cache \
    .php-cs-fixer.cache \
    test-results \
    playwright-report \
    coverage \
    reports \
    supervisord.log \
    supervisord.pid; do
    if [ -e "$OUTPUT_DIR/context/$path" ]; then
        echo "Excluded build-context path was exported: $path" >&2
        exit 1
    fi
done

echo 'Adversarial Docker build-context audit passed.'

cp composer.json composer.lock "$VALIDATION_DIR/"
printf '%s\n' \
    'FROM composer:2' \
    'WORKDIR /app' \
    'COPY composer.json composer.lock ./' \
    'RUN composer validate --strict' \
    >"$VALIDATION_DIR/Dockerfile"

docker build --quiet "$VALIDATION_DIR" >/dev/null

sed 's/"ext-json": "\*"/"ext-json": "*", "ext-mbstring": "*"/' \
    "$VALIDATION_DIR/composer.json" >"$VALIDATION_DIR/composer.json.stale"
mv "$VALIDATION_DIR/composer.json.stale" "$VALIDATION_DIR/composer.json"

if docker build --quiet "$VALIDATION_DIR" >/dev/null 2>&1; then
    echo 'Strict Composer validation accepted an inconsistent lockfile.' >&2
    exit 1
fi

echo 'Adversarial Composer lock consistency audit passed.'
