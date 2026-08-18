# Widget

The content card the browse pages are built from: a title strip, a body, an optional footer — laid out
in a `WidgetGroup` grid where every card's bands line up across a row.

| Piece                      | Role                                                                                           |
| -------------------------- | ---------------------------------------------------------------------------------------------- |
| **`Widget.vue`**           | The card. Assembles the parts from your slots; owns `loading` / `wide` / `refresh`.            |
| **`WidgetGroup.vue`**      | The responsive grid the cards go in. The subgrid alignment only works inside it.               |
| **`WidgetTitle.vue`**      | The accented header strip (a flex row, so a toggle can sit at its trailing edge).              |
| **`WidgetBody.vue`**       | The body band; `centered` centres short content vertically.                                    |
| **`WidgetFooter.vue`**     | The action strip: one control hugs the trailing edge, two or more spread out.                  |
| **`WidgetLoader.vue`**     | Busy-over-existing-content: a scrim + spinner over the whole card, revealed **after a delay**. |
| **`WidgetSkeleton.vue`**   | First-load placeholder: shimmer bars that reserve height.                                      |
| **`WidgetModeToggle.vue`** | The segmented latest / popular / random pill for a music widget's title strip.                 |
| **`Consumers/`**           | The concrete cards (`AlbumsWidget`, …, `StatsWidget`) + `WidgetList`, their shared list body.  |

`Widget` renders `WidgetTitle` / `WidgetBody` / `WidgetFooter` / `WidgetLoader` itself — you don't
import them. `WidgetGroup`, `WidgetSkeleton` and `WidgetModeToggle` you place yourself.

## Quick start

```vue
<script setup lang="ts">
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
</script>

<template>
    <widget-group>
        <widget refresh="artists">
            <template #title>
                <icon name="artist" />
                {{ t("music.widgets.artists") }}
            </template>

            <widget-list :items="items" />

            <template #footer>
                <Link href="/music/artists" class="btn btn-primary">{{ t("music.seeAll") }}</Link>
            </template>
        </widget>
    </widget-group>
</template>
```

### Props (`Widget`)

| Prop       | Type      | Default | Notes                                                                                                 |
| ---------- | --------- | ------- | ----------------------------------------------------------------------------------------------------- |
| `loading`  | `boolean` | `false` | Shows `WidgetLoader` over the card. Delayed reveal — a fast op never flashes it.                      |
| `wide`     | `boolean` | `false` | Spans two group columns, **from the `landscape` breakpoint up** (below that the group is one column). |
| `refresh`  | `string`  | —       | An Inertia prop key. Renders the footer's refresh button, which partial-reloads just that prop.       |
| `centered` | `boolean` | `false` | Centres the body vertically in its band (stays full-width) — for a card shorter than its row.         |

### Slots (`Widget`)

| Slot      | Renders                                                                      |
| --------- | ---------------------------------------------------------------------------- |
| `title`   | `WidgetTitle`, only if the slot is passed. A flex row: icon + text + toggle. |
| _default_ | The body. Replaced by `WidgetSkeleton` while a refresh reload is in flight.  |
| `footer`  | `WidgetFooter`, rendered if the slot is passed **or** `refresh` is set.      |

## The two loading states

They are not interchangeable — pick by whether there is already content on screen:

- **`WidgetSkeleton`** — _first_ load, no content yet. Put it in the default slot yourself
  (`<widget-skeleton v-if="!items" :rows="4" />`). Its bars reserve height so the subgrid bands don't
  jump when data lands.
- **`loading` → `WidgetLoader`** — busy _over_ existing content. A scrim + spinner across the card.
  Held invisible for `loader-delay` and only then revealed, so a fast operation never flashes it.
- The **refresh** button drives a third path automatically: while its partial reload is in flight the
  footer emits `refreshing`, and `Widget` swaps the body for a 4-row skeleton — nothing to wire.

## Refresh

`refresh="artists"` means "this card's data is the `artists` Inertia prop". The footer button then calls
`router.reload({ only: ["artists"] })`, so the controller re-runs just that query — a `random` mode
reshuffles, latest/popular re-read — and Inertia swaps the prop in place. `reload` forces
`preserveScroll` + `preserveState`, so the page doesn't jump and the card keeps its selected mode.

