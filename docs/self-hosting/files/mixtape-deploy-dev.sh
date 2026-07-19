#!/usr/bin/env bash
# /usr/local/bin/mixtape-dev-deploy   — 755 root:root
#
# Rebuild the DEV site after uploading code to it. Run as your own admin user:
#   mixtape-dev-deploy              # rebuild + migrate
#   mixtape-dev-deploy --fresh      # rebuild + migrate:fresh --seed (drops the dev DB)
#
# THIS DOES NOT FETCH CODE. The dev site is not a git checkout — source arrives
# by SFTP from the workstation (IDE upload), so this script rebuilds whatever is
# already on disk. That is the opposite of the prod script, which fetches and
# resets a real checkout. If your code looks stale here, the upload is what did
# not happen; re-running this will not help.
#
# Corollary: do not run it while an upload is in flight, or you will build a
# half-uploaded tree. Nothing can detect that for you.
#
# ---------------------------------------------------------------------------
# Ownership model — deliberately looser than prod
#
#   /var/www/mixtape.dev     <admin-user>:www-data, dirs setgid so the group
#                            sticks. You edit over SFTP; www-data serves.
#   storage/, bootstrap/cache  group-writable (2775), so BOTH identities write.
#
# Prod runs at umask 027 precisely so www-data can never rewrite prod code. Dev
# inverts that on purpose: the box is LAN-only, and the whole point is that you
# and the runtime both write freely. Hence umask 002 (files 664, dirs 2775).
# Do not copy this mask back into the prod script.
#
# WHY THE NORMALIZE STEP EXISTS: php-fpm runs as www-data with its own umask, so
# files it creates at runtime (storage/logs/laravel.log,
# bootstrap/cache/*.php, a regenerated icon sprite) end up www-data:www-data 644
# — not group-writable. The next deploy then runs as you, cannot overwrite them,
# and fails in confusing places: `composer install` dies in package:discover
# because it cannot rewrite bootstrap/cache/packages.php. Re-normalizing at the
# start makes each run self-healing instead of a slow slide into a broken tree.

set -Eeuo pipefail

SITE=/var/www/mixtape.dev
NVM_DIR="$HOME/.nvm"
LOCKFILE=/tmp/mixtape-dev-deploy.lock
# NOTE the quotes: unquoted, the angle brackets in the placeholder are parsed as
# shell redirections, and an unedited copy dies with a baffling "No such file or
# directory" instead of saying what is wrong. The guard further down says it.
HEALTH_URL="https://<your-dev-host>/"   # <-- set this

# Deterministic PATH — do not inherit whatever the invoking shell had.
export PATH=/usr/local/bin:/usr/bin:/bin

# 002: files 664, dirs 775 — group (www-data) reads AND writes. See the header.
umask 002

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }
fail() { printf '\033[1;31mFATAL: %s\033[0m\n' "$*" >&2; exit 1; }

# `if`, not `[[ … ]] && fail`: under `set -e` that idiom exits the script when the
# condition is FALSE, because the list returns the test's non-zero status. It
# would abort here on every correctly-edited copy.
if [[ $HEALTH_URL == *"<your-dev-host>"* ]]; then
    fail "edit HEALTH_URL at the top of this script before using it"
fi

FRESH=0
case "${1:-}" in
    '')       ;;
    --fresh)  FRESH=1 ;;
    *)        fail "unknown argument '$1' (only --fresh is supported)" ;;
esac

# --- Concurrency ---------------------------------------------------------
# Two overlapping runs would interleave builds and migrations.
if [[ -z ${_MIXTAPE_DEV_DEPLOY_LOCKED:-} ]]; then
    export _MIXTAPE_DEV_DEPLOY_LOCKED=1
    exec flock -n "$LOCKFILE" "$0" "$@" \
        || fail "another dev deploy is already running (lock: $LOCKFILE)"
fi

# Running as root would create root-owned files that neither you nor www-data
# can fix, and would run composer/npm install hooks — a large amount of
# third-party code — with full privileges. Refuse.
[[ $(id -u) -ne 0 ]] || fail "do not run as root; run as your own admin user"

