# TabbedNavigation

A tab strip over switchable panels. Declare the tabs as data and fill each panel from a **named slot
matching that tab's `id`** — the component owns panel visibility and the whole ARIA contract, so a
consumer never touches either.

```vue
<tabbed-navigation v-model:selected-tab="openTab" name="artist" :tabs="tabs" :label="t('music.artist.tabs.label')">
    <template #albums><discography :albums="discography" /></template>
    <template #songs><artist-songs :table="table" :base-url="…" /></template>
</tabbed-navigation>
```

```ts
const tabs = computed<TabDefinition[]>(() => [
    { id: "albums", label: t("music.columns.albums"), icon: "album", count: artist.albums },
    { id: "songs", label: t("music.columns.songs"), icon: "song", count: artist.songs }
]);
```

## Props

| Prop          | Type                  | Default     | Notes                                                                                                                              |
| ------------- | --------------------- | ----------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `name`        | `string`              | —           | Unique per strip; builds the tab / panel DOM ids. Two strips on one page need different names or their `aria-controls` cross-wire. |
| `tabs`        | `TabDefinition[]`     | —           | In display order. Each needs a matching named slot.                                                                                |
| `label`       | `string`              | —           | Accessible name for the `tablist`. Falls back to a generic string, but say what the tabs switch between.                           |
| `selectedTab` | `string \| undefined` | `undefined` | The open tab's id, as a **model** — see below.                                                                                     |

`TabDefinition` is exported from the component: `id`, `label` (already translated), optional `icon`
(a sprite symbol) and optional `count`.

## Selection

`selectedTab` is a model, optional in both directions. Leave it unbound and the strip keeps the
selection itself; bind it with `v-model:selected-tab` and the page owns it. An absent or unrecognised
value resolves to the **first** tab, which is what makes the strip self-healing — a typo, an unset
value, or a tab that disappeared when the data changed all fall back to a real tab with no watcher to
keep in step.

**Ids, not indices.** `songs` says what it selects where `1` says nothing, which matters the moment a
selection is stored, passed, or read back out of a URL.

## Keeping the tab in the URL

Bind the model to `Composables/useTabParam`, which mirrors it into `?tab=` so a reload or a shared link
reopens the same tab:

```ts
const { tab: openTab } = useTabParam();
```

**Switching tabs must not cost a request.** `useTabParam` rewrites the URL with `history.replaceState`,
never an Inertia visit, for two reasons — and the second is the load-bearing one:

1. There is nothing to fetch: a tabbed page's controller sends **every** panel's data on every request.
2. A visit would raise `DataTable`'s loading overlay over content already on screen — it listens to
   _any_ `router.on("start")`, not just its own navigations.

`replace` rather than `push`, because a tab is a view of one page, not a destination worth a history
entry. The trade is that Back leaves the page instead of stepping through tabs.

## Only one panel may hold a server-driven DataTable

`DataTableService` reads `sort` / `dir` / `page` / `search` **unprefixed**, and every panel renders at
once, so two server-driven tables in one strip would drive each other from the same params. Size the
second panel's presentation to its data instead — the artist page's albums tab is a plain
`Discography` list because the biggest discography is 26 rows.

## Accessibility

Real `role="tablist"` / `role="tab"` / `role="tabpanel"`, wired with `aria-controls` and
`aria-labelledby`, so the relationship between a tab and the panel it reveals is actually spoken
("tab, 2 of 2, selected").

| Key                              | Does                                         |
| -------------------------------- | -------------------------------------------- |
| <kbd>←</kbd> / <kbd>→</kbd>      | Move along the strip, wrapping at either end |
| <kbd>Home</kbd> / <kbd>End</kbd> | Jump to the first / last tab                 |
| <kbd>Tab</kbd>                   | Leaves the strip and enters the panel        |

- **Roving tabindex** — only the selected tab is tabbable, so a strip costs a keyboard user one Tab
  stop, not one per tab.
- **Selection follows focus**, the APG recommendation for panels already in the DOM (as all of ours
  are): arrowing shows each panel immediately, with no second key to confirm.
- Inactive panels stay **mounted** and are hidden with `v-show`, so a `DataTable` inside one keeps its
  scroll position across a tab switch. `display: none` also takes them out of the accessibility tree
  and the tab order, which is exactly what an inactive panel needs.
- The selected tab is distinguished by **fill, ink _and_ border weight**, never hue alone — `aria-selected`
  only reaches the reader who is being read to.

## Styling

Contextual tokens in `abstracts/{colors,sizes,timings}/components/_tabbed-navigation.scss`. The frame
takes the Card's metrics and surface; the pills reuse the site menu's link scheme, so the control that
switches a page's content looks like the control that switches the site's sections. The selected pill's
edge reads at `featured` weight via an **inset `box-shadow`** rather than a wider border — box-shadow
is never part of layout, so selecting a tab cannot resize it and shift the labels beside it.
