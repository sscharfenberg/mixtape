# Browse stats — the strip above a listing

A listing opens on a table of rows, which says nothing about what is in the library or what might be
wrong with it. Above each of the four browse tables — songs, albums, artists, genres — sits a strip
of counts, and every count but the total is **a way into the table**: press it and the listing
narrows to exactly the rows it counted.

Read alongside:

- [`components/DataTable/README.md`](../resources/app/components/DataTable/README.md) — the listing
  surface underneath, whose `filters` response key this feature is the first consumer of.
- [`components/UI/Widget/README.md`](../resources/app/components/UI/Widget/README.md) — the card and
  the tile grid the strip is built from.
- [`player.md`](player.md) → _`popular` means one thing everywhere_ — the browse widgets' modes,
  which answer a related question one layer up.

## The rule everything rests on

**A tile's count and the table its link opens are ONE predicate.** `SongFilter::apply()` and
`AlbumFilter::apply()` are the only place a filter is defined; the tile's number is that predicate
over a bare count query, and the filtered table is the same predicate over the listing's query.

Written twice — a count in the controller, a `where` beside it — the two drift on the first change,
and the drift surfaces as a **wrong number** rather than as a wrong filter. That is the harder bug to
see: a filter that returns the wrong rows is obvious, a tile that says 23 above a table of 19 gets
read as "the count is a bit off" for months. Both stats tests assert the pair together, and a browser
test walks it: press the tile, count the rows.

## A registry, not a branch

One enum per listing, and `cases()` **is** the display order, the way `SearchKind`'s is. A new
question is a case, two translations and an icon — never a branch somewhere.

The case's own value is the `?filter=` value **and** the i18n key
(`music.albumFilters.label["single-track"]`), so nothing maps between them. The only per-listing table
is glyph → filter, which lives in the page-local strip component because a sprite name is neither the
server's business nor a translator's.

**The strips are two components, deliberately not one.** What the listings share is the tile grid
(`StatTiles`) and the payload shape (`ListingStats<Key>`); what differs is every tile's meaning, its
glyph and its wording. A shared "listing strip" would be a component whose whole body is a
per-listing lookup table, with the page-local files still needed to hold the tables.

## The URL is the state

`?filter=` is applied to the query **before** `DataTableService` sees it, so the search, the sort and
the pager all work over the narrowed set rather than around it. It is echoed back through the
response's `filters` key, which is what makes the table drop a row selection when the filter changes:
the rows under those ticks are no longer the same rows.

**A bad value falls back rather than refusing** — the same reading `DataTableService` takes for
`sort`, `dir` and `search`, and for the same reason: the query string is the table's state and readers
pass whole URLs around, so a stale or hand-edited link should render the unfiltered listing, never a 422. This is why the filter is not a FormRequest, against the house rule. `?filter[]=x` arrives as an
**array**, which `tryFrom` typed against a string answers with a 500 — hence `fromInput()`'s
`is_string` guard, the same trap `DataTableService` documents for `?search[]=`.

## Three readings of a tile's link, all decided server-side

Like every other link in this app, an `href` is the controller's to decide, so the strip cannot drift
from the routes:

- **into** — the listing filtered to this count;
- **out** — the UNFILTERED listing, for the filter currently applied. A filtered table a reader
  cannot leave is a dead end, and the tile they arrived by is the honest place for that door. The
  component only chooses the WORD ("show" against "show all") from the same `active` flag that marks
  the tile;
- **none** — a count of zero offers no link at all, because a link to an empty table is a promise the
  page cannot keep. The tile still shows its 0: that a library has nothing filed twice is worth
  reading.

## The counts describe the whole listing, never the filtered view

So they hold still while a reader works through one of them. A strip that re-counted inside its own
filter would answer "23 without artwork" with "23 without artwork" forever, and the tile a reader
arrived by would be the one tile that could never change.

The cost is that the strip can say 920 above a table of 34, which is what the **active mark** is for:
without it, a reader arriving at a bookmarked `?filter=` URL sees a short table and no reason for it.
The link's changed wording is not enough — they have not read it yet.

## Eager, not deferred

A page of aggregates normally wants `Inertia::defer`. This one must not have it, because the DataTable
makes every sort, page and search a **full visit**: a deferred strip would blank and re-arrive on
every click, flashing a skeleton above a table that did not need to wait. Five counts, most of them
index-backed, against a listing query that already joins three tables and counts twice.

## What a tile must not be

**A sort in tile clothing.** Every column a listing shows is sortable, so "most played" and "longest"
are already one header click away, and a tile for either would be decoration. The tiles worth their
space are the questions the table cannot express at all — a gap in an album's track numbering is
invisible in a listing and takes opening albums one at a time to find.

## Choose the questions against the real library, not from a list

Measured on the live collection (920 albums, ~9.9k songs), which is meticulously tagged:

