#!/usr/bin/env bash
# mts — copy a library area from the server onto a local disk, from your
#       workstation. Built for the case it is named after: filling a USB stick
#       that gets plugged into a car.
#
#   mts music /Volumes/CAR-AUDIO/               # add what is missing, update what changed
#   mts music /Volumes/CAR-AUDIO/ --mirror      # …and delete what the library no longer has
#   mts music /Volumes/CAR-AUDIO/ -n            # show the plan, write nothing
#   mts audiobooks /Volumes/CAR-AUDIO/Books/
#
# Install on the WORKSTATION, not the server — it is the machine the disk is
# plugged into:
#
#   install -m 755 mts.sh ~/.local/bin/mts
#
# then edit HOST at the top. It refuses to run until you do. Tab completion for
# the areas and flags is in `_mts` alongside this file.
#
# WHY THIS IS NOT `mt transfer`. Every `mt` command ends in `exec ssh HOST
# <remote-command>`, and everything below its parser — the `printf %q` quoting
# for the remote shell, the TTY policy, the `sudo -u www-data` hop, resolving
# --dev/--prod to a site directory — exists to run something ON the server. A
# transfer's destination is a path on THIS machine and shares none of it.
# Worse, `mt`'s organising idea does not apply: the media library belongs to
# neither site (both read the same collection), so `mt transfer --prod` would
# parse cleanly, be stripped from the line like any target flag, and mean
# nothing. A command whose central flag is silently inert is worse than a
# separate command. `mt` draws the same line for deploys, for the same reason.
#
# MIRROR TO THE VOLUME ROOT, NOT INTO A SUBFOLDER — for music, at least. At the
# root, every path on the disk is exactly the area-relative path the database
# stores, so a playlist exported from the app with an EMPTY path prefix resolves
# on the disk verbatim. That is what config/mixtape.php already assumes about a
# car USB stick. Drop the .m3u files at the root beside the artist folders.
#
# ONE-TIME SETUP FOR REMOVABLE MEDIA, worth doing before the first run: stop
# macOS indexing a disk that a head unit has to read, or it writes a Spotlight
# index and an FSEvents log onto it.
#
#   sudo mdutil -i off /Volumes/CAR-AUDIO && sudo rm -rf /Volumes/CAR-AUDIO/.Spotlight-V100
#   touch /Volumes/CAR-AUDIO/.fseventsd/no_log
#
# This does NOT scan the library or touch the database. If files changed on the
# server, `mt artisan app:update` is what tells the app about them; this only
# moves bytes.

set -Eeuo pipefail

# --- Configuration ---------------------------------------------------------

# The ssh host to reach the server: an alias from ~/.ssh/config, or user@host.
# NOTE the quotes — unquoted, the angle brackets in the placeholder are parsed
# as shell redirections and an unedited copy dies with a baffling "No such file
# or directory" instead of saying what is wrong. The guard below says it.
HOST="<your-server>"          # <-- set this

# The parent of the library areas on the server, matching the paths in the
# app's .env (MIXTAPE_MUSIC_PATH, MIXTAPE_AUDIOBOOKS_PATH). Area names below
# line up with the keys of config/mixtape.php `library.paths`.
MEDIA_ROOT=/var/media

# What a player actually needs: the audio, plus the folder image a head unit
# shows as cover art. Everything else on the server side is left behind —
# spreadsheets, helper scripts and OS junk that has accumulated in the tree
# have no business on a car stick. Widen with --all.
#
# The audio list deliberately mirrors config/mixtape.php `scan.extensions`
# (mp3 today, "kept configurable for m4b/flac later") — if the scanner learns a
# format, this is the other place to teach it.
#
# WRITTEN AS CHARACTER CLASSES BECAUSE RSYNC'S PATTERNS ARE CASE-SENSITIVE, and
# the scanner's are not: it matches extensions case-insensitively, so the library
# legitimately holds a `.JPG` next to 1,100 `.jpg`. A plain `*.jpg` skips those
# two covers and reports nothing — the album simply arrives without art.
MEDIA_GLOBS=('*.[Mm][Pp]3' '*.[Jj][Pp][Gg]' '*.[Jj][Pp][Ee][Gg]' '*.[Pp][Nn][Gg]')

