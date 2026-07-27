# Tooltip

A floating text hint — hovered with a mouse, **tapped** on a touch screen. Two ways to attach one,
**one** tip node for the whole app.

| Piece                                          | Role                                                                       |
| ---------------------------------------------- | -------------------------------------------------------------------------- |
| **`v-tooltip`** (`app/directives/vTooltip.ts`) | The primary API. Hang a hint on any single element — no extra DOM.         |
| **`Tooltip.vue`**                              | Wrapper form for when the trigger is a _group_ of markup, not one element. |
| **`TooltipLayer.vue`**                         | The single tip element + **all** the CSS. Mounted once in `FullLayout`.    |
| **`useTooltipLayer`** (`app/composables/`)     | Module singleton holding the layer's state and the show/hide timing.       |

## Usage — the directive (prefer this)

```vue
<button v-tooltip="t('music.refresh')" :aria-label="t('music.refresh')">…</button>

<!-- placement via the directive argument -->
<button v-tooltip:right="hint">…</button>

<!-- full form: any subset of the options -->
<a v-tooltip="{ text: hint, placement: 'right', delay: 0 }">…</a>
```

| Option      | Type                                     | Default | Notes                                                       |
| ----------- | ---------------------------------------- | ------- | ----------------------------------------------------------- |
| `text`      | `string \| null \| undefined`            | —       | Already translated. Empty/falsy ⇒ the element is **inert**. |
| `placement` | `"top" \| "bottom" \| "left" \| "right"` | `"top"` | A CSS `position-area`. Object form wins over the argument.  |
| `delay`     | `number` (ms)                            | `300`   | Hover-intent only — focus and taps always show immediately. |

It's registered globally in `main.ts`, so nothing to import. Template types come from the
`GlobalDirectives` augmentation in `resources/types/directives.d.ts` — **add an entry there for any new
global directive**, or `npm run type-check` fails on the unresolved name.

## Triggers — what opens and closes the tip

A hint that only answers to hover doesn't exist on a phone, so the trigger set is **split by input
device**. The directive listens to _pointer_ events (not mouse events) precisely so it can tell them
apart: `event.pointerType`.

| Device        | Opens                                        | Closes                                                                              |
| ------------- | -------------------------------------------- | ----------------------------------------------------------------------------------- |
| **mouse**     | hover, after `delay`                         | leaving the trigger — **or a click**, which only ever dismisses (see below)          |
| **touch/pen** | **tap** (immediately, no delay)              | tapping the trigger again, tapping/scrolling anywhere else, the trigger unmounting   |
| **keyboard**  | focus (immediately) — real `:focus-visible`  | blur or <kbd>Esc</kbd>. An <kbd>Enter</kbd>/<kbd>Space</kbd> activation is ignored   |

Two asymmetries, both deliberate:

- **A mouse click dismisses, it never re-opens.** Hover already owns the reveal for a mouse, and most
  triggers here _are_ clickable (a `DataTable` sort header, a `WidgetModeToggle` radio, the widget
  refresh button). A symmetric toggle would flicker the tip on and off as you sort a column repeatedly;
  dismiss-only just gets the tip out of the way, and it stays away until you move the pointer.
  **That last clause is load-bearing** — see the re-dispatch trap below.
- **A tap-opened tip is _pinned_.** Touch has no hover to end, and the emulated `pointerleave` arrives
  at touch-end — milliseconds after the tap that opened the tip — so leaving can't be the dismissal
  signal. While a tip is pinned the composable keeps one capture-phase `pointerdown` listener on
  `document` and hides on the first press outside the trigger (which also covers scrolling, since a
  touch scroll starts with a `pointerdown`). The listener exists only while something is pinned.

Why `:focus-visible` for the focus reveal: clicking a button focuses it too, and revealing there would
flash the tip for the few ms until the click dismisses it again. `:focus-visible` is the browser's own
answer to "was this focus worth showing an affordance for", so the decision isn't guessed at here.

### The re-dispatch trap (why a "dismissed" latch exists)

