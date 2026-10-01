#!/bin/sh
set -eu

HOST="${MANTICORE_HOST:-manticore}"
PORT="${MANTICORE_PORT:-9308}"
APP_BIND_HOST="${APP_HOST:-0.0.0.0}"
APP_BIND_PORT="${APP_PORT:-8081}"
BOOTSTRAP_FLAG="${BOOTSTRAP_FLAG:-/app/storage/state/.bootstrapped}"

echo "Waiting for Manticore at ${HOST}:${PORT}..."
i=0
until curl -sf "http://${HOST}:${PORT}/" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 90 ]; then
        echo "Manticore did not become ready in time" >&2
        exit 1
    fi
    sleep 1
done
echo "Manticore is ready."

mkdir -p /app/storage/logs /app/storage/state /app/storage/imports /app/public/images/uploads

if [ "${FORCE_BOOTSTRAP:-0}" = "1" ] || [ ! -f "${BOOTSTRAP_FLAG}" ]; then
    echo "Bootstrapping demo catalog..."
    php /app/bin/bootstrap-demo.php
    touch "${BOOTSTRAP_FLAG}"
    echo "Bootstrap complete."
else
    echo "Bootstrap already done; skipping (set FORCE_BOOTSTRAP=1 to re-run)."
fi

echo "Starting PHP app on ${APP_BIND_HOST}:${APP_BIND_PORT}"
exec php -S "${APP_BIND_HOST}:${APP_BIND_PORT}" -t public
