#!/usr/bin/env bats
# mts, layer 2: whole `mts <area> adb:` runs against the stand-in in fakes/ —
# a "device" that is a temp folder on this Mac. What these prove is how the run
# is put together: what it refuses, what it copies, what it reports, and that it
# stops. What they cannot prove is anything about the phone's own storage (see
# fakes/adb); that is mts-device.bats.

setup() {
    load test_helper
    export TEST_MEDIA_ROOT="$BATS_TEST_TMPDIR/media"
    export DEVICE="$BATS_TEST_TMPDIR/device/Music"
    export FAKE_ADB_LOG="$BATS_TEST_TMPDIR/adb.log"
    export PATH="$FAKES_DIR:$PATH"
    mkdir -p "$TEST_MEDIA_ROOT/music" "$DEVICE"
    : > "$FAKE_ADB_LOG"
}

# A small library spread over several folders, every accented name in it
# DECOMPOSED as the share lists it — including a folder whose own name is the
# only accented part of a track's path.
library() {
    local m="$TEST_MEDIA_ROOT/music"
    make_nfd_file "$m/Equilibrium/[2008] Sagas/06 - Snüffel.mp3" 300
    make_nfd_file "$m/Equilibrium/[2008] Sagas/07 - Heimwärts.mp3" 400
    make_nfd_file "$m/Blue Öyster Cult/[1976] Agents/03 - Reaper.mp3" 500
    make_nfd_file "$m/Blue Öyster Cult/[1976] Agents/Folder.jpg" 50
    make_nfd_file "$m/Insomnium/[2016] Winter's Gate/01 - Winter's Gate.mp3" 600
}

pushes() {
    LC_ALL=C grep -c '^push ' "$FAKE_ADB_LOG" || true
}

@test "a fresh device gets every file, spelled precomposed" {
    library
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 0 ]
    [ "$(find "$DEVICE" -type f | wc -l | tr -d ' ')" -eq 5 ]
    [ "$(count_decomposed "$DEVICE")" -eq 0 ]
    lists_exactly "$DEVICE" 'Equilibrium/[2008] Sagas/07 - Heimwärts.mp3'
    lists_exactly "$DEVICE" 'Blue Öyster Cult/[1976] Agents/03 - Reaper.mp3'
    output_has "files copied                  5"
}

@test "a second run finds nothing to do" {
    library
    run_mts music "adb:$DEVICE"
    : > "$FAKE_ADB_LOG"
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 0 ]
    output_has "Nothing to do"
    [ "$(pushes)" -eq 0 ]
}

@test "a dry run lists the plan and touches nothing" {
    library
    run_mts music "adb:$DEVICE" -n
    [ "$status" -eq 0 ]
    output_has "5 file(s) to copy"
    output_has "Equilibrium/[2008] Sagas/07 - Heimwärts.mp3"
    [ "$(find "$DEVICE" -mindepth 1 | wc -l | tr -d ' ')" -eq 0 ]
    [ "$(pushes)" -eq 0 ]
}

@test "a device holding decomposed library names is refused, naming only the outermost" {
    library
    mkdir -p "$DEVICE/$(nfd 'Blue Öyster Cult')/[1976] Agents"
    make_nfd_file "$DEVICE/Equilibrium/[2008] Sagas/07 - Heimwärts.mp3" 400
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 1 ]
    output_has "2 library name(s) on the device are spelled decomposed"
    output_has "$(nfd 'Blue Öyster Cult')"
    output_lacks "Agents"
    output_has "Nothing has been copied"
    [ "$(pushes)" -eq 0 ]
}

@test "decomposed names that are not the library's do not block a sync" {
    library
    make_nfd_file "$DEVICE/@Recycle/Mý Own/nöte.txt" 10
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 0 ]
    output_has "files copied                  5"
    [ "$(count_decomposed "$DEVICE/@Recycle")" -eq 2 ]
}

@test "a push that keeps failing is retried, then reported by name" {
    library
    export FAKE_ADB_FAIL_PUSH=Heimw
    run_mts music "adb:$DEVICE" --attempts 2
    [ "$status" -eq 1 ]
    output_has "still not on the device after 2 attempt(s)"
    output_has "[2008] Sagas/07 - Heimwärts.mp3"
    # The folders that could be copied were.
    lists_exactly "$DEVICE" 'Blue Öyster Cult/[1976] Agents/03 - Reaper.mp3'
}

@test "a device without the room is refused before a byte moves" {
    library
    export FAKE_DF_FREE_KB=1
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 1 ]
    output_has "Nothing has been copied"
    [ "$(pushes)" -eq 0 ]
}

@test "a device that has not authorised this Mac is refused, naming the dialog" {
    export FAKE_ADB_STATE=unauthorized
    run_mts music "adb:$DEVICE"
    [ "$status" -eq 1 ]
    output_has "reports 'unauthorized' until"
}

@test "a device folder that does not exist is refused, not created" {
    library
    run_mts music "adb:$BATS_TEST_TMPDIR/device/Musik"
    [ "$status" -eq 1 ]
    output_has "does not exist on the device"
    [ ! -e "$BATS_TEST_TMPDIR/device/Musik" ]
}

@test "a relative device path is refused" {
    run_mts music adb:Music
    [ "$status" -eq 1 ]
    output_has "must be absolute"
}

@test "--mirror and rsync flags are refused rather than ignored" {
    run_mts music "adb:$DEVICE" --mirror
    [ "$status" -eq 1 ]
    output_has "--mirror is a disk-only option"
    run_mts music "adb:$DEVICE" --bwlimit=20m
    [ "$status" -eq 1 ]
    output_has "means nothing over adb"
}
