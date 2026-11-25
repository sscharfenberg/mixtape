#!/usr/bin/env bats
#
# waf-report (docs/self-hosting/files/waf-report): which refusals reach a person.
#
# THE RISK this file guards is a report that cries wolf or stays silent. It
# exists to say "a real visitor got a 403", so a scanner that kept a session
# cookie must not trip it (that is what made the cookie-only rule a false alarm
# every two weeks), a refusal must land in the right app's report, a delivered
# report must not repeat, and one that could not be delivered must come back.
# And the report itself must never carry what the visitor typed, the query or
# their address — every channel is a third party.
#
# Each test writes audit-log lines in libmodsecurity's JSON shape to a temp
# file and runs the script against the repository's notify, whose config gives
# each app a `file` channel, so nothing touches the network and no WAF is
# needed. Delivery itself is notify's and is tested in notify.bats; here it is
# only the door a report leaves by.

load test_helper

# WAF_REPORT may point at another copy — the installed one, or a deliberately
# broken one to see that a test can fail at all.
WAF_REPORT="${WAF_REPORT:-$(cd "$BATS_TEST_DIRNAME/../../docs/self-hosting/files" && pwd)/waf-report}"
export WAF_REPORT_NOTIFY="${WAF_REPORT_NOTIFY:-$(cd "$BATS_TEST_DIRNAME/../../docs/self-hosting/files" && pwd)/notify}"

# Fresh config, audit log and state directory per test. Two apps, so routing is
# always under test: `mix` on mix.example, `crd` on crd.example.
setup() {
    AUDIT="$BATS_TEST_TMPDIR/audit.log"
    CONFIG="$BATS_TEST_TMPDIR/apps.json"
    MIX_OUT="$BATS_TEST_TMPDIR/mix.reports"
    CRD_OUT="$BATS_TEST_TMPDIR/crd.reports"
    export STATE_DIRECTORY="$BATS_TEST_TMPDIR/state"
    export NOTIFY_CONFIG="$BATS_TEST_TMPDIR/notify.json"
    : > "$AUDIT"
    write_config "$MIX_OUT"
}

# The two-app config, and notify's for the same two apps; $1 is where the mix
# app's reports go (a path in a missing directory makes its only channel fail).
write_config() {
    cat > "$CONFIG" <<EOF
{
  "audit_logs": "$AUDIT",
  "apps": [
    {"name": "mix", "label": "Mix", "hosts": ["mix.example", "www.mix.example"],
     "session_cookie": "mix-session", "exclusions": "/etc/nginx/modsec/mix.conf"},
    {"name": "crd", "label": "Crd", "hosts": ["crd.example"],
     "session_cookie": "crd-session", "exclusions": "/etc/nginx/modsec/crd.conf"}
  ]
}
EOF
    cat > "$NOTIFY_CONFIG" <<EOF
{
  "secrets": "$BATS_TEST_TMPDIR/secrets.env",
  "apps": {
    "mix": {"label": "Mix", "channels": [{"type": "file", "path": "$1"}]},
    "crd": {"label": "Crd", "channels": [{"type": "file", "path": "$CRD_OUT"}]}
  }
}
EOF
}

# One audit-log line: refusal HOST COOKIE STATUS URI RULE [HTTP_VERSION] [CLIENT]
# [TRANSACTION_ID] — an empty HOST leaves the header out, as nginx does over
# HTTP/3, and an empty CLIENT keeps the default.
# The timestamp is ten minutes ago in server time, the shape ModSecurity writes;
# the matched value is a script tag, which a report must never repeat.
refusal() {
    python3 - "$@" >> "$AUDIT" <<'PY'
import datetime, json, sys
host, cookie, status, uri, rule = sys.argv[1:6]
version = float(sys.argv[6]) if len(sys.argv) > 6 and sys.argv[6] else 2.0
client = sys.argv[7] if len(sys.argv) > 7 and sys.argv[7] else "203.0.113.7"
unique_id = sys.argv[8] if len(sys.argv) > 8 else "test-1"
headers = {"Cookie": cookie} if cookie else {}
if host:
    headers["Host"] = host
when = (datetime.datetime.now() - datetime.timedelta(minutes=10)).strftime("%a %b %d %H:%M:%S %Y")
print(json.dumps({"transaction": {
    "client_ip": client, "time_stamp": when, "unique_id": unique_id,
    "request": {"method": "GET", "http_version": version, "uri": uri, "headers": headers},
    "response": {"http_code": int(status)},
    "messages": [
        {"message": "a rule", "details": {"ruleId": rule,
         "data": "Matched Data: <script> found within ARGS:q: <script>alert(1)</script>"}},
        {"message": "Inbound Anomaly Score Exceeded", "details": {"ruleId": "949110", "data": ""}},
    ],
}}))
PY
}

