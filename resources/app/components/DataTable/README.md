# DataTable Component

A server-driven, accessible data table for paginated, sortable, searchable data.
Built for Inertia.js — the **server owns the state**, the component renders it and
emits changes via `router.get()`. Ported from cantrip.me and adapted to mixtape's
conventions (styles-in-component, `v-model` Checkbox, the `Select` page-size
picker, the `sr-only` utility, `components.datatable.*` i18n).

## Quick Start

```vue
<script setup lang="ts">
import DataTable from "Components/DataTable/DataTable.vue";
import type { ColumnDef, TableResponse } from "Types/dataTable";

interface SongRow {
    id: string;
    name: string;
    artist: string | null;
    duration: string | null;
}

defineProps<{
    table: TableResponse<SongRow>;
}>();

const columns: ColumnDef<SongRow>[] = [
    { key: "name", label: "Title", sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "artist", label: "Artist", sortable: true, visibleInCard: true },
    { key: "duration", label: "Duration", sortable: true, align: "right" }
];
</script>

<template>
    <data-table :columns="columns" :response="table" base-url="/music/songs" :has-actions="false">
        <template #empty>
            <p>{{ t("music.empty") }}</p>
        </template>
    </data-table>
</template>
```

## Props

| Prop         | Type               | Default | Description                                                                                                                                         |
| ------------ | ------------------ | ------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `columns`    | `ColumnDef<T>[]`   | —       | Column definitions (see below).                                                                                                                     |
| `response`   | `TableResponse<T>` | —       | Server response containing rows, pagination, sort, and search state.                                                                                |
| `selectable` | `boolean`          | `false` | Enable row-selection checkboxes.                                                                                                                    |
| `hasActions` | `boolean`          | `true`  | Render the per-row actions column (header + three-dot button + popover). Pass `false` for read-only tables so non-owners don't see an empty column. |
| `baseUrl`    | `string`           | `""`    | Base path for Inertia navigation. Falls back to `window.location.pathname`.                                                                         |

## Slots

| Slot               | Scope                           | Description                                                                   |
| ------------------ | ------------------------------- | ----------------------------------------------------------------------------- |
| `#header-{key}`    | `{ column: ColumnDef<T> }`      | Custom header content for a column. Fallback: `column.label` as text.         |
| `#cell-{key}`      | `{ row: T }`                    | Custom renderer for a column. Fallback: raw `row[key]` as text.               |
| `#actions`         | `{ row: T, close: () => void }` | Row-action popover content (only when `hasActions`). Render as `<li>` items.  |
| `#toolbar-actions` | `{ selectedIds, clear }`        | Toolbar buttons (e.g. bulk actions). Only rendered when the slot is provided. |
| `#empty`           | —                               | Content shown when `rows` is empty and not loading.                           |

### Header Slots

Columns without a matching `#header-{key}` slot render the `label` as text.
Columns with a slot get full control:

```vue
<template #header-created_at>
    <icon name="recent" :size="1" />
</template>
```

When a header slot replaces the text label, the sort button automatically gets an
`aria-label` set to the column's `label` so the header stays accessible.

### Cell Slots

Columns without a matching `#cell-{key}` slot render the raw value as text.
Cell slots render in **both** the desktop table and the mobile card layout — write
them once:

```vue
<template #cell-duration="{ row }">
    <strong>{{ row.duration }}</strong>
</template>
```

### Row Actions

The `#actions` slot renders inside a **shared** popover (one instance for the whole
table, not per-row), and only when `hasActions` is `true`. Use the app's popover
classes; the slot's `close` dismisses the popover for in-place actions that don't
navigate:

```vue
<template #actions="{ row, close }">
    <li><button class="popover-list-item" @click="edit(row)">Edit</button></li>
    <li>
        <button
            class="popover-list-item popover-list-item--caution"
            @click="
                () => {
                    remove(row);
                    close();
                }
            "
        >
            Delete
        </button>
    </li>
</template>
```

### Toolbar Actions

Shown next to the search input. The slot exposes `selectedIds` and `clear` for
bulk actions (requires `selectable`):

```vue
<template #toolbar-actions="{ selectedIds, clear }">
    <button v-if="selectedIds.length > 0" @click="move(selectedIds).then(clear)">
        Move {{ selectedIds.length }}
    </button>
</template>
```

Call `clear` once an action has been carried out: nothing else will, since the
table only clears when the sort, search or filter changes. Clear on success
only — keeping the ticks after a failure is what makes pressing again the retry.

