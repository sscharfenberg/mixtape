# Artisan commands

Project-specific `artisan` commands for MixTape (namespaced `app:*`). Framework
and package commands (`migrate`, `queue:work`, …) are not listed here — run
`php artisan list` for the full set.

> Run everything from the app root. On a server, artisan resolves its base path
> from its own location, so an absolute path works from any working directory.

## Index

| Command | Summary |
| --- | --- |
| [`app:invite`](#appinvite) | Mint a one-time, expiring registration invite link |
| [`app:update`](#appupdate) | Scan the media library into the database (cleanup + content-hash diff) |
| [`app:clean`](#appclean) | Delete OS/Samba junk files from the library shares (the cleanup step, standalone) |
| [`app:encoding`](#appencoding) | Report the library paths a Windows-1252 playlist export cannot name |
| [`app:playlist`](#appplaylist) | Fill a playlist with random tracks from the scanned library |
| [`app:db-backup`](#appdb-backup) | Dump the database to the backup drive, verify it, prune old dumps |
| [`app:db-restore`](#appdb-restore) | Restore the database from one of those dumps (interactive, destructive) |

---

## `app:invite`

Mints a **one-time, expiring registration invite** and prints a link to share.

```
php artisan app:invite {note?} {--days=7}
```

### Why it exists

MixTape onboarding is **invite-only**: open self-service registration is disabled
by design. The `registration` feature is enabled in `config/fortify.php`, but
every registration path demands a valid invite — there is no way to create an
account without one. This is the headline access model: the owner shares music
with family and friends, and each new account must be deliberately granted.
`app:invite` is how the owner grants one — it creates the invite row and hands
back the URL to send to the person being onboarded.

### What it does

1. Generates a high-entropy, URL-safe code (`Str::random(40)`).
2. Stores **only the SHA-256 hash** of that code in the `invites` table
   (`token`), together with an optional `note` and a `valid_until` timestamp set
   to `now + --days`.
3. Prints the registration link: `…/register?code=<plaintext>`.

The recipient opens the link, which reaches the registration page **only if the
code is still valid** (unknown / expired / already-used codes bounce to the login
page with an error toast). They choose a username + e-mail + password, and on
success the invite row is **deleted** — the link can never be used again.

### Arguments & options

| Name | Kind | Default | Meaning |
| --- | --- | --- | --- |
| `note` | argument (optional) | *prompted* | Free-text reminder of who the invite is for. The invite is **not** tied to a specific user, so this note is the only human hint of the intended recipient. If omitted on the command line, the command **asks** for it interactively; press enter to leave it blank. |
| `--days` | option | `7` | How many days the invite stays valid. Must be ≥ 1. |

### Examples

```bash
# Interactive: prompts for the note, valid for 7 days
php artisan app:invite

# Note supplied inline, custom expiry
php artisan app:invite "Oma" --days=14
```

Example output:

```
 Invite minted — valid for 14 days.
   Note: Oma

 Registration link (copy & share — shown only once):
   https://example.tld/register?code=Xq3…40chars…
```

> The absolute URL is built from `APP_URL`, so make sure that is set correctly on
> the box you run the command from (otherwise the printed host is wrong).

### Security model & notes

- **The plaintext code is never stored.** Only its SHA-256 hash lives in the DB,
  so a database dump reveals no usable invites. SHA-256 (not bcrypt) is the right
  choice because the code is already high-entropy random, and a plain digest can
  be looked up by an indexed equality query.
- **Single-use.** The invite row is deleted the moment it is redeemed
  (`App\Actions\Fortify\CreateNewUser`), inside the same DB transaction (with a
  row lock) that creates the user — so two people racing the same link cannot
  both register, and a leaked link is good for at most one account.
- **Shown once.** Because only the hash is stored, a lost link cannot be
  recovered — mint a new invite instead. (This mirrors how password-reset tokens
  behave.)
- **Expiry is enforced on use**, not by a background job. Expired-but-unredeemed
  rows simply stop working; prune them later if desired.

### Related code

- `app/Console/Commands/CreateInvite.php` — the command.
- `app/Models/Invite.php` — the model (`hashCode()`, casts).
- `database/migrations/*_create_invites_table.php` — the schema.
- `app/Rules/ValidInvite.php` + `app/Actions/Fortify/CreateNewUser.php` — the
  redemption path (validate → lock → create user → delete invite).
- `routes/web.auth.php`, `app/Http/Controllers/Auth/AuthController.php` — the
  `GET` / `POST /register` wiring.
- `resources/app/pages/Auth/RegisterPage.vue` — the registration form.

---

## `app:update`

Scans the media library on disk into the database.

```
php artisan app:update {--area=*} {--skip-cleanup} {--reread}
```

### Why it exists

The library is the mp3/audiobook collection on disk; the database is a queryable
index of it. `app:update` reconciles the two. It runs on the host (cron / manual)
whenever files are added, removed, re-tagged, or moved.

### What it does

1. **Cleanup** (unless `--skip-cleanup`) — deletes OS/Samba junk (`.DS_Store`,
   AppleDouble `._*`, `Thumbs.db`, Samba `.@__*` / `.smbdelete*`, …) from the
   library roots *before* anything is analysed, so it can't be mistaken for media.
   Masks: `config('mixtape.scan.cleanup_masks')`.
2. **Scan** — a **content-hash diff**, not a truncate-and-rebuild
   ([`data-model.md`](data-model.md) → *The one fact that colours everything*).
   Per area, in one transaction: unchanged files fast-path on
   `(path, size, mtime)`; a same-path byte change is a re-tag (update in place); a
   new path is hash-matched against vanished rows to catch renames; the rest are
   inserts; gone files are relink-then-cascade deleted; orphan
   taxonomy/collections are pruned.
3. **Reconcile each container** — an album's or book's cover image, and its **year**. Both are
   facts about a CONTAINER derived from its files, so neither can be written by a single track:
   the year moves only when **every file of the container agrees** on one, which is what stops a
   single mis-tagged file re-dating a record and makes the outcome independent of the order the
   walk read them in (`LibraryScanService::syncCollectionYears`). A container whose files
   disagree is left alone — guessing a winner would invent a fact about a release out of a
   tagging mistake. Unanimity is measured over the WHOLE container, so correcting one file of a
   fifteen-track album makes the scan read the other fourteen before it decides.

**Identity is the audio-frame hash**, so a rename *or* a re-tag keeps the track's
id — playlists, most-played, and share links stay anchored. Two files with
identical audio are two rows (clones) sharing a hash.

**Re-tagging an artist, genre or album title renames the row in place**, id intact — so the
URLs to it, and any share pointing at it, keep working. That includes a **change of case
only** (`NARGAROTH` → `Nargaroth`), which needs explicit handling: name dedup is a
case-insensitive column collation, so a plain `firstOrCreate` *finds* the old row and hands it
back unchanged, with no insert and no update to notice. The tags are the source of truth for
the spelling, so the scan adopts it (`LibraryScanService::adoptSpelling`).

> **A scan only re-reads files whose `(size, mtime)` moved**, so this fixes future re-tags and
> does not retroactively repair a name that a previous scan already recorded. If a row is
> stuck on an old spelling, `touch` the files (or re-save the tags) and scan again.
>
> **`--reread` is the exception**: it drops the fast path entirely, so every file's tags are read
> again and everything derived from them is rebuilt — a stale spelling, a value that had nowhere to
> be written, a column added after the files were last read (NULL until then). Three real cases
> need it and none is visible to an ordinary scan:
>
> 1. **a tagger that preserves mtimes** — and ID3v2 padding is designed to absorb a tag edit without
>    moving the size either, so such an edit is invisible in both fields the fast path compares;
> 2. **a value read but not stored** — as a file's year was before `tracks.year` existed;
> 3. **a new column** — NULL on every existing row until its file is read again.
>
> It costs a full read of the library, because the content hash is taken over the audio stream: to
> read a file's tags is to read the file. A routine scan of 12,000 files takes under a second. That
> is why it is a flag and not a default.
>
> **A forced re-read is not a change**, and the summary keeps them apart: a file whose bytes are what
> they were is counted as `re-read`, keeps its cached cover (the picture cannot have moved), and is
> never reported as `changed`.

### Arguments & options

| Name | Kind | Default | Meaning |
| --- | --- | --- | --- |
| `--area` | option (repeatable) | all | Limit to `music` and/or `audiobooks`. |
| `--skip-cleanup` | flag | off | Skip the junk-file cleanup step. |
| `--reread` | flag | off | Read every file's tags again, ignoring the unchanged-file fast path (slow — see the note above). |

### Config (`config/mixtape.php`)

- `library.paths.{music,audiobooks}` — absolute server paths per
  area (`MIXTAPE_*_PATH`; default under `/var/media`).
- `scan.extensions` — audio extensions to scan (default `['mp3']`).
- `scan.cleanup_masks` — junk-file patterns for the cleanup step.
- `scan.alert_email` — where a **fatal** scan error is e-mailed
  (`MIXTAPE_SCAN_ALERT_EMAIL`; empty → log only). The run always logs to the
  `library` channel (`storage/logs/library.log`) and exits non-zero on failure.

> **Unused areas:** leave an area's path **empty (or unset)** to disable it — the
> scan skips it (touching no rows), so a collection with no audiobooks just leaves
> `MIXTAPE_AUDIOBOOKS_PATH` empty. There are no code defaults; the `.env` values are the
> config. A **non-empty** path that isn't a directory is treated as a failure (a
> typo or a dropped mount), so the area isn't silently "found empty" and
> orphan-deleted.

> **Empty-directory guard:** if a configured, existing directory yields **zero
> files while the library still has rows for that area**, the scan refuses to
> prune — it leaves every row intact — on the assumption a dropped mount is far
> likelier than a real mass-deletion. Because that almost always signals a
> problem, it is **escalated like a failure**: logged, e-mailed to
> `scan.alert_email` (`LibraryAreasEmpty`), and the command **exits non-zero** —
> but healthy areas in the same run still scan normally. To genuinely empty an
> area, remove the rows deliberately rather than via a scan that found nothing.

> **Resilience:** a file that can't be read is skipped rather than aborting the
> run — but never silently:
> getID3's full diagnosis (errors **and** warnings, e.g. *"garbage data for 49902
> bytes between 522 and 50424"*) is logged to the `library` channel, and if any
> files were skipped the run e-mails an end-of-run summary (`LibraryScanSkipped`,
> each path + reason) to `scan.alert_email`. Skips are non-fatal (exit 0); only a
> structural failure (a configured-but-missing path, a DB error) aborts and
> triggers the failure alert. Malformed files often re-mux clean with
> `ffmpeg -i in.mp3 -c copy fixed.mp3`, after which the next scan imports them.

### Related code

- `app/Console/Commands/UpdateLibrary.php` — the thin command (orchestrate +
  narrate + failure e-mail).
- `app/Services/Library/LibraryCleanupService.php` — the cleanup step.
- `app/Services/Library/LibraryScanService.php` — the content-hash diff.
- `app/Services/Library/Id3TagReader.php` (+ `Contracts/TagReader.php`) — getID3
  tag/stream reading and the audio-frame hash.
- `app/Mail/LibraryScanFailed.php` — the failure alert e-mail.
- `config/mixtape.php`, the `library` channel in `config/logging.php`.

---

## `app:clean`

Deletes the OS/Samba junk that clients scatter through the library shares —
`._*` (macOS AppleDouble), `.DS_Store`, `Thumbs.db`, `AlbumArt*`, Samba `.@__*` /
`.smbdelete*`, `*.gp5` (masks in `config('mixtape.scan.cleanup_masks')`).

```
php artisan app:clean {--area=*}
```

This is exactly the cleanup step [`app:update`](#appupdate) runs first (unless
`--skip-cleanup`), exposed as a standalone command for sweeping the shares
without a full scan — handy because macOS/Samba re-create these files whenever the
shares are browsed or played from. Both commands call the same
`LibraryCleanupService`, so behaviour is identical; only real files are removed
(never media or `Folder.jpg`), and a missing/empty area path is skipped, not an
error.

### Arguments & options

| Name | Kind | Default | Meaning |
| --- | --- | --- | --- |
| `--area` | option (repeatable) | all | Limit to `music` and/or `audiobooks`. |

### Related code

- `app/Console/Commands/CleanLibrary.php` — the thin command.
- `app/Console/Commands/Concerns/ResolvesLibraryAreas.php` — `--area` parsing +
  narration shared with `app:update`.
- `app/Services/Library/LibraryCleanupService.php` — the cleanup logic.

---

## `app:encoding`

Lists the library paths that a **Windows-1252** `.m3u` export cannot name, as a
Markdown file to work through.

```
php artisan app:encoding {--area=*} {--output=}
```

### Why it exists

A playlist can be exported as Windows-1252, which exists for one real reason:
some car head units render a UTF-8 playlist as mojibake. That encoding covers
about 250 characters, and `PlaylistExport` writes anything outside them as `?`.

On a path line that is not a cosmetic loss — it is a **dead line**. The player
looks for a file that cannot exist, and `?` is not even a legal filename
character on FAT. Nothing in the exporter can rescue it: if a filename holds a
character the encoding lacks, no byte sequence in a Windows-1252 file can name
that file. The only fix is to **rename the file**, which is why this reports
rather than repairs.

The export modal already warns the reader per playlist
(`resources/app/utils/encoding.ts`, same predicate). This is the other half —
the owner's view of the **whole collection**, so the handful of offenders can be
renamed once instead of being warned about forever. On the real collection that
was 89 paths of 12 074, and they **cluster**: 27 for one band, 23 for another,
10 for one record. Not 0.7% of every playlist — none of most, and all of a few.

### What it does

Walks the configured library roots (`scan.extensions` only, so artwork and
sidecars are ignored), tests every **area-relative path** against the encoding,
and writes a report containing:

1. **Summary** — files scanned, paths that fail, distinct characters, things to
   rename.
2. **The characters** — each offender with its code point, Unicode name, how
   many paths carry it, and what to do about it. Roughly half of what a real
   collection turns up is **invisible on screen**, so the glyph column is often
   `—` and the code point is the only handle you get.
3. **What to rename** — the work list, grouped by path **segment** and ordered
   by how many files each rename fixes. A bad folder name is one job, not one
   per track; a name containing an invisible character is reprinted with the
   offender spelled out (`My Room⟨U+F023⟩.mp3`) so you can see where it sits;
   and where intl has an ASCII equivalent for every offender, a concrete
   replacement name is suggested.
4. **Every affected file** — the full list, so the grouping hides nothing.

> **It reads the filesystem, not the database** — deliberately. Renaming and
> re-scanning are two steps, and the useful moment to run this is *between* them:
> you want to know whether the rename you just did was complete, before
> `app:update` writes the new paths in. Re-running it is how you confirm the list
> has emptied.

### Arguments & options

| Name | Kind | Default | Meaning |
| --- | --- | --- | --- |
| `--area` | option (repeatable) | all | Limit to `music` and/or `audiobooks`. |
| `--output` | option | `windows-1252-paths.md` in the **current directory** | Where to write the report. A throwaway working file belongs next to whoever ran the command, not in `storage/`. |

### Exit codes

`0` **even when it finds things** — this is a report, not a linter, and a
collection may legitimately sit with known offenders for as long as its owner
likes; exiting non-zero would turn a standing list into a nightly cron mail.
`2` on an unknown `--area`, `1` when the report could not be written (silently
"succeeding" with no file is the one outcome that would waste real time).

### The workflow

```bash
php artisan app:encoding          # read windows-1252-paths.md, rename what it lists
php artisan app:update            # let the database follow — reports the renames as MOVED
php artisan app:encoding          # confirm the list has emptied
```

The middle step is the one that is easy to forget, and its absence looks exactly
like a broken player: until it runs, the database still holds the old paths and
every file you just renamed is **unplayable**. Because identity is the
audio-frame hash and not the path, the scan reports renames as *moved* and each
track keeps its id — so playlists, play counts and share links survive. If it
reports *new* and *removed* instead, stop and look: a file was not matched.

> **Not every offender needs a new name.** macOS stores filenames decomposed, so
> `Chèvre` can arrive as `e` + a combining grave. The composed `è` **is** in
> Windows-1252 and the pair is not, so the same word passes or fails on its
> normal form alone. The report marks those *precompose only* — the fix changes
> bytes without changing one visible glyph.

### Related code

- `app/Console/Commands/AuditPathEncoding.php` — the thin command.
- `app/Services/Library/PathEncodingAudit.php` — the walk and the check. Asks
  mbstring the same question the exporter does (only the substitute differs), so
  the two can never disagree; a test pins them together through a real render.
- `app/Services/Library/PathEncodingAuditResult.php` — the roll-ups (character
  counts, rename targets).
- `app/Services/Library/PathEncodingReport.php` — the Markdown document.
- `app/Services/Playlists/PlaylistExport.php` — the exporter this protects.
- `resources/app/utils/encoding.ts` — the same check in the browser, for the
  per-playlist warning in the export modal.

---

## `app:playlist`

Fills a playlist with tracks taken at random from the library that is **actually
there**.

```
php artisan app:playlist {name=Testliste} {--user=} {--tracks=12} {--type=music}
```

### Why it exists

To fill a long list quickly with something arbitrary — which is what a test
playlist wants and what a hand-picked one is not. The UI can add to a playlist
from every detail page's hero and from the play queue's menu
(`POST /playlists/{playlist}/tracks`), but that is one track or one subject at a
time.

**A seeder is the alternative, and it is useless on any instance whose collection
is real.** `LibrarySeeder`'s tracks are factory rows pointing at paths no file was
ever written to, so the playlist *looks* right and every row is silently
unplayable; worse, running `migrate:fresh --seed` on a box that has a scanned
collection would throw that collection away to fix it. (That seeder is switched
off in `DatabaseSeeder` for exactly this reason.) This command picks from `tracks`
instead, so whatever `app:update` found on disk is what lands in the playlist,
and pressing play really plays.

### What it does

1. Validates everything *before* writing anything — an unknown user, an unusable
   `--type` or an empty library all bail before the playlist is created, so a
   failed run leaves no half-made playlist behind.
2. Resolves the owner: `--user` when given, otherwise the only account on the
   instance, otherwise an interactive picker. It never silently defaults to "the
   first user" — this box is shared with family and friends, and a command that
   guessed would occasionally fill a stranger's playlist.
3. Picks `--tracks` rows `inRandomOrder()`, so two runs give different playlists.
4. Finds the named playlist for that user, or creates it.
5. **Appends** them in one transaction and prints the playlist's URL.

> **Nothing here is destructive.** It only ever appends, so running it twice
> gives a longer playlist rather than a replaced one, and it is safe against a
> real account's real data. The playlist is matched on `(user_id, name)` — what
> the account already treats as unique — so a second run tops the same list up
> rather than failing on the constraint or making "Testliste (2)".

### Arguments & options

| Name | Kind | Default | Meaning |
| --- | --- | --- | --- |
| `name` | argument (optional) | `Testliste` | The playlist to fill. Created if the account has none by that name. |
| `--user` | option | *the only account, or a picker* | Owner's **username** (`users.name`). |
| `--tracks` | option | `12` | How many tracks to add. Must be ≥ 1. Asking for more than the library holds adds what there is and says so. |
| `--type` | option | `music` | Which kind to pick: `music`, `audiobook`, or `any`. |

### Examples

```bash
# 12 random songs into "Testliste", for the only account on the instance
php artisan app:playlist

# A long audiobook list for a named user
php artisan app:playlist "Hörproben" --user=Ashaltiriak --tracks=40 --type=audiobook
```

### Exit codes

`2` on an unusable `--type` or `--tracks` below 1; `1` when the user cannot be
resolved (unknown name, or no accounts at all) or the library has nothing of the
requested kind; `0` otherwise.

### Related code

- `app/Console/Commands/FillPlaylist.php` — the command (all of it; there is no
  service, because there is no second caller).
- `lang/{de,en}/playlist_command.php` — its console strings.
- `database/seeders/LibrarySeeder.php` — the seeder this exists instead of, and
  why it is switched off.

## `app:db-backup`

```
php artisan app:db-backup {--path=} {--retention-days=} {--keep-all}
```

### Why it exists

Half of this database is disposable and half of it is irreplaceable, and only one
command can tell them apart.

**Disposable:** tracks, albums, artists, genres, authors, narrators. All of it is
derived from the files under `/var/media`, and [`app:update`](#appupdate) rebuilds it
in under a minute. Losing it costs a scan.

**Irreplaceable:** accounts and their 2FA enrolment, invites, playlists, listening
history, player state, audiobook bookmarks, and **share links**. Nothing reconstructs
any of it from disk.

The share links are the sharpest of those. A share id *is* the capability, so a link
already sent cannot be reissued — losing that table means links sitting in other
people's chat windows stop working, with no way to reach whoever is holding them.

Because the derived half dominates the row count and the irreplaceable half is small,
these dumps are megabytes rather than gigabytes, which is what makes a 30-day window
affordable.

### What it does

1. Refuses if the backup drive is not mounted — see the trap below.
2. `pg_dump --format=custom --no-owner --no-privileges` to a `.partial` file.
3. Reads the archive's table of contents back with `pg_restore --list`.
4. Renames it into place only if that succeeded.
5. Deletes dumps older than the retention window, and any stale `.partial`.

**The verify step is the point.** "The file is 4 MB" and "Postgres can read this back"
are different claims, and only the second one is a backup. A name in the backup
directory therefore means *this one restored*, not *this one finished writing* — a dump
cut short by a full disk or a pulled cable is discarded rather than kept.

**Custom format, not `.sql`.** It is compressed already, so there is no second pass to
compress yesterday's dump, and it is the only format `pg_restore` can restore
selectively or in parallel from.

**The password never appears in `ps`.** It goes in a 0600 `PGPASSFILE`, not in
`PGPASSWORD` (readable from `/proc/<pid>/environ` by anything else running as the same
user) and never on the command line.

### Arguments & options

| Option | Default | Meaning |
| --- | --- | --- |
| `--path=` | `config('mixtape.backup.path')` | Where to write. |
| `--retention-days=` | `config('mixtape.backup.retention_days')` (30) | Delete dumps older than this. |
| `--keep-all` | off | Write the dump and prune nothing. |

### The trap it guards

> **An unmounted backup drive fails silently upward, not downward.** `mkdir -p
> /mnt/usb/db-backups` on an unmounted path succeeds — against the *root* filesystem —
> so the backup runs green for months onto exactly the disk it exists to survive the
> loss of. The command refuses when the parent directory is missing, and the systemd
> unit names the mount in `RequiresMountsFor=`. Both, deliberately.

### Related code

- `app/Console/Commands/BackupDatabase.php` — the command.
- `app/Console/Commands/Concerns/RunsPostgresTools.php` — the `PGPASSFILE` handling,
  shared with the restore.
- `config/mixtape.php` → `backup` — path and retention.
- `docs/self-hosting/03-production-deploy.md` → *Scheduled database backup* — the timer.

## `app:db-restore`

```
php artisan app:db-restore {--file=} {--path=} {--force}
```

### Why it exists

To put one of those dumps back. It is the most destructive command in this project and
the only one that can undo work nobody can redo.

### What it does

1. Lists the dumps newest-first and asks which, unless `--file=` names one.
2. Verifies the archive **before anything is dropped**.
3. Makes you type the database name.
4. `pg_restore --clean --if-exists --single-transaction`.

**Verifying first is the whole design.** `--clean` drops each object before recreating
it, so a truncated dump discovered halfway through would leave a database holding
neither the old data nor the new. The `--list` pass costs milliseconds and turns that
into a refusal that changes nothing.

**Typing the name, not answering a prompt.** "Are you sure? [y/N]" is answered yes by
reflex, and the mistake being guarded against is not doubt — it is restoring the right
dump into the wrong database, which a yes/no question cannot catch because it never
asks *which*.

### Arguments & options

| Option | Default | Meaning |
| --- | --- | --- |
| `--file=` | — | Restore this dump instead of choosing one. |
| `--path=` | `config('mixtape.backup.path')` | Where to look for dumps. |
| `--force` | off | Skip the typed confirmation. **Refused when `APP_ENV=production`.** |

`--force` exists so a development box can be reset from a script. On the live instance
an unattended restore is precisely what must not be possible, so there is no flag for
it — a person reads the name and types it, or it does not happen.

### Notes

> **Put the site in maintenance first** (`mt artisan down --prod`). Not enforced, since
> a restore is also how you recover an instance that is already down — but
> `pg_restore --clean` will fight open connections over the objects it drops.

Run [`app:update`](#appupdate) afterwards if the library has changed since the dump,
since the derived half will describe the files as they were.

### Related code

- `app/Console/Commands/RestoreDatabase.php` — the command.
- `tests/Feature/Console/DatabaseBackupTest.php` — the refusals, which is what is
  testable without a PostgreSQL server.
