#!/usr/bin/env sh
set -e

# Ensure an .env exists (env vars from compose still take precedence at runtime).
[ -f .env ] || cp .env.example .env

# Generate an app key if one wasn't provided via the environment.
if [ -z "$APP_KEY" ]; then
  php artisan key:generate --force || true
fi

# Wait for the database to accept connections.
echo "Waiting for database at ${DB_HOST}:${DB_PORT}…"
until php -r "exit(@fsockopen(getenv('DB_HOST'), (int)getenv('DB_PORT')) ? 0 : 1);" 2>/dev/null; do
  sleep 2
done

# Only the web container migrates; queue/scheduler set RUN_MIGRATIONS=false so
# they don't race each other on boot.
if [ "${RUN_MIGRATIONS:-true}" != "false" ]; then
  php artisan migrate --force
fi

# Seed roles/permissions + demo data on first boot only.
if [ "$SEED_ON_BOOT" = "true" ]; then
  php artisan db:seed --force || true
fi

# public/storage lives in the image layer, not the storage volume, so it has
# to be recreated on every boot — a link made from outside the container is
# lost on the next recreate. Harmless with FILESYSTEM_DISK=local (the local
# disk has serve=true and Laravel routes /storage itself), but required if the
# public disk is ever used.
php artisan storage:link || true

php artisan config:cache || true
php artisan route:cache || true

exec "$@"
