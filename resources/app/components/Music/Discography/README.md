# Discography

A compact list of albums — artwork, name, year, and what the record adds up to — each row linking to
that album's own page. It belongs to whatever is showing albums, not to one page: an artist's own
records, a genre's, and the **Audiobooks** area's three grids of books.

```vue
<discography :albums="discography" />
```

## Props

| Prop         | Type                 | Default                       | Notes                                                              |
| ------------ | -------------------- | ----------------------------- | ------------------------------------------------------------------ |
| `albums`     | `DiscographyAlbum[]` | —                             | Already ordered by the server. Empty ⇒ renders the empty sentence. |
| `showArtist` | `boolean`            | `false`                       | Show each album's artist as one of its facts — see below.          |
| `countKey`   | `string`             | `music.discography.songCount` | i18n key pluralising the `songs` chip — see below.                 |

`DiscographyAlbum` is exported from the component. Every value is **raw** — seconds, not a clock —
because formatting is the client's job against the active locale (`Utils/formatting.ts`):

| Field      | Type             | Notes                                                              |
| ---------- | ---------------- | ------------------------------------------------------------------ |
| `id`       | `string`         |                                                                    |
| `name`     | `string`         |                                                                    |
| `year`     | `number \| null` | `null` for an untagged rip.                                        |
| `artist`   | `string \| null` | Optional; only needed when `showArtist`. `null` for a compilation. |
| `songs`    | `number`         | How many tracks are filed under it.                                |
| `duration` | `number \| null` | Raw seconds; `null` when no file carried one.                      |
| `coverUrl` | `string \| null` | `null` ⇒ `CoverImage` draws its placeholder.                       |
| `href`     | `string`         | The album's own page — this row's single destination.              |

### `showArtist`

Off by default, because the first caller was an **artist's** own discography — the answer is the same
on every row there, and printing it down the list says nothing. A **genre's** albums are by different
people, so the genre page turns it on and the name becomes the fact that tells one tile from the next.

It renders as **plain text**, not a link to the artist, and cannot become one: the whole tile is
already an `<a>` to the album, and an anchor inside an anchor is invalid HTML that browsers silently
un-nest. Reaching the artist is a hop through the album — the trade for a tile-sized click target.
(`DataTable` affords both because its rows are not anchors; see its README on clickable rows.)

### `countKey`

The `songs` field is "how many tracks are filed under this tile", and what a track is **called**
depends on what the grid is showing. An album holds songs; an audiobook holds **chapters**, and
`32 Songs` on a book is simply wrong — so the Audiobooks area passes
`count-key="audiobooks.chapterCount"` on all three of its grids.

A **key**, not a finished string, so the caller does not hold a `t()` of its own and the chip
re-renders on a locale switch like everything else. And not a `kind` enum, so the next caller with a
different word adds a catalogue entry rather than a branch in the component.

## Server side

The caller's controller shapes the rows. `ArtistController::discography()` is the reference
implementation, and the two things worth copying from it are the **ordering** and the **cover URL**:

- Order in SQL, not here — the component renders what it is handed, in the order it arrives.
- Decide `coverUrl` from stored state (`collections.cover_path`, or any track carrying embedded art)
  rather than touching the filesystem, so a list of albums costs no `stat` per row.

Beware NULL ordering if you sort by `year`: Postgres and SQLite put NULLs at opposite ends, in both
directions. `ArtistController` sorts on a `CASE` flag (discography) or a COALESCEd alias (songs table)
rather than the raw column — see the comments there.

## Paging

It pages **on the client**: the caller hands over the whole set, this slices it, and
`DiscographyPagination` draws the control — which is the `DataTable`'s own pager, reused as the pure
presentation it is. So the albums tab gets the same control in the same place as the songs tab beside
it, while a page change stays a slice rather than a request.

| Prop       | Default | Notes                                                                             |
| ---------- | ------- | --------------------------------------------------------------------------------- |
| `pageSize` | `25`    | Must be one of the pager's own sizes (25 / 50 / 100) or its Select shows nothing. |

The pager is hidden entirely below one page, so the common case — an artist averaging under two albums
— never sees it. Changing page size keeps the reader's position rather than resetting to page 1, and
the page resets **only** when the album set itself changes (a different artist arriving in the same
mounted component; without it, a link from a 60-album genre to a 3-album one while on page 3 renders
nothing).

## Why this is not a DataTable

A server-paged list would be wrong here twice:

1. **The data is already on the client.** A tabbed page sends every panel's data on every request
   precisely so switching tabs costs nothing (`useTabParam`), and fetching a page of albums would give
   that back for a set measured in dozens.
2. **It shares a page with a real `DataTable`.** `DataTableService` reads `sort` / `dir` / `page` /
   `search` **unprefixed**, and both the artist and genre pages render every tab at once, so a second
   server-paged thing would drive the songs table from the same params. Local state leaves a single
   owner of the query string.

The sizes it is built for: an artist has at most **26** albums, a genre reaches **66** — enough that
showing them all at once was too much, nowhere near enough to be worth a round trip. That 66 is
measured under the current rule (an album belongs to its **main** genre only) and is unchanged from
the looser rule that preceded it: albums here are near-uniformly single-genre, so the two readings
diverge only on a real compilation.

**What it still does not do is sort or search.** A view needing either wants the `DataTable` instead —
see `../../DataTable/README.md`.

## Markup and accessibility

- A real `<ul>`, so a screen reader announces "list, N items" before the rows.
- Each row is a single `<Link>` wrapping the whole row, so it is a real link — keyboard reachable,
  middle-clickable, open-in-new-tab. Those are the affordances `DataTable` has to rebuild by hand for
  its clickable rows, and they come free here because a row has exactly one destination.
- The artwork is passed `decorative`, because the album's name is the next thing **inside the same
  link** — naming it twice would have a screen reader read every row twice.
- The row is already the click target, so it draws no underline; it does draw a `:focus-visible` ring,
  since nothing else says which row is focused.

## Styling

Contextual tokens in `abstracts/{colors,sizes,timings}/components/_discography.scss`. The rule between
rows and the hover wash deliberately re-pick the **same values** as `c.$c-datatable`'s: on the artist
page this sits one tab away from that table, and a reader flipping between them should not see the
rules change colour.
