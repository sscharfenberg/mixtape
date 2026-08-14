# Data model

The schema, and the reasoning behind the parts of it that are not obvious. Read
[`architecture.md`](architecture.md) first for how the app is wired; this document is about what is
stored and why it is shaped that way.

The database is **PostgreSQL 17**. Several decisions below exist because of that specifically:
Postgres does not auto-index the referencing side of a foreign key, and it is case-sensitive by
default. Both are things MySQL papers over for free, so a schema ported from one will be subtly wrong
on the other.

## The one fact that colours everything: the scan must preserve identity

The library is mp3 files on disk; the database is a queryable index of them, rebuilt by
[`app:update`](artisan-commands.md#appupdate). The hard question that scan has to answer is **what
makes an incoming file the same track as an existing row.**

The obvious answer is not to answer it: truncate every table and re-insert every file with fresh
UUIDs. That is a genuinely reasonable design, because **everything** about an mp3 can change between
scans — the `path` (a renamed file or a renamed parent directory) and every ID3 tag including `track` —
so there is no obvious stable key, and matching on the wrong key risks duplicate track rows.
Truncating sidesteps the question entirely: start empty, match nothing.

Its cost is that **row identity is destroyed on every scan**, and that cost is fatal here. Nothing
downstream can hold a real FK to a track, so playlists have to denormalise `path / song / artist /
album` as strings and reconnect by string comparison; `created_at` is meaningless on rows recreated
nightly; orphan handling is implicit rather than a real diff. Every feature this app is *for* — user
playlists, listen history, most-played, share links — needs stable identity. A playlist that silently
loses a track because you renamed a folder, or a play count that resets when you re-tag a file, is
broken.

So the question has to be answered. It splits into two independent ones:

1. **Identity** — what makes an incoming file *the same track* as an existing row, across a rename
   *and* a re-tag?
2. **Write strategy** — blind reinsert, or diff the filesystem against the database (insert new /
   update matched / remove gone)?

You cannot adopt a diff until identity is solved, and **`path` does not solve it** — it is one of the
*most* volatile attributes, not a stable key. Neither do the tags. The only thing that survives both a
rename and a re-tag is **the audio itself.**

### Identity is a hash of the audio stream

An mp3 is `[ID3v2 tag][audio frames][ID3v1 trailer]`. Editing tags (including `track`) rewrites the
tag regions and leaves the audio frames byte-for-byte identical. So hash **only the audio frames** —
the byte range getID3 already reports — not the whole file:

| candidate | verdict |
| --- | --- |
| **audio-stream hash** | stable across rename *and* re-tag — **chosen** |
| full-file hash | survives a rename but **breaks on a re-tag**, because the tag bytes are in the hash. Re-tagging is part of the workflow here |
| acoustic fingerprint (Chromaprint) | survives even re-encoding, but needs decoding plus a library. Overkill unless files are re-ripped at new bitrates |

`path`, `track`, artist and album therefore demote from *identity* to plain **mutable attributes**.
The audio hash is the identity; `path` is a change-detection input and a display value.

### The write strategy: a diff with a cheap fast-path

A scan needs exactly one column the naive schema would not have — `content_hash` — alongside `path`
(unique), `size` and `modified_at`. Then, per area, in one transaction:

1. Enumerate files; read cheap `(path, size, modified_at)`.
2. **Unchanged fast-path:** a row with matching `path` + `size` + `modified_at` is untouched → keep it,
   keep its id. *No hashing* — this is what keeps steady-state scans fast.
3. **Same path, changed content** (a re-tag): matched unambiguously **by `path`**, which is unique →
   update tags and hash in place, keep the id.
4. **New path on disk** — the only case that needs the hash. Hash it; look among the **unclaimed** rows
   (those whose old path vanished this scan — the rename candidates) for the same `content_hash`:
   - exactly one → it is a **rename/move** → update its path, keep the id;
   - none → **genuinely new audio** → insert;
   - several (duplicate audio, below) → disambiguate on parent directory and tag similarity.
5. **Gone files → hard delete, relink first:** unclaimed rows still absent from disk are gone, so the
   row is deleted outright (no soft-delete flag). Before deleting, the scan runs
   *relink-then-cascade* — if a surviving **clone** shares the row's `content_hash`, its
   `playlist_tracks` and `plays` are repointed to the clone; otherwise the FK cascade drops them.
6. **Prune orphan taxonomy** (below).

Trace the two feared mutations: a **rename** misses the fast-path → hashes → matches → updates the
path, **id preserved**; a **re-tag** trips size/mtime → matched by `path` → updates the tags, **id
preserved.** That is the guarantee truncation cannot give.

A first scan hashes everything, which for a large collection is minutes rather than seconds; steady
state stays fast because step 2 skips unchanged files. A happy side effect of content-based identity:
even a **backup restore that resets every mtime** re-hashes but re-matches, so ids survive the
restore, where a truncate-and-rebuild would renumber the whole library and break every playlist.

### Duplicate audio is allowed — and surfaced

**The same audio in two files is two track rows.** A recording on its original album *and* on a
compilation are both legitimate library entries, each with its own album and track-number tags. So:

- **No `UNIQUE (content_hash)`.** The uniqueness anchor is **`UNIQUE (type, path)`**: *one file per
  area ⇒ exactly one row.* That is the line between duplicates you *want* (two files) and the
  accidental ones you don't (one file spawning a phantom). `path` is stored **relative to the area
  root**, not as an absolute server path, so relocating the collection is a fast-path no-op — and
  relative paths can collide across areas (music vs. audiobook `Foo/1.mp3`), which is why the anchor is
  `(type, path)` rather than `path` alone.
- `content_hash` is stored **indexed, non-unique**. Its jobs are catching renames (step 4) and the
  clones feature below.
- `id` is an **independent random UUID**. A deterministic `uuidv5(content_hash)` would collapse clones
  into one row.
- **Clones:** `WHERE content_hash = ? AND id <> ?` — cheap and indexed — lets a track page say *"also
  appears in 2 other places"*. The same lookup powers **self-healing playlists**: when a file is
  deleted but a clone survives, the scan repoints that track's `playlist_tracks` and `plays` to the
  surviving copy before removing the row. Automatic, no dead entries.

**A known limit, gracefully handled:** if two clones are moved *in the same scan*, two unclaimed rows
share a hash and step 4 cannot tell which new file was which old row. It disambiguates on directory and
tags; in the pathological case (both moved *and* their distinguishing directory and tags changed at
once) the two identical-audio siblings may swap ids — invisible unless a playlist pinned one
specifically, and even then it points at an identical recording. Accepted, not engineered against.

## One `tracks` table, one `collections` table

Music and audiobooks are **tagged** differently. The same ID3 frames mean different things:

| ID3 source | → Music | → Audiobook |
| --- | --- | --- |
| `TPE1` (artist) | artist | **narrator** |
| `TPE2` (album-artist) | album artist | *(unused)* |
| `TALB` (album) | album | **book title** |
| `TCOM` (composer) | composer | **author** |
| `TCON` (genre) | genre | *(not applicable)* |

That remapping is handled entirely by the scanner having two arms, which makes it an **ingest**
concern, wholly independent of whether the rows land in one table or two. That frees the storage
decision to be made on its own merits — and on its merits, one table wins.

**`tracks` is unified, with a `type` enum (`music` / `audiobook`).** Every cross-cutting feature —
`plays`, share links, unified search, the play queue, background auto-advance — is about "a playable
thing". Under a split schema each of those needs two nullable FKs plus a CHECK, or a polymorphic
`playable_type`/`playable_id` pair that gives up referential integrity entirely. One table gives one
clean FK per feature, and mixed-type playlists for free. At the scale of a personal collection
performance is not the driver; maintainability is.

**`collections` is unified too, with its own `type` enum (`album` / `audiobook`)**, and that half is
not optional. The nullable-FK cost of a unified row has two sources that grow very differently as
types are added:

- **Container FK** (`album_id` / `audiobook_id` / …) — grows **one column per type**. Most of the
  sprawl.
- **Contributor FKs** (`artist_id`, `genre_id`, `narrator_id`, `author_id`) — grows only a little per
  type.

Merging the containers collapses every container FK into a single `collection_id` on `tracks`. The net
effect is four taxonomy FKs on `tracks` instead of seven, of which any given row uses two or three —
and, more importantly, **a new kind stays cheap**: an audio-drama or lecture kind becomes a new
`collections.type` value plus at most a contributor field, not a new container column that lands null
on every existing row.

**Generalising *contributors* the same way is deliberately not done.** A `contributors` table with a
role pivot would make a new type truly zero-column, and it would model "an artist who is also an
author" once. It also complicates the scanner and every UI query, for a payoff a bounded handful of
types does not justify. An artist is not an author is not a narrator; the three taxonomy trees stay
separate.

### Shape

```
collections
  id               uuid pk
  type             album | audiobook                    # varchar + CHECK
  name             string                               # ICU case-insensitive collation
  name_fold        string                               # search companion, default collation
  year             int    nullable
  cover_path       string nullable                      # RELATIVE to the area root — the directory image
  album_artist_id  uuid   fk → artists  nullable        # music only; the container's owner
  timestamps
  CHECK  album_artist_id set only when type = 'album'
  UNIQUE NULLS NOT DISTINCT (type, name, album_artist_id)

tracks
  id               uuid pk                              # independent random uuid
  type             music | audiobook                    # the playable kind
  collection_id    uuid   fk → collections  (restrict)  # ONE container FK, every type
  artist_id        uuid   fk → artists      nullable    # music: the performer
  genre_id         uuid   fk → genres       nullable    # music
  author_id        uuid   fk → authors      nullable    # audiobook — per CHAPTER, see audiobooks.md
  narrator_id      uuid   fk → narrators    nullable    # audiobook
  composer, publisher                                   # music, text
  path             string                               # RELATIVE to the area root
  content_hash     string index (non-unique)            # audio-stream hash = identity
  size, modified_at                                     # with path, the "unchanged" fast-path
  codec, channel, duration, sample_rate, bit_rate, vbr, cover, track, disc
  name, name_fold
  created_at                                            # a real "date added"
  UNIQUE (type, path)
  CHECK  music FKs null unless type = 'music'; audiobook FKs null unless type = 'audiobook'
```

`type` is stored on `tracks` rather than derived through the join because a Postgres `CHECK` cannot
reference another table — the type guard needs the value locally. The scanner keeps it in step with the
collection. The two enums are **parallel but not identical**: a track's `type` is the *playable kind*
(`music` / `audiobook`), its collection's `type` is the *container kind* (`album` / `audiobook`),
mapping `music↔album` and `audiobook↔audiobook`.

**An audiobook has no owner column at all**, unlike an album. `author_id` lives on the chapter beside
`narrator_id`, because TCOM is a per-file tag and an anthology uses it per story. See
[`audiobooks.md`](audiobooks.md) for what that costs and why a book-level column cannot work.

### Cover art: a bool on `tracks`, a path on `collections`

The asymmetry is deliberate, and it is about where the bytes live.

A **track's** art is *inside the file whose path is already stored*, so `tracks.cover` only has to
answer "is there one?" — `cover` (bool) plus `path` is already a complete location, and a path column
there would just repeat `path`.

A **collection's** art is a *sibling file whose name cannot be derived*. Measured across a real
collection of ~950 album directories: `folder.jpg` in the great majority, `cover.jpg` in a few dozen,
sometimes named after the album, and a handful with no image at all — plus `back.jpg` / `cd.jpg` /
`inlay.jpg` / `booklet.jpg`, every one of which sorts *before* `folder.jpg`. So the name is the fact
worth storing, and `collections.cover_path` (nullable, area-relative like `tracks.path`) stores it.

Recording it moves the resolution — candidate names in configured order
(`mixtape.covers.folder_images`), matched case-insensitively, then a lone unrecognised image — from
**every page render to once per scan** (`LibraryScanService::syncCollectionCovers`). A page of 50
albums would otherwise need 50 directory reads just to decide whether to show a thumbnail; it now reads
a column. Nothing is extracted at scan time: the column holds a filename, and cover *bytes* stay
lazily cached on first request. Pre-extracting every embedded picture instead would cost hundreds of
megabytes and minutes per scan for art nobody may ever open.

Two consequences, both accepted: art added **without** a rescan is unseen until the next
`app:update` (cover art arrives with the music it belongs to, which is when you scan anyway); and a
**stale** path degrades rather than 404s, because the cover route re-resolves live when the recorded
file has gone (`CoverService::albumFolderImage`).

An album whose only art is embedded has `cover_path = null` — that half of the question is answered by
`tracks.cover` — and an album prefers its directory image over any embedded picture precisely because
per-song inline art would otherwise decide the album's thumbnail by sort order.

## Foreign keys

**Taxonomy FKs are `restrict`, not `cascade` and not `nullOnDelete`.** That covers
`tracks.{collection_id, artist_id, genre_id, author_id, narrator_id}` and
`collections.album_artist_id`. `cascade` there would mean *deleting one artist deletes all their
tracks*, which is a footgun waiting for a stray statement. Because the scanner only ever removes
**orphaned** taxonomy (below), `restrict` never blocks the prune — an orphan has nothing referencing
it — and it turns any *accidental* delete of a still-referenced taxonomy row into a loud error rather
than a silent cascade or a stray null.

`cascade` is reserved for true ownership: `users → playlists → playlist_tracks`, `users →
player_states`, `tracks → plays`, and the four subject FKs on `shares`.

**Every name column is unique, on an ICU case-insensitive collation.** Postgres is case-sensitive by
default, so without this a scanner creates `Rock` *and* `rock`, `Beatles` *and* `beatles`. The
collation (`case_insensitive`, minted in the `users` migration) goes on `artists`, `authors`,
`narrators`, `genres` and the per-type composite on `collections`, making dedup case-insensitive *and*
database-enforced.

> **What that costs: a case-insensitive lookup cannot see a case-only rename.** Re-tagging
> `NARGAROTH` to `Nargaroth` makes `firstOrCreate` find the old row and hand it back unchanged — no
> insert, no update, nothing to notice — where every other rename works by minting a row and letting
> the old one be pruned. The scanner therefore adopts the tag's spelling explicitly
> (`LibraryScanService::adoptSpelling`), renaming in place so the id, and every URL and share pointing
> at it, survive. The tags are the source of truth for spelling.
>
> The sqlite side of these columns must be pinned to `nocase` for the same reason: sqlite's default is
> `BINARY`, so a test suite left at the default does **the opposite** of production for two names
> differing only in case — and neither outcome looks wrong on its own. See
> [`testing.md`](testing.md) → *Traps*.

**`collections` dedups on `UNIQUE NULLS NOT DISTINCT (type, name, album_artist_id)`.** The
`NULLS NOT DISTINCT` (Postgres 15+) matters because the owner is nullable: a plain unique treats NULLs
as *distinct*, so two rows with no owner tag and the same title would slip past as separate. With it,
two same-title albums by different artists stay distinct **and** two with no artist tag still dedup
instead of duplicating.

**Orphaned taxonomy must be pruned**, which is a problem the diff creates: truncation cleared unused
artists, albums and genres for free, and a diff leaves them behind. A browse list full of zero-track
artists is bad. After reconciling tracks, the scan deletes any taxonomy row with no remaining
referrers — checking **both** referring sides, since a contributor is reached from `tracks` *and*
`collections`:

```sql
DELETE FROM artists a
 WHERE NOT EXISTS (SELECT 1 FROM tracks t      WHERE t.artist_id = a.id)
   AND NOT EXISTS (SELECT 1 FROM collections c WHERE c.album_artist_id = a.id);
```

(`genres` / `authors` / `narrators` / empty `collections` likewise.) This is what makes the `restrict`
above a non-event: only orphans are ever deleted, and an orphan has nothing pointing at it.

**Postgres has no `FOREIGN_KEY_CHECKS=0`, and none is needed.** The schema is acyclic (`tracks →
collections → contributors`, no back-edge), so the scan writes **parent-first** (contributors →
collections → tracks) and prunes in reverse (orphan tracks, then orphan taxonomy) inside one
transaction. `DEFERRABLE INITIALLY DEFERRED` FKs are the fallback only if a cycle is ever introduced.

## Indexes

**PostgreSQL does not index the referencing side of a foreign key** — it only requires a unique index
on the *referenced* side, the PK. `foreignUuid()->constrained()` in Laravel adds the constraint,
**not** an index. So FK indexes have to be added back explicitly, but proportionately: at the scale of
a personal collection a seqscan join is sub-millisecond, so most FK indexes here do not speed up reads
at all. **They back the delete path** — every `restrict` / `cascade` check and the orphan prune scans
the child by its FK column, many times per scan.

The indexes that actually move *read* latency are the **`plays` composites** (the one table that grows
unbounded) and the **trigram search index** (a full scan on every keystroke is the one thing a reader
would feel). Index for those and for the delete checks; do not cargo-cult the rest.

- **FK columns needing a *standalone* index:** `tracks.{artist_id, genre_id, author_id, narrator_id}`,
  `collections.album_artist_id`, `playlist_tracks.track_id`. **Do *not* add standalone indexes for**
  `tracks.collection_id`, `playlist_tracks.playlist_id`, `playlists.user_id` or the `plays.*` FKs —
  each is already the leftmost prefix of a composite or unique below, so a separate index is pure
  write overhead.
- **Scan identity:** `tracks.content_hash`, a plain B-tree (equality only) for the rename match and
  the clones lookup. `tracks.path` is already indexed by its unique constraint; the fast-path also
  compares `size` and `modified_at`, which need no index because the `path` hit is the selective one.
- **Name equality and dedup:** the unique plus the ICU collation *is* the index. On `artists` /
  `authors` / `narrators` / `genres` it leads with `name`, so it serves both name-equality lookups and
  `firstOrCreate` dedup. On `collections` the unique is `(type, name, album_artist_id)`: it covers
  dedup **and** doubles as the alphabetical browse index (`WHERE type = ? ORDER BY name`) — but,
  leading with `type`, it does *not* serve a bare `name` lookup. Name *search* on collections rides the
  trigram GIN below.
- **Substring search: `name_fold` + a `pg_trgm` GIN index.** Every search here is
  `LIKE '%segment%'` — a leading wildcard, non-sargable, so a full scan — and matching the raw `name`
  columns is a dead end besides: **Postgres refuses `LIKE` / `ILIKE` / regex on the nondeterministic
  `case_insensitive` ICU collation** those columns carry. Pinning `COLLATE "C"` in the query works on
  Postgres and is un-runnable on the sqlite test database (`near "ILIKE": syntax error`), which leaves
  search untested.

  So each searchable name gains a **`name_fold` companion column** on the default (deterministic)
  collation — on `tracks`, `collections`, `artists`, `authors`, `narrators`, `genres` and `playlists` —
  holding `FoldedSearch::fold(name)`: lowercased, diacritics stripped, and anything with no ASCII form
  (CJK) **kept** rather than dropped, so the fold is a superset of the raw value and no second pass
  over `name` is needed. `HasFoldedName` writes it from the `name` mutator, so the scanner's three
  write paths (insert / re-tag / rename-match) cannot forget it. Search is then one plain `like` on
  both drivers, and `USING gin (name_fold gin_trgm_ops)` indexes it.

  **Folding in PHP rather than via `unaccent()` is deliberate:** the value is *stored*, so it must be
  identical on every machine (no ICU-version drift), the rule stays greppable and unit-tested, and it
  transliterates Cyrillic, which `unaccent()` does not. `pg_trgm` is a *trusted* extension on PG13+,
  so the app's own database user installs it in the migration — no superuser step, as long as it holds
  `CREATE` on the database.
- **Ordered album/book playback:** a composite `(collection_id, disc, track)` on `tracks` — which is
  also what covers `collection_id` on its own. `disc` and `track` are nullable, so nulls sort last,
  which is the right place for untracked files.
- **"Recently added", per media type** (music churns, audiobooks barely move, so the widgets are
  split): `(type, created_at)` at **both grains** — `collections (type, created_at)` for recently
  added albums and books, `tracks (type, created_at)` for the track grain — each answering `WHERE type
  = ? ORDER BY created_at DESC LIMIT n`. The `collections` type-unique does not cover this (no
  `created_at`), so it is a genuine extra index. `created_at` is meaningful precisely because of the
  diff: it is set once at insert and untouched by re-tags and renames, which are UPDATEs. A file
  mtime, by contrast, moves on every re-tag.
- **`plays`** is the only unbounded-growth table, so these are the read indexes that matter most:
  `(user_id, played_at)` for a user's history feed; `(track_id)` for global most-played (also serving
  the relink `UPDATE … WHERE track_id = …` and the cascade check); `(user_id, track_id)` for per-user
  most-played. Both most-played views group by `plays.track_id` (below), so they are answered by these
  indexes alone, with no join to `tracks` at all. A *subject's* count — an artist's, a genre's, an
  album's — does join `plays → tracks` and filter on the taxonomy FK, which the `tracks.artist_id` /
  `genre_id` / `(collection_id, …)` indexes already serve.