# Junk excluded in BOTH directions and in --all mode too: what macOS and
# Windows scatter over a removable disk, and what Samba clients leave in the
# library. Same list as config/mixtape.php `scan.cleanup_masks`, plus the
# volume-level directories only a desktop OS creates.
JUNK_GLOBS=(
    '._*' '.DS_Store' '.Spotlight-V100' '.fseventsd' '.Trashes'
    '.TemporaryItems' '.apdisk' 'Thumbs.db' 'AlbumArt*' '*.gp5'
    'System Volume Information' '$RECYCLE.BIN'
)

# Where a half-written file waits for the next run. It is a directory rather
# than rsync's default in-place partial for one reason that matters here: a
# truncated .mp3 sitting in an album folder is a file the head unit will happily
# try to play. Kept out of the visible tree, and excluded from transfer.
PARTIAL_DIR=.mts-partial

# An interrupted transfer must FAIL rather than hang, or there is nothing for
# the retry loop to retry. --timeout is rsync's own I/O timeout; a stalled
# connection dies inside two minutes instead of waiting on the kernel.
IO_TIMEOUT=120
CONNECT_TIMEOUT=20

# Retries are the whole answer to "resumable": rsync is incremental, so a second
# attempt re-sends only what is missing, and --partial means even the file that
# was in flight resumes rather than restarting.
MAX_ATTEMPTS=5

# Keepalives so a dropped link is noticed rather than sat on. openrsync has no
# --rsh, but its `-e program` does accept a command with options.
REMOTE_SHELL='ssh -o ServerAliveInterval=15 -o ServerAliveCountMax=4'

# --- Output helpers --------------------------------------------------------

note() { printf '\033[1;36m%s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }
fail() { printf '\033[1;31mmts: %s\033[0m\n' "$*" >&2; exit 1; }

# 1234567 -> 1,234,567. Done with sed rather than printf "%'d": that flag needs
# a thousands-grouping locale, and this script forces LC_ALL=C elsewhere.
commify() { printf '%s' "$1" | sed -e ':a' -e 's/\B[0-9]\{3\}\>/,&/;ta'; }

# Bytes as a person reads them. Decimal units, because that is what the disk's
# own capacity is quoted in, so 85 GB here matches 85 GB in Finder.
human_bytes() {
    awk -v b="${1:-0}" 'BEGIN {
        split("B KB MB GB TB", u, " ")
        i = 1
        while (b >= 1000 && i < 5) { b /= 1000; i++ }
        printf (i == 1 ? "%d %s" : "%.1f %s"), b, u[i]
    }'
}

# `if`, not `[[ … ]] && fail`: under `set -e` that idiom exits the script when
# the condition is FALSE, i.e. it would abort on every correctly-edited copy.
if [[ $HOST == *"<your-server>"* ]]; then
    fail "edit HOST at the top of this script before using it"
fi

usage() {
    cat <<EOF
mts — copy a library area from the server to a local disk

USAGE
  mts <area> <destination> [options] [rsync args...]

AREAS
  music                     $MEDIA_ROOT/music
  audiobooks                $MEDIA_ROOT/audiobooks

OPTIONS
  --mirror                  also delete files the library no longer has
  --all                     carry every file, not just audio and cover images
  -n                        show the plan and exit without writing
  --attempts N              retries on a dropped connection (default $MAX_ATTEMPTS)
  -h, --help

Anything else is passed through to rsync (--bwlimit=, --stats, …).

EXAMPLES
  mts music /Volumes/CAR-AUDIO/
  mts music /Volumes/CAR-AUDIO/ --mirror
  mts audiobooks /Volumes/CAR-AUDIO/Books/ --bwlimit=20m
EOF
}

# --- Parse -----------------------------------------------------------------
# Two positionals in order (area, destination); flags may sit anywhere around
# them, and anything unrecognised is forwarded to rsync verbatim. --attempts is
# consumed here so it never reaches rsync, which has no such option.

