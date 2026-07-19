#!/usr/bin/env bash
# /usr/local/sbin/mixtape-backup-alert.sh   — 755 root:root
#
# Failure reporter for the media backup. Invoked by systemd via
# OnFailure=mixtape-backup-failed.service, never directly.
#
# It lives in the OnFailure hook rather than inside the backup script so that a
# run which dies without executing its own cleanup — OOM killer, SIGKILL, a
# failure before any trap is installed — still reports. systemd knows the unit
# failed even when the process never got a chance to say so.
#
# Deliberately NOT `set -e`: both notifications must be attempted even if the
# first fails, and this script must exit 0 regardless, or the alerting unit
# itself enters a failed state and you get to debug your alarm instead of your
# backup.
set -uo pipefail

ALERT_ENV=/etc/mixtape/backup-alerts.env
# shellcheck source=/dev/null
[ -r "$ALERT_ENV" ] && . "$ALERT_ENV"

# Dead-man's-switch: mark this period failed immediately rather than waiting for
# the grace window to lapse.
if [ -n "${HC_PING_URL:-}" ]; then
    curl -fsS -m 10 --retry 3 -o /dev/null "${HC_PING_URL}/fail" || true
fi

# Push. Keep the body boring: a public ntfy topic is readable by anyone who
# knows the name, so this says what broke and where to look, and nothing about
# hosts, addresses or paths.
if [ -n "${NTFY_TOPIC:-}" ]; then
    curl -fsS -m 10 -o /dev/null \
        -H "Title: MixTape media backup FAILED" \
        -H "Priority: urgent" \
        -H "Tags: rotating_light,floppy_disk" \
        -d "The weekly media backup did not complete. Inspect with: journalctl -u mixtape-media-backup -n 50" \
        "https://ntfy.sh/${NTFY_TOPIC}" || true
fi

exit 0
