# Audiobooks

Three pages over the same books, plus the one thing music does not need.

| | |
| --- | --- |
| `/audiobooks` | a stats card, then **Books / Authors / Narrators** as three tabs |
| `/audiobooks/{id}` | one book: a hero, and its chapters as a table |
| resume | where you left off, **per book**, so several are in flight at once |

The area is gated on `library.audiobook` like every other, so an instance with no books never meets it
(`HandleInertiaRequests::areasWithTracks`).

## An audiobook's author lives on the CHAPTER

`tracks.author_id`, beside `narrator_id`, which is per-track too. **The book has no owner column at all**
— it dedupes on its title alone, and its authors and narrators are read through its chapters.

The reason is that **TCOM is a per-file tag and an anthology uses it per story.** Measured on a real
library:

    Necrophobia 1   32 chapters   6 authors   4 narrators   2 uncredited
    Necrophobia 2   32 chapters   5 authors   5 narrators   2 uncredited

With the author on the collection, `LibraryScanService::collection()` keys its `firstOrCreate` on
`(type, name, album_artist_id, author_id)` — so those two books scan as **eleven collection rows sharing
two titles**, one per (title, author) pair. A book-level column cannot hold that, and cannot fill the
detail page's per-chapter Author column either.

So the relations run the other way: `Collection::authors()` / `narrators()`, `Author::audiobooks()`, all
`belongsToMany` over `tracks`. **Two traps those relations carry**, both in their docblocks:

- **`belongsToMany` over `tracks`, not `hasManyThrough`.** The FK sits on the chapter, which makes the
  chapter a **pivot** rather than an intermediate owner; `hasManyThrough` looks for the key on the far table,
  finds nothing, and returns zero every time.
- **Qualify every column you name.** `->count()` rewrites the select to `count(*)` and leaves the
  `distinct()` nothing to apply to, so an author with nine chapters in one book counts nine times — it has
  to be `->count('authors.id')`. And `->pluck('name')` is an outright *"ambiguous column name"*, since the
  pivot has a `name` of its own.

## Resume — the reason the area exists

`audiobook_bookmarks`, one row per **(reader, book)**, composite primary key.

**Not `player_states`.** That row is the live player: one per user, holding whatever is playing now. A book
is the thing you put down for a fortnight with three others on the go, and losing your place in one because
you spent an evening on another is the failure this prevents.

- The write **rides the player's own heartbeat** — `usePlayerAudio` counts off the audio element's
  `timeupdate`, which keeps counting in a backgrounded tab where a `setInterval` is throttled to once a
  minute. A paused player writes nothing.
- A **new chapter is stored immediately** rather than at the next heartbeat: that is the fact most worth
  remembering, and waiting 30 seconds loses it to a closed tab.
- It only ever writes **this book's own chapters**. The queue is shared with music, so what is loaded may be
  a song or another book's chapter, and neither may move this bookmark.
- The write is `preserveState` + `preserveScroll` with `only: []`. Without them a background Inertia visit
  re-keys the page component mid-play — the same class of bug the prefetch rule in
  [`architecture.md`](architecture.md) exists for.

**The page opens on the bookmarked chapter's page.** `DataTableService::buildResponse` takes a
`defaultPage`, applied only when the request names no page of its own — otherwise the pager's own first
button would bounce back to the bookmark. The controller counts the rows ordered before that chapter (one
aggregate over the `(collection_id, disc, track)` index) and divides by
`DataTableService::pageSizeFor($request)`, so the arithmetic cannot disagree with the response it is aimed
at when a reader picks 25 rows. The row itself is marked with the `bookmark` icon.

## What the area deliberately does not look like

- **No DataTable on the entry page.** This is a music player that also holds audiobooks; twenty books do
  not need sorting, paging or a column of file sizes. They need to be *recognisable*, which means covers —
  so all three tabs draw the shared `Discography` grid.

  Borrowing that grid borrows its vocabulary with it, so the per-item count word is a **prop**
  (`count-key="audiobooks.chapterCount"`, passed on all three grids) rather than a `kind` branch inside the
  component. The next caller with a different word adds a catalogue entry, not a case.
- **The chapter rows are not links.** A chapter has no page of its own; what a reader wants from a row is to
  hear it, so each carries a play button instead.
- **Pressing a chapter queues the WHOLE BOOK** and starts there (`playSubjectFrom`). Playing that one
  chapter would strand a listener at the end of it, forty chapters from the end of the book.
- **No genre, no add-to-playlist.** The `tracks` CHECK forbids an audiobook a genre, and
  `PlaylistAdditions` resolves a subject's tracks music-only on purpose.
- **Authors and narrators have no pages of their own** — they are accordion sections, which is why the
  search finds a book by its title and never by its author.

## The seams it plugs into

Most of the data layer is type-agnostic by design, with docblocks saying so: `CoverService`,
`AlbumArchive`, `InternalRedirect`, `Track::absolutePath()`, `PlayCounts`, `PlayerStatePayload` and the
`plays` table all take an audiobook without changing. What each remaining seam cost:

| seam | cost |
| --- | --- |
| **Queue** | `QueuePayload::entry()` routes its three URLs by track type. Without that, a chapter is addressed as a song and 404s on the music guard — a book resumes as silence. |
| **Media** | Four routes. Nothing behind them needed changing. |
| **Search** | A class, an enum case, one registry line — exactly what the registry exists for ([`search.md`](search.md)). |
| **Shares** | One enum case and three `match` arms, no migration ([`sharing.md`](sharing.md)). |

**Chapter routes are FLAT** (`/audiobooks/chapters/{chapter}/…`), mirroring `/music/songs/…`. Nesting them
under the book was tried and dropped: everything is behind `auth` and any reader may play any chapter, so
containment buys no authorization — and a chapter whose file carries no album tag has no `collection_id` to
be nested under, which would hand the player a null `streamUrl`.

**Search scoping is per box** (`useLibrarySearch`'s `only`): the header searches everything, the Music card
cannot answer with audiobooks, the audiobook card cannot answer with music.

## What is not here yet

- **No end-to-end coverage of this area.** `E2ESeeder` is a fixed fixture holding no audiobooks, and adding
  them is its own piece of work — **add, never reshape**, because other specs name its rows by hand.
- **No "Continue listening" shelf.** The `(user_id, updated_at)` index on the bookmarks table is there for
  it.