# The script, as the timer runs it, with this test's config.
waf_report() {
    run python3 "$WAF_REPORT" --config "$CONFIG" "$@"
}

@test "a refusal with the session cookie and a real rule is reported as a visitor's" {
    refusal mix.example "XSRF-TOKEN=x; mix-session=abc" 403 "/music/songs/0b8e2c1a-1111-4222-8333-944455556666?q=<script>" 941100

    waf_report
    [ "$status" -eq 0 ]
    output_has "mix: window"
    output_has "1 visitor request(s) refused"

    run cat "$MIX_OUT"
    output_has "Mix WAF refused 1 visitor request"
    output_has "GET /music/songs/{id}"
    output_has "941100 a rule — in ARGS:q"
    output_has "/etc/nginx/modsec/mix.conf"
}

@test "the report never carries the matched value, the query or the client address" {
    refusal mix.example "mix-session=abc" 403 "/search?q=secret-term" 941100

    waf_report
    [ "$status" -eq 0 ]
    run cat "$MIX_OUT"
    output_has "GET /search"
    output_lacks "alert(1)"
    output_lacks "secret-term"
    output_lacks "203.0.113.7"
}

@test "a scanner's file-name probe is not a visitor's, even carrying the session cookie" {
    refusal mix.example "mix-session=kept-from-the-first-response" 403 "/.env" 930130
    refusal mix.example "mix-session=kept-from-the-first-response" 403 "/index.php.bak" 920440

    waf_report
    [ "$status" -eq 0 ]
    output_has "0 visitor request(s) refused, 2 other(s) refused"
    [ ! -e "$MIX_OUT" ]
}

@test "a client that probed for file names is a scanner for the whole window, payloads included" {
    # Measured: a scanner holding a kept session cookie probed /phpinfo.php.bak and, twenty
    # seconds earlier, POSTed a shell expression to / — an ordinary rule, so only its client
    # gives it away.
    refusal mix.example "mix-session=kept" 403 "/" 932130 "" 198.51.100.9
    refusal mix.example "mix-session=kept" 403 "/phpinfo.php.bak" 920440 "" 198.51.100.9

    waf_report
    [ "$status" -eq 0 ]
    output_has "0 visitor request(s) refused, 2 other(s) refused"
    [ ! -e "$MIX_OUT" ]
}

@test "another client's refusal in that window is still a visitor's" {
    refusal mix.example "mix-session=kept" 403 "/phpinfo.php.bak" 920440 "" 198.51.100.9
    refusal mix.example "mix-session=real" 403 "/playlists?q=<script>" 941100 "" 203.0.113.7

    waf_report
    [ "$status" -eq 0 ]
    output_has "1 visitor request(s) refused, 1 other(s) refused"
    run cat "$MIX_OUT"
    output_has "GET /playlists"
}

@test "a refusal without the app's session cookie is only counted" {
    refusal mix.example "crd-session=abc" 403 "/?q=<script>" 941100

    waf_report
    [ "$status" -eq 0 ]
    output_has "0 visitor request(s) refused, 1 other(s) refused"
    [ ! -e "$MIX_OUT" ]
}

@test "each app reports its own hosts only" {
    refusal crd.example "crd-session=abc" 403 "/decks?q=<script>" 941100

    waf_report
    [ "$status" -eq 0 ]
    [ ! -e "$MIX_OUT" ]
    run cat "$CRD_OUT"
    output_has "Crd WAF refused 1 visitor request"
    output_has "/etc/nginx/modsec/crd.conf"
}

@test "an HTTP/3 request without a Host belongs to the app its transaction id names" {
    refusal "" "crd-session=abc" 403 "/decks?q=<script>" 941100 3.0 "" "crd-1a2b3c"
    refusal "" "mix-session=abc" 403 "/playlists?q=<script>" 941100 3.0 "" "mix-4d5e6f"

    waf_report
    [ "$status" -eq 0 ]
    run cat "$CRD_OUT"
    output_has "GET /decks"
    output_lacks "GET /playlists"
    run cat "$MIX_OUT"
    output_has "GET /playlists"
    output_lacks "GET /decks"
}

@test "a Host-less request whose transaction id names no app is nobody's when two apps share the box" {
    refusal "" "mix-session=abc; crd-session=abc" 403 "/?q=<script>" 941100 3.0 "" "179550202582.492467"

    waf_report
    [ "$status" -eq 0 ]
    output_has "mix: window"
    output_has "crd: window"
    output_lacks "1 visitor request(s) refused"
    [ ! -e "$MIX_OUT" ]
    [ ! -e "$CRD_OUT" ]
}