AREA=""
DEST=""
MIRROR=0
ALL=0
DRY=0
PASSTHROUGH=()
expect_attempts=0

for arg in "$@"; do
    if [[ $expect_attempts == 1 ]]; then
        MAX_ATTEMPTS="$arg"
        expect_attempts=0
        continue
    fi
    case "$arg" in
        --mirror)        MIRROR=1 ;;
        --all)           ALL=1 ;;
        -n|--dry-run)    DRY=1 ;;
        --attempts)      expect_attempts=1 ;;
        -h|--help)       usage; exit 0 ;;
        -*)              PASSTHROUGH+=("$arg") ;;
        *)
            if [[ -z $AREA ]]; then
                AREA="$arg"
            elif [[ -z $DEST ]]; then
                DEST="$arg"
            else
                PASSTHROUGH+=("$arg")
            fi
            ;;
    esac
done

if [[ $expect_attempts == 1 ]]; then
    fail "--attempts needs a number"
fi
if [[ -z $AREA || -z $DEST ]]; then
    usage
    exit 1
fi
if ! [[ $MAX_ATTEMPTS =~ ^[1-9][0-9]*$ ]]; then
    fail "--attempts must be a positive integer, got '$MAX_ATTEMPTS'"
fi

# A `case` rather than an associative array: macOS ships bash 3.2, which has
# none. The same reason `mt` guards its empty-array expansions.
case "$AREA" in
    music|audiobooks) SRC="$MEDIA_ROOT/$AREA" ;;
    *) fail "unknown area '$AREA' (music | audiobooks)" ;;
esac

# --- Destination guards ----------------------------------------------------
# The expensive mistake this script can make is writing 86 GB onto the boot
# disk because a volume name was mistyped, so both halves of that are refused:
# the directory is never created (a typo does not exist, so it fails), and it
# must not live on the same filesystem as `/`. Device numbers rather than
# parsing `df`, whose mount-point column cannot be split safely when a volume
# name contains a space.

if [[ ! -d $DEST ]]; then
    fail "destination '$DEST' is not a directory — is the disk mounted? (create the folder yourself if it should exist)"
fi

DEST_DEV="$(stat -f %d "$DEST")"
ROOT_DEV="$(stat -f %d /)"

if [[ $DEST_DEV == "$ROOT_DEV" ]]; then
    fail "destination '$DEST' is on the boot volume — this writes to removable media only"
fi

if [[ ! -w $DEST ]]; then
    fail "destination '$DEST' is not writable (mounted read-only?)"
fi

