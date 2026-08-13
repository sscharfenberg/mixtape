# Audiobooks — build the area

> Planned 2026-08-13. Not started. Milestones are commit boundaries; the run stops at one with every
> suite green rather than mid-step.

## Context

`/audiobooks` is a lorem-ipsum placeholder, and it is the last unbuilt area of the v2 rewrite. Two
other finished subsystems are explicitly waiting on it: [`docs/search.md`](search.md) and
[`docs/sharing.md`](sharing.md) both carry the status row *"⬜ blocked — the Audiobooks area is a
placeholder"*.

The good news: **the data layer is already complete and already scanned.** `collections`/`tracks`
carry a `type` discriminator, `authors` and `narrators` exist as taxonomy tables,
`LibraryScanService` has a full audiobook arm (TCOM→author, TPE1→narrator), covers are synced, and
the nav already gates the area on `library.audiobook`. `CoverService`, `AlbumArchive`,
`InternalRedirect`, `PlayCounts`, `PlayerStatePayload` and the `plays` table are all deliberately
type-agnostic already, with docblocks saying so. So this is mostly **wiring an area onto a model
that exists**, not new plumbing.

The exception is what audiobooks need that music does not: **you must be able to put a 673-chapter
book down and pick it up where you left it.** That is the one genuinely new subsystem here.

## Decisions taken (owner, 2026-08-13)

- Resume remembers **chapter + seconds**, **per book** (several books in flight at once).
- Hero **play resumes**; a second action restarts from chapter 1.
- **One shared queue** with music — starting a book replaces what was queued. The bookmark is what
  makes a book recoverable, so nothing is lost.
- **Commit per milestone**, nothing pushed. On an ambiguity: leave an explicit commented placeholder
  and carry on.

## The one schema change — author moves to the track

Measured against the real library (`/Volumes/media/audiobooks`) while planning: **"Necrophobia 1"
carries 4 different authors across its 33 chapters** (Lumley, Meyrink, Lovecraft, Laymon) plus
untagged ones, and **"Necrophobia 2" carries 5**. `LibraryScanService::collection()` firstOrCreates
on `(type, name, album_artist_id, author_id)`
(`app/Services/Library/LibraryScanService.php:421-436`), so those two anthologies scan as **eleven
collection rows sharing two names**. The detail page's per-track Author column cannot be filled from
a book-level column either.

So `author_id` moves from `collections` to `tracks`, mirroring `narrator_id`, which is already
per-track and already handles the multi-narrator anthologies correctly. TCOM is a per-file tag; this
matches what the files say.

**No data migration.** Owner's call: `migrate:fresh` + a full re-scan, no real users yet. The
migration therefore assumes a clean database — it must not be run over the existing dev rows,
because the new `(type, name, album_artist_id)` unique index cannot be created while the duplicate
audiobook collections are still there.

## Milestones

### M1 — schema + scanner

- Migration: add `tracks.author_id` (uuid FK→`authors`, `restrictOnDelete`), drop
  `collections.author_id`; rewrite `collections_owner_type_ck` (only `album_artist_id`, only on
  albums), extend `tracks_type_taxonomy_ck` (music ⇒ `author_id IS NULL`); rebuild
  `collections_dedup_uq` as `(type, name, album_artist_id) NULLS NOT DISTINCT`.
- `LibraryScanService.php:350-357` — write `author_id` onto the track, drop it from the
  `collection()` owner array.
- `Author::audiobooks()` (`app/Models/Author.php:33`) becomes a distinct-collections query over
  tracks; add `Author::tracks()` and `Track::author()`.
- Factories: `TrackFactory::audiobook()` sets `author_id`; `CollectionFactory::audiobook()` drops it.
  `LibrarySeeder` grows **one multi-author, multi-narrator anthology** so the shape is seeded.
- Test: an anthology whose chapters carry three different TCOM values scans as **one** collection
  with three distinct track authors (`tests/Feature/Library/LibraryScanServiceTest.php`).

### M2 — read model, routes, media

Mirrors `routes/web.php:194-274` exactly, names under `audiobooks.*`: `audiobooks.show`, `.cover`,
`.chapters.stream`, `.chapters.cover`, `.download` (throttle `10,1,audiobook-download`, matching the
album ceiling).

- `AuthorizesAudiobook` / `AuthorizesAudiobookTrack` concerns in
  `app/Http/Requests/Audiobooks/Concerns/`, `failedAuthorization()` → `abort(404)`, exactly like
  `AuthorizesMusicAlbum` (`app/Http/Requests/Music/Concerns/AuthorizesMusicAlbum.php:21-42`).
- **`QueuePayload::entry()`** (`app/Services/Music/QueuePayload.php:139-155`) branches on
  `$track->type` for its three URL fields. This is the one change that makes a chapter playable —
  today it would emit `/music/songs/…` URLs that 404 on `AuthorizesMusicTrack`.
- Reuse unchanged: `CoverService`, `AlbumArchive` (already `$album->type->trackType()`),
  `InternalRedirect`, `PlayCounts` (album-grain callers pass `musicOnly: false`).

### M3 — entry page `/audiobooks`

`AudiobooksController` returns closure props (per `MusicController:41-59`, so widget refresh works).

- `<headline glow>` + `audiobook` icon, outside `<container>` (`AlbumPage.vue:193-194` says why).
- **`AudiobookStatsWidget`** — a `Consumers/` sibling of `StatsWidget`: books, chapters, size,
  **authors**, **narrators**, playtime, plus the search hub **scoped to audiobooks**.