- **`playlist_tracks`:** `(playlist_id, position)` for the ordered render (also covers
  `playlist_id`); `track_id` for the reverse lookup ("which playlists contain this track"), the relink
  UPDATE and the cascade check.

Postgres portability notes, all non-issues but worth knowing: Laravel's `$table->year()` maps to
`integer`; `enum('channel', …)` becomes `varchar` + `CHECK`; `float(precision: 53)` is
`double precision`.

## Saved playlists and the play queue are two different things

"Playlist" bundles two concepts that are cleaner kept apart:

| | **Saved playlists** | **Play queue** |
| --- | --- | --- |
| Lifespan | durable, named, CRUD | ephemeral — what is playing now and next |
| Owner | a user | the current player |
| Driven by | user intent (curate) | the player (auto-advance) |
| Lives in | **server / database** | **a client composable**, synced to `player_states` |

### Saved playlists

```
playlists
  id            uuid pk
  user_id       uuid fk → users  (cascade)
  name          string           # ICU case-insensitive collation
  name_fold     string
  description   text nullable
  description_fold text nullable
  position      int              # the user's ordering of their own playlists
  timestamps
  UNIQUE (user_id, name)         # your "Rock" is not my "Rock"

playlist_tracks
  id            uuid pk
  playlist_id   uuid fk → playlists (cascade)
  track_id      uuid fk → tracks    (cascade)   # always live — relink-to-clone, else cascade
  position      int
  created_at
  INDEX (playlist_id, position)
  INDEX (track_id)
```