Mixtape's own bulk actions (play / enqueue / add to playlist) live in
`Components/Music/SelectionActions.vue`, which injects the table rather than
taking the slot props, so a page needs only `<selection-actions subject="…" />`.

## Column Definition

```ts
interface ColumnDef<T extends { id: string }> {
    key: keyof T & string; // field name in row data — type-safe
    label: string; // display label (pass an already-translated string)
    sortable?: boolean; // default false
    width?: string; // CSS value, default 'auto'
    align?: "left" | "center" | "right"; // default 'left'
    visibleInCard?: boolean; // show in mobile card layout, default false
    cardPrimary?: boolean; // main column at the top of the card, first wins
    cardMedia?: boolean; // render as the card's leading artwork instead of a field, first wins
    cellClass?: string; // extra CSS class(es) for the <td>
}
```

### Card media (artwork)

A column of images — an album cover, an avatar — works as a table column but not as a
card _field_: the card renders `visibleInCard` columns as a label/value `<dl>`, and
"Cover: `<img>`" reads worse than no thumbnail. Mark it `cardMedia` instead and the card
places it beside its heading:

```ts
{ key: "coverUrl", label: t("music.columns.cover"), width: "4rem", cardMedia: true }
```

Three things follow from it:

- it renders through the column's own **`#cell-{key}` slot**, so the `<img>` (and
  whatever stands in when there is none) is written once and both layouts show it;
- the slot content keeps the **page's** style scope, so the page sizes its own
  artwork — the card only positions it (`flex-shrink: 0`, no dimensions);
- it is independent of `visibleInCard`, and the column is dropped from the field list
  either way, so a card can't show the same value twice.

## Server Response Contract

The server must return this shape as an Inertia prop:

```ts
interface TableResponse<T> {
    rows: T[]; // current page of data
    total: number; // total row count across all pages
    page: number; // current page (1-based)
    pageSize: number | null; // null = no pagination
    sort: { key: string; direction: "asc" | "desc" } | null;
    tiebreakers?: string[]; // extra keys ordering the table after `sort`, always ascending
    search: string | null;
    searchIn?: string | null;    // "name" when the search was narrowed to the row's own name
    filters: Record<string, string | string[]> | null; // reserved; currently always null
}
```

Every row object **must** have an `id: string` field (UUID). Rows may optionally
carry an `href: string` — when present the row/card becomes clickable and navigates
to it (`router.visit`).

### Tiebreakers — more than one sorted column

The component sorts by **one** key, because that is all a header click can express. Some
tables have a natural order that needs two: an album's tracks read _disc, then track_.
`DataTableService` takes an optional `tiebreakers` list of sort **keys**, appends them
after the chosen sort (always ascending, mapped through `sortColumnMap` like the primary,
and skipping one that IS the primary), and echoes back the ones it applied:

```php
DataTableService::buildResponse(
    …,
    defaultSort: 'disc',
    tiebreakers: ['disc', 'track', 'name'],   // AlbumController — the album's running order
);
```

The header then marks CD _and_ Track with the ascending marker, so the compound order is
visible rather than implied. Three things worth knowing:

- a tiebreak column is marked but is **not** the sort: clicking it sorts by it from
  scratch (ascending), rather than toggling to descending as it would if it were already
  the sorted column;
- `aria-sort` stays on the primary column alone, since ARIA asks for one sorted column at
  a time; a tiebreak column carries the same fact as `sr-only` text instead;
