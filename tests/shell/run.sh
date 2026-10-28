#!/usr/bin/env bash
# Runs the shell-script suite — `npm run test:shell`, or this file directly.
# Arguments go through to bats (`npm run test:shell -- --filter nfc`).
#
# Two things are checked before bats is started, each with what to do about it,
# because either one missing otherwise fails in a way that names neither:
#
# - macOS. The scripts under test are macOS tools (BSD `stat -f`, the media share
#   under /Volumes, openrsync), and so are the tests, which drive the same BSD
#   tools. Elsewhere every test would fail on a `stat` usage error.
# - bats-core, which is not part of macOS. Without it `npm run test:shell` dies
#   with "bats: command not found" and no hint of where bats comes from.
#
# The real-phone tests in mts-device.bats are opt-in: MTS_DEVICE_TESTS=1, with
# one device attached. Without it they are reported as skipped.

set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"

if [[ "$(uname -s)" != Darwin ]]; then
    printf 'The shell suite runs on macOS only, and this is %s.\n' "$(uname -s)" >&2
    printf 'The scripts it tests use BSD stat and the media share under /Volumes.\n' >&2
    exit 1
fi

if ! command -v bats >/dev/null 2>&1; then
    cat >&2 <<'EOF'
bats-core is not installed — it runs the shell suite and does not ship with macOS.

    brew install bats-core

Without Homebrew, see https://bats-core.readthedocs.io/en/stable/installation.html
(an npm package, `npm install -g bats`, works as well).
EOF
    exit 1
fi

# A bare `[[ … ]]` line in a test is an assertion that cannot fail: bats runs
# under macOS's bash 3.2, which does not stop a test on a failing `[[ ]]` unless
# it is the last line. Refused here rather than left to review, because a suite
# full of them is green whatever the script does — this one was, once. Use
# output_has / output_lacks (test_helper.bash), `[ … ]`, or `[[ … ]] || false`.
if grep -nE '^[[:space:]]*\[\[' "$here"/*.bats >&2; then
    printf '\nBare [[ … ]] assertions above cannot fail a test under bash 3.2 — see run.sh.\n' >&2
    exit 1
fi

exec bats "$here" "$@"
