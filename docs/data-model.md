# Data model (Phase 2)

> Findings + proposed data model for the MixTape v2 rewrite. See [../CLAUDE.md](../CLAUDE.md) for the
> overview and [app-rewrite.md](app-rewrite.md) for the app-rewrite plan this feeds into.
>
> **Status: proposal — all substantive decisions settled.** Nothing here is implemented yet. It records
> the analysis of the legacy schema (`../MixTape`, read-only reference) and the recommended v2 shape. The
> four forks are now decided: the **scan model** (content-hash *diff*, not truncate-and-rebuild), the
> **tracks split** (option B + the collections half-step), **play-queue persistence** (client composable +
> server `player_states`), and the **playlist-reorder strategy** (contiguous positions) — see
> [Open decisions](#open-decisions) for each. The fifth — most-played — aggregates by `content_hash`, so
> clones count as one song. Treat the schemas below as the recommended direction, not final migrations;
> the next step is drafting the v2 migrations + models. Written 2026-07-19; decisions settled 2026-07-20.

## Scope

Three questions drove the investigation, plus the decision to make playlists first-class:

- **(a)** Should music and audiobooks keep separate tables? (They were split in v1 because the two are
  usually *tagged* differently.)
- **(b)** Are the foreign-key constraints right?
- **(c)** Are additional indexes needed?
- **Playlists** should stop being an afterthought — user-specific, central to the UX (queue an album,
  add to existing playlists, etc.).

The single most important context: **v1 runs on MySQL/MariaDB (InnoDB, `utf8mb4_unicode_ci`); v2 runs
on PostgreSQL 17.** Several recommendations below exist only because of that move — Postgres does not
auto-index FK columns and is case-sensitive by default, both of which MySQL papered over for free.

---

## The one fact that colours everything: the scan must preserve identity

The legacy library scan is **truncate-and-rebuild, not a diff.** Every `app:db:music` /
`app:db:audiobook` run does `SET FOREIGN_KEY_CHECKS=0` → `TRUNCATE` all four tables → re-`INSERT` every
file, **minting fresh random UUIDs each time.** That was a *deliberate, reasonable* choice: **everything**
about an mp3 can change between scans — the `path` (a renamed file or a renamed parent directory) and
every ID3 tag including `track` — so there was no obvious stable key to match an incoming file back to
its existing row, and matching on the wrong key risks **duplicate track rows.** Truncating sidesteps the
question entirely: start empty, match nothing.

Its cost is that **row identity is destroyed on every scan.** Fresh UUIDs mean nothing downstream can
hold a real FK to a track — which is exactly why the legacy schema is shaped the way it is:

- `playlist_entries` can't hold a `song_id` FK (it would be invalidated on every scan), so it
  **denormalises** `path / song / artist / album` as strings and reconnects later via `where('path', …)`.
- `timestamps = false` on every media model (no point tracking created/updated on rows recreated nightly).
- Orphan handling is implicit (truncate) rather than a real diff.

Every headline v2 feature — user playlists, listen history / most-played, share links — **requires**
stable identity: a playlist that silently loses a track because you renamed a folder, or a "most played"
that resets when you re-tag a file, is broken UX. So v2 has to answer the question truncate dodged.

### Two decisions, not one

"Truncate vs. diff" bundles two independent choices that are cleaner kept apart:

1. **Identity** — what makes an incoming file *the same track* as an existing row, across a rename *and*
   a re-tag?
2. **Write strategy** — blind reinsert, or diff the filesystem against the DB (insert new / update
   matched / mark-gone)?

You can't adopt a diff until identity is solved, and **`path` does not solve it** — it is one of the
*most volatile* attributes, not a stable key. Neither do the tags. The only thing that survives both a
rename and a re-tag is the **audio itself.**

### Identity = a hash of the audio stream

An mp3 is `[ID3v2 tag][audio frames][ID3v1 trailer]`. Editing tags (including `track`) rewrites the tag
regions and leaves the audio frames byte-for-byte identical. So hash **only the audio frames** — the byte
range the tag library (getID3) already reports — not the whole file:

- **audio-stream hash** → stable across rename *and* re-tag. ✅ chosen.
- full-file hash → survives rename but **breaks on re-tag** (tag bytes are in the hash). Rejected —
  re-tagging is part of the workflow here.
- acoustic fingerprint (Chromaprint) → survives even re-encoding, but needs decoding + a library.
  Overkill unless files are re-ripped at new bitrates.

`path`, `track`, artist, album all demote from *identity* to plain **mutable attributes.** The audio hash
is the identity; `path` becomes a change-detection input and a display value.

### Write strategy = diff, with a cheap fast-path

The scan needs just **one** new column, `content_hash` — `path` (unique), `size`, and `modified_at`
(filemtime) already exist in the legacy schema. (`created_at`, for "recently added", is also new but
incidental to the diff — see (c).) A scan:

1. Enumerate files; read cheap `(path, size, modified_at)`.
2. **Unchanged fast-path:** a row with matching `path` + `size` + `modified_at` is untouched → keep it,
   keep its id. *No hashing* — this is what keeps steady-state scans fast.
3. **Same path, changed content** (a re-tag): matched unambiguously **by `path`** (which is `UNIQUE`) →
   update tags/hash in place, keep id.
4. **New path on disk** — the only case that needs the hash. Hash it; look among the **unclaimed** rows
   (those whose old path vanished this scan — rename candidates) for the same `content_hash`:
   - exactly one → it's a **rename/move** → update its path, keep id;
   - none → **genuinely new audio** → insert (new id);
   - several (duplicate audio, below) → disambiguate on parent directory / tag similarity.
5. **Orphans → hard delete, relink-first:** unclaimed rows still absent from disk → the file is gone →
   **delete the row outright** (no soft-delete flag). Before deleting, the scan runs *relink-then-cascade*
   — if a surviving **clone** shares the row's `content_hash`, its `playlist_tracks` and `plays` are
   repointed to the clone; otherwise the FK `cascade` drops them (see b#4).

Trace the two feared mutations: a **rename** misses the fast-path → hashes → matches → updates path,
**id preserved**; a **re-tag** trips size/mtime → matched by `path` → updates tags, **id preserved.**
That is the guarantee truncate couldn't give, and it's why the extra code is worth it. First scan hashes
everything (reading ~96 GB is minutes, not the legacy ~40 s); steady state stays fast because step 2
skips unchanged files.

### Duplicate audio is allowed — and surfaced

**Decision (2026-07-20): the same audio in two files is two track rows.** The recording on its original
album *and* on a compilation are both legitimate library entries, each with its own album / track-number
tags. So:

- **No `UNIQUE (content_hash)`.** The uniqueness anchor is **`UNIQUE (type, path)`**:
  *one file per area ⇒ exactly one row.* That is the line between duplicates you *want* (two files) and
  the accidental ones you *don't* (one file spawning a phantom). (`path` is stored **relative to the area
  root**, not the absolute server path, so relocating the collection is a fast-path no-op — and relative
  paths can collide across areas, e.g. music vs. audiobook `Foo/1.mp3`, which is why the anchor is
  `(type, path)` rather than `path` alone. Implemented 2026-07-22, superseding the original absolute
  `UNIQUE (path)`.)
- `content_hash` is stored **indexed, non-unique** — its jobs are catching renames (step 4) and the
  clones feature below.
- `id` stays an **independent random uuid.** (A deterministic `uuidv5(content_hash)` is off the table —
  it would collapse clones into one row.)
- **"x clones" feature:** `WHERE content_hash = ? AND id <> ?` (cheap, indexed) → a track view shows
  *"also appears in 2 other places"* and links to them. The same lookup powers **self-healing playlists**:
  when a file is deleted but a clone survives, the scan **repoints** that track's `playlist_tracks` and
  `plays` to the surviving copy before removing the row (relink-then-cascade, b#4) — automatic, no
  dead entries. The same hash also drives **most-played by recording** (aggregate `plays` by
  `content_hash`, so clones count as one song) — decided, decision #5.

**Known limit (graceful):** if two clones are moved *in the same scan*, two unclaimed rows share a hash
and step 4 can't tell which new file was which old row. It disambiguates on directory / tags; in the
pathological case (both moved *and* their distinguishing directory/tags changed at once) the two
identical-audio siblings may swap ids — invisible unless a playlist pinned one specifically, and even
then it points at an identical recording. Acceptable; not engineered against.

**Foundational recommendation: v2 replaces truncate-and-rebuild with a diff keyed on an audio-stream
`content_hash`** (path/size/mtime as the fast-path), keeping `UNIQUE (path)` and allowing duplicate audio
as distinct rows. Stable ids then make everything below possible — real playlist FKs, real
listen-history FKs, a genuine "recently added", and removal of the denormalisation hack. Happy side
effect: because identity is content-based, even a **backup/restore that resets every mtime** re-hashes
but re-matches, so ids survive the restore — where truncate-rebuild would renumber the whole library and
break every playlist.

---

## (a) Separate tables for music vs. audiobooks

### What's there

Two parallel hierarchies:

- **Music:** `artists` → `albums` → `songs`, plus `genres`.
- **Audiobooks:** `authors` / `narrators` / `audiobooks` → `tracks`.

`songs` and `tracks` share **15 identical columns** (`path`, `codec`, `channel`, `size`, `duration`,
`sample_rate`, `bit_rate`, `vbr`, `cover`, `track`, `disc`, `modified_at`, `name`, `id`, …). They differ
only in the taxonomy FKs, plus `composer` / `publisher` (music only).

### The "tagged differently" argument is an *ingest* concern, not a *storage* one

The two are read by **two separate scanners** over two separate share directories (`/music/`,
`/audiobooks/`), and they *remap the same ID3 frames* to different meanings:

| ID3 source            | → Music      | → Audiobook         |
| --------------------- | ------------ | ------------------- |
| `artist` tag          | artist       | **narrator**        |
| `TPE2` (album-artist) | album_artist | *(unused)*          |
| `album` tag           | album        | **audiobook title** |
| `TCOM` (composer)     | composer     | **author**          |
| `genre` tag           | genre        | *(dropped)*         |

Because the different tagging is handled entirely by keeping two scanners, it is **independent of whether
the rows land in one table or two.** That frees the storage decision to be made on its own merits.

### Options

| Option                           | What                                                                                    | Pros                                                                                                            | Cons                                                                                                                                                |
| -------------------------------- | --------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A. Keep the split** (legacy)   | 2× everything                                                                           | Proven; semantically clean; no nullable type-only columns                                                       | Every cross-cutting v2 feature built **twice** or via UNION/polymorphic                                                                             |
| **B. Unify the playable row** ⭐ | One `tracks` table + `type` enum (`music` / `audiobook`); keep taxonomy tables separate | `plays`, share-links, unified search, the play queue all point at **one** table & FK; mixed-type playlists free | 3–4 nullable taxonomy FKs per row (guard with a `CHECK` on `type`) — cut to 2–3, and made cheap to extend, by the **collections half-step** (below) |
| **C. Full normalisation**        | `collections`(type) + `contributors`(+ role pivot) + one `tracks`                       | Textbook-DRY; an artist-who-is-also-an-author modelled once                                                     | Over-engineered at this scale; a role pivot complicates scanner + UI                                                                                |

### Decision: B + the collections half-step ✅ (2026-07-20)

Unify the playable row into one `tracks` table with a `type` enum, **and** take the collections
half-step up front: merge `albums` + `audiobooks` into one `collections` table with its own `type`.
Keep the two taxonomy *trees* separate — an artist isn't an author isn't a narrator. Option **C**
(generalising *contributors* into a role pivot) is explicitly rejected as over-engineering for a
personal collection.

Every headline v2 feature — listen history / most-played, signed share-links per song/album/playlist,
unified search, background auto-advance — is cross-cutting over "a playable thing." Under the split,
each needs two nullable FKs + a `CHECK`, or a polymorphic `playable_type/playable_id` that gives up FK
integrity. One `tracks` table gives one clean FK per feature. At ~12k songs, performance is not the
driver — maintainability is.

#### Why the collections half-step is now part of B (not optional)

The nullable-FK cost of a unified row has two sources that grow very differently as *types* are added:

- **Container FK** (`album_id` / `audiobook_id` / …) — grows **one column per type.** Most of the sprawl.
- **Contributor FKs** (`artist_id`, `album_artist_id`, `genre_id` / `author_id`, `narrator_id`) — grows
  only a little per type.

Merging the containers into one `collections` table collapses every container FK into a single
`collection_id` on `tracks`, and moves the container-level owner (`album_artist_id` for music,
`author_id` for audiobooks) up onto the collection row where it belongs (this also fixes b#3). Net
effect on `tracks`: a drop from **seven** taxonomy FKs to **four** (`collection_id` + `artist_id` +
`genre_id` + `narrator_id`), of which any given row uses 2–3.

The reason to adopt it **now** rather than leave it optional: **adding a third type stays cheap.** A
`podcast` (or audio-drama, lecture, …) becomes a new `collections.type` value plus at most a contributor
field or two — **not a new container column that lands null on every existing row.** Container growth is
exactly where the "does every new type cost more columns?" pain lives, and this removes it. (Generalising
*contributors* the same way would make a new type truly zero-column — but that is option C, and it
complicates the scanner + UI for a payoff a bounded 2–4 types doesn't justify.)

#### Shape

```
collections
  id               uuid pk
  type             album | audiobook | podcast_show          # enum → varchar + CHECK
  name             string
  year             int   nullable
  cover            bool
  album_artist_id  uuid  fk → artists   nullable  (music)       # container owner
  author_id        uuid  fk → authors   nullable  (audiobook)   # fixes b#3
  timestamps
  CHECK: album_artist_id set only when type='album'; author_id only when type='audiobook'
  unique NULLS NOT DISTINCT (type, name, album_artist_id, author_id)   # dedup, case-insensitive; see (b#3)

tracks
  id               uuid pk                                   # independent random uuid (NOT uuidv5(hash))
  type             music | audiobook | podcast               # playable kind; corresponds to collection.type
  collection_id    uuid  fk → collections   (restrict)       # ONE container FK, every type; taxonomy FKs = restrict (b#1)
  artist_id        uuid  fk → artists       nullable  (music)      # performer
  genre_id         uuid  fk → genres        nullable  (music)
  narrator_id      uuid  fk → narrators     nullable  (audiobook)
  composer, publisher   (music, text)
  path             string                                    # RELATIVE to the area root; UNIQUE (type, path) — one file per area
  content_hash     string  index (non-unique)                # NEW — audio-stream hash = identity; clones share it
  size, modified_at                                          # with path = the "unchanged" fast-path
  … other technical columns (codec, channel, duration, sample_rate, bit_rate, vbr, cover, track, disc) …
  created_at                                                 # NEW — real "recently added" (see (c))
  CHECK: music FKs null unless type='music'; narrator_id null unless type='audiobook'
```

`type` is stored on `tracks` (not just derived through the join) because a Postgres `CHECK` can't
reference another table — the type-guard above needs the value locally; the scanner keeps it in step
with the collection. The two enums are **parallel but not identical**: a track's `type` is the *playable
kind* (`music` / `audiobook` / `podcast`), its collection's `type` is the *container kind* (`album` /
`audiobook` / `podcast_show`), mapping `music↔album`, `audiobook↔audiobook`, `podcast↔podcast_show`. The
guard now covers a **smaller** set of columns than literal B (the container and both owners moved to
`collections`), and `collections` carries its own small type-guard for the two owner FKs.

---

## (b) Foreign-key constraints

Findings and recommended changes:

1. **Everything is `onDelete('cascade')` — a footgun once you stop truncating.** Today it's masked by
   `FOREIGN_KEY_CHECKS=0` + truncate; under a real diff those cascades fire, and `songs.artist_id →
   cascade` literally means *deleting one artist deletes all their songs.* Because the scanner only ever
   removes **orphaned** taxonomy (see #5), the right constraint on the taxonomy FKs
   (`tracks.{collection_id, artist_id, genre_id, narrator_id}`, `collections.{album_artist_id,
   author_id}`) is **`restrict`** — not `cascade`, not `nullOnDelete`. An orphan has nothing referencing
   it, so `restrict` never blocks the prune; and it turns any *accidental* delete of a still-referenced
   taxonomy row into a loud error rather than a silent cascade or a stray null (which is what you'd
   rather not have to reason about). Reserve `cascade` for true ownership (`user → playlists →
   playlist_tracks`).

2. **`albums`, `genres`, `audiobooks` have no unique constraint on `name`** — dedup is *purely*
   `firstOrCreate` logic. MySQL's `utf8mb4_unicode_ci` still made matching case-insensitive; **Postgres
   is case-sensitive by default,** so the v2 scanner would create `Rock` *and* `rock`, `Beatles` *and*
   `beatles`. Add unique constraints and **reuse the ICU `case_insensitive` collation already minted for
   `users.name`** (see the v2 `create_users_table` migration) on every name column: `artists`,
   `authors`, `narrators`, `genres`, and the per-type composite on `collections` (see #3). Dedup then
   becomes case-insensitive *and* DB-enforced.

3. **`audiobooks` has no `author_id` / `narrator_id`,** and `getAudiobook()` dedups on **name only** — so
   two different books whose `album` tag collides **collapse into one row.** A latent data-integrity bug.
   Under **B + collections** this is fixed by construction: the `collections` row carries the owner FK
   (`author_id` for audiobooks, `album_artist_id` for music albums) and the dedup key is
   **`UNIQUE NULLS NOT DISTINCT (type, name, album_artist_id, author_id)`** (Postgres 15+, fine on 17).
   The `NULLS NOT DISTINCT` matters because the owner is nullable: a plain unique treats NULLs as
   *distinct*, so two *untagged-owner* rows of the same title would slip past as separate. With it, two
   same-title books by different authors stay distinct **and** two with no author tag still dedup instead
   of duplicating.

4. **`playlist_entries` has no `song_id` FK** (see *the one fact*). Under stable IDs it becomes a real
   `playlist_tracks.track_id → tracks` (**`cascade`**), owned via `playlist_id → playlists → users` (no
   separate `user_id` on the row — ownership rides the playlist). On a file deletion the scanner runs
   **relink-then-cascade**: before hard-deleting the orphaned track it looks for a surviving **clone**
   (another row, same `content_hash`) and, if found, `UPDATE`s that track's `playlist_tracks` *and*
   `plays` to point at the clone; only if no clone survives does the `cascade` drop them. So `track_id` is
   **always a live FK** — no nulls, no snapshot, no dead entries — and a curated playlist survives your
   culling one of two identical files. `plays.track_id → tracks` takes the same `cascade` + relink rule.

5. **Orphaned taxonomy must be pruned — a *new* problem the diff creates.** Truncate cleared unused
   artists/albums/genres for free every night; a diff leaves them behind, and a browse list full of
   zero-track artists is bad UX. After reconciling tracks, the scan deletes any taxonomy row with no
   remaining referrers — checking **both** referring sides, since a contributor is reached from `tracks`
   *and* `collections`:

    ```sql
    DELETE FROM artists a
     WHERE NOT EXISTS (SELECT 1 FROM tracks t      WHERE t.artist_id = a.id)
       AND NOT EXISTS (SELECT 1 FROM collections c WHERE c.album_artist_id = a.id);
    ```

    (`genres` / `narrators` / `authors` / empty `collections` likewise.) This is what makes the `restrict`
    in #1 a non-event: only orphans are ever deleted, and an orphan has nothing pointing at it.

6. **Postgres has no `FOREIGN_KEY_CHECKS=0`.** The legacy scan disabled FK checking wholesale; there's no
   session equivalent — and none is needed. The schema is acyclic (`tracks → collections → contributors`,
   no back-edge), so the scan writes **parent-first** (contributors → collections → tracks) and prunes in
   reverse (orphan tracks, then orphan taxonomy) inside one transaction. `DEFERRABLE INITIALLY DEFERRED`
   FKs (`->deferrable()->initiallyDeferred()`) are the fallback only if a cycle is ever introduced.

---

## (c) Indexes

**Lead finding:** on the legacy MySQL box, InnoDB auto-created a backing index for every FK, so the
`*_id` columns were indexed for free. **PostgreSQL does not index the referencing side of a FK** — it
only requires a unique index on the *referenced* side (the PK). `foreignUuid()->constrained()` in
Laravel adds the constraint, **not** an index. So the FK indexes have to be added back explicitly — but
be proportionate about *why*. At ~12k tracks a seqscan join is sub-millisecond, so most FK indexes here
don't speed up reads; they **back the delete path** — every `restrict` / `cascade` check and the
orphan-prune (b#5) scans the child by its FK column, many times per scan. The indexes that actually move
*read* latency are the **`plays` composites** (the one table that grows unbounded) and the **trgm search
index** (a full-text seqscan on every keystroke is the one thing a user would feel). Index for those and
for the delete checks; don't cargo-cult the rest.

Recommended indexes:

- **FK columns that need a *standalone* index:** `tracks.{artist_id, genre_id, narrator_id}`,
  `collections.{album_artist_id, author_id}`, and `playlist_tracks.track_id`. Their main job is backing
  the `restrict` / `cascade` checks and the orphan-prune (b#1, b#5), not read-joins. **Do *not* add
  standalone indexes for** `tracks.collection_id`, `playlist_tracks.playlist_id`, `playlists.user_id`, or
  the `plays.*` FKs — each is already the leftmost prefix of a composite / unique below (ordered-playback,
  `(playlist_id, position)`, `unique (user_id, name)`, the `plays` composites), so a separate index is
  pure write-overhead.
- **Scan identity:** `tracks.content_hash` (plain B-tree, equality only) for the rename-match step *and*
  the "x clones" lookup; `tracks.path` is already indexed by its `UNIQUE` constraint (the fast-path also
  compares `size` / `modified_at`, which need no index — the `path` hit is the selective one).
- **Name equality / dedup:** the unique + ICU `case_insensitive` collation from (b) *is* the index. On
  `artists` / `authors` / `narrators` / `genres` it leads with `name`, so it serves both name-equality
  lookups and `firstOrCreate` dedup. On `collections` the unique is `(type, name, album_artist_id,
  author_id)`: it covers dedup **and** doubles as the alphabetical browse index (`WHERE type = ? ORDER BY
  name`) — but, leading with `type`, it does *not* serve a bare `name` lookup; name *search* on
  collections rides the trgm GIN below.
- **Substring search (`pg_trgm` GIN):** every legacy search is `LIKE '%segment%'` (leading wildcard,
  non-sargable → full scan). Add `CREATE EXTENSION pg_trgm` + `USING gin (<col> gin_trgm_ops)` on every
  searchable name: **`tracks.name`** (song / chapter titles — the primary target), `collections.name`,
  `artists.name`, `authors.name`, `narrators.name`, `genres.name`. `gin_trgm_ops` does its own
  case-folding for `ILIKE`, independent of the ICU collation, so the trgm GIN and the collated unique
  coexist (substring-search vs equality-dedup).
- **Ordered album/book playback:** composite `(collection_id, disc, track)` on `tracks` — this is also
  the index that covers `collection_id` on its own. `disc` / `track` are nullable → nulls sort last,
  which is the right place for untracked files.
- **"Recently added" — per media type** (music churns, audiobooks barely move, so the widgets are split):
  `(type, created_at)` at **both grains** — `collections (type, created_at)` for "recently added
  albums / books" and `tracks (type, created_at)` for the track grain — each answering `WHERE type = ?
  ORDER BY created_at DESC LIMIT n`. The `collections` type-unique does *not* cover this (no
  `created_at`), so it's a genuine extra index. And `created_at` is finally meaningful: under the diff
  it's set once at insert and untouched by re-tags / renames (those are UPDATEs) — a stable true "date
  added," unlike legacy's `modified_at` (file mtime), which moved on every re-tag.
- **`plays` (new) — most-played is per-user *and* global:** `(user_id, played_at)` for a user's history
  feed; `(track_id)` for **global** most-played (also serves the relink `UPDATE … WHERE track_id = …` and
  the cascade check); `(user_id, track_id)` for **per-user** most-played. Both most-played views **group
  by `tracks.content_hash`** (#5) via a `plays → tracks` join — the `plays` FK indexes serve the
  join/filter, the existing `tracks.content_hash` index the grouping, so no extra `plays` index is
  needed (pre-aggregate into a materialized view only if `plays` ever grows enough to feel it). `plays`
  is the only unbounded-growth table, so these are the read indexes that matter most.
- **`playlist_tracks`:** `(playlist_id, position)` for ordered render (also covers `playlist_id`);
  `track_id` for the reverse lookup ("which playlists contain this track") + the relink `UPDATE` + the
  cascade check.

Postgres portability notes (all non-issues, just be aware): `$table->year()` maps to `integer`;
`enum('channel', …)` becomes `varchar` + `CHECK`; `float(precision: 53)` = `double precision`.

---

## Playlists as a first-class concept

v1 treated playlists as a global, denormalised afterthought. v2 makes them user-owned and central. The
key reframe is that "playlist" is **two concepts** v1 smushed together:

|           | **Saved playlists**  | **Play queue** ("temporary playlist")                                                             |
| --------- | -------------------- | ------------------------------------------------------------------------------------------------- |
| Lifespan  | Durable, named, CRUD | Ephemeral — what's playing now / next                                                             |
| Owner     | A user               | The current session                                                                               |
| Driven by | User intent (curate) | The player (auto-advance)                                                                         |
| Lives in  | **Server / DB**      | **Client composable** (live) + server `player_states` (logged-in); `localStorage` fallback (anon) |

### Saved playlists — schema (assumes B + collections)

```
playlists
  id            uuid pk
  user_id       uuid  fk → users        (cascade)     # user-specific (v2 users use uuid PKs — HasUuids)
  name          string
  description   text  nullable
  position      int                                    # user's ordering of their own playlists
  timestamps
  unique (user_id, name)                               # your "Rock" ≠ my "Rock"

playlist_tracks                                         # renamed from playlist_entries
  id            uuid pk
  playlist_id   uuid  fk → playlists     (cascade)
  track_id      uuid  fk → tracks        (cascade)     # always live — relink-to-clone, else cascade (b#4)
  position      int
  created_at
  index (playlist_id, position)
  index (track_id)                                       # reverse lookup + the relink UPDATE
```

This fixes two v1 problems: a **real `track_id` FK** (only possible thanks to stable, content-hash
identity), and **mixed-type playlists for free** — because `tracks` is unified (option B), a playlist row
list is just `track_id`s and can hold music *and* audiobook chapters with no polymorphism. (Under option A this table
needs two nullable FKs + a `CHECK` — another point for B.) And unlike v1's denormalised
`path/song/artist/album` strings, **no snapshot is needed**: relink-then-cascade keeps `track_id`
pointing at a live row, so title/artist come from the join.

### The play queue — client composable, server-persisted

The queue is the natural home for the **background-playback** feature: auto-advance drives off the audio
element's `ended` event, which lives in the browser, and the player keeps running while Inertia swaps
pages (it lives in a **persistent layout**). So the **live** queue is always a client composable —
`usePlayerQueue`, an ordered array of track refs + current index + position — never a server round-trip
per track change.

For logged-in users that composable is **persisted server-side** as a single per-user JSON row, so the
queue and your place in it resume on any device. It is deliberately *not* a normalised table: unlike a
saved playlist (relational, queried, shared), the queue is private to one player and read/written
**wholesale** — load it whole, save it whole.

```
player_states
  user_id     uuid pk  fk → users (cascade)
  queue       jsonb        # ordered [track_id, …] + current_index + position_ms  (+ shuffle/repeat later)
  updated_at
```

- **Hydrate via Inertia:** the server ships `player_states.queue` in the shared props on load; the
  persistent player layout hydrates from it, then the client POSTs **debounced** syncs (on track change /
  pause / unload — a lightweight `204`, not a full Inertia visit). Last-write-wins across devices is fine
  at this scale.
- **Anonymous listeners** have no `user_id` → the same composable falls back to `localStorage` (or
  in-memory). Server-persistence enhances logged-in use; the queue must still work without it.
- **Stale ids:** a persisted queue can reference a track the DB has since removed (cascade) → hydration
  **skips missing ids**, never assuming the cached queue is still valid.
- Operations: `playNow` (replace), `queue` (append), `playNext` (insert after current), `remove`,
  `reorder`, `clear`.
- Wire the **Media Session API** here for OS / lock-screen controls + now-playing metadata.
- **Bridge to saved playlists:** "Save queue as playlist" → POST the queue → create a `playlists` row +
  entries. "Play playlist" → load its tracks → replace/append the queue. The temporary list can be
  *promoted* to a permanent one.

### Actions → where they land

| UI action (album or song context)     | Target | Mechanism                                     |
| ------------------------------------- | ------ | --------------------------------------------- |
| Play album now                        | Queue  | `playNow` — replace queue with album's tracks |
| Queue album                           | Queue  | `queue` — append album's tracks               |
| Play next                             | Queue  | insert after current index                    |
| Add song / album to existing playlist | Saved  | POST append `track_id`(s) → `playlist_tracks` |
| New playlist from album/selection     | Saved  | POST create playlist + entries                |
| Save current queue as a playlist      | Saved  | POST queue → playlist                         |
| Reorder / remove within a playlist    | Saved  | PATCH positions (re-normalise in a txn)       |

### How it plugs into the rest of v2

- **Share links:** the access model already names a playlist as a share target ("signed URLs scoped to a
  single song / album / **playlist**"). A first-class playlist is the natural unit — a signed/temporary
  URL renders a read-only, playable playlist for a friend with no account. No extra schema unless you
  want *revocable per-link* shares (a separate `shares` table, later).
- **Listen history / most-played:** the client fires a "played" beacon on `ended`/threshold as the queue
  advances → the `plays` table. Same unified `track_id`, so listens count uniformly across playlists,
  albums, and ad-hoc queue plays; **most-played then aggregates by `content_hash`** (#5), so the same
  recording on album + compilation + best-of counts once. This dovetails with relink-then-cascade (b#4):
  a recording keeps its count as long as *any* copy of that audio survives, and loses it only when the
  last copy is gone — exactly the "hash nowhere in the DB → don't care" rule.
- **Cross-device resume:** because the whole player-state (queue + `current_index` + `position_ms`)
  persists per user, resuming the *current* session on another device is free — including mid-audiobook.
  Remembering your place in *every* book across sessions (after you've since played other things) is a
  separate optional per-book bookmark, later.

---

## Open decisions

1. ~~**Scan write model + track identity.**~~ **Decided 2026-07-20 → content-hash diff:** replace
   truncate-and-rebuild with a diff keyed on an audio-stream `content_hash`; `UNIQUE (path)` anchors
   *one file ⇒ one row*; duplicate audio is allowed and surfaced as "clones". Rationale + algorithm in
   *the one fact that colours everything*.
2. ~~**Tracks split: A / B / C.**~~ **Decided 2026-07-20 → B + the collections half-step:** unify the
   playable row into one `tracks` table; merge `albums` + `audiobooks` into `collections`; keep the two
   taxonomy *trees* separate; reject C. Rationale + shape in (a). The playlist / `plays` / share-link
   designs all assume it, and a future `podcast`-style type is a new `collections.type` value rather than
   new columns.
3. ~~**Play-queue persistence.**~~ **Decided 2026-07-20 → client composable, server-persisted.** The live
   queue is a client composable (`usePlayerQueue`, in a persistent layout — forced by the browser audio
   player), synced to a per-user JSON `player_states` row for logged-in users (cross-device resume,
   hydrated via Inertia shared props) with a `localStorage` fallback for anonymous listeners. Shape +
   rationale under *The play queue*.
4. ~~**Playlist reorder strategy.**~~ **Decided 2026-07-20 → contiguous integers, renumber in a txn.** A
   reorder rewrites `position = 0…n−1` for the new order (client PATCHes the full sequence) inside one
   transaction — at tens-to-hundreds of tracks that's a sub-millisecond bulk `UPDATE`, so the
   write-amplification that gap-based / LexoRank optimise away is noise here. `(playlist_id, position)`
   stays **non-unique** (transient mid-txn dups); same rule for `playlists.position`. Reconsider only at
   thousands of items *plus* frequent concurrent moves — not this app.
5. ~~**Most-played aggregation grain.**~~ **Decided 2026-07-20 → aggregate by `content_hash`.** Most-played
   groups by `tracks.content_hash`, not `track_id` / `path`, so a recording that lives on its album, a
   compilation, and a best-of counts as **one** song (they share a hash). Different *audio* — studio vs
   live vs remastered — has a different hash and counts separately, which is the desired behaviour and
   falls out for free. Both grains from (c) group the same way: global = `GROUP BY t.content_hash`,
   per-user = `WHERE user_id = ? … GROUP BY t.content_hash` (join `plays → tracks`). Display picks a
   representative clone for the title/artist label, since clones share audio but their *tags* can differ.

**Every decision is now settled** — #5 aggregates most-played by `content_hash` (clones count once). The
proposal is ready to become the v2 schema: draft the migrations + models (`Track`, `Collection`,
taxonomy, `Playlist`, `PlaylistTrack`, `PlayerState`) and the `usePlayerQueue` composable shape.

---

## Appendix — legacy schema as-is (`../MixTape`, MySQL/MariaDB)

Reference snapshot of what the legacy migrations actually create. UUID PKs throughout except `users`
(bigint). `songs` / `tracks` set `timestamps = false`.

**Music**

- `artists` — `id`, `name` **unique**.
- `albums` — `id`, `name`, `year?`, `album_artist_id → artists (cascade)`. *(name not unique)*
- `genres` — `id`, `name`. *(name not unique)*
- `songs` — `id`, `name`, `track?`, `disc?`, `publisher?`, `composer?`, `codec?`, `channel?` (enum:
  stereo/dual_mono/joint_stereo/mono), `size?`, `duration?` (double), `sample_rate?`, `bit_rate?`,
  `vbr` (default false), `cover` (default false), `path` **unique**, `artist_id → artists (cascade)`,
  `album_artist_id → artists (cascade)`, `album_id → albums (cascade)`, `genre_id → genres (cascade)`,
  `modified_at?`.

**Audiobooks**

- `authors` — `id`, `name` **unique**.
- `narrators` — `id`, `name` **unique**.
- `audiobooks` — `id`, `name`, `year?`. *(name not unique; no author/narrator FK)*
- `tracks` — `id`, `name`, `track?`, `disc?`, `codec?`, `channel?` (enum), `size?`, `duration?`,
  `sample_rate?`, `bit_rate?`, `vbr` (default false), `cover` (default false), `path` **unique**,
  `author_id → authors (cascade)`, `narrator_id → narrators (cascade)`,
  `audiobook_id → audiobooks (cascade)`, `modified_at?`. *(no genre/publisher/composer columns)*

**Other**

- `global_properties` — `id`, `key` (64), `updated_at?`. Used only for the `refresh.full` timestamp.
- `playlists` — `id`, `name` **unique**, `sort`, timestamps. *(global, not user-scoped)*
- `playlist_entries` — `id`, `path`, `song`, `artist`, `album` (all denormalised strings), `duration?`,
  `size?`, `sort`, `playlist_id → playlists (cascade)`, timestamps. *(no song FK)*

**Tag → column mapping (ingest)**

- *Music* `songs`: `name←song`, `artist_id←artist`, `album_artist_id←TPE2 ?? artist`,
  `album_id←album (+album_artist, +year)`, `genre_id←genre`, `publisher←TPUB`, `composer←TCOM`,
  `track←track`, `disc←TPOS`, technical fields from the stream, `modified_at←filemtime`.
- *Audiobook* `tracks`: `name←song`, `author_id←TCOM`, `narrator_id←artist`, `audiobook_id←album (+year)`,
  `track←track`, `disc←TPOS`, technical fields, `modified_at←filemtime`. No genre; `bit_rate` is not
  populated by the audiobook scanner.

**Known query hot-spots** (for the index rationale; all in `app/Services/`)

- List endpoints eager-load all rows then aggregate in PHP (`count`/`sum`/`unique`) — plus an N+1 genre
  load per album in `AlbumService`, and repeated `Author::all()` / `Narrator::all()` inside a per-book
  map in `AudiobookService`.
- Search is space-split `LIKE '%segment%'` per token (non-sargable).
- "Recently added" widgets load the whole table then sort by `modified_at` in PHP.
- Playlist rendering runs 3 aggregate queries per playlist and reconnects entries via
  `where('path', …)` against an unindexed `playlist_entries.path`.
