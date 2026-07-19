#!/usr/bin/env bash
# /usr/local/sbin/mixtape-media-backup.sh   — 755 root:root
#
# MixTape media backup — dated tar snapshot, hashed-on-write + VERIFIED, rotated (keep KEEP).
# Skips a run if the collection is unchanged since the last snapshot.
#
# Reports liveness to a dead-man's-switch (healthchecks.io or compatible). See
# backup-alerts.env.template for the config file this reads.
#
# WHY A DEAD-MAN'S-SWITCH AND NOT JUST LOGGING: the failure that actually happens
# to a weekly backup is the silent one — the machine was off, the timer got
# disabled, the USB drive was never remounted. No log line can report an event
# that never occurred. Something outside the box has to notice the absence.
#
# Failure reporting is deliberately NOT here — it lives in the systemd
# OnFailure= hook (mixtape-backup-failed.service), so that a run killed outright
# (OOM, signal, a bug before the trap installs) still reports. The cost is that
# running this script by hand does not alert on failure; that is fine, because
# by hand you are watching the output.
set -euo pipefail

SRC=/var/media
DST=/mnt/usb/snapshots
KEEP=4

# --- Dead-man's-switch ---------------------------------------------------
# Secrets live outside this script so it can be tracked in version control.
# Absent or unreadable config simply disables pinging: a backup must never fail
# because its monitoring is misconfigured.
ALERT_ENV=/etc/mixtape/backup-alerts.env
# shellcheck source=/dev/null
[ -r "$ALERT_ENV" ] && . "$ALERT_ENV"

hc() {
    [ -n "${HC_PING_URL:-}" ] || return 0
    # `|| true`: a ping failure (DNS down, service outage) must never turn a
    # good backup into a failed unit.
    curl -fsS -m 10 --retry 3 -o /dev/null "${HC_PING_URL}${1:-}" || true
}

# Signal success on EVERY zero exit — including the "nothing changed, skipping"
# path below. Skipped runs are healthy runs; if they stayed silent the switch
# would false-alarm every week the collection happened not to change, and an
# alert you learn to ignore is worse than no alert.
trap 'rc=$?; if [ "$rc" -eq 0 ]; then hc; fi' EXIT

hc /start

mountpoint -q "$SRC" || { echo "ERROR: $SRC not mounted"; exit 1; }
[ -d "$SRC/music" ] && [ -d "$SRC/audiobooks" ] || { echo "ERROR: $SRC missing music/audiobooks"; exit 1; }
mountpoint -q /mnt/usb || { echo "ERROR: /mnt/usb not mounted (USB unplugged?)"; exit 1; }
mkdir -p "$DST"

SIG=$(find "$SRC/music" "$SRC/audiobooks" -type f -printf '%s\t%p\n' | LC_ALL=C sort | sha256sum | cut -d' ' -f1)
LAST=$(cat "$DST/.last-signature" 2>/dev/null || true)
if [ "$SIG" = "$LAST" ] && ls "$DST"/media-*.tar >/dev/null 2>&1; then
  echo "no changes since last snapshot — skipping ($(date -u +%FT%TZ))"; exit 0
fi

STAMP=$(date -u +%Y-%m-%d); BASE="media-$STAMP.tar"
echo "snapshot START $(date -u +%FT%TZ) -> $DST/$BASE"
# hash the tar STREAM as it is written, so the recorded hash = the intended bytes...
HASH=$(tar -cf - -C "$SRC" music audiobooks | tee "$DST/$BASE.partial" | sha256sum | cut -d' ' -f1)
mv "$DST/$BASE.partial" "$DST/$BASE"
printf '%s  %s\n' "$HASH" "$BASE" > "$DST/$BASE.sha256"
# ...then VERIFY by re-reading the written FILE — catches a bad/corrupt USB write.
echo "verify-on-backup: re-reading $BASE"
if ! ( cd "$DST" && sha256sum -c "$BASE.sha256" ); then
  echo "VERIFY FAILED — deleting corrupt snapshot $BASE"; rm -f "$DST/$BASE" "$DST/$BASE.sha256"; exit 1
fi
printf '%s\n' "$SIG" > "$DST/.last-signature"   # only mark state backed-up AFTER a clean verify
echo "created + verified $BASE ($(du -h "$DST/$BASE" | cut -f1))"

mapfile -t OLD < <(ls -1 "$DST"/media-*.tar 2>/dev/null | LC_ALL=C sort | head -n "-$KEEP")
for f in "${OLD[@]}"; do echo "prune old: $(basename "$f")"; rm -f "$f" "$f.sha256"; done
echo "snapshot DONE $(date -u +%FT%TZ). Kept:"; ls -1sh "$DST"/media-*.tar 2>/dev/null
