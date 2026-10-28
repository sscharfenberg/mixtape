#!/usr/bin/env bats
# mts, layer 1: the script's own logic, with no device and no server. Every test
# hands a function text or a temp directory and reads back text. A whole run
# against a stand-in device is mts-adb.bats; a real phone, mts-device.bats.

setup() {
    load test_helper
    # shellcheck source=../../docs/self-hosting/files/mts.sh
    source "$MTS_SCRIPT"
    ADB_TMP="$BATS_TEST_TMPDIR"
}

# --- Spelling: nfc -------------------------------------------------------------

@test "nfc precomposes a letter whose accent falls across a 4 KB boundary" {
    # `iconv -f UTF-8-MAC`, the obvious tool, converts in 4,096-byte buffers and
    # leaves a letter decomposed when it is a buffer's last byte and its accent
    # the next one's first — one name in 14,268 of the real library. Placed there
    # on purpose, because a uniform listing never lands on the boundary.
    { head -c 4095 /dev/zero | tr '\0' x; printf 'a\xcc\x88rts.mp3\n'; } | nfc > "$BATS_TEST_TMPDIR/out"
    run env LC_ALL=C grep -c $'\xcc\x88' "$BATS_TEST_TMPDIR/out"
    [ "$output" = 0 ]
}

@test "nfc passes a line that is not UTF-8 through untouched" {
    printf 'caf\xe9.txt\n' | nfc > "$BATS_TEST_TMPDIR/out"
    printf 'caf\xe9.txt\n' | cmp - "$BATS_TEST_TMPDIR/out"
}

@test "nfc leaves ASCII and already-precomposed names byte for byte" {
    printf 'Heimw\xc3\xa4rts.mp3\nplain.mp3\n' > "$BATS_TEST_TMPDIR/in"
    nfc < "$BATS_TEST_TMPDIR/in" | cmp - "$BATS_TEST_TMPDIR/in"
}

# --- Small helpers -------------------------------------------------------------

@test "shq quotes a name so the device's shell reads it back unchanged" {
    name="Guns N' Roses \$HOME \"x\" \\ y"
    run sh -c "printf '%s' $(shq "$name")"
    [ "$output" = "$name" ]
}

@test "commify groups thousands with macOS's sed" {
    [ "$(commify 0)" = 0 ]
    [ "$(commify 999)" = 999 ]
    [ "$(commify 1000)" = 1,000 ]
    [ "$(commify 11207)" = 11,207 ]
    [ "$(commify 1234567)" = 1,234,567 ]
}

@test "human_bytes counts in decimal units, as a disk's capacity is quoted" {
    [ "$(human_bytes 999)" = "999 B" ]
    [ "$(human_bytes 1500)" = "1.5 KB" ]
    [ "$(human_bytes 85000000000)" = "85.0 GB" ]
}

# --- What is carried -----------------------------------------------------------

# The source-side find over a fixture tree, one path per line, sorted.
carried() {
    (cd "$1" && find "${ADB_FIND[@]}") | tr '\0' '\n' | LC_ALL=C sort
}

@test "the media filter takes any case of an extension and nothing else" {
    src="$BATS_TEST_TMPDIR/src/A/B"
    mkdir -p "$src"
    touch "$src/01.mp3" "$src/02.MP3" "$src/Folder.JPG" "$src/cover.png" "$src/booklet.pdf"
    ALL=0
    adb_build_find
    run carried "$BATS_TEST_TMPDIR/src"
    [ "$output" = "$(printf '%s\n' ./A/B/01.mp3 ./A/B/02.MP3 ./A/B/Folder.JPG ./A/B/cover.png)" ]
}

@test "junk is pruned as a folder as well as a file, even with --all" {
    src="$BATS_TEST_TMPDIR/src"
    mkdir -p "$src/A" "$src/.Spotlight-V100/Store"
    touch "$src/A/01.mp3" "$src/A/._01.mp3" "$src/A/.DS_Store" "$src/.Spotlight-V100/Store/x.jpg" "$src/A/notes.txt"
    ALL=1
    adb_build_find
    run carried "$src"
    [ "$output" = "$(printf '%s\n' ./A/01.mp3 ./A/notes.txt)" ]
}

# --- Manifests and the plan ----------------------------------------------------

