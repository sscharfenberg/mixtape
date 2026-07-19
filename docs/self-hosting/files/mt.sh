#!/usr/bin/env bash
# mt — run commands against the MixTape sites on the server, from your
#      workstation.   Install as ~/.local/bin/mt (chmod +x), not on the server.
#
#   mt artisan down --dev            # dev site into maintenance
#   mt artisan migrate --prod        # migrate production
#   mt artisan route:list --dev      # pipes cleanly: | grep song
#   mt tinker --prod
#   mt logs -f --dev
#
# The target flag may appear ANYWHERE in the line — `mt artisan down --dev` and
# `mt --dev artisan down` are the same command. It is stripped before the rest
# is forwarded, so artisan never sees it.
#
# DEFAULT IS DEV, ON PURPOSE. Production is only ever touched when you type
# --prod. A forgotten flag can then only hit the throwaway site.
#
# WHY THE TWO TARGETS RUN AS DIFFERENT USERS: this mirrors the ownership model
# the deploy scripts establish, and is not incidental.
#
#   dev   /var/www/mixtape.dev   <admin-user>:www-data, group-writable (2775)
#         -> runs as you, directly. You own the tree; artisan writing as you is
#            exactly right.
#   prod  /var/www/mixtape.prod  mixtape-deploy:www-data, dirs 2750
#         -> your account is NOT in www-data and cannot even read the tree.
#            Runs via `sudo -u www-data`, the same invocation the prod deploy
#            script uses, so everything artisan writes (logs, compiled views,
#            framework cache) stays owned by the runtime user. Running prod
#            artisan as any other identity leaves behind files www-data cannot
#            rewrite, and the failure surfaces much later somewhere confusing.
#
# The prod sudo hop prompts for YOUR password — mixtape-deploy's NOPASSWD rule
# belongs to that account, not yours. That prompt is a feature here: it is one
# more beat of friction between a typo and production.
#
# This does NOT deploy. Deploys have guards (unpushed-commit check,
# maintenance-mode handling) that a passthrough wrapper has no business
# duplicating — call mixtape-prod-deploy / mixtape-dev-deploy for those.
#
# Tab completion for the subcommands is in `_mt` alongside this file.

set -Eeuo pipefail

# The ssh host to reach the server: an alias from ~/.ssh/config, or user@host.
# NOTE the quotes — unquoted, the angle brackets in the placeholder are parsed
# as shell redirections and an unedited copy dies with a baffling "No such file
# or directory" instead of saying what is wrong. The guard below says it.
HOST="<your-server>"          # <-- set this

DEV_SITE=/var/www/mixtape.dev
PROD_SITE=/var/www/mixtape.prod
PHP=/usr/bin/php

# Shown in `mt help` only; nothing depends on it being reachable.
DEV_URL="https://<your-dev-host>/"

warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }
fail() { printf '\033[1;31mmt: %s\033[0m\n' "$*" >&2; exit 1; }

# `if`, not `[[ … ]] && fail`: under `set -e` that idiom exits the script when
# the condition is FALSE, i.e. it would abort on every correctly-edited copy.
if [[ $HOST == *"<your-server>"* ]]; then
    fail "edit HOST at the top of this script before using it"
fi

usage() {
    cat <<EOF
mt — drive the MixTape sites on the server

USAGE
  mt <command> [args...] [--dev|--prod]

COMMANDS
  artisan <args...>   run artisan on the target site
  tinker              open a tinker REPL
  logs [-f] [-n N]    tail the newest app log (add --auth for auth.log)
  shell               interactive shell in the site directory
  help

TARGET
  --dev    (default)  $DEV_URL
  --prod              the public site — always explicit, prompts for sudo

EXAMPLES
  mt artisan down --dev
  mt artisan up --dev
  mt artisan migrate --prod
  mt artisan route:list --dev | grep song
  mt logs -f --prod
EOF
}

