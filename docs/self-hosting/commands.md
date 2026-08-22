# Commands

The scripts you actually run once the box exists — two on the server, two on the workstation. Building
the box is [`03-production-deploy.md`](03-production-deploy.md); this page is the day after.

| Script | Runs on | For |
| --- | --- | --- |
| [`mixtape-prod-deploy`](files/mixtape-prod-deploy.sh) | the server | deploying `main` to production, or rolling back to a SHA |
| [`mixtape-dev-deploy`](files/mixtape-deploy-dev.sh) | the server | rebuilding the dev site from whatever is on disk |
| [`mt`](files/mt.sh) | the workstation | one-off artisan, logs, tinker and a shell, on either site |
| [`mts`](files/mts.sh) | the workstation | copying a library area onto a local disk |

> **The two workstation scripts are written for macOS.** Not incidentally — they depend on it. Both
> guard against **bash 3.2**, which is what `/bin/bash` still is on a Mac and where expanding an empty
> array under `set -u` is an error rather than an empty expansion. `mts` additionally builds its whole
> rsync invocation around **openrsync**, the macOS `rsync` that is not rsync 3.x; reads volumes under
> **`/Volumes`**; compares filesystems with BSD **`stat -f %d`**; and sweeps the AppleDouble sidecars
> that only a Mac writing to FAT creates. On Linux, `mt` would need little more than a shebang change,
> while `mts` would need most of its flag choices revisited — the things it works around are not there.
>
> Both install on the machine you sit at, never on the server, and both refuse to run until `HOST` at
> the top names your server.

## On the server

### Routine deploys — `mixtape-prod-deploy`

```bash
sudo -u mixtape-deploy /usr/local/bin/mixtape-prod-deploy
```

That is the whole loop: push to `main`, run that on the server. Pass a commit SHA as an argument to
roll back to a known-good commit.

The script puts the site into maintenance mode first and **deliberately leaves it there if the deploy
fails** — serving new code against a half-applied migration is worse than showing a maintenance page.
It also refuses to run if the working tree is dirty, on the grounds that someone hand-patched the box
and `git reset --hard` would silently destroy their work.

### Rebuilding the dev site — `mixtape-dev-deploy`

The dev site works nothing like the above, and conflating the two is the main way to waste an
afternoon. It is **not a git checkout** — source arrives by SFTP from the workstation IDE — so there
is nothing to fetch. [`files/mixtape-deploy-dev.sh`](files/mixtape-deploy-dev.sh) rebuilds whatever is
already on disk:

```bash
mixtape-dev-deploy            # rebuild + migrate
mixtape-dev-deploy --fresh    # rebuild + migrate:fresh --seed
```

Install it as `/usr/local/bin/mixtape-dev-deploy` (755 root:root) and edit `HEALTH_URL` at the top.
Run it as your own admin user, never root, and never while an upload is still in flight — it cannot
detect a half-uploaded tree.

Four ways it deliberately differs from the prod script:

- **It does not cache config.** Prod ends with `config:cache`/`route:cache`; dev ends with
  `optimize:clear` and nothing else. Caching on dev would reintroduce the "editing `.env` changes
  nothing" trap on the box where you iterate most.
- **It installs dev dependencies.** No `--no-dev`; tests and debug tooling are the point of dev.
- **It runs at `umask 002`, not `027`.** Prod's mask exists so `www-data` can never rewrite prod code.
  Dev inverts that: the box is LAN-only and both you and the runtime write freely.
- **It normalizes `storage/` and `bootstrap/cache` ownership first.** This is the non-obvious one.
  php-fpm runs as `www-data` with its own umask, so files it creates at runtime
  (`storage/logs/laravel.log`, `bootstrap/cache/*.php`) come out `www-data:www-data 644` — not
  group-writable. The next rebuild runs as *you*, cannot overwrite them, and fails somewhere
  unhelpful: `composer install` dies inside `package:discover` because it cannot rewrite
  `bootstrap/cache/packages.php`. Re-normalizing each run makes it self-healing rather than a slow
  slide into a broken tree.

> **A note on both scripts' `HEALTH_URL`.** Write it quoted. Unquoted, the angle brackets in a
> `https://<placeholder>/` template are shell *redirections*, so an unedited copy fails with a
> confusing "No such file or directory" rather than saying what is wrong. The dev script quotes it
> and guards on the placeholder still being there.

### Running artisan in production

```bash
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan <command>
```

Always as `www-data`, so anything artisan writes is owned by the runtime user.