# --mirror DELETES, so it is allowed onto a local disk only. The library's own
# Samba share is very likely mounted on this same machine — that is what the
# app's .m3u path prefix points at — and `mts audiobooks /Volumes/<share>/music/
# --mirror` would faithfully delete the entire music collection off the server.
# A local block device is the one kind of destination where everything present
# is a copy of something the server still has, which is the assumption --delete
# rests on.
#
# Read from `df`'s FIRST field rather than its mount-point column: a device path
# never contains a space, so awk can split it safely, while a volume name
# frequently does. Local disks are /dev/…; smbfs, nfs and disk images are not.
if [[ $MIRROR == 1 ]]; then
    DEST_FS="$(df "$DEST" | awk 'NR==2 {print $1}')"
    case "$DEST_FS" in
        /dev/*) ;;
        *) fail "--mirror needs a local disk, but '$DEST' is on '$DEST_FS' — refusing to delete over a network mount" ;;
    esac
fi

# Does this filesystem hand back the filename it was given, byte for byte?
#
# One probe file named with a PRECOMPOSED é (C3 A9), then the directory is read
# back: a filesystem that preserves the form returns those bytes, one that
# decomposes returns `e` followed by a combining accent (65 CC 81) and the test
# fails. Written and removed in the destination itself, because the answer is a
# property of that volume rather than of the platform — the same Mac preserves on
# APFS and decomposes on FAT.
preserves_name_form() {
    local nfc probe found
    nfc="$(printf 'caf\303\251')"
    probe="$TARGET.mts-probe-$nfc"

    : > "$probe" 2>/dev/null || return 0   # cannot probe: do not block on it
    found="$(find "$TARGET" -maxdepth 1 -name '.mts-probe-*' -print 2>/dev/null | head -1)"
    rm -f "$probe" 2>/dev/null || true

    [[ $found == *"$nfc"* ]]
}

# --- Build the rsync argument list -----------------------------------------
# -rt, NOT -a. FAT32 and exFAT have no ownership, permissions or symlinks, so
# -a asks for four things the filesystem cannot store. Nothing needs subtracting
# afterwards, which is just as well: the openrsync that ships with macOS has no
# --no-perms / --no-owner / --no-group at all.
#
# --modify-window=1 is the one flag whose absence is silent and expensive: FAT
# stores modification times at two-second granularity, so without it every file
# looks changed on every run and the whole area is re-sent each time.

RSYNC_ARGS=(
    -rt
    --modify-window=1
    --partial
    --partial-dir="$PARTIAL_DIR"
    --timeout="$IO_TIMEOUT"
    --contimeout="$CONNECT_TIMEOUT"
    --itemize-changes
    --stats
    -e "$REMOTE_SHELL"
    --exclude="$PARTIAL_DIR/"
)

for glob in "${JUNK_GLOBS[@]}"; do
    RSYNC_ARGS+=(--exclude="$glob")
done

if [[ $ALL == 0 ]]; then
    # Filter-rule ordering, and it only works in this order: keep every
    # directory so the walk can descend, keep the media, drop the rest.
    # --prune-empty-dirs then stops a folder that held nothing but a
    # spreadsheet from being created on the disk.
    RSYNC_ARGS+=(--include='*/')
    for glob in "${MEDIA_GLOBS[@]}"; do
        RSYNC_ARGS+=(--include="$glob")
    done
    RSYNC_ARGS+=(--exclude='*' --prune-empty-dirs)
fi

if [[ $MIRROR == 1 ]]; then
    # --delete only, never --delete-excluded: excluded means "not my business",
    # and on a shared stick that covers the owner's own files. It would also
    # delete the partial directory mid-transfer.
    RSYNC_ARGS+=(--delete)
fi

RSYNC_ARGS+=(${PASSTHROUGH[@]+"${PASSTHROUGH[@]}"})

# A trailing slash on both sides: copy the CONTENTS of the area into the
# destination, rather than nesting an "music" directory inside it.
#
# No quoting needed for these two paths, but know why: rsync hands the remote
# path to a shell on the far side, and openrsync has no --protect-args to stop
# that. An area root has no spaces in it; a sub-path would (this library is full
# of them) and would arrive re-split into two nonexistent paths.
SOURCE="$HOST:$SRC/"
TARGET="${DEST%/}/"

# AND THE FILESYSTEM MUST GIVE BACK THE NAME IT WAS GIVEN. macOS writes a
# filename to FAT in decomposed form — `é` becomes `e` plus a combining
# accent — while the server stores whatever the tagger wrote, here
# precomposed. Lookups are normalisation-insensitive, so TRANSFERS are
# unaffected and stay idempotent; a directory LISTING is not, so rsync reads
# back a name that is not in its send list and calls a correctly-copied file
# extraneous. Measured on this collection: 0 files to copy and 553 to delete,
# every one of them an accented title that is present and playable. A mirror
# would delete them and re-copy them on every run, for ever.
#
# So the property is PROBED rather than assumed — write one precomposed name,
# read the directory back, see which form returns — because it belongs to the
# filesystem and not to any list of filesystems this script could keep. This
# refuses rather than asking: 553 deletions of good files is not something a
# yes/no prompt should be able to wave through, and dropping --mirror loses
# nothing but the deletions.
if [[ $MIRROR == 1 ]] && ! preserves_name_form; then
    fail "$(printf '%s\n' \
        "--mirror is unsafe here: $DEST normalises filenames, so rsync reads back" \
        "     a different spelling than it sent and treats every accented title as" \
        "     extraneous. Re-run without --mirror; the copy itself is unaffected.")"
fi