DEV_USER=$(id -un)
[[ -f $SITE/artisan ]] || fail "$SITE does not look like a Laravel app (no artisan)"
[[ -f $SITE/.env ]]    || fail "$SITE/.env missing — dev site not configured"

# The prod script refuses on a dirty tree. Here there is no git to ask, so the
# equivalent sanity check is simply that the upload left something coherent.
[[ -f $SITE/composer.json && -f $SITE/package-lock.json ]] \
    || fail "$SITE looks incomplete — is an upload still running?"

cd "$SITE"

log "Dev site: $SITE (as $DEV_USER)"

# --- Normalize runtime permissions ---------------------------------------
# See the header for why this is not optional. Needs sudo only because some of
# these files belong to www-data from the last runtime write; chown/chmod on a
# file you do not own is root-only.
log "Normalizing storage/ and bootstrap/cache ownership"
sudo chown -R "$DEV_USER:www-data" storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} +
sudo find storage bootstrap/cache -type f -exec chmod 664 {} +

# --- Node ---------------------------------------------------------------
# Source nvm explicitly: Debian's stock .bashrc returns early for
# non-interactive shells, so a scripted run never reaches the nvm block.
# `nvm use` with no argument reads the repo's .nvmrc.
[[ -s $NVM_DIR/nvm.sh ]] || fail "nvm not found at $NVM_DIR"
export NVM_DIR
# shellcheck disable=SC1091
. "$NVM_DIR/nvm.sh"
nvm use || fail "no Node matching .nvmrc installed — run: nvm install"
log "Node $(node -v) / npm $(npm -v)"

# --- PHP dependencies ----------------------------------------------------
# WITH dev dependencies, unlike prod: this is where tests and debugging tools
# are supposed to be available.
log "Composer (with dev dependencies)"
composer install --no-interaction --prefer-dist

log "npm ci"
npm ci

# `npm run build` also runs eslint/stylelint with --fix, which MUTATES source
# files. On prod that breaks the clean-tree check; here it is worse in a quieter
# way — the fix lands on the SERVER copy, the workstation copy stays unfixed,
# and the next SFTP upload silently reverts it. Lint on the workstation; the
# server only compiles.
log "Vite build (build-only: no lint --fix on the server copy)"
npm run type-check
npm run build-only

# The icon sprite (storage/app/public/sprite.svg) is gitignored AND not produced
# by the vite build. Skip this and every icon in the app renders empty.
log "Icon sprite"
npm run icons

log "Storage symlink"
ln -sfn "$SITE/storage/app/public" "$SITE/public/storage"

# --- Database ------------------------------------------------------------
if (( FRESH )); then
    warn "--fresh: DROPPING the dev database and re-seeding"
    php artisan migrate:fresh --seed --force
else
    log "Migrations"
    php artisan migrate --force
fi

# --- Caches --------------------------------------------------------------
# CLEAR ONLY — deliberately no config:cache / route:cache / view:cache.
#
# Prod caches everything, which is why editing prod's .env appears to do nothing
# until config:cache runs. Dev must behave the opposite way: you change .env or a
# config file and reload the page. Caching here would reintroduce that exact
# confusion on the box where you iterate most.
log "Clearing caches (dev runs uncached on purpose)"
php artisan optimize:clear

# --- Reload php-fpm ------------------------------------------------------
# Clears opcache so the new code is actually served. Note the dev site uses the
# default [www] pool, and a reload restarts every pool on the box — including
# prod's. It is graceful (workers finish in-flight requests), but be aware this
# is not a dev-only action.
log "Reloading php-fpm"
sudo /usr/bin/systemctl reload php8.4-fpm

# --- Health check --------------------------------------------------------
# -k because the LAN vhost uses a self-signed certificate by design; this is a
# liveness probe, not a TLS test.
log "Health check"
code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)
case "$code" in
    200|302) log "Health check OK (HTTP $code)" ;;
    *)       fail "health check returned HTTP $code — the dev site is not serving" ;;
esac

log "Dev deploy complete"