> `artisan tinker` fails with `Writing to directory /var/www/.config/psysh is not allowed` — www-data's
> home is deploy-owned by design. Give it a writable home for the one command:
>
> ```bash
> sudo -u www-data env HOME=/tmp /usr/bin/php /var/www/mixtape.prod/artisan tinker --execute='...'
> ```

`artisan about` is the fastest way to see what the application actually believes about its
configuration — particularly the mail and database drivers, which is where a stale config cache shows
up.

## On the workstation (macOS)

### Driving both sites — `mt`

Everything above assumes you are already logged into the server. In practice most artisan calls are
one-offs — put dev into maintenance, check `about` on prod, tail a log — and doing them by hand means
remembering which site runs as which user, then typing a different `ssh` line for each.

[`files/mt.sh`](files/mt.sh) is a thin wrapper for exactly that. Install it on the **workstation**,
not the server:

```bash
install -m 755 mt.sh ~/.local/bin/mt     # ensure ~/.local/bin is on $PATH
```

Then edit `HOST` at the top to your ssh alias. It refuses to run until you do.

```bash
mt artisan down --dev         # dev into maintenance
mt artisan up --dev
mt artisan migrate --prod     # prompts for your sudo password
mt artisan about --prod
mt logs -f --dev
mt logs --auth --prod       # the auth.log that feeds fail2ban
mt tinker --prod
mt shell --dev
```

The `--dev` / `--prod` flag may appear **anywhere** in the line and is stripped before the rest is
forwarded, so `mt artisan down --dev` and `mt --dev artisan down` are the same command and artisan
never sees the flag.

#### How mt decides things

**Dev is the default target.** Prod is only ever touched when you explicitly type `--prod`, so a
forgotten flag can only ever hit the throwaway site. This is the single most useful property of the
wrapper and the reason not to make the target a positional argument.

