#!/bin/sh

set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo 'The container entrypoint must start as root to prepare persistent storage.' >&2
    exit 1
fi

# Supervisor reopens these descriptors for Caddy's log stream after privileges drop.
chown app:app /dev/stdout /dev/stderr

if [ "${STORAGE:-}" = 'local' ]; then
    storage_path="${STORAGE_PATH:-}"
    if [ -z "$storage_path" ]; then
        echo 'STORAGE_PATH is required when STORAGE=local.' >&2
        exit 1
    fi

    mkdir -p "$storage_path"
    chown app:app "$storage_path"

    if ! su-exec app:app test -w "$storage_path"; then
        echo "STORAGE_PATH is not writable by app:app: $storage_path" >&2
        exit 1
    fi
fi

exec su-exec app:app "$@"
