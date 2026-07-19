#!/usr/bin/env bash
# /usr/local/bin/mixtape-prod-deploy   — 750 root:mixtape-deploy
#
# Deploy to the production site. Run as the deploy user:
#   sudo -u mixtape-deploy /usr/local/bin/mixtape-prod-deploy          # origin/main
#   sudo -u mixtape-deploy /usr/local/bin/mixtape-prod-deploy <sha>    # a specific commit
#
# The optional SHA is the rollback mechanism: pass a known-good commit to put
# prod back on it. It must already exist on origin (this fetches, it never
# invents commits), and it is checked out detached — the next argument-less
# deploy returns to origin/main.
#
# WHY NOT ROOT: composer install and npm ci execute a large amount of
# third-party install code (npm postinstall hooks in particular). As root, one
# compromised transitive dependency owns the machine. Here that code runs
# unprivileged as mixtape-deploy, which can write nothing outside the site.
#
# WHY THIS LIVES OUTSIDE THE GIT TREE: the script git-resets the very checkout
# it would otherwise live in. Bash reads scripts incrementally, so rewriting the
# running file mid-execution can make it jump to garbage. Installed copy = safe.
#
# Ownership model:
#   /var/www/mixtape.prod        mixtape-deploy:www-data  dirs 2750 files 640
#     -> www-data reads and executes the app, but cannot rewrite prod code
#   storage/, bootstrap/cache    www-data:www-data        dirs 2770
#     -> both write; mixtape-deploy is in the www-data group (dirs are group-
#        writable; this script runs at umask 027, so files stay group-read-only)
#
# The only privileged operations are the two sudoers-allowlisted commands below:
# reloading php-fpm, and running artisan as www-data.

set -Eeuo pipefail

SITE=/var/www/mixtape.prod
BRANCH=main
NVM_DIR=/home/mixtape-deploy/.nvm
DEPLOY_USER=mixtape-deploy
LOCKFILE=/tmp/mixtape-prod-deploy.lock
HEALTH_URL=https://<your-domain>/   # <-- set this

# Deterministic PATH — do not inherit whatever the invoking shell had.
export PATH=/usr/local/bin:/usr/bin:/bin

# 027: files 640, dirs 750 — group (www-data) reads, never writes. This is what
# keeps prod code unwritable by the web user, so do NOT relax it to 002. The one
# file this script creates under storage/ (the icon sprite) only needs to be
# READ by www-data, and 640 with group www-data grants exactly that; the setgid
# bit on storage/ supplies the group.
umask 027

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }
fail() { printf '\033[1;31mFATAL: %s\033[0m\n' "$*" >&2; exit 1; }

# --- Concurrency ---------------------------------------------------------
# Two overlapping deploys would interleave git resets, builds and migrations.
# Re-exec once under flock; the inner run holds the lock for its lifetime.
if [[ -z ${_MIXTAPE_DEPLOY_LOCKED:-} ]]; then
    export _MIXTAPE_DEPLOY_LOCKED=1
    exec flock -n "$LOCKFILE" "$0" "$@" \
        || fail "another deploy is already running (lock: $LOCKFILE)"
fi

# Running this as the wrong user silently produces a tree www-data can't read,
# or root-owned files the deploy user can never fix. Refuse instead.
[[ $(id -un) == "$DEPLOY_USER" ]] || fail "run as $DEPLOY_USER, not $(id -un)"
[[ -d $SITE/.git ]] || fail "$SITE is not a git checkout"

TARGET_REF="${1:-}"
if [[ -n $TARGET_REF ]]; then
    [[ $TARGET_REF =~ ^[0-9a-f]{7,40}$ ]] || fail "not a commit SHA: '$TARGET_REF'"
fi

cd "$SITE"

# --- Node ---------------------------------------------------------------
# Source nvm explicitly rather than relying on shell init: Debian's stock
# .bashrc returns early for non-interactive shells, so a scripted `bash -lc`
# never reaches the nvm block. `nvm use` with no argument reads the repo's
# .nvmrc (currently "26"), so bumping Node is a repo change plus one
# `nvm install` as this user — this script needs no edit.
[[ -s $NVM_DIR/nvm.sh ]] || fail "nvm not found at $NVM_DIR"
export NVM_DIR
# shellcheck disable=SC1091
. "$NVM_DIR/nvm.sh"
nvm use || fail "no Node matching .nvmrc installed — run: nvm install"
log "Node $(node -v) / npm $(npm -v)"

