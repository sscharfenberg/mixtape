# The library audit

`app:audit` asks everything MixTape can tell about what is wrong with a music and audiobook
collection, and writes the answers to one Markdown file to work through. This page is the
catalogue: what each check means, why it is worth a section, and what to do about a finding.

The command's own reference — signature, options, exit codes — is in
[`artisan-commands.md`](artisan-commands.md#appaudit).

## Two rules the whole thing rests on

**It reports and never repairs.** Every finding is a decision only the owner of the collection can
make, in a tagger or a file manager. A command that guessed would quietly invent facts about a
library — which is the same reason the scanner reconciles an album's year from its files and
refuses to settle a disagreement.

**A tile's count and an audit's count are the same question asked twice.** Where a check also
exists as a listing filter it reads *that* predicate rather than a copy: `AlbumFilter::Incomplete`
for albums missing a track, `ArtistFilter::LookalikeName` for lookalike names. Written twice they
drift, and the drift reads as a wrong number rather than as a wrong filter. (Note the enum's own
`count()` helper scopes itself to albums, so the audit applies `apply()` to a query of its own —
which is also how one predicate answers for audiobooks.)

## Where a check gets its facts, and why the report says so

Every check declares a **source**, printed beside its count:

| Source | Freshness |
| --- | --- |
| `database` | As of the last `app:update`. |
| `disk` | True now. |
| `disk + database` | Both, compared. |

That distinction is not an implementation detail, and it is why **scan drift is the first check in
the document**. A file that is on disk but has never been scanned is in no listing at all — and
the album holding it reports as *missing a track*, which sends a reader hunting for a file that is
already there. When drift is clean, every database section below it describes the library as it is
right now.

It is also the reason the encoding check reads the filesystem rather than the database: renaming
files and re-scanning are two steps, and the useful moment to look is *between* them.

## The report

One document, and every check appears in it:

- **Summary** — one table per group, each under the promise that group makes. A clean check is a
  row reading `clean`; a check that could not run says **why** instead of showing a zero, because
  "0" for a check that never looked reads exactly like a healthy library.
- **A section per check with findings** — its own explanation of what the finding means and how to
  fix it, then the findings themselves. The document outlives the run and will be read by somebody
  who did not make it, so each section carries its own argument rather than pointing here.
- **Sections are capped at 50 findings** and say how many they left out. A truncated list that
  does not admit it reads as "that is all of them", which is the one wrong impression an audit must
  never leave; the summary's count is always the real total.

**Skipped is never clean**, and the distinction survives all the way up: a run where every check was
skipped is not reported as a healthy library, on the console or in the exit code.

**Clean checks get a row and no section.** Twenty-five sections mostly saying "nothing" would bury
the four that matter, and dropping a clean check entirely would leave a reader unable to tell
"checked and fine" from "not checked". A row costs one line and says both. This is the one place
the browse-stats rule (*a tile that can only read 0 is worse than no tile*) deliberately does not
transfer: a row is not a tile competing for space in a strip, and a check that reads 0 on a
meticulous collection is exactly the check somebody else's library needs.

## The checks

### Scan drift

| Check | Source | What it means |
| --- | --- | --- |
| Files the database and the disk disagree about | disk + database | **On disk only**: not scanned yet — invisible everywhere, and its album reports as short. **Database only**: deleted or renamed since the scan — the row still lists and playing it fails. Both are cleared by `app:update`. A third finding, **library path not reachable**, means a configured share is not mounted: nothing about that area can be compared, so every database section below is describing files nothing can currently see. An area nobody configured says nothing at all. |

### Library structure

| Check | Source | What it means |
| --- | --- | --- |
| Paths a Windows-1252 playlist cannot name | disk | A car head unit reading a Windows-1252 `.m3u` gets a **dead line** for these, since the encoding writes the missing character as `?`. Only a rename fixes it. Has its own section format — see [below](#the-one-check-that-is-not-a-table). |
| Albums missing a track | database | Per disc, the highest track number is **greater** than the file count. |
| Albums with no folder image | database | An album prefers the one image chosen for it as a whole; without it, the thumbnail is whichever track sorts first. Read from `collections.cover_path`, which the scanner writes. |
| Repeated track numbers | database | Two files in **one folder** claiming the same number — a bonus track that kept its neighbour's number, a hidden track numbered 0. Renumber the tags. |
| Two albums in one row | database | The same collision **across folders**, and the **cause** column says which of two faults it is, because the cure is opposite. *Same ALBUM tag*: two different records under one name, so give one a distinguishing tag. *No DISC tags*: one genuine multi-disc set that was never disc-numbered, so tag the discs — renaming it would split a record that belongs together. A properly disc-tagged set never appears at all. |
| Split albums | database | The inverse — one directory feeding **two** album rows, because one file's ALBUM or ALBUM ARTIST tag differs from its siblings'. The album then appears twice with its tracks divided, and each half looks incomplete. |
| Inconsistent disc tags | database | Some files carry a disc number and others none, which splits one album into two groups for every per-disc question — so a **complete** album reads as short in *Albums missing a track*. |

### Library structure — audiobooks

| Check | Source | What it means |
| --- | --- | --- |
| Books missing a chapter | database | The same predicate as albums, over the other area. It costs more here: a gap mid-book is a hole a listener walks into hours in, and per-book resume carries them straight past it. |
| Chapters with no author | database | An audiobook's author lives on the **chapter** (COMPOSER/TCOM is per file, and an anthology uses it per story), so a book has no owner column and dedupes on its title alone. A chapter with no author contributes nothing to who wrote the book. |
| Chapters with no narrator | database | Half of what makes one recording of a book different from another, read per chapter for the same reason. |

### Tag hygiene

Most of these read 0 on a well-kept collection. They exist for the library imported from fifteen
years of mixed sources.

| Check | Source | What it means |
| --- | --- | --- |
| Files with no year | database | The scanner reconciles an album's year from its files and never guesses, so one untagged file leaves the album with a year it did not choose. |
| Songs with no genre | database | Reachable from search and its album, and **from nowhere else** — the Genres section cannot list it and an artist's dominant genre is calculated without it. |
| Songs with no artist | database | Search matches a row on its own name, so it can only be found by title, and it appears under no artist anywhere. ALBUM ARTIST does not stand in. |
| Files with no embedded cover | database | Only a real gap where the folder has no image either — read it beside *Albums with no folder image* and fix whichever is cheaper. |
| Files with no track number | database | Cannot be judged for completeness at all (that check compares the highest number against the file count), and plays in whatever order the names sort in. |
| Files in no album or book | database | No ALBUM tag, so no album page, no cover at album grain, no siblings — and every collection-level check is blind to it. |
| Albums with no album artist | database | The tag that holds a record together when its tracks credit different performers. Without it the album belongs to no artist. |
| Mono files | database | A stereo master encoded to one channel is a permanent loss; genuinely mono sources are fine. See the [trap](#traps-each-of-these-cost-a-measurement). |
| Files below CD sample rate | database | Downsampled somewhere in their history. **Bit rate is deliberately not audited** — 128 kbps is a taste judgement the Songs listing already filters on, while a 22 kHz file is a defect. |
| Files with an impossible year | database | Outside 1900 – next year: 0000 from an empty tag, 9999 from a typo. Both ends are deliberately generous — 1900 itself is a common tagger default but also a real release year, and a January pressing legitimately ships tagged with the year ahead. |
| Audio files the library cannot index | disk | An audio extension outside `mixtape.scan.extensions` — invisible to the scanner, so its album reports as missing a track instead. Widen the config or convert the file. `.m4b` is on the watch list because it is the standard audiobook container. |

### Review queues

**Not faults.** Most entries are legitimate, and the point of listing them is that only a person
can tell which are not. Every queue section repeats that in its own heading.

| Check | Source | What to look for |
| --- | --- | --- |
| Albums whose files disagree about the year | database | A soundtrack or a best-of legitimately carries each track's original year. What is worth finding is the other shape: one file re-tagged from a different release, or two albums merged into one row. |
| Artist names that look like several | database | `feat.`, `vs`, `&`, `/`, a comma. Most are real band names — *Nick Cave & The Bad Seeds*, *Earth, Wind & Fire*. The **song count** is the triage: a lookalike with one song beside an artist with fifty is usually a guest credit that became an artist of its own. This is the longest queue in a well-kept library (113 of 641 artists here). |
| Books read by more than one narrator | database | Either a genuine dual reading, or two recordings of one book merged under one title. |

## The one check that is not a table

The encoding check renders its own section, because its findings cannot be rows: roughly half of
what a real collection turns up is **invisible on screen** — a private-use character a tagger
swapped in for a quote, an exotic space, a combining accent that renders exactly like a normal one
— so a name in a cell tells the reader nothing. Its section carries a character inventory (code
point, Unicode name, what to type instead), the work list grouped by path **segment** so a bad
folder name is one job rather than one per track, the full file list, and the reminder to run
`app:update` afterwards.

Its findings count is therefore **renames**, not paths.

## Running it

```bash
php artisan app:audit                       # every check, every area
php artisan app:audit --area=music          # one area
php artisan app:audit --check=incomplete-albums --check=split-albums
php artisan app:audit --cron                # for a scheduler; see below
```

A run narrowed to database checks never touches the shares. When any disk check is in the run, the
library is walked **once** and every disk-side check reads that one traversal — the walk is the only
cost that scales with the size of the collection.

**The report lands in the working directory as `library-audit.md`**, and the console says so with an
absolute path when the run finishes. A throwaway working file belongs next to whoever ran the
command rather than in `storage/`, so it is a plain relative default — `--output=/path/to/file.md`
puts it anywhere else.

**On a production checkout the default cannot work, and that is correct.** Under the
`mixtape-deploy` ownership model the app root belongs to the deploy user and is group-readable
only, so the admin running artisan there cannot create a file beside it — a web root nobody can
write to is the point of the model. Pass somewhere you own:

```bash
php artisan app:audit --output=~/library-audit.md
```

The command says which directory refused it and who it is running as, rather than leaving you to
work it out. **Give a scheduled run an absolute `--output`** for the same reason plus one more: a
timer's working directory is whatever its unit was given, and on a quiet week `--cron` prints
nothing at all, so there is no line telling you where the file went.

### On a schedule

`--cron` writes the report as always, but says nothing and exits `0` when the findings have not
moved since the last `--cron` run; when they have, it prints one line per changed check and exits
`1`. A weekly job that re-reported the same standing findings would become a mail rule, and the week
that mattered would be filtered with it.

The baseline lives beside the report (`library-audit.state.json`) and holds, per check, the count
**and a hash of the finding keys** — a count alone is blind to a swap, and one fault fixed while
another appears is precisely the week worth hearing about. Delete the file to force a full report.
Only `--cron` writes it, so reading the report by hand never eats the next alert.

With no baseline at all, the run reports the checks that **found something** and stays quiet about
the clean ones: a first alert padded with twenty "Mono files: 0" lines is one nobody reads twice. A
check that drops to zero against a baseline that *does* exist is still reported — that is the
reader finding out their fixes landed.

A skipped check is **absent** from the baseline rather than recorded as zero, and a write **merges**
over what was there rather than replacing it — those two together are what make the promise hold. An
area going offline must not read as a batch of repairs, its return must not read as a batch of new
faults, and a narrower run (`--check=`) must not wipe the history of the checks it did not ask
about.

A run where **no check ran at all** is an alert rather than a quiet week: `--cron` prints why and
exits 1. An `--area` and `--check` that do not overlap, or shares that are all unmounted, would
otherwise leave a scheduler green and silent for ever, which is the one failure this mode exists to
prevent.

> **Ping your dead-man's switch on exit 1 anyway.** The wrapper should report success to
> healthchecks.io whichever code it gets, and push to ntfy separately on `1`. Otherwise a new
> finding reads as "the audit stopped running", which is the one thing that switch exists to
> detect.

## Adding a check

A check is **one enum case and one class** — `App\Enums\AuditCheck` holds only the order and the
slug, and everything about a question lives with the question:

1. Add a case to `AuditCheck` where it belongs in the report order, and map it in `check()`.
2. Add a class in `app/Services/Library/Audit/Checks/` implementing `Contracts\Check` — or
   extending `TrackCheck` (findings are files) or `CollectionCheck` (findings are albums or books),
   which need only a predicate and their prose.
3. Add a row to the catalogue above.

`LibraryAuditTest` runs every registered case and asserts it resolves and answers, so a case added
without a class fails there rather than on somebody's library.

Two things a new check must get right. Declare the **areas** it can answer for — an audiobook
chapter has no genre or artist by construction, so an area-blind predicate reports the whole
audiobook area as broken music. And write the **blurb** for a stranger: a list of album names with
no explanation is a puzzle, and it is the half that makes a report worth re-running.

## Traps, each of these cost a measurement

**Mono is `channel = 'mono'`, never `<> 'stereo'`.** MP3 encodes most stereo material as *joint*
stereo: the loose predicate reads **5,708** faults on a collection that has none.

**Never parse a folder name for meaning.** A directory string is a grouping key and nothing else.
The first draft of the merged-album check looked for `[Disc n]` and produced three false positives
out of four, because one real collection spells its disc folders `[Disc 1] The Coming Of The
Martians`, `Disc 1` and `[Disc 1]`. What actually stops a multi-disc set colliding is that its
discs carry different **disc numbers** — a fact in the tags.

**One detection can have three diagnoses.** Colliding `(disc, track)` pairs are duplicate numbering
inside one folder, *or* two albums in one row across two, *or* — across two folders with no disc
numbers anywhere — one multi-disc set that was never disc-tagged, which needs the opposite advice to
the album it otherwise looks exactly like. The directory splits the first from the other two and the
presence of any disc tag splits those. Reported without the split, one merged album is eight rows of
"repeated track number" with the wrong cure on every one. `TrackNumberCollisions` does the detection
once and both checks read it off the scope (the same place the disk walk lives) — had each done its
own grouping they would have been free to disagree about which albums collide at all, and each
resolving its own instance would run the detection's two full-table queries twice per audit.

**Grouping by directory happens in PHP, not in SQL.** There is no portable spelling of `dirname`
over a stored path: Postgres would want `regexp_replace` and the test suite's sqlite has no regular
expressions at all, so a directory-grouped `having` clause cannot be written once for both.

**`--quiet` is not available.** Symfony defines `-q|--quiet` globally and registering it again
throws before the command can run.

## Related code

- `app/Console/Commands/AuditLibrary.php` — the thin command.
- `app/Enums/AuditCheck.php` — the registry, and the report order.
- `app/Enums/AuditGroup.php`, `app/Enums/AuditSource.php` — the promise a group makes, and how
  fresh a check's facts are.
- `app/Services/Library/Audit/` — the orchestrator, the shared disk walk, the collision detector,
  the document, the cron baseline, and one class per check under `Checks/`.
- `app/Services/Library/PathEncodingAudit.php` + `PathEncodingReport.php` — the encoding check's
  own machinery, which predates the audit and still owns its section.
- `tests/Feature/Library/Audit/` — one file per group, plus the document, the orchestrator and the
  baseline.
