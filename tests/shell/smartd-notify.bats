#!/usr/bin/env bats
#
# smartd-notify (docs/self-hosting/files/smartd-notify): how a disk warning
# leaves the box.
#
# THE RISK this file guards is a warning that never arrives, or arrives
# carrying what it must not. smartd runs the script with SMARTD_* variables and
# takes anything it prints as a sign that it is broken, so the warning must
# reach notify's `host` app while the script itself prints nothing; the drive's
# serial number, which is part of the by-id path smartd.conf names it by, must
# not reach a third-party channel; and only a real warning may be urgent.
#
# The script runs against the repository's notify, whose config gives `host` a
# `file` channel, and a fake systemd-cat that appends what it is handed to a
# file in place of the journal — so nothing touches the network.

load test_helper

# SMARTD_NOTIFY may point at another copy — the installed one, or a deliberately
# broken one to see that a test can fail at all.
SMARTD_NOTIFY="${SMARTD_NOTIFY:-$(cd "$BATS_TEST_DIRNAME/../../docs/self-hosting/files" && pwd)/smartd-notify}"
export SMARTD_NOTIFY_COMMAND="${SMARTD_NOTIFY_COMMAND:-$(cd "$BATS_TEST_DIRNAME/../../docs/self-hosting/files" && pwd)/notify}"

# notify's config with the one app the script names; a disk the way
# smartd.conf names it, a by-id link whose name carries the serial number; and
# a systemd-cat that records its tag and the command's output in $JOURNAL.
setup() {
    OUT="$BATS_TEST_TMPDIR/host.out"
    JOURNAL="$BATS_TEST_TMPDIR/journal"
    export NOTIFY_CONFIG="$BATS_TEST_TMPDIR/apps.json"
    cat > "$NOTIFY_CONFIG" <<EOF
{
  "secrets": "$BATS_TEST_TMPDIR/secrets.env",
  "apps": {
    "host": {"label": "Server", "channels": [{"type": "file", "path": "$OUT"}]}
  }
}
EOF
    mkdir -p "$BATS_TEST_TMPDIR/dev/disk/by-id"
    : > "$BATS_TEST_TMPDIR/dev/sda"
    BY_ID="$BATS_TEST_TMPDIR/dev/disk/by-id/usb-WD_Passport_SERIAL0815-0:0"
    ln -s ../../sda "$BY_ID"

    mkdir -p "$BATS_TEST_TMPDIR/bin"
    cat > "$BATS_TEST_TMPDIR/bin/systemd-cat" <<EOF
#!/bin/bash
# systemd-cat -t TAG COMMAND…: COMMAND's stdout and stderr go to the journal.
printf 'tag=%s\n' "\$2" >> "$JOURNAL"
shift 2
exec "\$@" >> "$JOURNAL" 2>&1
EOF
    chmod +x "$BATS_TEST_TMPDIR/bin/systemd-cat"
    PATH="$BATS_TEST_TMPDIR/bin:$PATH"
}

# Runs the script the way smartd does: the problem in the environment, nothing
# on the command line. $1 is the failure type, $2 the one-line message.
run_smartd_notify() {
    run env SMARTD_DEVICE="$BY_ID" SMARTD_FAILTYPE="$1" SMARTD_MESSAGE="$2" "$SMARTD_NOTIFY"
}

@test "a warning reaches the host app, urgent, naming the disk by its kernel name" {
    run_smartd_notify CurrentPendingSector "Device: $BY_ID [SAT], 8 Currently unreadable (pending) sectors"
    [ "$status" -eq 0 ]

    run cat "$OUT"
    output_has "[urgent] Disk: CurrentPendingSector on "
    output_has "dev/sda [SAT], 8 Currently unreadable (pending) sectors"
    output_has "Inspect with: smartctl -a "
    output_lacks "SERIAL0815"
    output_lacks "by-id"
}

@test "the script prints nothing itself: notify's report goes to the journal" {
    run_smartd_notify Health "Device: $BY_ID [SAT], FAILED SMART self-check. BACK UP DATA NOW!"
    [ "$status" -eq 0 ]
    [ -z "$output" ]

    run cat "$JOURNAL"
    output_has "tag=smartd-notify"
    output_has "host: file: sent"
}

@test "smartd's test message arrives, but not as an urgent one" {
    run_smartd_notify EmailTest "TEST EMAIL from smartd for device: $BY_ID"
    [ "$status" -eq 0 ]

    run cat "$OUT"
    output_has "Disk: EmailTest on "
    output_lacks "[urgent]"
    output_lacks "SERIAL0815"
}

@test "an alert that could not be delivered fails the script, still without a word of output" {
    sed -i '' "s#\"path\": \"$OUT\"#\"path\": \"$BATS_TEST_TMPDIR/missing-dir/host.out\"#" "$NOTIFY_CONFIG"

    run_smartd_notify Health "Device: $BY_ID [SAT], FAILED SMART self-check."
    [ "$status" -ne 0 ]
    [ -z "$output" ]

    run cat "$JOURNAL"
    output_has "host: file: failed"
}

@test "a warning without a device still goes out" {
    run env SMARTD_FAILTYPE=FailedOpenDevice SMARTD_MESSAGE="Device open failed" "$SMARTD_NOTIFY"
    [ "$status" -eq 0 ]

    run cat "$OUT"
    output_has "[urgent] Disk: FailedOpenDevice on an unknown disk"
    output_has "Device open failed"
}