# --- Parse: pull the target flag out of anywhere in the line ---------------
# Everything that is not a target flag keeps its original order and is
# forwarded verbatim. Done in one pass so `mt artisan foo --prod bar` works.
TARGET=dev
TARGET_SET=0
AUTH_LOG=0
ARGS=()
for arg in "$@"; do
    case "$arg" in
        # Consumed here, like the target flags, so it never reaches `tail`.
        --auth)
            AUTH_LOG=1 ;;
        --dev|--development)
            if [[ $TARGET_SET == 1 && $TARGET != dev ]]; then
                fail "both --dev and --prod given"
            fi
            TARGET=dev;  TARGET_SET=1 ;;
        --prod|--production)
            if [[ $TARGET_SET == 1 && $TARGET != prod ]]; then
                fail "both --dev and --prod given"
            fi
            TARGET=prod; TARGET_SET=1 ;;
        *)
            ARGS+=("$arg") ;;
    esac
done

[[ ${#ARGS[@]} -gt 0 ]] || { usage; exit 1; }

CMD="${ARGS[0]}"
ARGS=("${ARGS[@]:1}")

if [[ $TARGET == prod ]]; then
    SITE=$PROD_SITE
    # Absolute paths: this is what the sudoers rule and the deploy script use.
    RUN_ARTISAN=(sudo -u www-data "$PHP" "$SITE/artisan")
else
    SITE=$DEV_SITE
    RUN_ARTISAN=("$PHP" "$SITE/artisan")
fi

# `artisan tinker` on prod needs a writable HOME. www-data's home is /var/www,
# which is deploy-owned by design, and psysh refuses to start with "Writing to
# directory /var/www/.config/psysh is not allowed". /tmp for the lifetime of the
# one command is enough; nothing about the site is changed. Applied to BOTH
# entry points — `mt tinker --prod` and `mt artisan tinker --prod` — because it
# is the same underlying command either way. Dev is unaffected: it runs as you,
# with your own home.
prod_tinker_env() {
    if [[ $TARGET == prod ]]; then
        RUN_ARTISAN=(sudo -u www-data env HOME=/tmp "$PHP" "$SITE/artisan")
    fi
}

# --- Destructive-command guard --------------------------------------------
# These drop or rewind the production database. `migrate:fresh --prod` is one
# fumbled flag away from `migrate:fresh --dev`, and the two outcomes are not
# remotely comparable: dev is reseeded in seconds, prod is user accounts,
# playlists and listen history that exist nowhere else.
#
# Laravel's own --force confirmation does NOT protect you here: artisan is
# running non-interactively on the far side of an ssh pipe, where that prompt
# never fires. So the question has to be asked on THIS side, before anything is
# sent.
confirm_destructive() {
    local sub="$1"
    case "$sub" in
        migrate:fresh|migrate:refresh|migrate:reset|migrate:rollback|db:wipe)
            [[ $TARGET == prod ]] || return 0
            warn ""
            warn "  You are about to run '$sub' against PRODUCTION."
            warn "  This destroys or rewinds live data: users, playlists, history."
            warn ""
            printf 'Type the word PRODUCTION to continue: '
            local reply
            # Read from the terminal, not stdin — stdin may be a pipe.
            read -r reply < /dev/tty || fail "no terminal to confirm on; aborted"
            [[ $reply == PRODUCTION ]] || fail "aborted"
            ;;
    esac
}

# --- Build the remote command ---------------------------------------------
# printf %q quotes every argument for the REMOTE shell. Without it, ssh
# concatenates the args and the remote shell re-splits them, so anything with a
# space or a glob (`mt artisan make:model "Foo Bar"`) silently arrives wrong.
remote_cmd() {
    local out=""
    local a
    for a in "$@"; do
        out+=" $(printf '%q' "$a")"
    done
    printf '%s' "${out# }"
}

# WHY NO `cd` INTO THE SITE: on prod the `cd` would run as YOUR login user,
# which cannot traverse the 2750 mixtape-deploy:www-data tree — only the `sudo`
# that follows switches to www-data. `cd … && sudo …` therefore dies with
# "Permission denied" before sudo is ever reached.
#
# It is not needed anyway: artisan resolves the application base path from its
# own location (`__DIR__` in the artisan script), not from the working
# directory, so an absolute path behaves identically from any cwd. Verify with
# `cd / && php /var/www/mixtape.dev/artisan about` — identical output, including
# the storage symlink check. Absolute paths are also what the sudoers rule pins,
# so this matches how the deploy script invokes artisan.
run_remote() {
    local cmdline="$1"
    # TTY policy:
    #  - prod always forces one (-tt): sudo needs a terminal for the password
    #    prompt even when our own stdout is a pipe.
    #  - dev allocates one only when stdout is a terminal, so piping stays clean
    #    (a TTY makes ssh translate LF to CRLF, which mangles `| grep`).
    if [[ $TARGET == prod ]]; then
        exec ssh -tt "$HOST" "$cmdline"
    elif [[ -t 1 ]]; then
        exec ssh -t "$HOST" "$cmdline"
    else
        exec ssh -T "$HOST" "$cmdline"
    fi
}

case "$CMD" in
    artisan)
        [[ ${#ARGS[@]} -gt 0 ]] || fail "artisan needs a command, e.g. mt artisan down --dev"
        confirm_destructive "${ARGS[0]}"
        if [[ ${ARGS[0]} == tinker ]]; then prod_tinker_env; fi
        run_remote "$(remote_cmd "${RUN_ARTISAN[@]}" "${ARGS[@]}")"
        ;;

    tinker)
        prod_tinker_env
        # Interactive by definition — always wants a TTY on both targets.
        # NOTE the ${ARGS[@]+...} guard: macOS ships bash 3.2, where expanding
        # an EMPTY array under `set -u` is an "unbound variable" abort. Plain
        # "${ARGS[@]}" would therefore break the commonest call of all —
        # `mt tinker --dev`, which has no remaining args. Same guard below.
        exec ssh -tt "$HOST" \
            "$(remote_cmd "${RUN_ARTISAN[@]}" tinker ${ARGS[@]+"${ARGS[@]}"})"
        ;;

    logs)
        # DO NOT hardcode laravel.log. The two sites use different log drivers:
        # dev is `single` (storage/logs/laravel.log), prod is `stack`+`daily`
        # (LOG_STACK=daily in the prod .env), and Laravel's daily driver writes
        # laravel-YYYY-MM-DD.log — so laravel.log does not exist on prod at all.
        # Resolve the newest match on the server rather than guessing here; that
        # is correct for either driver, and keeps working when the date rolls.
        pattern='laravel*.log'
        if [[ $AUTH_LOG == 1 ]]; then
            pattern='auth.log'
        fi

        logs_dir=$(printf '%q' "$SITE/storage/logs")
        tail_args=$(remote_cmd ${ARGS[@]+"${ARGS[@]}"})

        # The glob is deliberately left unquoted so the REMOTE shell expands it;
        # only the directory is quoted. On failure, list what is actually there —
        # "no such file" on a guessed filename is the least useful thing this
        # could say.
        snippet="f=\$(ls -t ${logs_dir}/${pattern} 2>/dev/null | head -1); \
if [ -z \"\$f\" ]; then \
echo 'mt: no log matching ${pattern} in ${SITE}/storage/logs — found:' >&2; \
ls -1 ${logs_dir} 2>&1 >&2 || true; exit 1; fi; \
exec tail ${tail_args} \"\$f\""

        # sudo on prod because the logs live inside a 2750 tree your account
        # cannot traverse — the whole snippet must run as www-data, not just the
        # tail, or the glob itself fails.
        if [[ $TARGET == prod ]]; then
            run_remote "sudo -u www-data bash -c $(printf '%q' "$snippet")"
        else
            run_remote "bash -c $(printf '%q' "$snippet")"
        fi
        ;;

    shell)
        # Here a `cd` IS wanted — the point is to land in the site directory —
        # so on prod it has to happen INSIDE the sudo, as www-data.
        #
        # Not `sudo -u www-data -s`: `-s` runs the target user's login shell,
        # and www-data's is /usr/sbin/nologin by design, so that exits
        # immediately with "This account is currently not available". Name bash
        # explicitly instead. HOME=/tmp keeps bash from trying to write history
        # into /var/www, which it cannot.
        if [[ $TARGET == prod ]]; then
            exec ssh -tt "$HOST" \
                "sudo -u www-data env HOME=/tmp bash -c $(printf '%q' "cd $SITE && exec bash -i")"
        else
            exec ssh -tt "$HOST" "cd $(printf '%q' "$SITE") && exec \$SHELL -l"
        fi
        ;;

    help|-h|--help)
        usage ;;

    *)
        fail "unknown command '$CMD' (try: mt help)" ;;
esac