- **Tabs** Books / Authors / Narrators via `TabbedNavigation` + `useTabParam`, as
  `GenrePage.vue:201-227`. Only one panel may be a DataTable (`GenreController.php:38-42`) — none
  here is, so all three ship on every request.
- **Books (3a)** reuses `components/Music/Discography/Discography.vue` — it already renders exactly
  cover / title / year / count / duration with its own pager. Pass books as `DiscographyAlbum[]`.
- **Authors (3b) / Narrators (3c)** need a **new shared `Accordion`** (`components/UI/Accordion/`) —
  none exists. Native disclosure semantics, keyboard, `aria-expanded`, motion under
  `prefers-reduced-motion: no-preference` with `timings/` tokens. Header = name + book count +
  summed playtime; body = the same `Discography` grid. An anthology appears under **every**
  contributor.
- Tests: `assertInertia` on props + tab payloads; Vitest on the Accordion and on locale formatting;
  an E2E browse spec.

### M4 — detail page `/audiobooks/{audiobook}`

Mirrors `AlbumPage.vue` structurally.

- `<headline glow>` + title; `<hero-section>` with `#cover`, `#metadata` (a `FactPair` run: authors,
  narrators, year, chapters, discs, duration, size, added, `PlayCountFacts`), `#actions` =
  `<action-panel>` with **Play (resumes)**, **Restart**, **Enqueue**, then `DownloadButton` and
  `ShareButton` outside the panel. `SubjectActions` already fetches `queueTracks` lazily on first
  press (`SubjectActions.vue:48-59`).
- **Chapters DataTable**: CD#, Track#, Name, Author, Narrator, Playtime + a per-row play button that
  clears the queue, enqueues the book and starts at that row.
- **The bookmark page-jump — the controller can do this.** `DataTableService::buildResponse` gains an
  optional **`defaultPage`**, used only when the request carries no `?page=`
  (`app/Services/DataTableService.php:152` passes it to `paginate()`). The controller counts the rows
  ordered before the bookmarked chapter and sends `ceil(index / pageSize)`, so a book bookmarked at
  chapter 279 opens on that chapter's page. The row is marked with the `bookmark` icon.

### M5 — resume (the new subsystem)

- `audiobook_bookmarks`: `user_id` + `collection_id` composite PK (cascade), `track_id`,
  `position_ms`, `updated_at`. Per-book, so several books stay in flight.
- `PUT /audiobooks/{audiobook}/bookmark` → FormRequest (ownership, and the track must belong to the
  book), 204. Written on the same throttled cadence as the queue position — reuse
  `bindPositionSource` / `notePlaybackProgress` (`usePlayerQueue.ts:611-671`) and
  `config('mixtape.player.position_heartbeat')`, plus a flush on hide.
- Client: `useAudiobookBookmark` composable; the hero's play seeks to it, Restart ignores it.
- Tests: feature tests for write/read and the page-jump; E2E — play, leave, return, land on the
  bookmarked chapter's page with the icon on it.

### M6 — search

`SearchKind::Audiobook` + `AudiobookKind extends DatabaseKind` + one `registry()` line
(`LibrarySearch.php:99-103`); frontend `SearchKind` union, `KIND_ICONS` / `KIND_FACTS` (both
`Record<SearchKind, …>`, so `vue-tsc` fails until they are filled), `SearchScopeChips.vue:55`, and
the `search.kind.*` i18n.

**Scoping, per the spec:** `useLibrarySearch` gains an `only` option constraining what `"all"` means.
Header = everything; Music page = the music kinds; the audiobook widget = audiobooks only.

### M7 — shares (the share button lights up here)

`ShareSubject::Audiobook` + its three `match` arms (`collection_id`, `PlaylistSubject::Album`,
`collections`) — **no migration, no new FK, no CHECK change**; the schema already covers it
([`docs/sharing.md`](sharing.md) → *"Audiobook shares"*). `ShareGrant::subject()` (`:73-82`) must
join `collections.type` to tell an audiobook share from an album share — the one genuinely new line.
Plus `subjectName()`, `StoreShareRequest::subjectExists()`, `SharePageController::identity()`, the
four frontend unions, and the **gendered German** `share.intro.*` sentence.

## Verification

Per milestone: `php artisan test`, `npm run test:unit`, `npm run lint`, `npm run build`.

E2E after M3 / M4 / M5 / M7: `npm run test:e2e`. `E2ESeeder` has **no audiobooks today** and must
gain a book plus the multi-author anthology — it is a fixed fixture other specs name by hand, so
**add, never reshape**.

End to end against real data, which is the only check that catches the scanner half:

```bash
php artisan migrate:fresh --seed          # local — the migration assumes a clean DB
DB_PORT=5342 php artisan migrate:fresh    # dev DB over the tunnel, then on debbie:
mt artisan app:update --dev               # full re-scan, ~40s
```

Then browse `/audiobooks` and check against the measured library: **20 books, 2271 chapters**, and
**Necrophobia 1 and 2 appearing once each**, with 4 and 5 authors respectively.

## Notes

- **Prod stays untouched.** Its path is `migrate:fresh` + re-scan too, which wipes its accounts,
  shares and playlists — acceptable only because there are no real users yet. Deploying prod is not
  part of this work.
- `docs/audiobooks.md` gets written as the area's design record, and the two blocked status rows in
  `docs/search.md` and `docs/sharing.md` get flipped.
- Anything skipped gets an explicit commented placeholder, in the shape of the genre page's Artists
  tab — a comment saying what is missing and why — and a line in the final report.