@test "on a box with a single app, a Host-less request is that app's" {
    cat > "$CONFIG" <<EOF
{"audit_logs": "$AUDIT", "apps": [
  {"name": "mix", "label": "Mix", "hosts": ["mix.example"],
   "session_cookie": "mix-session", "exclusions": "/etc/nginx/modsec/mix.conf"}]}
EOF
    refusal "" "mix-session=abc" 403 "/playlists?q=<script>" 941100 3.0 "" "179550202582.492467"

    waf_report
    [ "$status" -eq 0 ]
    run cat "$MIX_OUT"
    output_has "GET /playlists"
}

@test "--app does not take another app's Host-less request" {
    refusal "" "crd-session=abc" 403 "/decks?q=<script>" 941100 3.0 "" "crd-1a2b3c"

    waf_report --app mix
    [ "$status" -eq 0 ]
    output_has "mix: window"
    output_has "0 visitor request(s) refused, 0 other(s) refused"
    [ ! -e "$MIX_OUT" ]
}

@test "a delivered report moves the app's window, so the next run is quiet" {
    refusal mix.example "mix-session=abc" 403 "/?q=<script>" 941100

    waf_report
    [ "$status" -eq 0 ]
    [ -s "$STATE_DIRECTORY/mix.window-end" ]

    waf_report
    [ "$status" -eq 0 ]
    output_has "mix: window"
    output_has "0 visitor request(s) refused"
    run grep -c "Mix WAF refused" "$MIX_OUT"
    [ "$output" = 1 ]
}

@test "a dry run sends nothing and keeps the window" {
    refusal mix.example "mix-session=abc" 403 "/?q=<script>" 941100

    waf_report --dry-run
    [ "$status" -eq 0 ]
    output_has "would send"
    [ ! -e "$MIX_OUT" ]
    [ ! -e "$STATE_DIRECTORY/mix.window-end" ]
}

@test "a request that matched but passed is only counted" {
    refusal mix.example "mix-session=abc" 200 "/?q=<script>" 941100

    waf_report
    [ "$status" -eq 0 ]
    output_has "0 visitor request(s) refused, 0 other(s) refused, 1 matched but passed"
    [ ! -e "$MIX_OUT" ]
}

@test "an undeliverable report keeps the window and fails the run, and the other app is unaffected" {
    write_config "$BATS_TEST_TMPDIR/missing-dir/mix.reports"
    refusal mix.example "mix-session=abc" 403 "/?q=<script>" 941100
    refusal crd.example "crd-session=abc" 403 "/?q=<script>" 941100

    waf_report
    [ "$status" -eq 1 ]
    output_has "mix: report not delivered"
    [ ! -e "$STATE_DIRECTORY/mix.window-end" ]
    [ -s "$STATE_DIRECTORY/crd.window-end" ]
    run grep -c "WAF report FAILED" "$CRD_OUT"
    [ "$output" = 0 ]
}

@test "a report one channel took and another did not moves the window but fails the run" {
    cat > "$NOTIFY_CONFIG" <<EOF
{"apps": {"mix": {"label": "Mix", "channels": [
    {"type": "file", "path": "$MIX_OUT"},
    {"type": "file", "path": "$BATS_TEST_TMPDIR/missing-dir/mix.reports"}]},
  "crd": {"label": "Crd", "channels": [{"type": "file", "path": "$CRD_OUT"}]}}}
EOF
    refusal mix.example "mix-session=abc" 403 "/?q=<script>" 941100

    waf_report
    [ "$status" -eq 1 ]
    output_has "mix: report delivered, but not by every channel"
    [ -s "$STATE_DIRECTORY/mix.window-end" ]
    run grep -c "Mix WAF refused" "$MIX_OUT"
    [ "$output" = 1 ]
    run grep -c "WAF report FAILED" "$MIX_OUT"
    [ "$output" = 1 ]
}

@test "a missing notify is an undelivered report, not a crash" {
    refusal mix.example "mix-session=abc" 403 "/?q=<script>" 941100

    WAF_REPORT_NOTIFY="$BATS_TEST_TMPDIR/no-such-notify" waf_report
    [ "$status" -eq 1 ]
    output_has "mix: notify did not run"
    output_has "mix: report not delivered by any channel"
    [ ! -e "$STATE_DIRECTORY/mix.window-end" ]
}

@test "--app limits the run to one app" {
    refusal mix.example "mix-session=abc" 403 "/?q=<script>" 941100
    refusal crd.example "crd-session=abc" 403 "/?q=<script>" 941100

    waf_report --app crd
    [ "$status" -eq 0 ]
    output_lacks "mix: window"
    [ ! -e "$MIX_OUT" ]
    [ -s "$CRD_OUT" ]
}
