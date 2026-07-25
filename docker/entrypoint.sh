#!/usr/bin/env sh
#
# app container entrypoint — brings the app up from nothing on `docker compose up`.
# Idempotent: safe to re-run on every restart. The source (incl. vendor/) is
# bind-mounted, so this only fills the gaps a laptop-local, offline dev box needs.
set -e

cd /var/www

# 1. PHP deps. Normally the bind-mounted vendor/ from your Mac is reused as-is
#    (it is pure PHP, portable to the container's 8.4). Only install when it is
#    genuinely absent — that path also makes the setup work on a clean checkout.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing — running composer install…"
    composer install --no-interaction --prefer-dist
fi

# 2. App key. Reuse the one already in the bind-mounted .env; mint one only if it
#    is genuinely unset (fresh checkout) — this writes to your real .env.
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "[entrypoint] generating APP_KEY…"
    php artisan key:generate --force
fi

# 3. Make the runtime dirs writable — they are bind-mounted from the host and
#    php-fpm runs as www-data.
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

# 4. Drop any config/route/view cache carried over from your Mac runs, so the
#    compose env overrides (Postgres, APP_URL, …) actually take effect. None of
#    these touch the DB, so they are safe before the schema exists.
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear  >/dev/null 2>&1 || true
php artisan view:clear   >/dev/null 2>&1 || true
php artisan package:discover --ansi >/dev/null 2>&1 || true

# 5. Wait for Postgres to accept connections before migrating.
echo "[entrypoint] waiting for postgres at ${DB_HOST}:${DB_PORT}…"
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" >/dev/null 2>&1; do
    sleep 1
done

# 6. Migrate. Detect a brand-new database BEFORE migrating (migrate:status exits
#    non-zero when the migrations table doesn't exist yet) so we seed the demo
#    library exactly once — on first boot — and never re-seed a populated DB
#    (which would collide on unique columns). See DatabaseSeeder / LibrarySeeder.
FRESH=0
php artisan migrate:status >/dev/null 2>&1 || FRESH=1
php artisan migrate --force
if [ "$FRESH" = "1" ]; then
    echo "[entrypoint] fresh database — seeding demo library (login: Ashaltiriak / passwort)…"
    php artisan db:seed --force
fi

# 7. Public storage symlink for served assets (idempotent).
php artisan storage:link >/dev/null 2>&1 || true

echo "[entrypoint] ready — http://localhost:8080"
exec "$@"