Two things this buys, both only possible because of content-hash identity: a **real `track_id` FK**,
so title and artist come from the join and no denormalised snapshot is needed; and **mixed-type
playlists for free**, because `tracks` is unified, so a playlist row list is just `track_id`s and can
hold music *and* audiobook chapters with no polymorphism.

**Reordering rewrites contiguous integers inside a transaction** — the client PATCHes the full
sequence and the server writes `position = 0…n−1`. At tens to hundreds of tracks that is a
sub-millisecond bulk UPDATE, so the write amplification that gap-based schemes and LexoRank optimise
away is noise. `(playlist_id, position)` stays **non-unique**, because a mid-transaction state has
transient duplicates; same rule for `playlists.position`. Reconsider only at thousands of items *plus*
frequent concurrent moves, which is not this app.

### The play queue

```
player_states
  user_id     uuid pk fk → users (cascade)
  queue       jsonb    # { version, tracks: [track_id, …], currentIndex, repeat, shuffle, positionMs, updatedAt }
  updated_at
```

The **live** queue is always a client composable (`usePlayerQueue`), never a server round-trip per
track change. That is forced by playback: auto-advance drives off the audio element's `ended` event,
which lives in the browser, and the player keeps running while Inertia swaps pages, so it lives in the
persistent layout.

