# CoverImage

Album / track artwork. The **only** place the app draws a cover — never hand-roll an `<img>` for one.

```vue
<!-- a table row: the title is in the next cell, so the artwork is decorative -->
<cover-image :src="row.coverUrl" :title="row.name" size="small" decorative />

<!-- a hero: here the picture IS the subject, so it keeps its alt text -->
<cover-image :src="album.coverUrl" :title="album.name" size="xlarge" />
```

## Props

| Prop         | Type                                       | Default   | Notes                                                                |
| ------------ | ------------------------------------------ | --------- | -------------------------------------------------------------------- |
| `src`        | `string \| null`                           | `null`    | The artwork URL. `null` ⇒ the placeholder glyph, not a broken image. |
| `title`      | `string`                                   | —         | What the picture is _of_ — an album or song name. Becomes the `alt`. |
| `size`       | `"tiny" \| "xsmall" \| "small" \| "large" \| "xlarge"` | `"small"` | See below — a size is a triple, not just a width. |
| `decorative` | `boolean`                                  | `false`   | Renders `alt=""` and hides the placeholder from assistive tech.      |

## Sizes — a triple, not a width

The corner radius and frame width move **with** the size, which is why they aren't separate props: the
12px rounding that reads as deliberate on a 240px sleeve eats a visible bite out of a 48px thumbnail,
and the hero's 5px frame around a 48px square would be a tenth of the picture.

| Size     | Width                     | Radius     | Frame      | Used for                          |
| -------- | ------------------------- | ---------- | ---------- | --------------------------------- |
| `tiny`   | 24px                      | `base`     | `base`     | (no consumer yet)                 |
| `xsmall` | 32px                      | `base`     | `base`     | a playlist's own rows             |
| `small`  | 48px                      | `base`     | `base`     | every table / list row            |
| `large`  | 96px                      | `base`     | `base`     | (no consumer yet)                 |
| `xlarge` | **100% of its container** | `featured` | `featured` | the detail-page hero, album cards |

`xlarge` has **no width of its own** — it fills whatever it is placed in, kept square by
`aspect-ratio`. **The container decides the size**, which is the whole contract: it fills
`HeroSection`'s frame at every breakpoint _without this component knowing that frame's sizes_
(220 / 200 / 240px), and it spans a `Discography` card whatever its grid column works out to.

It briefly carried a 240px ceiling, and that was wrong in exactly the case a fluid grid produces: any
column wider than the cap left the cover short of its own card. A caller that needs a bound puts it on
the **container**, where the rest of that layout's sizing already lives.

The four small sizes stay fixed on purpose: they sit in rows whose height must not move with the
viewport, or the column stops scanning as a column.

`xsmall` sits **between `tiny` and `small`**, which the t-shirt names do not admit: the scale reads
tiny (24) < xsmall (32) < small (48) < large (96). `tiny` was named first and kept its name rather
than being renumbered, since renaming a rung moves every consumer of it. It exists because a
playlist's rows wanted artwork small enough not to set the row's height — at 48px the row was half
as tall again — while 24px is a favicon rather than a record.

Metrics live in `abstracts/sizes/components/_cover-image.scss`, colours in the `colors` twin.

## Three states, all owned here

1. **The artwork** — an `<img>`, lazily loaded.
2. **No artwork** (`src` is `null`) — a muted `music` glyph.
3. **Failed artwork** — the same glyph. This is the one that earns the component: `coverUrl` rests on
   scan-time flags (`tracks.cover`, `collections.cover_path`), so a file re-tagged or deleted since
   the last `app:update` is still advertised and then 404s. Handled at the call site, that costs
   every consumer a `failedCovers` `Set` keyed by row id plus an `@error` handler, purely because the
   `<img>` sits inside a `v-for`. A component instance already has that identity, so it is one
   boolean here.

The `src` watcher that resets that boolean is **load-bearing**: Vue reuses a component instance when a
keyed list re-orders, so without it a row that once 404'd would keep its placeholder after being handed
a different album's artwork.

The placeholder is deliberately **just a glyph**, with no frame of its own — inside a `HeroSection` the
hero draws its own dashed square around whatever is not an `<img>`, and in a table row the bare icon is
the whole signal. A frame here would be drawn twice in one place and be wrong in the other.

## Accessibility

Pass `decorative` wherever the title is **already adjacent** — a table row, a card heading, a link that
names the album — so a screen reader doesn't read every row twice. It renders `alt=""` and drops the
placeholder's `role="img"` / label. A hero omits it: there the picture is the subject.

## Gotcha — never size this from the outside

A `> :slotted(img)` rule in a host such as `HeroSection` **silently outranks this component's own
sizing**: Vue puts the slot scope id on a slotted component's _root element_, and this component's root
is the `<img>`, so `:slotted` reaches straight through it and wins on specificity ((0,2,1) against
(0,2,0)). The two can appear to agree for a long time, as long as the numbers happen to match.

The general rule: a `:slotted` selector that sets **size** is a trap once slots receive components
rather than bare elements. Reaching in deliberately to _paint_ is fine — `HeroSection` still does it
for `FactPair`'s link halo, by naming that component's own class.
