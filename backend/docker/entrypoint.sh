#!/usr/bin/env bash
# Entrypoint for the InsureHub backend container.
#
# Runs once at container start, before FrankenPHP takes over. Idempotent —
# safe to run on every restart.

set -euo pipefail

cd /app

# Wait for the database to be reachable before running migrations. Coolify
# starts services in parallel; without this we race and crash-loop until
# MySQL is up.
if [[ -n "${DB_HOST:-}" ]]; then
    tries=30
    while ! php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306};dbname=${DB_DATABASE:-insurehub}', '${DB_USERNAME:-root}', getenv('DB_PASSWORD') ?: ''); exit(0); } catch (Throwable) { exit(1); }" 2>/dev/null; do
        tries=$((tries - 1))
        if [[ $tries -le 0 ]]; then
            echo "entrypoint: gave up waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}" >&2
            exit 1
        fi
        echo "entrypoint: waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306} … ($tries retries left)"
        sleep 2
    done
fi

# Cache config/routes/views/events for prod perf. Rebuilt every start
# because envvars can change between deploys.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

# Run migrations. `--force` is required in non-interactive environments.
# The first deploy on a fresh DB will run every migration in order; every
# subsequent deploy is a no-op unless a new migration was added.
php artisan migrate --force --no-interaction

# Storage symlink (public/storage → storage/app/public). Idempotent.
php artisan storage:link || true

# Hand off (PID 1) to the CMD from the Dockerfile — this replaces the
# shell so signals reach FrankenPHP cleanly.
exec "$@"