**A DOM change under a stationary cursor makes Chrome re-fire `pointerleave` + `pointerenter`.** Traced
on a `DataTable` sort header (Chrome 150) — the cursor never moved:

```
609ms  pointerdown          642ms  click → tip dismissed
644ms  thead mutated  ← the Inertia sort visit re-renders the header
649ms  pointerleave   ← Chrome recomputing which element is under the cursor…
666ms  pointerenter   ← …and it's this button again
966ms  tip re-opens    ← hover-intent fired, 300ms after the click that killed it
```

So "hides on click and stays hidden" can't be built on leave/enter alone. The directive latches the
dismissal against a **document-wide count of real `pointermove` events** and ignores any `pointerenter`
that arrives without one. Coordinates were tried first and don't work: a re-dispatched enter carries the
unchanged cursor position, which is indistinguishable from "left and came back to the same pixel".
Anything that mutates a hovered trigger's subtree hits this — remember it before adding another
"stays hidden until X" rule.

## Usage — the wrapper component

Use it when the trigger isn't a single element you can put the directive on: a stat tile's
value + label, a radio + its label, or a control that **disables itself** (see gotchas).

```vue
<script setup lang="ts">
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";
</script>

<template>
    <tooltip :text="tile.hint" placement="top" class="widget-stats__cell">
        <span class="widget-stats__value">{{ tile.value }}</span>
        <span class="widget-stats__label">{{ tile.label }}</span>
    </tooltip>
</template>
```

It renders one `inline-flex` `<span>` and applies `v-tooltip` to it — same props as the directive
options, same shared layer. A `class` lands on that span, so it can be a layout box.

## How it works

- The **trigger is the CSS anchor**: the directive writes a unique inline `anchor-name` (`--tt-<n>`)
  on the element. When a trigger takes ownership, the layer's `position-anchor` / `position-area` are
  pointed at it through the `--tooltip-anchor` / `--tooltip-area` custom properties.
- The tip is a native **`popover`**, so it renders in the **top layer** — no clipping by an
  `overflow: hidden` or stacking-context ancestor (a widget frame, a sticky `<thead>`), and no
  z-index anywhere. Positioning is pure CSS anchor positioning: **no JS positioning library**, and the
  tip follows its anchor on scroll for free.
- All state lives in the composable — which trigger owns the tip, which one has a reveal queued behind
  its delay, and whether the tip is **pinned** (tapped open). The directive only translates DOM events
  into `showFor` / `hideFor` / `toggleFor` / `updateFor`, and the components stay declarative.

## Styling

All of it is in **`TooltipLayer.vue`'s scoped `<style>`** — that is the single place to restyle a
tooltip, and the only file with visuals in it. (`Tooltip.vue` carries one rule, `display: inline-flex`
on its wrapper span; the directive and composable contain no CSS at all.)

Scoped **and** teleported is fine: Vue stamps the scope attribute on this component's own markup, so
it travels with the node into `<body>`.

Per the repo's token convention (`styles/abstracts/README.md`) the SFC contains **no literals for
colour, size or timing** — it reads three contextual token maps:

| `@use` in the SFC           | Token           | Defined in                                          | Keys                                    |
| --------------------------- | --------------- | --------------------------------------------------- | --------------------------------------- |
| `"Abstracts/colors" as c`   | `c.$c-tooltip`  | `styles/abstracts/colors/components/_tooltip.scss`  | `background`, `surface`                 |
| `"Abstracts/sizes" as s`    | `s.$c-tooltip`  | `styles/abstracts/sizes/components/_tooltip.scss`   | `padding`, `radius`, `max-width`, `gap` |
| `"Abstracts/timings" as ti` | `ti.$c-tooltip` | `styles/abstracts/timings/components/_tooltip.scss` | (a single duration)                     |

`gap` is applied as the tip's **margin** — with anchor positioning that is what holds the tip off its
trigger. Two values are deliberately literal because they're typographic proportions, not themeable
decisions: `font-size: 0.85rem` and `line-height: 1.3`.

### Copying this folder into another project

