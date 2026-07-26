#!/usr/bin/env bash
# Entrypoint for the InsureHub backend container.
#
# Runs once at container start, before FrankenPHP takes over. Idempotent —
# safe to run on every restart.

set -euo pipefail

cd /app

# Ensure runtime-writable dirs exist AND are owned by www-data. Coolify
# may mount a volume over /app/storage for persistence, which shadows
# the dirs created at image-build time — leaving Blade with no place to
# write compiled views (crashes /up and every view render).
mkdir -p storage/framework/{cache,sessions,views} \
         storage/logs \
         storage/app/public \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX             storage bootstrap/cache

# Refuse to boot without an explicit DB_CONNECTION. Without this Laravel
# silently falls back to the default (SQLite at database/database.sqlite
# baked into the image) — writes go to ephemeral container storage and
# every restart re-runs migrations against a half-populated file, which
# looks like "duplicate column" errors in the log. Better to fail fast
# and force the deploy operator to wire the DB env vars.
if [[ -z "${DB_CONNECTION:-}" ]]; then
    echo "entrypoint: DB_CONNECTION is not set." >&2
    echo "entrypoint: set DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD in the deploy environment." >&2
    exit 1
fi

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

# Clear any stale caches from a previous deploy before rebuilding. Wrapped
# in `|| true` because on first boot the cache files don't exist yet and
# artisan's *:clear commands exit non-zero.
php artisan config:clear || true
php artisan route:clear  || true
php artisan view:clear   || true
php artisan event:clear  || true

# Cache config/routes/views/events for prod perf. Rebuilt every start
# because envvars can change between deploys. Each `|| true` prevents one
# broken sub-cache from killing the entrypoint and crash-looping the
# container (e.g., view:cache when resources/views is missing).
php artisan config:cache || true
php artisan route:cache  || true
php artisan view:cache   || true
php artisan event:cache  || true

# Run migrations. `--force` is required in non-interactive environments.
# The first deploy on a fresh DB will run every migration in order; every
# subsequent deploy is a no-op unless a new migration was added.
php artisan migrate --force --no-interaction

# Storage symlink (public/storage → storage/app/public). Idempotent.
php artisan storage:link || true

# Hand off (PID 1) to the CMD from the Dockerfile — this replaces the
# shell so signals reach FrankenPHP cleanly.
exec "$@"