# --- AppleDouble sidecars --------------------------------------------------
# macOS stamps a file it creates with an extended attribute (today
# `com.apple.provenance`, with an empty value), and FAT cannot store extended
# attributes — so the volume driver spills each one into a 4 KB `._<name>`
# AppleDouble file beside the real one. The library's own files carry no xattrs
# at all; these are made HERE, on the way in, which is why the `._*` exclude
# above cannot prevent them. Left alone, a ten-thousand-file library arrives
# with ten thousand phantom 4 KB files, and a head unit lists them as tracks.
#
# Deleted rather than merged. `dot_clean` is the sanctioned tool for this and it
# does not finish the job on FAT — measured on two files: it merged one sidecar,
# left the other and the directory's own behind, and restored the attribute it
# had just merged, which spills straight back.
#
# DELETING THE SIDECAR IS THE WHOLE JOB — there is no attribute left to clear
# afterwards, because on FAT the sidecar IS where the attribute is stored.
# Measured: delete the sidecars and `xattr -l` on the file it belonged to comes
# back empty. An `xattr -rc` pass over the tree therefore removes nothing that
# the delete has not already removed, and on a real library it is not merely
# redundant but ruinous — over twelve thousand entries it runs for minutes, a
# syscall at a time, while the run appears to have hung.
#
# Scoped to sidecars that EXIST, so a destination on a filesystem which stores
# extended attributes natively (an external APFS disk) is left alone: no
# sidecars means the volume is not spilling, so there is nothing here to fix.
#
# They come back on EVERY run, not only the first. rsync re-stamps directory
# times whenever it walks the tree, so a pass that transfers nothing still leaves
# a sidecar beside each of ~1,300 directories — which is why this is the last
# thing a run does rather than something the first run gets out of the way.
#
# A FUNCTION, AND ALSO A TRAP, because the sweep is the last thing a run does
# and an abandoned run is the likeliest kind: interrupting a two-hour copy would
# otherwise leave thousands of phantom files on a disk somebody is about to
# unplug and drive away with. The partial directory is deliberately NOT touched
# here — on an interrupt it holds the file that was in flight, which is the whole
# point of keeping it.
#
# THE ORDER IS CAUSE THEN ARTEFACT, AND THEN IT CHECKS. Clearing the extended
# attributes first removes the thing the volume is spilling, so deleting the
# sidecars afterwards cannot race a driver that is still writing them; doing it
# the other way round is what a two-file test cannot tell you apart from doing it
# right. The count is then re-read rather than trusted, and the pass repeated,
# because "I deleted 12,319 files" and "there are now none" are different claims
# and only the second one is what the disk goes into the car with. SWEPT carries
# the honest number to the summary.
SWEPT=0
sweep_sidecars() {
    local before after pass
    # A dry run must leave the disk exactly as it found it. The EXIT trap fires
    # on `-n` too, and sweeping there would make a command whose whole purpose is
    # to change nothing quietly delete files.
    if [[ ${DRY:-0} == 1 ]]; then
        return 0
    fi

    before="$(sidecar_count)"
    [[ ${before:-0} -gt 0 ]] || return 0

    for pass in 1 2 3; do
        find "$TARGET" -name '._*' -type f -delete 2>/dev/null || true
        after="$(sidecar_count)"
        [[ ${after:-0} -gt 0 ]] || break
    done

    SWEPT=$((before - after))
    if [[ ${after:-0} -gt 0 ]]; then
        warn "$(commify "$after") AppleDouble sidecar(s) survived three sweeps — the volume is still spilling them."
    fi
}

# Counted in one place, because the sweep compares two readings of it and a
# predicate written twice can disagree with itself. A directory's sidecar is a
# regular file too, which is why -type f does not miss it.
sidecar_count() {
    find "$TARGET" -name '._*' -type f 2>/dev/null | wc -l | tr -d ' '
}

# --- The plan --------------------------------------------------------------
# A dry run first, for three things at once: the file count that makes a
# progress display possible (openrsync has no --info=progress2), the deletion
# count, and a rehearsal that costs one file-list walk and no writes.