- **marking is limited to the default sort, while applying is not.** The response reports
  `tiebreakers` only while the table sits on its `defaultSort` — there the extra keys are
  the order a reader is actually looking at. Sort by something else and they still order
  the query (paging stability isn't optional) but go unadvertised: durations are near
  unique, so disc/track almost never separates two rows, and four columns wearing an
  ascending arrow when the reader picked one makes the marking mean less.

**Pass tiebreakers even when you don't need a multi-column order.** SQL guarantees no
order at all between rows the sort column cannot separate, so with hundreds of albums
sharing a year, one row can appear on page 1 _and_ page 2 across two requests. A
unique-ish trailing key makes paging deterministic.

### Clickable rows

Put the detail URL on the row server-side and the rest follows — `SongsController`
is the reference:

```php
rowMapper: fn (Track $song): array => [
    'id' => $song->id,
    // …cells…
    'href' => route('music.songs.show', $song->id, absolute: false),
],
```

The row then gets `cursor: pointer`, a hover state, and a click handler.

**Hover = the app's neon halo, not a background tint.** A wash sitting between two
zebra stripes is almost invisible, so a hovered row lights up with the same
two-layer `box-shadow` an open popover / focused input / checked checkbox uses
(`c.$c-datatable` → `row-glow`, the same `c3` neon as `c.$c-popover` → `glow`), over
the slightly stronger `row-hover` fill. The `<tr>` and the mobile card both do it.

Two implementation notes, both load-bearing:

- **The hovered row is `position: relative`.** A `box-shadow` paints outside its own
  border box, so the next row's opaque cells would cover the bottom half of the halo.
  Positioning the row moves it into the positioned paint phase, above its
  unpositioned siblings — and **without a `z-index` on purpose**, so it still passes
  _under_ the sticky `<thead>` (`z.$c-main` = 1). Verified: with the header stuck, the
  glow stops at its edge instead of bleeding over it.
- **The fill lives on the `td`s, the glow on the `tr`** — so both need their own
  `transition` line, and the fill rule needs one class more than the zebra rules to
  win (see the comment in `DataTableBody.vue`).

**Not every click on a row is a row click**, so both handlers go through
`isRowNavigation()` in
[`rowNavigation.ts`](./rowNavigation.ts), which ignores three cases:

| Ignored                                                            | Why                                                                         |
| ------------------------------------------------------------------ | --------------------------------------------------------------------------- |
| the click landed on `a, button, input, select, textarea, label, …` | that control owns its click; navigating too would fight it or double-fire   |
| a text selection is open (drag-select ending in a click)           | copying a song title must not throw the listing away                        |
| ⌘/ctrl/shift/alt was held                                          | "open elsewhere" — which `router.visit()` can't do; leave it to a real link |

The checkbox and actions cells additionally carry `@click.stop`, so the guard is
belt-and-braces there — it exists for whatever a cell slot renders.

**Give the primary column a real `<Link>` too** (see `SongsPage`'s `#cell-name`).
A click handler on a `<tr>` is invisible to the keyboard and to a screen reader, and
offers no ⌘-click; the link fixes all three and costs one slot:

```vue
<template #cell-name="{ row }">
    <Link :href="row.href" class="songs__title">{{ row.name }}</Link>
</template>
```

Style it to inherit the cell's colour and underline on hover/focus, rather than
looking like a link on every row — the row's cursor and hover wash already say
"clickable".

## URL State

Sort, pagination, and search live in URL query parameters for bookmarkability:

```
?sort=name&dir=asc&page=2&pageSize=25&search=Lightning&searchIn=name
```

The component reads initial state from the `response` prop (the server is the
source of truth) and emits changes via `router.get()` with `preserveState` and
`preserveScroll`. Existing query params are preserved across navigations (changing
page keeps the current sort and pageSize).

### `searchIn=name` — the narrow search

A listing's search is usually **wider than one column**: Songs matches title, artist, album *and*
genre, which is right for somebody who arrived to browse. The cross-kind search dropdown
([`docs/search.md`](../../../../docs/search.md)) matches a row's **own name** only — so a group header
saying 70 handing off to the wide search opens a table of 2,000+, and the two surfaces then contradict
each other about one query.

`?searchIn=name` is that hand-off's mode. Pass a **second, narrower callback** and the service uses
it whenever the parameter is present:

```php
DataTableService::buildResponse(
    …,
    searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
        'tracks.name', 'artists.name', 'collections.name', 'genres.name',
    ]),
    nameSearchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
        'tracks.name',
    ]),
);
```

Four things follow, and each is load-bearing:

- **Omit `nameSearchCallback` where the listing has no narrower reading.** Artists and Genres already
  match a single column, so the mode there would be a claim with nothing behind it — the service
  ignores it and echoes `searchIn: null`.
- **The response echoes back which search RAN**, not which was asked for. The toolbar draws its
  "titles only" chip off that, so a mode that did not apply is never announced.
- **The chip is the way out.** Pressing it drops the parameter (back to page 1) and the listing's own
  wider search runs again.
- **Typing keeps the mode.** The reader arrived in "titles only", the URL says so, and `buildUrl`
  preserves the query params it does not own — so refining the query stays in the reading they chose
  until they press the chip.

## Server Implementation (Laravel)

`App\Services\DataTableService::buildResponse()` handles sort/search/pagination in
the controller. This is the real Music → Songs listing:

```php
use App\Enums\TrackType;
use App\Services\DataTableService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

public function __invoke(Request $request): Response
{
    $query = Track::query()
        ->where('tracks.type', TrackType::Music)
        ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
        ->leftJoin('collections', 'tracks.collection_id', '=', 'collections.id')
        ->leftJoin('genres', 'tracks.genre_id', '=', 'genres.id')
        ->select([
            'tracks.id', 'tracks.name', 'tracks.duration',
            'artists.name as artist_name',
            'collections.name as album_name',
            'genres.name as genre_name',
        ]);

    $table = DataTableService::buildResponse(
        query: $query,
        request: $request,
        sortable: ['name', 'artist', 'album', 'genre', 'duration'],  // whitelist of sort keys
        sortColumnMap: [                                              // sort key → real DB column
            'name' => 'tracks.name',
            'artist' => 'artists.name',
            'album' => 'collections.name',
            'genre' => 'genres.name',
            'duration' => 'tracks.duration',
        ],
        defaultSort: 'name',
        searchCallback: function (Builder $q, string $search): void {   // null to disable search
            $like = '%'.$search.'%';
            $q->where(function (Builder $q) use ($like): void {
                $q->whereRaw('tracks.name COLLATE "C" ILIKE ?', [$like])
                    ->orWhereRaw('artists.name COLLATE "C" ILIKE ?', [$like])
                    ->orWhereRaw('collections.name COLLATE "C" ILIKE ?', [$like])
                    ->orWhereRaw('genres.name COLLATE "C" ILIKE ?', [$like]);
            });
        },
        rowMapper: fn (Track $song): array => [                         // model → plain row array
            'id' => $song->id,
            'name' => $song->name,
            'artist' => $song->artist_name,
            'album' => $song->album_name,
            'genre' => $song->genre_name,
            'duration' => $song->duration,
        ],
    );

    return Inertia::render('Music/Songs/SongsPage', ['table' => $table]);
}
```

`DataTableService` validates the sort key against the whitelist (falling back to
`defaultSort`), the direction (`asc`/`desc`), and the page size (25/50/100), maps
the sort key to its real column, applies the search callback, paginates, and shapes
the `TableResponse`.

> **Postgres + ICU collation gotcha.** The taxonomy `name` columns
> (`artists`/`genres`/`collections`) carry the case-insensitive, **nondeterministic**
> ICU collation, and Postgres **forbids `LIKE`/`ILIKE`** on those ("nondeterministic
> collations are not supported for ILIKE"). Pin each match to the deterministic
> `"C"` collation (`<col> COLLATE "C" ILIKE ?`) so it's legal — it case-folds ASCII,
> which is enough for now. `tracks.name` is default-collated so plain ILIKE would
> work there. `ORDER BY` is unaffected (only LIKE is restricted). The proper
> accent-aware substring search via `pg_trgm` is deferred.

## Styling & contextual design tokens

Unlike most mixtape components (whose appearance lives in global `styles/components/`
classes), **all of the DataTable's styling lives inside each `.vue` file's scoped
`<style lang="scss">` block** — the sub-components are internal, so their styles
travel with them. To port the component you must also create its **contextual
tokens** (one partial per token group, `@forward`ed from that group's
`components/_index.scss`, consumed as `*.$c-datatable`):

| File                                 | Consumed as       | Holds                                                                                                                                                                                                                                                                                                                        |
| ------------------------------------ | ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `colors/components/_datatable.scss`  | `c.$c-datatable`  | A nested map: `border`, `overlay` (loading scrim), `spinner`, `row-hover` + `row-glow` (clickable row/card), `th{background,surface,background-stuck,surface-stuck}`, `td{background{odd,even},surface}`, `pagination{border,background,surface,page{…},page-hover{…},page-current{…}}`, `cards{background,border,surface}`. |
| `sizes/components/_datatable.scss`   | `s.$c-datatable`  | `breakpoint` (the `landscape` step — a breakpoint NAME, read by the three `m.cq()` calls), `border` (`base`), `radius` (`featured`), `padding.{th,td}`, `pagination.{…,page.{…},jump.{…}}`, `cards.{min-width,padding,gap,border,radius}`.                                                                                                                                           |
| `timings/components/_datatable.scss` | `ti.$c-datatable` | The hover duration (`fast`) — pagination page buttons and the clickable-row wash.                                                                                                                                                                                                                                            |

Two rules apply (see `styles/abstracts/README.md`):

- **Colours are picked from the global palette, never minted.** Greys use an
  opacity-only `color.adjust($alpha: …)` (so the striped rows/header let the page
  bleed through); the accent states (stuck header, current page) pick the shared
  `$brand` pairs. Black/white come from `$grey`, not raw hex.
- **Every `transition` is wrapped in `@media (prefers-reduced-motion: no-preference)`**
  and its duration comes from `ti.$c-datatable`, never a raw `ms`.

The **sticky header** reuses the existing `z.$c-main` z-index rung — no new
z-index token. The component also depends on the **`sr-only`** utility (in
`styles/layout/_base.scss`), the global **`popover-*`** classes (row-action popover

- three-dot button), and the **`Select`** component for the page-size picker (which
  carries its own `*.$c-select` token set — see `Components/Form/Select/Select.vue`).

## Sub-Components

All live in `Components/DataTable/`. The parent only imports `DataTable.vue`; the
rest are internal.

| Component               | Responsibility                                                                                                                     |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **DataTable.vue**       | Orchestrator: selection state (provide/inject), slot forwarding, Inertia navigation, loading overlay, aria-live, sticky detection. |
| **DataTableToolbar**    | Search input (600ms debounce), selection-count badge, `#toolbar-actions` slot.                                                     |
| **DataTableHead**       | Sticky `<thead>` with sort buttons, `aria-sort`, three-state select-all checkbox.                                                  |
| **DataTableBody**       | `<tbody>` with cell-slot rendering, row selection, clickable rows, three-dot action button.                                        |
| **DataTableCards**      | Mobile card layout via a container query. Renders `visibleInCard` columns.                                                         |
| **DataTablePagination** | First/prev/next/last, windowed page numbers with ellipsis, jump-to-page, "from–to / total", and a page-size `Select`.              |
| **DataTableActions**    | Shared row-action popover (one instance, repositioned per click) via CSS anchor positioning + the `popover-content` classes.       |

## Responsive Behavior

The component uses CSS **container queries** (not media queries), so it adapts to
its own width, not the viewport, via the `cq` mixin at the `landscape` breakpoint
(**768px** container width — lowered from `desktop`/1024px when the play queue
started taking 240px out of `<main>`; see the token's own note). Both the `<table>` and the card grid are always in the
DOM; `display` toggles which shows. With ≤100 rows per page the DOM duplication is
negligible.

In the card layout: only `visibleInCard` columns show, the `cardPrimary` column is
the card heading, and cell slots render identically to the table.

## Selection

When `selectable` is enabled:

- Each row gets a checkbox (mixtape's `Checkbox`, bound via `v-model`).
- The header checkbox is three-state: unchecked (none) / checked (all on page) /
  indeterminate (some).
- Selection **persists across page changes** (IDs in component state) and **clears
  on sort / search / filter changes** — page two is the same question further down,
  a re-sort is a different one. Every visit is `preserveState`, so the distinction
  is drawn by comparing the serialised sort/search/filters rather than by a `deep`
  watcher, which fires on any re-run and so cleared on paging too.
- Selected IDs are exposed via `#toolbar-actions` and via provide/inject
  (`DATA_TABLE_KEY`), alongside `clearSelection`.

## Accessibility

- Semantic `<table>`/`<thead>`/`<tbody>`/`<th>`/`<td>`.
- Sort headers are `<button>`s with `aria-sort`.
- Sort headers also carry a `v-tooltip:top` hint naming the direction the next click
  applies (`components.datatable.sort_hint_asc` / `_desc`); above the header, so it never
  covers the rows being sorted — see
  [`UI/Tooltip/README.md`](../UI/Tooltip/README.md).
- `aria-busy="true"` on the table during Inertia navigation.
- An `aria-live="polite"` (`sr-only`) region announces sort and page changes.
- The row-action popover returns focus to the three-dot button on close.
- All interactive controls have `aria-label`s from `components.datatable.*` i18n keys.
- **A clickable row is a mouse/touch convenience, never the only way in.** The `<tr>`
  gets no `tabindex`/`role="link"` — 25 rows of fake links would bloat the tab order
  and announce badly. The primary cell carries a real `<Link>` instead, which is the
  keyboard path, the screen-reader path and the ⌘-click path; the row click is the
  large-target shortcut on top of it. A table with clickable rows and no link in a
  cell is an accessibility bug, not a style choice.

## Sticky Header

Column headers stick via `position: sticky`. Set the custom property
`--datatable-sticky-offset` on a parent to account for a fixed site header:

```css
.my-layout {
    --datatable-sticky-offset: 64px;
}
```

Default offset is `0`.

## i18n

All UI text uses keys under `components.datatable.*` (both `de.json` and `en.json`).
Column labels are passed as already-translated strings via `ColumnDef.label`.
