# Design tokens (SCSS)

How design tokens are organised under `abstracts/`, and the one rule that keeps them maintainable.
**Every** token group follows the same three-stage pipeline:

> **global tokens** (the raw palette/scale) **→ contextual tokens** (per-component / per-page partials,
> plus any shared cross-cutting tokens) **→ consumed** by a component's own SCSS or a `.vue <style>` block.

The shape is identical across groups. It applies **today** to `colors/`, `sizes/`, and `z-indexes/`, and
will apply **unchanged** to any future group (`typography/`, `shadows/`, …). Learn it once here — the
examples use `colors/`, but every group reads the same. Where a group differs only in surface detail
(whether it has shared tokens, whether a partial's token is a map or a scalar) is spelled out in
[What varies between groups](#what-varies-between-groups).

## Three layers

```
abstracts/colors/
  _global-color-tokens.scss   1. GLOBAL   raw palette — $grey, $brand, $state, …
  _index.scss                 2a. ENTRYPOINT + shared contextual tokens ($glass, $shadows)
  components/
    _index.scss               2b. barrel — one @forward per component partial
    _button.scss              2c. CONTEXTUAL partial — $button, derived from globals
  pages/
    _index.scss               barrel — one @forward per page partial
    _home.scss                CONTEXTUAL partial — $home, derived from globals
```

1. **Global tokens** (`_global-<group>-tokens.scss`) — the raw, meaningless palette/scale
   (`$grey`, `$brand`, `$radius`, `$padding`, …). Values, no intent.
2. **Contextual partials** (`components/_*.scss`, `pages/_*.scss`, plus the shared `$glass`/`$shadows`
   in `_index.scss`) — semantic tokens that **pick and theme** the globals (`light-dark()`, `map.get()`,
   opacity-only `color.adjust()`, `math.round()` off a scale). One file per component / page. They pick
   globals — they don't mint new colours; see [the second hard rule](#the-second-hard-rule-contextual-tokens-pick-they-dont-compute).
3. **Consumers** — a component's own SCSS or a `.vue <style>` block. These read **only** contextual
   tokens, through the group entrypoint.

## The one hard rule

> **Outside its own token group, never `@use` or read a global token.**
> A consumer (component SCSS, page SCSS, `.vue`) must go through a **contextual partial**.

Globals are consumed in exactly two places, both _inside_ the token group:

- the group's `_index.scss` — for cross-cutting tokens not tied to one component (`$glass`, `$shadows`);
- a `components/` or `pages/` partial — for that one component's / page's tokens.

To give a component a colour or size you therefore **create (or edit) its contextual partial** — you
never reach for `$grey` from a `.vue` file. Why this matters:

- **One source of truth** — "which colour does the button use?" is answered by `_button.scss`, not by
  hunting `map.get($grey, …)` calls scattered across components.
- **Theming happens once** — the `light-dark()` pick / opacity tweak / `math.round()` lives in the
  partial, not duplicated at every call site (and colour _derivation_ lives one layer deeper, in the
  global palette — see [the second hard rule](#the-second-hard-rule-contextual-tokens-pick-they-dont-compute)).
- **Palette is free to change** — renaming or retuning a global entry only touches partials. The global
  file is deliberately **not** forwarded from the entrypoint, so `c.$grey` is _unreachable_ from a
  consumer by construction — the rule is enforced by the module graph, not just convention.

## The second hard rule: contextual tokens pick, they don't compute

> **A contextual token consumes global values. It never mints a new one.** The only maths a contextual
> partial may do to a global colour is a trivial **opacity** tweak. Any new colour — lightened, darkened,
> saturated, hue-shifted — is pre-computed **in the global palette**, given a name, and consumed from
> there.

Inside a `colors/` partial you may:

- **pick** a theme pair — `light-dark(map.get(c.$retro, "c2", "light"), map.get(c.$retro, "c2", "dark"))`;
- **pick** a single value — `map.get(c.$grey, "abbey")`;
- apply a **trivial opacity** change — `color.adjust(map.get(c.$grey, "white"), $alpha: -0.125)`.

You may **not** derive a fresh colour from a global — that is the global layer's job:

```scss
// ❌ don't: minting a colour in a contextual token
"glow": color.scale(map.get(c.$retro, "c2"), $lightness: -23%, $saturation: 12%),

// ✅ do: bake the variant into the global palette (a named entry), then pick it
"glow": light-dark(map.get(c.$retro, "c3", "light"), map.get(c.$retro, "c3", "dark")),
```

This is why `$retro` stores every hue as a baked `("light": …, "dark": …)` pair, and why the WCAG-tuned
control glow is its own named palette entry (`c3`) rather than each component re-scaling `c2` at its own
call site. **The palette is the single source of truth for what a colour _is_; a contextual token only
says _which_ colour a thing uses and _how opaque_.** Retuning a hue then happens in exactly one place, and
"which blue is this?" always has one answer.

The other groups follow the same spirit through their own raw layer: `sizes/` / `z-indexes/` partials
**pick from a scale** (`map.get(s.$scale, …)`) and at most round or step off `$base` — they never invent a
magnitude from thin air. The hard, no-exceptions edge is the **colours** rule above: never create a colour
outside the global palette.

## Adding a token

Say you need tokens for a new `card` component.

1. **Create the partial** `colors/components/_card.scss` — `@use` the globals (one `../`), derive values:

    ```scss
    @use "../global-color-tokens" as c;
    @use "sass:map";

    $card: (
        "background": light-dark(map.get(c.$grey, "white"), map.get(c.$grey, "bunker")),
        "border": light-dark(map.get(c.$grey, "infra"), map.get(c.$grey, "abbey"))
    ) !default;
    ```

2. **Register it** in `colors/components/_index.scss` — one line:

    ```scss
    @forward "card";
    ```

That's the whole ceremony. Same steps under `pages/`, and identically for every other group (`sizes/`,
`z-indexes/`, …) — only the value you derive from the globals changes.

## Consuming a token

```scss
@use "Abstracts/colors" as c; // `Abstracts` alias → resources/app/styles/abstracts (vite.config.ts)
@use "Abstracts/sizes" as s;
@use "sass:map";

.card {
    background: map.get(c.$c-card, "background");
    padding: map.get(s.$c-card, "padding");
}
```

The entrypoint re-exports each partial with a prefix, so the partial named `$card` is consumed as
`c.$c-card`. The prefix is **stamped centrally** by a single `@forward … as` line in `_index.scss` —
leaf partials stay prefix-free (`$card`, not `$c-card`).

| origin                                  | colors (`as c`) | sizes (`as s`) | z-indexes (`as z`) |
| --------------------------------------- | --------------- | -------------- | ------------------ |
| component partial (`$button` / `$main`) | `c.$c-button`   | `s.$c-button`  | `z.$c-main`        |
| page partial `$home`                    | `c.$p-home`     | `s.$p-home`    | `z.$p-home`        |
| shared (in `_index.scss`) `$glass`      | `c.$glass`      | `s.$glass`     | (none)             |

`c-` = component, `p-` = page — the suffix means the same thing in every group; only the namespace
prefix (`c.` / `s.` / `z.`) changes.

## What varies between groups

The pipeline (**global → contextual partial → consumer**) and the one hard rule are identical in every
group. Only two surface details differ, both by group:

- **Shared tokens in `_index.scss` are optional.** Cross-cutting tokens not owned by any single
  component/page live inline in the entrypoint — `colors/` and `sizes/` have `$glass` / `$shadows`
  (and `sizes/` also `$app`). `z-indexes/` has **none**: every rung belongs to a specific landmark, so
  the entrypoint is a pure barrel and each z-index lives in its own partial. A group may have shared
  tokens or not — the module graph is the same either way.
- **A partial's token is a map _or_ a scalar.** `colors/`/`sizes/` tokens are maps with several
  sub-values (`$button: ("background": …, "border": …)`), read with `map.get(c.$c-button, "background")`.
  A `z-indexes/` token is a single rung, so it's a plain scalar (`$main: map.get(z.$scale, "raised")`)
  consumed directly as `z.$c-main` — no `map.get` at the call site. Use a map when the component needs
  several related values, a scalar when one value says it all.

Everything else — the three layers, the `c-*` / `p-*` prefixes, "never read a global outside its
group", one `@forward` line per partial — carries over unchanged. A future `typography/` or `shadows/`
group is created by copying any existing group's folder shape; no new concepts.

## Why `@forward … as`, not `@use`

`@forward` re-exports a partial's members **transparently**, so a downstream `@use "Abstracts/colors"`
sees `c.$c-button` directly. `@use … as co` would instead trap the members behind a `co.` namespace,
forcing the entrypoint to re-declare every one — pure boilerplate. The `as c-*` / `as p-*` prefixes add
two things for free:

- **collision safety** — a component `$button` and a page `$button` would otherwise clash once both
  flatten onto the `c.` namespace; the prefixes guarantee they can't.
- **origin at the call site** — `c.$c-button` vs `c.$p-home` tells you where the token is defined.

Dart Sass has no directory globbing in the module system, so the **one `@forward "<name>";` line per
partial** is the irreducible minimum — and the only registration step you ever repeat.