PLAN="$(mktemp -t mts-plan)"

# THE SWEEP HANGS OFF `EXIT`, NOT OFF THE SUCCESS PATH. A call after the transfer
# loop looks equivalent and is not: `fail` exits, so a run that gives up after
# its retries — or hits a usage error, or trips `set -e` — would skip it and
# leave every sidecar it made on the disk. `EXIT` fires on every path out of
# here, including the two early exits just below, so the disk is always left fit
# to unplug. Armed only now, once the destination is validated: it reads $TARGET,
# and nothing before this point has written anything to sweep.
#
# The signal trap therefore does nothing but exit — `exit` runs the EXIT trap, so
# the sweep still happens on Ctrl-C, in one place rather than two. HUP is in the
# list because closing the terminal on a two-hour copy is not an unusual way for
# one to end. `${VAR:-}` because COUNT_FILE does not exist yet when an early
# exit fires this, and `set -u` would abort inside the trap.
trap 'rm -f -- "${PLAN:-}" "${COUNT_FILE:-}"; sweep_sidecars' EXIT
trap 'printf "\n"; exit 130' INT TERM HUP

note "Planning: $SOURCE -> $TARGET"

if ! rsync -n "${RSYNC_ARGS[@]}" "$SOURCE" "$TARGET" > "$PLAN" 2>&1; then
    cat "$PLAN" >&2
    fail "could not read the plan — is '$HOST' reachable?"
fi

# grep -c on an empty match exits 1, which `set -e` would take as fatal; `|| true`
# keeps a legitimate zero.
TO_SEND="$(grep -c '^>f' "$PLAN" || true)"
TO_DELETE="$(grep -c '^\*deleting' "$PLAN" || true)"

note "$TO_SEND file(s) to copy, $TO_DELETE to delete"

if [[ $DRY == 1 ]]; then
    cat "$PLAN"
    exit 0
fi

if [[ $TO_SEND == 0 && $TO_DELETE == 0 ]]; then
    note "Nothing to do — the disk already matches the library."
    exit 0
fi

# Deleting from the destination is confirmed, but NOT with the typed-word
# ceremony `mt` demands of a production migration, because the stakes are not
# comparable: everything on this disk is a copy of something the server still
# has, and a wrong answer costs a re-run rather than data. The question exists
# so a --mirror that would empty the disk cannot pass unnoticed.
if [[ $TO_DELETE -gt 0 && -t 0 ]]; then
    warn ""
    warn "  --mirror will DELETE $TO_DELETE file(s) from $TARGET"
    grep '^\*deleting' "$PLAN" | head -5 | sed 's/^/    /' >&2
    if [[ $TO_DELETE -gt 5 ]]; then
        warn "    … and $((TO_DELETE - 5)) more"
    fi
    warn ""
    printf 'Continue? [y/N] '
    reply=""
    read -r reply < /dev/tty || fail "no terminal to confirm on; aborted"
    case "$reply" in
        y|Y|yes|YES) ;;
        *) fail "aborted" ;;
    esac
fi

# --- Progress --------------------------------------------------------------
# openrsync has --progress, but it reports per FILE — ten thousand little
# percentages and no idea how far through the job you are. The dry run above
# already counted the work, so the real run is filtered through awk, which
# turns each itemized line into one updating counter.
#
# The count is carried ACROSS attempts in a file: a retry re-sends only what is
# missing, so its own counter starts at zero and the display would otherwise
# jump backwards after a dropped connection.

COUNT_FILE="$(mktemp -t mts-count)"

printf '0 0 0 0\n' > "$COUNT_FILE"

WIDTH=100
if [[ -t 1 ]]; then
    WIDTH="$(tput cols 2>/dev/null || echo 100)"
fi

