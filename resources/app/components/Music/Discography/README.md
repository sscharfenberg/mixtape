# Discography

A compact list of albums — artwork, name, year, and what the record adds up to — each row linking to
that album's own page. It belongs to whatever is showing albums, not to one page: an artist's own
records today, a genre's next.

```vue
<discography :albums="discography" />
```

## Props

| Prop     | Type                 | Notes                                                              |
| -------- | -------------------- | ------------------------------------------------------------------ |
| `albums` | `DiscographyAlbum[]` | Already ordered by the server. Empty ⇒ renders the empty sentence. |

`DiscographyAlbum` is exported from the component. Every value is **raw** — seconds, not a clock —
because formatting is the client's job against the active locale (`Utils/formatting.ts`):

| Field      | Type             | Notes                                                 |
| ---------- | ---------------- | ----------------------------------------------------- |
| `id`       | `string`         |                                                       |
| `name`     | `string`         |                                                       |
| `year`     | `number \| null` | `null` for an untagged rip.                           |
| `songs`    | `number`         | How many tracks are filed under it.                   |
| `duration` | `number \| null` | Raw seconds; `null` when no file carried one.         |
| `coverUrl` | `string \| null` | `null` ⇒ `CoverImage` draws its placeholder.          |
| `href`     | `string`         | The album's own page — this row's single destination. |

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
showing them all at once was too much, nowhere near enough to be worth a round trip.

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