For signed-in users that composable is **persisted as a single per-user JSON row**, so the queue and
your place in it resume on any device. It is deliberately *not* a normalised table: unlike a saved
playlist (relational, queried, shared), the queue is private to one player and read and written
**wholesale** — load it whole, save it whole.

- **Hydrated through Inertia**, from the shared props on a full page load; the client then PUTs
  debounced syncs answering `204`, not a full Inertia visit.
- **Ids go up, whole tracks come down.** The server is where the tracks came from, so a title sent up
  would only be a copy to go stale; the browser has no REST API to turn an id back into a title, which
  is the same reason the client-side queue holds whole tracks.
- **Conflicts settle on a client-issued `updatedAt` stamp**, not by the server winning. A page load
  races the sync PUT, so the server's copy is regularly the older one.
- **Stale ids are skipped**, with the pointer following them. A persisted queue can reference a track
  the database has since removed.
- **Anonymous listeners** have no `user_id`, so the same composable falls back to `localStorage`.
  Server persistence *enhances* signed-in use; the queue must still work without it.

[`play-queue.md`](play-queue.md) is the queue as built, including the storage trim, the write
coalescing and the shuffle walk. [`player.md`](player.md) is everything that makes sound.

### Listen history

The client fires a "played" beacon as the queue advances, writing one row per listen to `plays`
(`user_id`, `track_id`, `played_at`). What counts as a play — heard seconds against half the track,
capped at four minutes, no de-duplication for repeats — is settled in [`player.md`](player.md) →
*What counts as a play*.