@test "the manifest keeps a folder's files together, ahead of its subfolders" {
    # Sorted whole, `CD 1/` lands between `01 - a.mp3` and `Folder.JPG`, and the
    # push loop visits Album twice.
    printf '%s\n' '10 ./Album/01 - a.mp3' '20 ./Album/CD 1/01 - b.mp3' '30 ./Album/Folder.JPG' '40 ./root.mp3' \
        | LC_ALL=C awk "$ADB_MANIFEST_AWK" | LC_ALL=C sort > "$BATS_TEST_TMPDIR/manifest"
    printf '%s\t%s\t%s\n' . root.mp3 40  Album '01 - a.mp3' 10  Album Folder.JPG 30  'Album/CD 1' '01 - b.mp3' 20 \
        | cmp - "$BATS_TEST_TMPDIR/manifest"
}

@test "the source manifest spells every name precomposed, as the server does" {
    ADB_SRC="$BATS_TEST_TMPDIR/music"
    make_nfd_file "$ADB_SRC/Blue Öyster Cult/[1976] Agents/03 - Fear.mp3" 7
    ALL=0
    adb_build_find
    # From somewhere else: the subshell has to span `stat` as well as `find`, or
    # stat resolves the relative paths here and the manifest comes back empty.
    cd /
    adb_manifest_source
    printf '%s\t%s\t%s\n' 'Blue Öyster Cult/[1976] Agents' '03 - Fear.mp3' 7 | cmp - "$ADB_TMP/src.tsv"
}

@test "the plan takes every file when the device is empty, a zero-byte one too" {
    # `NR == FNR` cannot tell an empty first file from the first file, and an
    # unset size reads as 0 — either way a fresh phone would get nothing.
    printf 'A\ta.mp3\t10\nA\tempty.mp3\t0\n' > "$ADB_TMP/src.tsv"
    : > "$ADB_TMP/dev.tsv"
    adb_todo
    [ "$TODO_FILES" -eq 2 ]
    [ "$TODO_BYTES" -eq 10 ]
}

@test "the plan skips a file present at its size and re-sends one that differs" {
    printf 'A\ta.mp3\t10\nA\tb.mp3\t20\nA\tc.mp3\t30\n' > "$ADB_TMP/src.tsv"
    printf 'A\ta.mp3\t10\nA\tb.mp3\t19\n' > "$ADB_TMP/dev.tsv"
    adb_todo
    [ "$(cut -f2 "$ADB_TMP/todo")" = "$(printf '%s\n' b.mp3 c.mp3)" ]
}

# --- Arguments -----------------------------------------------------------------

@test "an unknown area is refused" {
    run parse_args films adb:
    [ "$status" -eq 1 ]
    output_has "unknown area 'films'"
}

@test "--attempts needs a positive whole number" {
    run parse_args music adb: --attempts
    [ "$status" -eq 1 ]
    output_has "--attempts needs a number"
    run parse_args music adb: --attempts 0
    [ "$status" -eq 1 ]
    output_has "positive integer"
}

@test "the transport is read off the destination's shape" {
    parse_args music adb:/sdcard/Music -n
    [ "$TRANSPORT" = adb ]
    [ "$DEVICE_DIR" = /sdcard/Music ]
    [ "$DRY" = 1 ]
    parse_args audiobooks /Volumes/CAR-AUDIO/
    [ "$TRANSPORT" = disk ]
    [ "$SRC" = /var/media/audiobooks ]
}

# --- Platform ------------------------------------------------------------------

@test "it runs on macOS" {
    run require_macos
    [ "$status" -eq 0 ]
}

@test "Windows through Git Bash is told this is macOS only" {
    uname() { echo MINGW64_NT-10.0-26100; }
    run require_macos
    [ "$status" -eq 1 ]
    output_has "macOS only, and this is Windows (MINGW64_NT-10.0-26100)"
}

@test "WSL is named as Windows, although uname says Linux" {
    uname() { echo Linux; }
    export WSL_DISTRO_NAME=Ubuntu
    run require_macos
    [ "$status" -eq 1 ]
    output_has "this is Windows (WSL, Ubuntu)"
}

@test "Linux is named as itself" {
    uname() { echo Linux; }
    unset WSL_DISTRO_NAME
    run require_macos
    [ "$status" -eq 1 ]
    output_has "this is Linux."
}

@test "a run checks the platform before anything else" {
    # Through main, because a check nothing calls would pass every test above.
    run bash -c 'source "$MTS_SCRIPT"; uname() { echo MINGW64_NT-10.0-26100; }; main music adb:/sdcard/Music' < /dev/null
    [ "$status" -eq 1 ]
    output_has "macOS only, and this is Windows"
}
