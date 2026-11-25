#!/usr/bin/env bats
#
# notify (docs/self-hosting/files/notify): the one path every alert on the box
# takes — a unit's failure, a dead-man's-switch ping, the WAF report.
#
# THE RISK this file guards is an alarm that does not go off, or goes off in the
# wrong place. A failed unit must reach the app it belongs to and mark its own
# check, not a neighbour's; one dead channel must not swallow the others; a
# success ping must never be able to fail the job it reports on; and nothing
# notify prints may carry a secret, because its output is the journal.
#
# Every channel here is a `file` and every check a file:// URL, so nothing
# touches the network.

load test_helper

# NOTIFY may point at another copy — the installed one, or a deliberately broken
# one to see that a test can fail at all.
NOTIFY="${NOTIFY:-$(cd "$BATS_TEST_DIRNAME/../../docs/self-hosting/files" && pwd)/notify}"

# Two apps, `mix` and `crd`, each with a file channel of its own, sharing a
# third (`$SHARED`) the way two apps share one ntfy topic; a secrets file with a
# check for one mix unit; and a fake systemctl that knows that unit.
setup() {
    CONFIG="$BATS_TEST_TMPDIR/apps.json"
    SECRETS="$BATS_TEST_TMPDIR/secrets.env"
    MIX_OUT="$BATS_TEST_TMPDIR/mix.out"
    CRD_OUT="$BATS_TEST_TMPDIR/crd.out"
    SHARED="$BATS_TEST_TMPDIR/shared.out"
    PINGS="$BATS_TEST_TMPDIR/pings"
    export NOTIFY_CONFIG="$CONFIG"
    cat > "$CONFIG" <<EOF
{
  "secrets": "$SECRETS",
  "apps": {
    "mix": {"label": "Mix", "channels": [{"type": "file", "path": "$MIX_OUT"}, {"type": "file", "path": "$SHARED"}]},
    "crd": {"label": "Crd", "channels": [{"type": "file", "path": "$CRD_OUT"}, {"type": "file", "path": "$SHARED"}]}
  }
}
EOF
    cat > "$SECRETS" <<EOF
HC_MIX_DB_BACKUP=file://$PINGS
EOF
    mkdir -p "$BATS_TEST_TMPDIR/bin"
    cat > "$BATS_TEST_TMPDIR/bin/systemctl" <<'EOF'
#!/bin/sh
# systemctl show -p Description --value UNIT
[ "$5" = mix-db-backup.service ] && echo "Mix database backup" && exit 0
exit 1
EOF
    chmod +x "$BATS_TEST_TMPDIR/bin/systemctl"
    PATH="$BATS_TEST_TMPDIR/bin:$PATH"
}

@test "--app sends to every channel of that app and to no other" {
    run "$NOTIFY" --app mix --title "Hello" "the body"
    [ "$status" -eq 0 ]
    output_has "mix: file: sent"

    run cat "$MIX_OUT"
    output_has "Hello"
    output_has "the body"
    run cat "$SHARED"
    output_has "Hello"
    [ ! -e "$CRD_OUT" ]
}

@test "the body can come on stdin, and the channels that show text raw get it without Markdown" {
    run bash -c 'printf "**3×** \`GET /x\`" | "$0" --app crd --title T' "$NOTIFY"
    [ "$status" -eq 0 ]
    run cat "$CRD_OUT"
    output_has "3× GET /x"
    output_lacks "**"
}

@test "one dead channel costs none of the others, and the exit code says some got through" {
    sed -i '' "s#\"path\": \"$MIX_OUT\"#\"path\": \"$BATS_TEST_TMPDIR/missing-dir/mix.out\"#" "$CONFIG"

    run "$NOTIFY" --app mix --title T body
    [ "$status" -eq 3 ]
    output_has "mix: file: failed"
    run cat "$SHARED"
    output_has "T"
}

@test "when no channel takes it, the exit code says so" {
    cat > "$CONFIG" <<EOF
{"apps": {"mix": {"label": "Mix", "channels": [{"type": "file", "path": "$BATS_TEST_TMPDIR/missing-dir/out"}]}}}
EOF
    run "$NOTIFY" --app mix --title T body
    [ "$status" -eq 4 ]
}

@test "an unknown app or a broken config sends nothing" {
    run "$NOTIFY" --app nope --title T body
    [ "$status" -ne 0 ]
    output_has "no app 'nope'"

    sed -i '' 's#"type": "file", "path": "'"$CRD_OUT"'"#"type": "pigeon"#' "$CONFIG"
    run "$NOTIFY" --app mix --title T body
    [ "$status" -eq 1 ]
    output_has "unknown channel type 'pigeon'"
    [ ! -e "$MIX_OUT" ]
}

