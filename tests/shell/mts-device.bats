#!/usr/bin/env bats
# mts, layer 3: a real phone, through the real adb. OPT-IN — it writes to the
# phone — so it runs only with MTS_DEVICE_TESTS=1 and exactly one device
# attached; otherwise every test is reported as skipped.
#
# EVERYTHING HAPPENS UNDER /storage/emulated/0/Download, and that is the point of
# the layer. The phone's shared storage is casefolded — both Unicode spellings of
# a name are one entry — and its kernel keeps resolving a spelling it has once
# looked up. /data/local/tmp, adb's obvious scratch space, is neither, so a test
# there passes on exactly the behaviour this file exists to catch. Each test uses
# its own scratch folder and removes it afterwards.

setup() {
    if [[ ${MTS_DEVICE_TESTS:-} != 1 ]]; then
        skip "writes to an attached phone — set MTS_DEVICE_TESTS=1 to run it"
    fi
    load test_helper
    if [[ "$(adb devices | awk 'NR > 1 && $2 == "device"' | wc -l | tr -d ' ')" != 1 ]]; then
        echo "MTS_DEVICE_TESTS=1, but there is not exactly one authorised device attached" >&2
        return 1
    fi
    export TEST_MEDIA_ROOT="$BATS_TEST_TMPDIR/media"
    export DEVICE="/storage/emulated/0/Download/mts-test-$$-$BATS_TEST_NUMBER"
    mkdir -p "$TEST_MEDIA_ROOT/music"
    adb shell "mkdir -p '$DEVICE'" < /dev/null
}

teardown() {
    if [[ -n ${DEVICE:-} ]]; then
        adb shell "rm -rf '$DEVICE'" < /dev/null || true
    fi
}

# The device folder's listing, one path per line, as the phone spells it.
device_listing() {
    adb shell "cd '$DEVICE' && find . -mindepth 1" < /dev/null | LC_ALL=C tr -d '\r'
}

@test "a library listed decomposed lands on the phone precomposed" {
    make_nfd_file "$TEST_MEDIA_ROOT/music/Blue Öyster Cult/[1976] Agents/03 - Reaper.mp3" 500
    make_nfd_file "$TEST_MEDIA_ROOT/music/Equilibrium/[2008] Sagas/07 - Heimwärts.mp3" 400
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 0 ]
    device_listing > "$BATS_TEST_TMPDIR/listing"
    [ "$(count_decomposed_lines < "$BATS_TEST_TMPDIR/listing")" -eq 0 ]
    LC_ALL=C grep -qF './Blue Öyster Cult/[1976] Agents/03 - Reaper.mp3' "$BATS_TEST_TMPDIR/listing"
    LC_ALL=C grep -qF './Equilibrium/[2008] Sagas/07 - Heimwärts.mp3' "$BATS_TEST_TMPDIR/listing"
}

@test "a decomposed folder is refused even once its precomposed name is cached, and clearing it cures it" {
    make_nfd_file "$TEST_MEDIA_ROOT/music/Blue Öyster Cult/[1976] Agents/03 - Reaper.mp3" 500
    # The phone's state that made renaming in place fail: the folder stored
    # decomposed, and its precomposed spelling looked up since.
    adb shell "mkdir -p '$DEVICE/$(nfd 'Blue Öyster Cult')/[1976] Agents'" < /dev/null
    adb shell "stat '$DEVICE/Blue Öyster Cult' > /dev/null" < /dev/null
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 1 ]
    output_has "spelled decomposed"
    adb shell "rm -rf '$DEVICE/$(nfd 'Blue Öyster Cult')'" < /dev/null
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 0 ]
    [ "$(device_listing | count_decomposed_lines)" -eq 0 ]
}

# How many lines of stdin are not in NFC — count_decomposed for a listing that
# arrives as text, because the phone cannot be `find`-ed from here.
count_decomposed_lines() {
    perl -MUnicode::Normalize -MEncode -ne '
        my $s = decode("UTF-8", $_); $n++ if NFC($s) ne $s;
        END { print $n + 0 }'
}
