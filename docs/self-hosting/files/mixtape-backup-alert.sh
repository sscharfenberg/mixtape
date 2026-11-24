#!/usr/bin/env bash
# /usr/local/sbin/mixtape-backup-alert.sh   — 755 root:root
#
# Failure reporter for this box's backups. Invoked by systemd via OnFailure= on a
# backup unit, never directly: mixtape-db-backup-failed.service passes
# `database mixtape-db-backup`, mixtape-restic-failed.service `restic mixtape-restic`.
#
# It lives in the OnFailure hook rather than inside the backup script so that a
# run which dies without executing its own cleanup — OOM killer, SIGKILL, a
# failure before any trap is installed — still reports. systemd knows the unit
# failed even when the process never got a chance to say so.
#
# ONE SCRIPT, EVERY JOB. Each reporter passes its job's name and unit.
# Near-identical copies of an alarm is how one of them quietly stops matching the
# others.
#
# Deliberately NOT `set -e`: both notifications must be attempted even if the
# first fails, and this script must exit 0 regardless, or the alerting unit
# itself enters a failed state and you get to debug your alarm instead of your
# backup.
set -uo pipefail

# What failed, and where to look. Without arguments the push still goes out,
# naming every MixTape unit rather than none.
JOB="${1:-unnamed}"
UNIT="${2:-mixtape-*}"

ALERT_ENV=/etc/mixtape/backup-alerts.env
# shellcheck source=/dev/null
[ -r "$ALERT_ENV" ] && . "$ALERT_ENV"

# EACH JOB GETS ITS OWN dead-man's-switch. Pinging one check for both would mark
# the restic run's period failed because the database dump broke — an alarm that
# names the wrong thing sends you to the wrong place at the wrong hour. An unset
# URL simply skips: a box that has not configured one is not an error, and an
# unknown job has no check to fail.
case "$JOB" in
    database) PING="${HC_DB_PING_URL:-}" ;;
    restic)   PING="${HC_RESTIC_PING_URL:-}" ;;
    *)        PING="" ;;
esac

# Mark this period failed immediately rather than waiting for the grace window to
# lapse.
if [ -n "$PING" ]; then
    curl -fsS -m 10 --retry 3 -o /dev/null "${PING}/fail" || true
fi

# Push. Keep the body boring: a public ntfy topic is readable by anyone who
# knows the name, so this says what broke and where to look, and nothing about
# hosts, addresses or paths.
if [ -n "${NTFY_TOPIC:-}" ]; then
    curl -fsS -m 10 -o /dev/null \
        -H "Title: MixTape ${JOB} backup FAILED" \
        -H "Priority: urgent" \
        -H "Tags: rotating_light,floppy_disk" \
        -d "The ${JOB} backup did not complete. Inspect with: journalctl -u ${UNIT} -n 50" \
        "https://ntfy.sh/${NTFY_TOPIC}" || true
fi

exit 0
