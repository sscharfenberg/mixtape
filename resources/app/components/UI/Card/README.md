# Card

The panel a **detail page** is built from: a title, a body, and nothing else moving. Several of them
wrap in a `CardGroup`; `Facts` is the ready-made filling for the common case — grouped key/value pairs.

| Piece               | Role                                                                                      |
| ------------------- | ----------------------------------------------------------------------------------------- |
| **`Card.vue`**      | The panel. An optional `<h2>` title and a slot; owns `wide` and its own width.            |
| **`CardGroup.vue`** | The wrapping row the cards sit in. Layout only.                                           |
| **`Facts.vue`**     | Buckets key/value pairs by `group` and renders one `Card` per group, each pair as a tile. |

You place all three yourself. `Facts` renders `CardGroup` + `Card` internally — reach for `Card`
directly only when the content _isn't_ key/value pairs.

## Card or Widget?

They look related and are not interchangeable. Pick by **whether the content moves**.

|                | **Card** (this folder)                            | [**Widget**](../Widget/README.md)                                   |
| -------------- | ------------------------------------------------- | ------------------------------------------------------------------- |
| Belongs to     | detail pages (a song, an album, an artist)        | browse pages (`/music`, and the areas beside it)                    |
| Content        | facts already on the page — static for its life   | a slice of a live query the user can re-roll                        |
| Loading states | none. There is nothing to wait for                | `WidgetSkeleton` (first load) + `WidgetLoader` (busy over content)  |
| Refresh        | none                                              | `refresh="prop"` → `router.reload({ only: [...] })` from the footer |
| Footer         | none                                              | `WidgetFooter` — "see all" links, the refresh button                |
| Header         | a real `<h2>`, bare type in the app's h2 ink      | `WidgetTitle`, a styled `<div>` on a gradient strip                 |
| Mode toggle    | none                                              | `WidgetModeToggle` (latest / popular / random)                      |
| Row layout     | wrapping **flex**; a line's cards share its width | auto-fit **grid** + `subgrid`; bands align across a row             |
| Full width     | `wide` → `flex-basis: 100%`, at every width       | `wide` → spans two columns, `landscape` and up only                 |

So: **a Widget is a window onto something that changes, with all the machinery that implies. A Card is
a frame around something that doesn't.** Using a Widget for static facts drags a loader, a skeleton, a
footer and a refresh path through a card that will never use them; using a Card for live data leaves you
hand-rolling the states the Widget already has.

The surfaces are deliberately the same (fill, border, radius, minimum width) — a detail page should read
as the same app as the listing it was reached from. That similarity is why the tokens mirror each other
and say so; it is not an invitation to swap one component for the other.

## Quick start

### Grouped key/value pairs — `Facts`

```vue
<script setup lang="ts">
import Facts, { type Fact } from "Components/UI/Card/Facts.vue";

const facts = computed<Fact[]>(() => [
    { key: "artist", group: t("…groups.tags"), icon: "artist", label: t("…artist"), value: song.artist },
    { key: "year", group: t("…groups.tags"), icon: "calendar", label: t("…year"), value: String(song.year) },
    { key: "path", group: t("…groups.file"), icon: "file", label: t("…path"), value: song.path, wide: true, mono: true }
]);
</script>

<template>
    <facts :facts="facts" wide-groups />
</template>
```

One flat list in, a row of titled cards out. Order is the only layout control you have, and that is the
point: pairs land in cards in the order their groups first appear, so there is no second list of group
titles to keep in sync.

### Anything else — `Card` + `CardGroup`

```vue
<card-group>
    <card :title="t('album.tracks')">
        <track-list :tracks="tracks" />
    </card>
    <card :title="t('album.notes')" wide>
        <p>…</p>
    </card>
</card-group>
```

## Props

### `Card`

| Prop    | Type      | Default | Notes                                                                                    |
| ------- | --------- | ------- | ---------------------------------------------------------------------------------------- |
| `title` | `string`  | —       | Rendered as an `<h2>`. Omit for an untitled panel. Assumes the page's own title is `h1`. |
| `wide`  | `boolean` | `false` | Takes a whole row — `flex-basis: 100%`, so it needs no breakpoint.                       |

### `Facts`

| Prop         | Type      | Default | Notes                                                                             |
| ------------ | --------- | ------- | --------------------------------------------------------------------------------- |
| `facts`      | `Fact[]`  | —       | The pairs, in display order.                                                      |
| `wideGroups` | `boolean` | `false` | Lets a card holding a `wide` pair take a row to itself. Opt-in — see the gotchas. |

### `Fact`

| Field   | Type             | Notes                                                                                        |
| ------- | ---------------- | -------------------------------------------------------------------------------------------- |
| `key`   | `string`         | Keys the `v-for`.                                                                            |
| `label` | `string`         | Already translated. Rendered in small caps.                                                  |
| `value` | `string \| null` | Display-ready text. **`null` or `""` drops the pair** — see below.                           |
| `group` | `string?`        | Card title, already translated. Pairs sharing one land in one card. Omitted → untitled card. |
| `icon`  | `string?`        | Sprite symbol id for the _kind_ of fact, shown beside the label.                             |
| `wide`  | `boolean?`       | Marks the pair as long (a path). With `wideGroups`, widens its **card**, not the tile.       |
| `mono`  | `boolean?`       | Renders the value monospaced — for values read character by character.                       |

## Dropping empty pairs is the feature