progress_filter() {
    # LC_ALL=C, because rsync's itemized output is NOT guaranteed to be valid
    # UTF-8. It escapes some non-ASCII bytes in a filename as `\#NNN` octal and
    # passes others through raw, so a name like `Tír na mBan.mp3` reaches awk as
    # a lone 0xC3 followed by the literal text `\#255`. In a UTF-8 locale awk
    # then warns `towc: multibyte conversion failure` once per such line — it
    # keeps going and the transfer is unaffected, but on a collection with any
    # accented titles the warnings bury the progress display. Byte-oriented awk
    # never attempts the conversion. The only cost is that `length()` counts
    # bytes, so a line with multibyte characters is truncated a little early.
    #
    # The four running totals are read in and written back out, so a retry
    # continues the count instead of restarting it — see read_state.
    LC_ALL=C awk -v total="$TO_SEND" -v width="$WIDTH" -v cf="$COUNT_FILE" \
        -v tty="$([[ -t 1 ]] && echo 1 || echo 0)" \
        -v b_new="$S_NEW" -v b_upd="$S_UPD" -v b_del="$S_DEL" -v b_bytes="$S_BYTES" '
        BEGIN {
            nw = b_new + 0; up = b_upd + 0; dl = b_del + 0; by = b_bytes + 0
            blank = sprintf("%" width "s", "")
            pending = 0
        }
        # Anything not itemized (a warning, a --stats line we do not want) is
        # passed through, after a newline if a progress line is unterminated.
        function passthrough(line) {
            if (pending == 1) { printf "\n"; pending = 0 }
            print line
            fflush()
        }
        /^\*deleting/ { dl++; next }
        # Itemized lines are eleven flag characters, a space, then the path. A
        # transfer whose every attribute slot is `+` is a file that was not there
        # at all; any other flag string means it was, and something about it
        # differed. That is the whole difference between "new" and "updated", and
        # it is the only place either number comes from.
        /^[<>ch.][fdLDS.+?]/ {
            if ($0 !~ /^>f/) { next }
            if ($1 ~ /^>f\++$/) { nw++ } else { up++ }
            sent = nw + up
            path = $0
            sub(/^[^ ]+ +/, "", path)
            pct = (total > 0) ? int(sent * 100 / total) : 100
            line = sprintf("[%d/%d %d%%] %s", sent, total, pct, path)
            if (length(line) > width) { line = substr(line, 1, width) }
            if (tty == 1) {
                printf "\r%s%s", line, substr(blank, 1, width - length(line))
                pending = 1
                fflush()
            } else if (sent % 200 == 0 || sent == total) {
                print line
                fflush()
            }
            next
        }
        # The one --stats figure the summary needs; the rest of that block is
        # swallowed rather than printed over the progress line.
        /^Total transferred file size:/ { by += $5; next }
        /^(sent |total size |Number of |Total |Unmatched |Matched |File list )/ { next }
        /^$/ { next }
        { passthrough($0) }
        END {
            if (pending == 1) { printf "\n" }
            printf("%d %d %d %d\n", nw, up, dl, by) > cf
        }
    '
}

# The four totals as shell variables, re-read before every attempt so the counts
# survive a dropped connection. One file rather than four, because they are only
# ever written together.
read_state() {
    S_NEW=0; S_UPD=0; S_DEL=0; S_BYTES=0
    # `|| true` IS LOAD-BEARING. `read` returns non-zero when it reaches EOF
    # without its delimiter, even though it has assigned every field it found —
    # so a state file with no trailing newline sets the variables and then kills
    # the script, under `set -e`, with no message at all. The writers here do end
    # the line, and this still tolerates one that does not.
    read -r S_NEW S_UPD S_DEL S_BYTES < "$COUNT_FILE" || true
    S_NEW="${S_NEW:-0}"; S_UPD="${S_UPD:-0}"; S_DEL="${S_DEL:-0}"; S_BYTES="${S_BYTES:-0}"
}

# --- Transfer, with retries ------------------------------------------------
# Exit codes that mean "the setup is wrong" are not worth retrying — a bad flag
# or an incompatible protocol will fail identically five times. Everything else
# (a dropped link is 255 from ssh, a stall is 30/35, a socket error is 10/12) is
# transient by nature and the next attempt picks up where this one stopped.
fatal_code() {
    case "$1" in
        1|2|4) return 0 ;;   # syntax, protocol incompatibility, unsupported action
        *)     return 1 ;;
    esac
}

