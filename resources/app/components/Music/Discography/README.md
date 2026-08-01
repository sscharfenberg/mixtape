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

## Why this is not a DataTable

Every _full_ album listing in the app is a `DataTable`. This deliberately isn't, for two reasons — and
the second is the one that constrains reuse:

1. **There is usually nothing to page.** The biggest discography in the collection is 26 albums and the
   average is 1.5. A toolbar, a search box and a pager around a couple of rows is furniture around
   nothing.
2. **It can share a page with a server-driven table.** `DataTableService` reads `sort` / `dir` /
   `page` / `search` **unprefixed**, so two server-driven tables on one page drive each other from the
   same params. On the artist page both tabs render at once (which tab is open is client-side state),
   so a second table there would re-sort and re-paginate the songs table. Staying plain leaves a single
   owner of the query string.

**The limit that follows:** this component shows everything it is handed, so the **caller** must keep
the set small. A view that genuinely needs paging, sorting or search over albums wants the `DataTable`
instead — see `../../DataTable/README.md`.

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