**Most-played aggregates by `track_id`.** A recording living on its album, a compilation and a
best-of counts as **three** entries, each with its own figure. The alternative — grouping by
`content_hash`, so clones count once, on the grounds that a reader thinks of those copies as one song
— loses on three counts:

- **It is what makes the figures add up.** An album's count is the sum of its tracks'. Under the hash
  rule each track quietly counts its twin elsewhere, so the tracks sum to more than the record they
  sit on, with nothing on screen to say which number is the odd one.
- **A subject count cannot use the hash anyway.** "Plays of this artist" joins `plays → tracks` and
  filters on `artist_id`; matching by hash would count one recording twice for any artist holding two
  copies of it, which is the normal case in a real collection.
- **One rule beats two.** Every ranking query in the app groups by the row that was played.

The cost, accepted knowingly: play a song from the best-of and its entry on the album shows nothing.
That is the honest reading — these are two files, and a page is about the file. The `content_hash`
index keeps both its other jobs (rename-matching in the scan, relink-then-cascade on delete); it is
only the play grain that does not use it.

`plays` being an **event table rather than a counter** is what makes every question with "when" in it
answerable. Fifteen listens are fifteen rows, about four kilobytes; a household of five listening three
hours a day writes roughly 25 MB a year against a collection two thousand times that size on the same
disk. A counter would save that and delete the feature.

## How the pieces plug together

- **Share links** name a `tracks`, `collections`, `artists` or `playlists` row directly, with real FKs
  so a rescan that drops a subject cascades its shares away. See [`sharing.md`](sharing.md).
- **Cross-device resume** is free, because the whole player state (queue, pointer, position) persists
  per user — including mid-audiobook, for the book that is playing *now*. Remembering your place in
  *every* book, after you have since played other things, is a separate per-book bookmark
  (`audiobook_bookmarks`) — see [`audiobooks.md`](audiobooks.md).
- **Search** rides the `name_fold` columns and their trigram indexes, and the rule that a row matches
  its **own** name is what keeps it cheap. See [`search.md`](search.md).