`Facts` filters out every pair whose value is `null` or `""`, and a group emptied by that filter
disappears with it. So a page passes **one fixed list** and lets the holes fall out, instead of a pile
of conditionals:

```ts
{ key: "composer", group: tags, label: t("…composer"), value: song.composer } // gone if untagged
```

That is the common case, not an edge case — tags in a ripped collection are sparse, and a page showing
"Genre: —" a dozen times reads as broken rather than as untagged. The caller formats; `Facts` decides
what is worth a tile.

## Layout: flex all the way down, and why

Two nested wrapping flex rows: cards in `CardGroup`, tiles inside each card. Neither is a grid, for the
same underlying reason — **filling a line beats filling a track.**

- **Cards.** An auto-fit grid collapses tracks nothing is placed in, which is what would keep three
  cards filling a four-track row. But a `wide` card spanning `1 / -1` occupies every track, so none
  collapse and the row ends in dead space the width of a card. Grid cannot say "span the tracks that are
  actually used", and a fixed `span 2` is a magic number that overflows by inventing implicit columns
  wherever fewer tracks exist. In flex there are no tracks to leave empty: a shared `flex-basis`
  (`s.$c-card "basis"`) decides how many fit a line, and `flex-grow` hands the leftover back to whichever
  cards are on it.
- **Tiles.** A grid would impose shared column widths, and these tiles have nothing to line up with each
  other — "CD 1/1" has no business being as wide as an album title. Each tile is as wide as its content,
  then grows to fill its line.

Two consequences to know:

- `Card` stretches its body (`flex: 1`) and the body stretches its content (`display: grid`), so cards
  sharing a line keep equal height. That is also why `Facts`' tile list sets `align-content: start` —
  without it a wrapped flex container's default `normal` behaves as `stretch` and spreads the lines of
  tiles down the whole card.
- A line holding few tiles — the last one, usually — stretches them wider than their content needs. That
  is the cost of never ending a line ragged.

Unlike `Widget`, a `Card` **outside** a `CardGroup` is fine: it sizes itself and there is no subgrid to
lose. You only lose the wrapping row.

## Styling

Each component's CSS lives in its own scoped `<style>`; there is no shared card stylesheet. Per the
repo's token convention (`styles/abstracts/README.md`) those blocks hold no colour, size, typography or
z-index literals:

| `@use` in the SFC             | Token        | Defined in                                          |
| ----------------------------- | ------------ | --------------------------------------------------- |
| `"Abstracts/colors" as c`     | `c.$c-card`  | `styles/abstracts/colors/components/_card.scss`     |
| `"Abstracts/sizes" as s`      | `s.$c-card`  | `styles/abstracts/sizes/components/_card.scss`      |
| `"Abstracts/typography" as t` | `t.$c-card`  | `styles/abstracts/typography/components/_card.scss` |
| (`Facts`, colours + sizes)    | `*.$c-facts` | `styles/abstracts/*/components/_facts.scss`         |

What the keys cover:

- **`c.$c-card`** — `background` / `surface` / `border`, and `title` (the app's h2 ink).
- **`s.$c-card`** — `border`, `radius`, `padding`, `basis` (the flex basis, i.e. cards per line), `gap`
  (the group's gutter, which the song page also reads for the gap between its blocks), `title-font-size`.
- **`t.$c-card`** — `title`, the headline family, so a card title reads like every other heading.
- **`c.$c-facts`** — `tile-background` (the wash behind a pair) and `label`.
- **`s.$c-facts`** — `tile-padding` / `tile-radius`, `pair-gap` (label → value, deliberately much tighter
  than…) and `gap` (between tiles), the label's size / tracking / icon gap, `value-font-size`,
  `mono-font-size`.

Nothing here animates, so there is no timing token and no motion guard to honour.

## Accessibility

- A `Card` title is a real **`<h2>`**, so the panels appear in the page's heading outline. That assumes
  the page's own title is its `h1` — true of the detail pages (`HeroSection` holds it). Nest deeper than
  one level and the outline will need revisiting.
- `Facts` renders its tiles as a `<ul>` with **`role="list"`**, because the marker is styled away and
  Safari/VoiceOver drop list semantics from an unmarked list.
- Each pair is a label followed by its value in DOM order, so the reading order matches the visual one
  without any `aria-describedby` wiring.
- Icons in a label are decoration next to the text that names the fact — they carry no accessible name of
  their own, deliberately.

## Gotchas

1. **`wide` on a `Fact` widens its card, not the tile.** Every value is full-width inside its own tile
   already; the flag means "this group carries something long", and it only does anything when the caller
   also passes `wideGroups`.
2. **`wideGroups` is opt-in for a reason.** A card promoted to a full row with two short pairs in it
   reads worse than a narrow one — it only pays off where a group really holds a path.
3. **Values are file tags, so assume the worst.** An unbroken 80-character token (a German compound, a
   glued-together credit) would otherwise set the tile's min-content and push the card ~600px past its
   line; `overflow-wrap: anywhere` on the value is what allows the tile to shrink. Keep it.
4. **A `group` is matched by its translated string.** Two pairs land together because their `group` text
   is identical, so build group titles from one `t()` call per group (as the song page does) rather than
   repeating the literal — a stray difference silently splits one card into two.
5. **Reordering the list reorders the cards.** Group order follows first appearance, which is convenient
   until someone moves a pair "just to group it with a similar one" and a whole card jumps.