## Mode toggle (music widgets)

```vue
<widget-mode-toggle v-model="mode" name="artists-mode" :modes="modes" />
```

| Prop       | Type                        | Notes                                                                   |
| ---------- | --------------------------- | ----------------------------------------------------------------------- |
| `v-model`  | `WidgetMode` (**required**) | `"latest" \| "popular" \| "random"`.                                    |
| `name`     | `string`                    | Radio-group name — **must be unique per page**, or two toggles collide. |
| `modes`    | `WidgetMode[]`              | Segments in display order; pass only what the widget supports.          |

Each segment's tooltip comes from `music.mode.tip.<mode>` — one phrase per mode, the same on every
card, because `popular` ranks by the reader's own play count wherever it appears.

Pair it with **`useWidgetMode(widget, fallback, allowed)`** (`app/composables/`), which persists the
choice in `localStorage` (`mixtape:widget-mode:<widget>`), validates a stored value against `allowed`,
and reads synchronously so the stored mode is on the first paint — no default-then-swap flash.

The segments are real radios (visually hidden, still focusable), so native arrow keys move selection.
The icons carry no visible text, so each segment is wrapped in
[`Tooltip`](../Tooltip/README.md) — keep the `input` + `label` adjacent inside that wrapper or the
`input:checked + &` selectors stop matching.

## Layout: why `WidgetGroup` is not optional