started="$SECONDS"
attempt=1

while :; do
    if [[ $attempt -gt 1 ]]; then
        # A yanked disk is the other way a transfer is interrupted, and retrying
        # into a vanished mount point would recreate it as a directory on the
        # boot volume — the very thing the guard above refuses.
        if [[ ! -d $TARGET ]] || [[ "$(stat -f %d "$TARGET" 2>/dev/null || echo none)" != "$DEST_DEV" ]]; then
            fail "the destination disk is no longer mounted at '$TARGET' — stopping"
        fi
        note "Attempt $attempt of $MAX_ATTEMPTS — resuming"
    fi

    read_state

    # `set +e` around the pipeline: we want the code, not the trap. pipefail is
    # on, so PIPESTATUS[0] is rsync's own status rather than awk's.
    set +e
    rsync "${RSYNC_ARGS[@]}" "$SOURCE" "$TARGET" | progress_filter
    rc="${PIPESTATUS[0]}"
    set -e

    if [[ $rc == 0 ]]; then
        break
    fi

    if fatal_code "$rc"; then
        fail "rsync exited $rc — that is a usage or protocol error, not a dropped connection"
    fi

    if [[ $attempt -ge $MAX_ATTEMPTS ]]; then
        fail "rsync exited $rc after $attempt attempt(s); re-run to continue where it stopped"
    fi

    backoff=$((attempt * 10))
    if [[ $backoff -gt 60 ]]; then backoff=60; fi
    warn "rsync exited $rc — retrying in ${backoff}s"
    sleep "$backoff"
    attempt=$((attempt + 1))
done

# --- Finish ----------------------------------------------------------------
# Only a completed run may remove the partial directory, and only while it is
# empty — rsync empties it as each file lands, so an empty one is spent.
find "$TARGET" -name "$PARTIAL_DIR" -type d -empty -delete 2>/dev/null || true

# The sweep is run HERE rather than left to the EXIT trap, even though the trap
# would do it: the summary below reports how many sidecars went, and a trap that
# fires after the last line printed cannot be part of it. Running it twice is
# free — the second call reads a count of zero and returns.
sweep_sidecars

# FAT writes sit in the page cache, and a stick pulled out of a Mac without
# being ejected loses whatever had not landed. `sync` is not a substitute for
# ejecting, so the summary says so rather than implying the disk is safe to pull.
sync

read_state
elapsed=$((SECONDS - started))

# The summary answers "what did that just do to my disk", which is a different
# question from the progress line's "how far along is it". Every row is a
# measurement the run actually took rather than a restatement of the plan: the
# plan is what was intended, and the two differing is exactly the case worth
# seeing.
summary() {
    local label rate

    rate=""
    if [[ $elapsed -gt 0 && ${S_BYTES:-0} -gt 0 ]]; then
        rate="  at $(human_bytes $((S_BYTES / elapsed)))/s"
    fi

    printf '\n'
    note "  $AREA → $TARGET"
    printf '  %s\n' "────────────────────────────────────────────────────"
    printf '  %-18s %12s\n' "new files"      "$(commify "$S_NEW")"
    printf '  %-18s %12s\n' "updated"        "$(commify "$S_UPD")"
    printf '  %-18s %12s\n' "deleted"        "$(commify "$S_DEL")"
    printf '  %-18s %12s\n' "sidecars swept" "$(commify "$SWEPT")"
    printf '  %-18s %12s%s\n' "transferred"  "$(human_bytes "${S_BYTES:-0}")" "$rate"
    printf '  %-18s %12s\n' "elapsed"        "$(printf '%dm %02ds' $((elapsed / 60)) $((elapsed % 60)))"
    printf '  %s\n' "────────────────────────────────────────────────────"

    label="$(du -sh "$TARGET" 2>/dev/null | cut -f1 | tr -d ' ')"
    if [[ -n $label ]]; then
        printf '  %-18s %12s\n' "on disk now" "$label"
    fi
    printf '\n'
}

summary
warn "Eject before unplugging:  diskutil eject '$TARGET'"