ARTISAN=(sudo -u www-data /usr/bin/php "$SITE/artisan")

# --- Maintenance mode ----------------------------------------------------
# Snapshot the state BEFORE touching it. If someone put the site into
# maintenance by hand (an incident, a paused rollout), a deploy must not
# silently un-pause it.
if [[ -f storage/framework/maintenance.php ]]; then
    was_down=1
    log "Site was ALREADY in maintenance — it will stay down after this deploy"
else
    was_down=0
fi

log "Maintenance mode on"
# May fail if the app is already down or not yet bootable — never abort on it.
"${ARTISAN[@]}" down --render="errors::503" || true

# Lift maintenance ONLY on success, and only if we put it down ourselves.
# A failed deploy deliberately leaves the site in maintenance: serving new code
# against a half-applied migration is worse than showing a maintenance page.
finish() {
    local rc=$?
    if (( rc == 0 )); then
        if (( was_down == 0 )); then
            "${ARTISAN[@]}" up || true
        else
            warn "Leaving maintenance mode ON (site was already down before this deploy)."
        fi
    else
        warn ""
        warn "DEPLOY FAILED (exit $rc) — site left in MAINTENANCE MODE on purpose."
        warn "Fix the cause, then re-run the deploy, or roll back:"
        warn "    sudo -u $DEPLOY_USER $0 <last-good-sha>"
        warn "To lift maintenance by hand without deploying:"
        warn "    sudo -u www-data /usr/bin/php $SITE/artisan up"
    fi
    return $rc
}
trap finish EXIT

log "Fetching origin"
git fetch --prune origin
# Prod is deploy-only, never edited in place. A dirty tree means someone hand-
# patched the box; reset --hard would silently destroy it, so stop instead.
git diff --quiet || fail "working tree dirty; refusing to deploy"

if [[ -n $TARGET_REF ]]; then
    git cat-file -e "${TARGET_REF}^{commit}" 2>/dev/null \
        || fail "commit $TARGET_REF not found on origin — push it first"
    log "Deploying pinned commit $TARGET_REF (detached; rollback mode)"
    git checkout --detach "$TARGET_REF"
    git reset --hard "$TARGET_REF"
else
    log "Deploying origin/$BRANCH"
    git checkout "$BRANCH"
    git reset --hard "origin/$BRANCH"
fi
log "Now at $(git rev-parse --short HEAD) — $(git log -1 --format=%s)"

log "Composer (no dev, optimized autoloader)"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

log "npm ci"
npm ci

# `npm run build` also runs eslint/stylelint with --fix, which MUTATES tracked
# source files and would leave the tree dirty, breaking the next deploy's clean-
# tree check above. Linting is a dev-machine gate; prod only compiles.
log "Vite build (build-only: no lint --fix on prod)"
npm run type-check
npm run build-only

# The icon sprite (storage/app/public/sprite.svg) is gitignored AND not produced
# by the vite build. Skip this and every icon in the app renders empty.
log "Icon sprite"
npm run icons

log "Storage symlink"
# NOT `artisan storage:link`: that would run as www-data, which cannot write
# into public/ (750, deploy-owned) — by design. public/storage belongs to the
# deployed tree, so the deploy user creates it. `ln -sfn` is exactly what
# storage:link does, minus the framework bootstrap.
ln -sfn "$SITE/storage/app/public" "$SITE/public/storage"

log "Migrations"
"${ARTISAN[@]}" migrate --force

log "Caching config/routes/views/events"
"${ARTISAN[@]}" optimize:clear
"${ARTISAN[@]}" config:cache
"${ARTISAN[@]}" route:cache
"${ARTISAN[@]}" view:cache
"${ARTISAN[@]}" event:cache

log "Reloading php-fpm (clears opcache for the prod pool)"
sudo /usr/bin/systemctl reload php8.4-fpm

# --- Health check --------------------------------------------------------
# Maintenance mode is still ON here, so a healthy app answers 503 from the
# maintenance renderer. Either 200 or 503 proves nginx -> php-fpm -> Laravel
# still works; 502/504 means the stack is broken and we must NOT lift the
# maintenance page over it.
log "Health check"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)
case "$code" in
    200|503) log "Health check OK (HTTP $code)" ;;
    *)       fail "health check returned HTTP $code — refusing to lift maintenance" ;;
esac

log "Deploy complete — $(git rev-parse --short HEAD)"
# `finish` (EXIT trap) lifts maintenance mode if we put it down.