`WidgetGroup` is `grid-template-columns: repeat(auto-fit, minmax(min(<group-min>, 100%), 1fr))` with
`grid-auto-flow: dense` (so a `wide` card doesn't leave holes). Each `Widget` is
`grid-row: span 3` + `grid-template-rows: subgrid`, i.e. it claims the group's **title / body / footer**
row bands and subgrids into them. That's what makes every card in a row share band heights so all the
footers line up.

Consequences worth knowing:

- A `Widget` **outside** a `WidgetGroup` has nothing to subgrid into: `subgrid` on a non-grid-item
  degrades to `none`, so the card still stacks correctly — it just sizes its bands to its own content and
  aligns with nothing. `wide` is inert there too. Wrap even a single card.
- The cards in a group are assumed to share that three-band structure. A card that omits a section just
  leaves its band empty (which is why an omitted `#title` still aligns).
- `min(<group-min>, 100%)` is what keeps a lone card from overflowing a narrow viewport.

## Styling

Each part's CSS lives in **its own SFC's scoped `<style>`** — there is no global widget stylesheet, so a
change is always in the component you're looking at. Per the repo's token convention
(`styles/abstracts/README.md`) those blocks hold **no colour, size, timing or z-index literals**; they
read contextual token maps:

| `@use` in the SFC              | Token                     | Defined in                                               |
| ------------------------------ | ------------------------- | -------------------------------------------------------- |
| `"Abstracts/colors" as c`      | `c.$c-widget`             | `styles/abstracts/colors/components/_widget.scss`        |
| `"Abstracts/sizes" as s`       | `s.$c-widget`             | `styles/abstracts/sizes/components/_widget.scss`         |
| `"Abstracts/timings" as ti`    | `ti.$c-widget`            | `styles/abstracts/timings/components/_widget.scss`       |
| `"Abstracts/z-indexes" as z`   | `z.$c-widget`             | `styles/abstracts/z-indexes/components/_widget.scss`     |
| (mode toggle, all four groups) | `*.$c-widget-mode-toggle` | `styles/abstracts/*/components/_widget-mode-toggle.scss` |

What the keys cover:

- **`c.$c-widget`** — `background` / `surface` / `border` (the card), `title-background` (a brand
  gradient) + `title-surface`, `footer-border` / `footer-surface`, `loader-overlay` /
  `loader-spinner`, `skeleton-base` / `skeleton-sheen`, and `cell-background` for tiles inside a body.
- **`s.$c-widget`** — `border`, `radius`, `padding`, `group-min` / `group-gap` (the grid),
  `skeleton-bar` / `skeleton-gap` / `skeleton-radius`, `title-font-size`, `cell-radius` / `cell-padding`.
- **`ti.$c-widget`** — `loader-delay` (the no-flash hold), `loader-fade`, `skeleton` (shimmer sweep).
- **`z.$c-widget`** — the loader's rung. It only has to clear the card's own content: `Widget` sets
  `isolation: isolate`, so the overlay's z-index can't escape the card.

Motion follows the repo rule — every transition/animation sits inside
`@media (prefers-reduced-motion: no-preference)`, durations come from the timing tokens. Both the
loader and the skeleton stay _functional_ without motion: the loader still appears after its delay via a
`0s` step animation, and the skeleton bars are simply static (unlike a frozen spinner, a static bar
doesn't read as broken).

Other dependencies: the `m.mq("landscape")` breakpoint mixin (`Abstracts/mixins`), `LoadingSpinner`,
`Icon`, `Tooltip`, and the global `.btn` classes used by footer actions.

### Copying this folder into another project

1. Copy `components/UI/Widget/` (drop `Consumers/`, which is MixTape-specific — or keep `WidgetList` as
   a starting point) and `composables/useWidgetMode.ts` if you want the mode toggle.
2. Provide the path aliases the imports use, or rewrite them: `Components/*`, `Composables/*`,
   `Types/*`, `Utils/*`, and the SCSS `Abstracts` alias.
3. Supply the token entries above — port the `_widget.scss` / `_widget-mode-toggle.scss` partials from
   each of `colors/`, `sizes/`, `timings/`, `z-indexes/` and `@forward` them from that group's
   `_index.scss`, or replace the `map.get(…)` calls with your own values.
4. Provide the `mq` mixin (or replace `@include m.mq("landscape")` with a plain media query), a
   `LoadingSpinner`, an `Icon`, and — for the mode toggle — a `Tooltip`.
5. The refresh button assumes **Inertia** (`router.reload({ only: [...] })`). Without Inertia, drop the
   `refresh` prop or re-point it at your own fetch.
6. i18n keys used: `common.loading`, `music.refresh`, `music.reloadServerData`, `music.mode.*`,
   `music.empty`.

## Accessibility

- `WidgetLoader` and `WidgetSkeleton` are `role="status"` with an `aria-label` of `common.loading`, so a
  screen reader hears that the card is busy.
- The refresh button is icon-only and carries its own `aria-label` (`music.refresh`); its tooltip is
  decoration on top, not the accessible name.
- The mode toggle is a `role="radiogroup"` of real radios — arrow keys work natively, each segment has
  an `aria-label`, and `:focus-visible` draws an inset ring (inset because the pill's `overflow: hidden`
  would clip an outset one).
- Nothing here is a landmark or heading: `WidgetTitle` is a styled `<div>`. If a page needs the card
  titles in its heading outline, put a real heading element in the `#title` slot.

## Gotchas

1. **A `Widget` wants a `WidgetGroup` parent.** Subgrid is what aligns the bands across a row; without
   the group `grid-row: span 3` has no rows to span and the alignment silently does nothing — the card
   looks right on its own, so this is easy to miss in a row of two.
2. **`wide` does nothing below the `landscape` breakpoint** — deliberately: the group is a single column
   there, so spanning two would overflow.
3. **`loading` vs. a skeleton** — see _The two loading states_. Using the overlay for a first load shows
   a spinner over an empty card; using a skeleton for a refresh throws away content the user can still read.
4. **`WidgetModeToggle`'s `name` must be unique per page.** Duplicate names put every toggle in one radio
   group, and selecting a mode in one card clears another's.
5. **Don't move the `label` away from its `input`** inside the toggle's tooltip wrapper — the checked and
   focus styles are adjacent-sibling selectors.
6. **The footer's "lone control hugs the right, two spread apart" behaviour is `:has(> :nth-child(2))`**
   — it counts _direct children_. Wrapping a control (e.g. in a `Tooltip`) keeps the count right; adding
   an invisible extra element silently switches the layout to `space-between`.
7. **`refresh` takes the Inertia prop key, not a URL.** A wrong key reloads nothing and the skeleton
   flashes for one round-trip.
8. **The card sets `overflow: hidden`** (to clip the title strip to the radius), so nothing inside can
   spill out. That's why the mode toggle's hint uses `Tooltip` — a native-popover tip renders in the top
   layer and escapes the clip.