@test "--failed reaches the unit's own app, urgently, and says where to look" {
    run "$NOTIFY" --failed mix-db-backup.service
    [ "$status" -eq 0 ]

    run cat "$MIX_OUT"
    output_has "[urgent] Mix: mix-db-backup FAILED"
    output_has "Mix database backup failed."
    output_has "journalctl -u mix-db-backup.service -n 50"
    [ ! -e "$CRD_OUT" ]
}

@test "--failed marks the unit's own check failed, and only that one" {
    echo "HC_CRD_BACKUP=file://$BATS_TEST_TMPDIR/crd-pings" >> "$SECRETS"

    run "$NOTIFY" --failed mix-db-backup.service
    run cat "$PINGS"
    [ "$output" = "mix-db-backup.service fail" ]
    [ ! -e "$BATS_TEST_TMPDIR/crd-pings" ]
}

@test "--failed for a unit no app claims reaches every app, a shared channel once" {
    run "$NOTIFY" --failed certbot.service
    [ "$status" -eq 0 ]
    output_has "no HC_CERTBOT, not pinged"

    run cat "$MIX_OUT"
    output_has "certbot FAILED"
    output_has "certbot.service failed."
    run cat "$CRD_OUT"
    output_has "certbot FAILED"
    run grep -c "certbot FAILED" "$SHARED"
    [ "$output" = 1 ]
}

@test "--ok pings the unit's check" {
    run "$NOTIFY" --ok mix-db-backup.service
    [ "$status" -eq 0 ]
    output_has "mix-db-backup.service: pinged"
    run cat "$PINGS"
    [ "$output" = "mix-db-backup.service ok" ]
    [ ! -e "$MIX_OUT" ]
}

@test "--ok never fails the job it reports on: no check, a dead check, no config" {
    run "$NOTIFY" --ok crd-backup.service
    [ "$status" -eq 0 ]
    output_has "no HC_CRD_BACKUP, not pinged"

    echo "HC_CRD_BACKUP=file://$BATS_TEST_TMPDIR/missing-dir/pings" >> "$SECRETS"
    run "$NOTIFY" --ok crd-backup.service
    [ "$status" -eq 0 ]

    NOTIFY_CONFIG="$BATS_TEST_TMPDIR/no-such.json" run "$NOTIFY" --ok mix-db-backup.service
    [ "$status" -eq 0 ]
    output_has "not pinged"
}

@test "a failed channel is named by its key, never by its secret" {
    cat > "$CONFIG" <<EOF
{"secrets": "$SECRETS", "apps": {"mix": {"label": "Mix", "channels": [
    {"type": "ntfy", "server": "http://127.0.0.1:9"},
    {"type": "ntfy", "topic_key": "OTHER_TOPIC"}]}}}
EOF
    # The topic alone in the file: a message that leaked the file's contents
    # would carry it before notify's 200-character cut, not after.
    echo "NTFY_TOPIC=secret-topic-4711" > "$SECRETS"

    run "$NOTIFY" --app mix --title T body
    [ "$status" -eq 4 ]
    output_has "no OTHER_TOPIC in $SECRETS"
    output_lacks "secret-topic-4711"
}

@test "dotenv values read the way Laravel reads them" {
    cat > "$BATS_TEST_TMPDIR/app.env" <<'EOF'
APP_NAME=MixTape
MAIL_FROM_NAME="${APP_NAME}"
LITERAL='${APP_NAME}'
MAIL_SCHEME=null
QUOTED_NULL="null"
PLAIN=value # a comment
export EXPORTED=yes
EOF
    run python3 -B -c '
import importlib.machinery, importlib.util, sys
loader = importlib.machinery.SourceFileLoader("notify", sys.argv[1])
spec = importlib.util.spec_from_loader("notify", loader)
notify = importlib.util.module_from_spec(spec)
loader.exec_module(notify)
env = notify.env_file(sys.argv[2])
for key in ("MAIL_FROM_NAME", "LITERAL", "MAIL_SCHEME", "QUOTED_NULL", "PLAIN", "EXPORTED"):
    print(f"{key}=[{env.get(key)}]")
' "$NOTIFY" "$BATS_TEST_TMPDIR/app.env"
    [ "$status" -eq 0 ]
    output_has "MAIL_FROM_NAME=[MixTape]"
    output_has 'LITERAL=[${APP_NAME}]'
    output_has "MAIL_SCHEME=[]"
    output_has "QUOTED_NULL=[null]"
    output_has "PLAIN=[value]"
    output_has "EXPORTED=[yes]"
}
