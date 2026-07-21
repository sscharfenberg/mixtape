# Artisan commands

Project-specific `artisan` commands for MixTape (namespaced `app:*`). Framework
and package commands (`migrate`, `queue:work`, …) are not listed here — run
`php artisan list` for the full set.

> Run everything from the app root. On the server that is
> `/var/www/mixtape.dev`; locally it is your working copy.

## Index

| Command | Summary |
| --- | --- |
| [`app:invite`](#appinvite) | Mint a one-time, expiring registration invite link |
| [`app:update`](#appupdate) | Scan the media library into the database (cleanup + content-hash diff) |

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

Scans the media library on disk into the database. This is the v2 replacement for
the legacy `app:update` chain (`app:clean` + `app:csv:*` + `app:db:*`).

```
php artisan app:update {--area=*} {--skip-cleanup}
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
2. **Scan** — a **content-hash diff**, not the legacy truncate-and-rebuild
   ([`data-model.md`](data-model.md) → *the one fact*). Per area, in one
   transaction: unchanged files fast-path on `(path, size, mtime)`; a same-path
   byte change is a re-tag (update in place); a new path is hash-matched against
   vanished rows to catch renames; the rest are inserts; gone files are
   relink-then-cascade deleted; orphan taxonomy/collections are pruned.

**Identity is the audio-frame hash**, so a rename *or* a re-tag keeps the track's
id — playlists, most-played, and share links stay anchored. Two files with
identical audio are two rows (clones) sharing a hash.

### Arguments & options

| Name | Kind | Default | Meaning |
| --- | --- | --- | --- |
| `--area` | option (repeatable) | all | Limit to `music`, `audiobooks`, and/or `podcast_shows`. |
| `--skip-cleanup` | flag | off | Skip the junk-file cleanup step. |

### Config (`config/mixtape.php`)

- `library.paths.{music,audiobooks,podcast_shows}` — absolute server paths per
  area (`MIXTAPE_*_PATH`; default under `/var/media`).
- `scan.extensions` — audio extensions to scan (default `['mp3']`).
- `scan.cleanup_masks` — junk-file patterns for the cleanup step.
- `scan.alert_email` — where a **fatal** scan error is e-mailed
  (`MIXTAPE_SCAN_ALERT_EMAIL`; empty → log only). The run always logs to the
  `library` channel (`storage/logs/library.log`) and exits non-zero on failure.

> **Unused areas:** leave an area's path **empty (or unset)** to disable it — the
> scan skips it (touching no rows), so a collection with no podcasts just leaves
> `podcast_shows` empty. There are no code defaults; the `.env` values are the
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

> **Resilience:** unlike the legacy scanner (one bad file aborted the whole run
> *after* truncation), a file that can't be read is skipped — but never silently:
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
