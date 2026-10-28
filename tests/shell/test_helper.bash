# Shared by every .bats file here, through `load test_helper`.
#
# mts.sh is SOURCED, not copied or edited: its `main` runs only when the file is
# executed, so loading it defines every function and changes nothing else —
# not even the shell options, which `main` sets for itself. A test then either
# calls a function directly or drives a whole run through run_mts.

# MTS_SCRIPT may point at another copy — the installed `~/.local/bin/mts`, or a
# deliberately broken one to see that a test can fail at all.
MTS_SCRIPT="${MTS_SCRIPT:-$(cd "$BATS_TEST_DIRNAME/../../docs/self-hosting/files" && pwd)/mts.sh}"
FAKES_DIR="$BATS_TEST_DIRNAME/fakes"
export MTS_SCRIPT

# A path in DECOMPOSED form (NFD) — `ä` as `a` plus a combining diaeresis — which
# is how macOS's SMB client lists every name on the media share, and so the
# spelling every test of the naming rules has to start from.
nfd() {
    perl -MUnicode::Normalize -MEncode -e 'print encode("UTF-8", NFD(decode("UTF-8", $ARGV[0])))' "$1"
}

# Create a file of SIZE bytes (default 100) with every component of its path
# DECOMPOSED. APFS keeps a name in the form it was created in, so the directory
# then lists the way the share does.
make_nfd_file() {
    local path
    path="$(nfd "$1")"
    mkdir -p "$(dirname "$path")"
    head -c "${2:-100}" /dev/zero > "$path"
}

# How many names under DIR are not in NFC. Read off a listing, never by looking a
# name up: APFS — like the phone's storage — finds a file by either spelling, so
# `[ -e … ]` cannot tell which one is stored. Only the listing can.
count_decomposed() {
    find "$1" -mindepth 1 | perl -MUnicode::Normalize -MEncode -ne '
        my $s = decode("UTF-8", $_); $n++ if NFC($s) ne $s;
        END { print $n + 0 }'
}

# Whether DIR lists NAME spelled byte for byte as given — the question a phone
# player effectively asks of a playlist line.
lists_exactly() {
    find "$1" -mindepth 1 | LC_ALL=C grep -qF -- "$2"
}

# Whether the last `run` printed TEXT — and output_lacks, whether it did not.
#
# FUNCTIONS, NEVER A BARE `[[ $output == *…* ]]`. bats runs under macOS's
# bash 3.2, where a failing `[[ ]]` on any line but a test's last does not fail
# the test: every such assertion in this suite once passed whatever it was
# given, and a test written to catch a missing guard stayed green without it. A
# failing function call does stop a test, so the comparison lives in here, and
# run.sh refuses a .bats file with a bare `[[` line in it.
output_has() {
    if [[ $output != *"$1"* ]]; then
        printf 'expected the output to contain: %s\n' "$1"
        return 1
    fi
}

output_lacks() {
    if [[ $output == *"$1"* ]]; then
        printf 'expected the output NOT to contain: %s\n' "$1"
        return 1
    fi
}

# A whole `mts` run with these arguments, as the command would make it — but in a
# fresh bash that sources the script and points LOCAL_MEDIA_ROOT at this test's
# library. A separate process rather than `run main` in the test's own shell,
# because main turns on `set -Eeuo pipefail`, and bats' own error trap must not
# be running under it. stdin is /dev/null for the reason given in fakes/adb.
run_mts() {
    run bash -c 'source "$MTS_SCRIPT"; LOCAL_MEDIA_ROOT="$TEST_MEDIA_ROOT"; main "$@"' mts "$@" < /dev/null
}
