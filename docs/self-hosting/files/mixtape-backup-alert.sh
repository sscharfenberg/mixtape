#!/usr/bin/env bash
# /usr/local/sbin/mixtape-backup-alert.sh   — 755 root:root
#
# Failure reporter for this box's backups. Invoked by systemd via OnFailure= on a
# backup unit, never directly.
#
# It lives in the OnFailure hook rather than inside the backup script so that a
# run which dies without executing its own cleanup — OOM killer, SIGKILL, a
# failure before any trap is installed — still reports. systemd knows the unit
# failed even when the process never got a chance to say so.
#
# ONE SCRIPT, TWO JOBS. Called with no arguments it reports the media backup,
# which is what mixtape-backup-failed.service does; the database backup's own
# reporter passes its name and unit. Two near-identical copies of an alarm is how
# one of them quietly stops matching the other.
#
# Deliberately NOT `set -e`: both notifications must be attempted even if the
# first fails, and this script must exit 0 regardless, or the alerting unit
# itself enters a failed state and you get to debug your alarm instead of your
# backup.
set -uo pipefail

# What failed, and where to look. Defaults reproduce the media backup exactly.
JOB="${1:-media}"
UNIT="${2:-mixtape-media-backup}"

ALERT_ENV=/etc/mixtape/backup-alerts.env
# shellcheck source=/dev/null
[ -r "$ALERT_ENV" ] && . "$ALERT_ENV"

# EACH JOB GETS ITS OWN dead-man's-switch. Pinging one check for both would mark
# the media backup's period failed because the database dump broke — an alarm
# that names the wrong thing sends you to the wrong drive at the wrong hour.
# An unset URL simply skips: a box that has not configured one is not an error.
case "$JOB" in
    database) PING="${HC_DB_PING_URL:-}" ;;
    *)        PING="${HC_PING_URL:-}" ;;
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
