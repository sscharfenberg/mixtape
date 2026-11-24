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
# file and runs the script against a config whose channels are `file`s, so
# nothing touches the network and no WAF is needed.

load test_helper

# WAF_REPORT may point at another copy — the installed one, or a deliberately
# broken one to see that a test can fail at all.
WAF_REPORT="${WAF_REPORT:-$(cd "$BATS_TEST_DIRNAME/../../docs/self-hosting/files" && pwd)/waf-report}"

# Fresh config, audit log and state directory per test. Two apps, so routing is
# always under test: `mix` on mix.example (no HTTP/3), `crd` on crd.example
# (the HTTP/3 app).
setup() {
    AUDIT="$BATS_TEST_TMPDIR/audit.log"
    CONFIG="$BATS_TEST_TMPDIR/apps.json"
    MIX_OUT="$BATS_TEST_TMPDIR/mix.reports"
    CRD_OUT="$BATS_TEST_TMPDIR/crd.reports"
    export STATE_DIRECTORY="$BATS_TEST_TMPDIR/state"
    : > "$AUDIT"
    write_config "$MIX_OUT"
}

# The two-app config; $1 is where the mix app's reports go (a path in a missing
# directory makes its only channel fail).
write_config() {
    cat > "$CONFIG" <<EOF
{
  "audit_logs": "$AUDIT",
  "apps": [
    {"name": "mix", "label": "Mix", "hosts": ["mix.example", "www.mix.example"],
     "session_cookie": "mix-session", "exclusions": "/etc/nginx/modsec/mix.conf",
     "channels": [{"type": "file", "path": "$1"}]},
    {"name": "crd", "label": "Crd", "hosts": ["crd.example"], "http3_default": true,
     "session_cookie": "crd-session", "exclusions": "/etc/nginx/modsec/crd.conf",
     "channels": [{"type": "file", "path": "$CRD_OUT"}]}
  ]
}
EOF
}

# One audit-log line: refusal HOST COOKIE STATUS URI RULE [HTTP_VERSION] [CLIENT].
# The timestamp is ten minutes ago in server time, the shape ModSecurity writes;
# the matched value is a script tag, which a report must never repeat.
refusal() {
    python3 - "$@" >> "$AUDIT" <<'PY'
import datetime, json, sys
host, cookie, status, uri, rule = sys.argv[1:6]
version = float(sys.argv[6]) if len(sys.argv) > 6 and sys.argv[6] else 2.0
client = sys.argv[7] if len(sys.argv) > 7 else "203.0.113.7"
headers = {"Cookie": cookie} if cookie else {}
if host:
    headers["Host"] = host
when = (datetime.datetime.now() - datetime.timedelta(minutes=10)).strftime("%a %b %d %H:%M:%S %Y")
print(json.dumps({"transaction": {
    "client_ip": client, "time_stamp": when, "unique_id": "test-1",
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

@test "an HTTP/3 request without a Host belongs to the app that speaks HTTP/3" {
    refusal "" "crd-session=abc" 403 "/decks?q=<script>" 941100 3.0

    waf_report
    [ "$status" -eq 0 ]
    [ ! -e "$MIX_OUT" ]
    run cat "$CRD_OUT"
    output_has "GET /decks"
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

@test "--app limits the run to one app" {
    refusal mix.example "mix-session=abc" 403 "/?q=<script>" 941100
    refusal crd.example "crd-session=abc" 403 "/?q=<script>" 941100

    waf_report --app crd
    [ "$status" -eq 0 ]
    output_lacks "mix: window"
    [ ! -e "$MIX_OUT" ]
    [ -s "$CRD_OUT" ]
}