1. Copy `components/UI/Tooltip/`, `composables/useTooltipLayer.ts` and `directives/vTooltip.ts`.
2. Register the directive (`app.directive("tooltip", vTooltip)`) and mount `<tooltip-layer />` **once**,
   last in your root layout — see gotcha 3 about tree order.
3. Provide the path aliases the imports use, or rewrite them: `Composables/*`, `Components/*` and the
   SCSS `Abstracts` alias (in both `vite.config.ts` and `tsconfig.json` here).
4. Supply the three token entries above — either port the three `_tooltip.scss` partials and
   `@forward` them from their group's `_index.scss`, or replace the `map.get(…)` calls in the SFC with
   your own variables/literals. Nothing else in the folder needs to change.
5. Copy the `GlobalDirectives` augmentation (`resources/types/directives.d.ts`) if the project
   type-checks templates with `vue-tsc`.

## Accessibility

- A tooltip is a **visual** affordance: the trigger must still carry its own accessible name
  (`aria-label` on an icon button, real text in a header button).
- While the tip is open the trigger gets `aria-describedby="app-tooltip"`, removed again on hide — so
  the hint isn't announced for something that isn't on screen.
- Shows on `focusin` (immediately, no delay) when the focused element matches `:focus-visible`, hides on
  `focusout` and on **Escape**. Keyboard activation (<kbd>Enter</kbd>/<kbd>Space</kbd>) leaves the tip
  alone — only a real pointer click dismisses it.
- Touch users reach every hint by tapping the trigger, so nothing is hover-only.
- The fade sits under `prefers-reduced-motion: no-preference`; with the preference set the tip just
  appears. Durations come from `ti.$c-tooltip`.

## Gotchas

1. **Disabled controls emit no pointer events.** `v-tooltip` on a `<button :disabled>` goes quiet while
   disabled — and if the tip is open when the button _becomes_ disabled, no `pointerleave` ever arrives.
   Put the hint on an enabled element around it: that's why `WidgetFooter`'s refresh button uses
   `Tooltip.vue`.
2. **One tip at a time, by design.** A second trigger takes the tip over from the first (and a fresh
   reveal always starts unpinned, so it can't inherit the previous trigger's pin). Intentional — two
   hints on screen is never right — but it means you can't show two at once.
3. **On touch, a tap both activates the control and shows its hint.** Unavoidable for a hint hung on a
   button: a tap is the only input a phone has. Tapping a `DataTable` header sorts _and_ pins the hint;
   the next tap anywhere clears it. Don't hang a tooltip on something where that double effect would be
   destructive — put the hint on a neighbouring element instead.
4. **`click` is a directive-owned listener on the trigger.** It doesn't `preventDefault` or stop
   propagation, so the element's own `@click` still runs — but a handler that calls `stopPropagation()`
   in a _capture_ listener above the trigger would swallow the tap toggle.
5. **Tree order matters.** Anchor positioning only resolves an anchor that _precedes_ the positioned
   element. `TooltipLayer` teleports to `<body>` and is mounted last in `FullLayout`, which satisfies
   this for anything inside the layout — a tooltip on something teleported _after_ the layer would not
   position.
6. **The tip is `pointer-events: none`.** One shared tip roams the whole page, so it must never swallow
   the hover or click of whatever it covers (a `bottom` tip on a sticky header sits over the first row).
   That's also what lets a tap "through" a pinned tip land on the element underneath — and dismiss it.
   Don't put interactive content in a tooltip — use `PopOver` for that.
7. **Needs CSS anchor positioning** (Chromium 125+). Where it's unsupported the tip still shows, but
   unanchored (at its static position in `<body>`) — the _triggers_ work everywhere, it's only the
   placement that degrades. Check current mobile-Safari / Firefox support before treating either as a
   target; on iOS every browser is Safari's engine, so a phone is exactly as capable as its Safari.
8. **`text` must already be translated** — the directive and the component take a plain string, neither
   calls `t()`.
9. **Don't set `anchor-name` on the trigger yourself** — the directive owns that inline property.
10. **Don't `v-tooltip` a `display: contents` element**: no box, nothing to anchor to. Same caution for
    a component root — the directive lands on that component's root element, which may not be the box
    you meant.