| Question                             | Count | Verdict                          |
| ------------------------------------ | ----- | -------------------------------- |
| songs never played                   | 9,806 | useful                           |
| albums never played                  | 921   | useful                           |
| albums missing a track               | 74    | useful — and invisible elsewhere |
| albums holding one track             | 92    | useful (a loose file, usually)   |
| songs under 192 kbps                 | 1,165 | useful, a re-rip queue           |
| songs filed twice (same audio hash)  | 8     | small but real                   |
| songs with no embedded cover         | 0     | **a slot spent on nothing**      |
| songs with no genre / artist / album | 0     | dead                             |
| albums with no year                  | 0     | dead                             |
| mono files                           | 0     | dead                             |
| artists with no album of their own   | 291   | useful                           |
| credits that read as several names   | 110   | useful — a review queue          |
| genres carried by a single artist    | 74    | useful — and invisible elsewhere |
| genres with one song                 | 14    | small but real                   |
| genres that are nobody's main one    | 1     | dead                             |
| ID3v1-style numeric genre names      | 0     | dead                             |

**A tile that can only ever read 0 is worse than no tile**, and which questions those are is a fact
about this collection rather than about music libraries in general. Measure before choosing.
Tag-hygiene questions are largely dead here; listening, completeness, audio quality and the shape of
the credits are the live axes.

**Two windows, not one.** Songs, albums and genres ask "new this week"; artists ask "new this
MONTH", because an artist is a coarser thing than a file — a week brings new songs constantly and
new artists rarely, so the window that reads 43 songs reads a handful of artists and looks broken (41
over seven days against 53 over thirty). Each label says which, so nothing is ambiguous on screen.

## Four filters whose definition is the interesting part

**`incomplete` is asked per DISC, and strictly greater.** Numbering restarts on disc 2, so a complete
two-disc album has a highest number of 10 against twenty files — asked album-wide, every box set in
the library reports as missing ten tracks. Grouped by `(collection_id, disc)`, an album is incomplete
when any disc numbers HIGHER than its file count.

Strictly greater, not merely different, because the other direction is a different fault: more files
than the numbering reaches is **repeated numbering** — a reissue whose bonus disc claims disc 1, an
album with two track 4s. Measured, 74 albums number above their file count against 4 below, and
reporting those four as "incomplete" sends a reader hunting a file that was never missing. An album
carrying no track numbers at all is a third fault and falls out of the comparison on its own.

**`one-artist` (genres) is not the listing's `artists` column with a filter on it.** That column
counts artists whose MAIN genre this is (`DominantGenre`) — a question about where an artist mostly
sits. The tile counts the distinct performers of the genre's own songs — a question about the genre.
The two disagree by design: a genre can hold two hundred songs by one band and be nobody's main
genre, or be several artists' main genre while its own songs come from one of them. Conflating them
leaves a tile that still looks plausible and answers something else, which is why its test asserts
the tile against that column rather than only against itself.

**`lookalike-name` (artists) counts CANDIDATES, not faults.** It matches a curated list of
separators — ` feat`, ` ft.`, ` vs`, `with`, `, `, `&`, `/` — and much of what it finds is
somebody's real name (_Nick Cave & The Bad Seeds_). That is the point: only the reader can say which
is a mis-tag, so the wording is "names that look like several artists", never "wrong". Matched with
`LIKE` against `name_fold` rather than a regex against `name`, for two reasons that both bite — the
raw column's nondeterministic ICU collation makes Postgres refuse `LIKE` and regex against it alike,
and sqlite, which the test suite runs, has no regex operator at all.

**`never-played` needs something playable.** The artists and genres listings deliberately show rows
holding no music — a compilation owner named on a sleeve with none of their own recordings on it, a
genre whose songs were all pruned — and a row a reader cannot play is not one they have never
played. Its tile would otherwise link to rows nobody can act on.

**`added-this-week` reads the FILE's mtime, not the row's `created_at`.** A row's timestamp is a fact
about the database: the scanner stamps it on insert, so rebuilding the library tables re-stamps
everything and the tile answers "all 920 arrived this week" — measured, exactly that, four days after
a rebuild. `modified_at` is a fact about the file and survives it (7 albums, 43 songs on the same
data). The trade is that re-tagging a file makes it look new, which is the smaller lie and is already
what the rest of the app calls "latest".

## Where it lives

| Piece                                           | Role                                                                   |
| ----------------------------------------------- | ---------------------------------------------------------------------- |
| `app/Enums/{Song,Album,Artist,Genre}Filter.php` | The questions, and the predicate each one is                           |
| the four `Music/…Controller`s                   | The strip's payload: the total, each count, each href, the active flag |
| `Services/DataTableService::buildResponse`      | Echoes the active filter back through `filters` (it never applies one) |
| `pages/Music/…/…Stats.vue`, one per listing     | Glyphs, wording, and the tiles handed to `StatTiles`                   |
| `components/UI/Widget/StatTiles.vue`            | The tile grid, the optional action link, the active mark               |
| `types/music.ts` → `ListingStats<Key>`          | The payload shape all four strips share                                |

A tile's link reserves a line in **every** tile of a strip that has one: a tile is a centred column,
so one with a link under its value pushes that value up, and a row of big numbers that do not share a
baseline reads as a rendering fault (measured at 1440px: 10px off). Collection cards, whose tiles link
nowhere, must not grow a blank line — hence the grid's own flag rather than a per-tile one.