**The two targets run as different users**, mirroring the [ownership model](03-production-deploy.md#the-deploy-model-in-one-paragraph) the build establishes rather
than inventing a third convention:

| | Runs as | Why |
| --- | --- | --- |
| dev | you, directly | you own the tree (`<admin-user>:www-data`, 2775); artisan writing as you is correct |
| prod | `sudo -u www-data` | your account is not in `www-data` and cannot even traverse `2750` prod; this is the same invocation the deploy script uses, so everything artisan writes stays owned by the runtime user |

Running prod artisan as any other identity leaves files `www-data` cannot rewrite, and that surfaces
much later somewhere confusing — the same failure described under *Rebuilding the dev site*.

**The prod sudo hop prompts for your own password.** `mixtape-deploy`'s `NOPASSWD` rule belongs to
that account, not yours, so there is no way to make this passwordless without widening sudoers. Treat
the prompt as a feature: one more beat of friction between a typo and production.

**Destructive migrations against prod require typing `PRODUCTION` first.** `migrate:fresh`,
`migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe` are one fumbled flag away from
their harmless dev equivalents. Laravel's own `--force` confirmation is no help here: artisan runs
non-interactively on the far side of an ssh pipe, where that prompt never fires. The wrapper therefore
asks on the *workstation* side, reading from `/dev/tty` so a piped stdin cannot answer for you.

**It deliberately does not deploy.** Deploys carry guards a passthrough wrapper has no business
duplicating — the unpushed-commit check, maintenance-mode handling, the dirty-tree refusal. Keep
calling `mixtape-prod-deploy` / `mixtape-dev-deploy` for those.

#### mt traps

- **`cd <site> && sudo -u www-data …` fails on prod with "Permission denied".** The `cd` runs as
  *your* login user, and only the `sudo` after it switches to `www-data` — so on the `2750`
  deploy-owned tree the command dies before sudo is ever reached. The fix is not to `cd` at all:
  artisan resolves its base path from its own location (`__DIR__`), not the working directory, so an
  absolute path behaves identically from any cwd. Confirm for yourself with
  `cd / && php /var/www/mixtape.dev/artisan about`. Where a `cd` genuinely is wanted — an interactive
  shell — it has to happen *inside* the sudo.
- **`sudo -u www-data -s` exits immediately.** `-s` runs the target user's login shell, and
  `www-data`'s is `/usr/sbin/nologin` by design, so you get "This account is currently not available"
  rather than a shell. Name the shell explicitly (`sudo -u www-data bash`), and give it `HOME=/tmp`
  so it is not trying to write history into `/var/www`.
- **`storage/logs/laravel.log` does not exist on prod.** The two sites run different log drivers: dev
  is `single`, which writes exactly that file, while prod's `.env` sets `LOG_CHANNEL=stack` and
  `LOG_STACK=daily`, and Laravel's **daily** driver writes `laravel-YYYY-MM-DD.log` instead. Anything
  that hardcodes `laravel.log` works on dev and fails on prod with a bare "No such file or directory".
  Resolve the newest `laravel*.log` at read time instead — that is right under either driver, and
  survives the date rolling over. Note the auth channel is a *separate* `single` file,
  `storage/logs/auth.log`, which is what the fail2ban jail reads.
- **`artisan tinker` on prod needs `HOME=/tmp`** — the psysh problem noted above. The wrapper applies
  it to both `mt tinker --prod` and `mt artisan tinker --prod`, since they are the same underlying
  command.
- **macOS ships bash 3.2**, where expanding an *empty* array under `set -u` is an "unbound variable"
  abort, not an empty expansion. `"${ARGS[@]}"` therefore breaks the commonest call of all —
  `mt tinker --dev`, which has no remaining arguments. Every such expansion needs the
  `${ARGS[@]+"${ARGS[@]}"}` guard.
- **A TTY mangles pipes.** `ssh -t` translates LF to CRLF, so `mt artisan route:list --dev | grep …`
  would silently see `\r` on every line. The wrapper allocates a TTY only when stdout is a terminal —
  except on prod, which must force one (`-tt`) so sudo can prompt. Piping prod output does therefore
  yield CRLF; that is the one accepted rough edge.

#### Tab completion (zsh)

[`files/_mt`](files/_mt) completes the subcommands, the artisan commands, and the target flags.
Install it into any directory on `$fpath` — under oh-my-zsh, `$ZSH_CUSTOM/completions` is already
there:

```bash
cp _mt ~/.oh-my-zsh/custom/completions/_mt
rm -f ~/.zcompdump* && exec zsh          # see below — this line is not optional
```

The artisan list is **static on purpose**. Completing from the live `artisan list` would open an ssh
connection on every `<TAB>` — and on `--prod` block on a sudo password prompt with no terminal to
render it. The list drifts as the app grows; that is fine, because completion is a convenience and
never a whitelist. Anything absent still runs when typed in full.

Three things that made this harder than it looks, all of which fail *silently*:

- **oh-my-zsh caches completions** in `~/.zcompdump-<host>-<version>` and rebuilds it only when the
  OMZ revision or the **`fpath` string** changes. `$ZSH_CUSTOM/completions` is on `fpath` whether or
  not it contains anything, so *adding a file there does not invalidate the cache*. Restarting the
  shell is not enough; delete the dump.
- **`_describe` splits each entry on the first unescaped colon.** Artisan commands are full of colons,
  so every one must be written `\:`. Miss it and the entry half-works in a way that looks plausible in
  the list: `'config:show:…'` completes the value `config` with the description
  `show:show a resolved config value`, and inserts the wrong command.
- **Candidates starting with `-` are options**, and options are only displayed when the surrounding
  context has requested the `options` tag. Inside a nested `_arguments` state that negotiation does not
  happen, so `_describe` reports success while displaying nothing — `mt artisan down --<TAB>`, the
  commonest position of all, completes silently to nothing. Use `_wanted options expl … compadd`,
  which requests the tag explicitly.

### Copying the collection to a local disk — `mts`

The other thing a workstation wants from the server is the media itself — filling a USB stick for a
car, or a phone. [`files/mts.sh`](files/mts.sh) does that one job, and it is deliberately a **separate
script rather than an `mt` subcommand.** Every `mt` command ends in `exec ssh HOST <remote-command>`,
and everything below its parser — the `printf %q` quoting for the remote shell, the TTY policy, the
`sudo` hop, resolving a target to a site directory — exists to run something *on the server*. A
transfer's destination is a path on the workstation and shares none of it. `mt`'s organising idea does
not survive the move either: the media library belongs to neither site, because both read the same
collection, so `mt transfer --prod` would parse cleanly, be stripped from the line like any other
target flag, and mean nothing. A command whose central flag is silently inert is worse than a second
command.

```bash
install -m 755 mts.sh ~/.local/bin/mts             # ensure ~/.local/bin is on $PATH
cp _mts ~/.oh-my-zsh/custom/completions/_mts
rm -f ~/.zcompdump* && exec zsh                    # the cache note above applies here too
```

Then edit `HOST` at the top, exactly as for `mt`. It refuses to run until you do.

```bash
mts music /Volumes/<usb-label>/              # add what is missing, update what changed
mts music /Volumes/<usb-label>/ --mirror     # …and delete what the library no longer has
mts music /Volumes/<usb-label>/ -n           # print the plan, write nothing
mts audiobooks /Volumes/<usb-label>/Books/
```

Once per disk, stop macOS indexing something a head unit has to read:

```bash
sudo mdutil -i off /Volumes/<usb-label>
sudo rm -rf /Volumes/<usb-label>/.Spotlight-V100
touch /Volumes/<usb-label>/.fseventsd/no_log
```

**Mirror music to the volume root, not into a subfolder.** At the root, every path on the disk is
exactly the area-relative path the database stores, so a playlist exported from the app with an
**empty** path prefix resolves on the disk verbatim — which is what `config/mixtape.php` means when it
says a prefix describes "the machine doing the listening". Put the `.m3u` files at the root, beside
the artist folders.

#### How mts decides things

**It carries audio and cover images, not the tree.** A library accumulates spreadsheets, helper
scripts and OS droppings that have no business on a car stick, so the filter is an allowlist —
`--include='*/'` to descend, the media globs, then `--exclude='*'` — with `--prune-empty-dirs` so a
folder that held nothing else is not created at all. `--all` turns it off. The audio extensions
mirror the app's `scan.extensions`; if the scanner learns a format, this is the other place to teach
it.

The globs are written as character classes — `*.[Jj][Pp][Gg]`, not `*.jpg` — because **rsync's
patterns are case-sensitive and the scanner's are not.** A library the app is perfectly happy with
can hold a `.JPG` among a thousand `.jpg`, and a plain lowercase glob leaves those albums on the disk
with no cover art while reporting nothing wrong. Measured here: exactly two files, which is precisely
the size of mistake that never gets noticed.

**`-rt`, never `-a`.** FAT32 and exFAT store no ownership, permissions or symlinks, so `-a` asks for
four things the filesystem cannot hold. Nothing needs subtracting afterwards, which is fortunate —
see the flag table below.

**`--modify-window=1` is the flag whose absence is silent and expensive.** FAT records modification
times to a two-second granularity. Without the window every file looks changed on every run, so a
"top up" re-sends the whole library, and nothing anywhere reports a problem.

**Resumability is retries, not a resume protocol.** rsync is already incremental, so the honest
answer to a dropped connection is to run it again — `--partial-dir` keeps the file that was in flight,
and the loop re-runs up to `--attempts` times with a backoff. What makes that work is `--timeout`: an
interrupted transfer has to *fail* before it can be retried, and without an I/O timeout a stalled
connection simply hangs. Exit codes that mean the invocation is wrong (`1`, `2`, `4`) are not retried,
because they will fail identically five times.

**`--mirror` is refused where the filesystem renames what you write.** macOS writes a filename to FAT
in *decomposed* form — `é` becomes `e` plus a combining accent — while the server stores whatever the
tagger wrote, here precomposed. Lookups are normalisation-insensitive, so transfers are unaffected and
stay idempotent; a directory *listing* is not, so rsync reads back a name that is not in its send list
and calls a correctly-copied file extraneous. Measured on this collection: **0 files to copy and 553
to delete**, every one an accented title sitting there and playing fine. A mirror would delete them
and re-copy them on every run, for ever. So the property is *probed* rather than assumed — write one
precomposed name into the destination, read the directory back, see which form returns — because it
belongs to the volume and not to any list of filesystems a script could keep: the same Mac preserves
on APFS and decomposes on FAT. It refuses rather than asking, because 553 deletions of good files is
not something a yes/no prompt should be able to wave through, and dropping `--mirror` loses nothing
but the deletions.

**Two more destination guards, because the mistake is expensive.** The directory is never created — a
mistyped volume name does not exist, so the run fails instead of quietly filling the boot disk — and
its device number must differ from `/`'s. Device numbers rather than parsing `df`'s mount-point
column, which cannot be split safely when a volume name contains a space. `--mirror` adds a third:
it requires a destination on a local `/dev/…` disk, because the library's own Samba share is very
likely mounted on the same workstation, and `mts audiobooks /Volumes/<share>/music/ --mirror` would
otherwise faithfully delete the music collection off the server.

**Deleting from the disk is confirmed, but not with a typed word.** `mt` demands one for a production
migration because that data exists nowhere else. Here everything on the destination is a copy of
something the server still has, so a wrong answer costs a re-run — the question exists only so a
`--mirror` that would empty the disk cannot pass unnoticed, and it is asked only when the plan
actually contains deletions.

**The AppleDouble sweep is not optional.** macOS stamps a file it creates with an extended attribute,
and FAT cannot store one — so the volume driver spills each into a 4 KB `._<name>` sidecar beside the
real file. The library's own files carry no extended attributes at all; these are made on the way in,
which is why an `--exclude` cannot prevent them, and an eleven-thousand-file library otherwise
arrives with **12,319** phantom files that a head unit lists as tracks. `dot_clean` does not finish
the job on FAT: measured on two files, it merged one sidecar, left the other and the directory's own
behind, and restored the extended attribute it had just merged, which spills again.

**Deleting the sidecar is the whole cure, and an `xattr` pass is the trap.** On FAT the sidecar *is*
where the attribute is stored, so `xattr -l` on a swept file already comes back empty — an
`xattr -rc` pass over the tree removes nothing the delete has not, and over twelve thousand entries
it runs for **minutes**, a syscall at a time, while the transfer looks like it has hung. That is worth
stating plainly because the intuitive order — remove the artefact, then clear the cause — is both
redundant and the slowest possible way to do nothing.

They come back on **every** run, not only the first: rsync re-stamps directory times whenever it walks
the tree, so a pass that transfers nothing still leaves a sidecar beside each of ~1,300 directories.
The sweep therefore hangs off the shell's `EXIT` trap rather than sitting after the transfer loop —
`fail` exits, so a run that gives up after its retries would otherwise skip it and leave every sidecar
it made on a disk somebody is about to unplug. The same trap covers Ctrl-C and a closed terminal, and
it is armed only once the destination is validated. A dry run is exempt: `-n` must leave the disk
exactly as it found it. The partial directory is never swept, because on an interrupt it holds the
file that was in flight — the whole reason for keeping it.

**Progress is counted, not measured.** The dry run that produces the plan also produces the file
count, so the real run is filtered through `awk` into one updating `[n/total pct%]` line — openrsync
has no `--info=progress2` to do it properly. The count is carried across attempts in a temporary
file, or the display would jump backwards after a dropped connection.

**The run ends with a summary, not just a last progress line**, because "how far along is it" and
"what did that just do to my disk" are different questions. New files and updated ones are counted
separately, straight off the itemized flags — a transfer whose every attribute slot is `+` was not
there at all, any other flag string means it was and something differed — alongside deletions,
sidecars swept, bytes moved with the rate they moved at, and elapsed time. Every row is a measurement
the run actually took rather than a restatement of the plan; the two differing is exactly the case
worth seeing.

That `awk` runs under **`LC_ALL=C`**, which is not a detail. rsync's itemized output is not
guaranteed to be valid UTF-8: it escapes some non-ASCII bytes in a filename as `\#NNN` octal and
passes others through raw, so `Tír na mBan.mp3` arrives as a lone `0xC3` followed by the literal text
`\#255`. In a UTF-8 locale `awk` then prints `towc: multibyte conversion failure` once per such line.
It keeps going and the transfer is unaffected — but on a collection with any accented titles the
warnings bury the display they are printed over. Byte-oriented `awk` never attempts the conversion.

#### macOS ships openrsync, and it is missing flags you will reach for

`/usr/bin/rsync` on a current macOS is **openrsync**, which announces itself as `rsync version 2.6.9
compatible` and is not rsync 3.x. Most of what a sync like this needs is there; the gaps are what
make a recipe copied from anywhere else fail on the first line.

| Flag | | Consequence |
| --- | --- | --- |
| `--modify-window`, `--partial-dir`, `--timeout`, `--contimeout`, `--include`, `--prune-empty-dirs`, `--delete`, `--bwlimit`, `--itemize-changes`, `--stats` | present | nothing to work around |
| `--no-perms`, `--no-owner`, `--no-group` | **missing** | nothing to subtract from `-a`, so build up from `-rt` instead |
| `--info=progress2` | **missing** | `--progress` reports per *file*; an overall figure has to be counted |
| `--dry-run` | **missing** | `-n` works; only the long spelling is absent |
| `--protect-args` | **missing** | a remote path is handed to a shell on the far side, so a space in it arrives re-split into two paths that do not exist. Escape it inside the quotes: `'<host>:/var/media/music/2\ Ohm/'` |
| `--rsh` | **missing** | `-e` accepts a command with options, which is where the ssh keepalives go |
