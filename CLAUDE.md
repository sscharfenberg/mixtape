# CLAUDE.md — MixTape v2

Steering context for this repo. Kept lean on purpose — detailed design lives in the `docs/` folder
(linked at the bottom).

## What this is

**MixTape** is a self-hosted web app that organizes a personal mp3 / audiobook collection and plays it
in the browser. It runs on a **home server** and is **intentionally reachable from the internet** — a
real domain CNAMEs to a DynDNS host, and the router forwards **only 80/443** — so the owner can share
links to music with family and friends. Access is gated by auth.

*(The host is referred to generically here. Its name, addresses, and everything else concrete live in
the untracked `docs/host.local/` — see **Docs** below.)*

**mixtape.v2** is a **ground-up rewrite** of the existing app. The legacy code is the sibling folder
**`../MixTape`** (newer than the public GitHub repo) — read it for behaviour, the data model, artisan
commands, and `config/collection.php`, then re-implement on the new stack. It is **reference only**;
this repo starts clean.

## Two phases (Phase 1 first)

1. ✅ **Rebuild the server** — **DONE & verified 2026-06-28.** Fresh Debian on plain LVM (large `/var`),
   hardened, services up (PostgreSQL 17 / php-fpm 8.4 / nginx / Samba), collection restored, PoC proven.
   Spec + design in [`docs/self-hosting/01-requirements.md`](docs/self-hosting/01-requirements.md);
   the concrete box in `docs/host.local/infrastructure.md` (**untracked**, see *Docs*).
2. ⬜ **Rewrite the app** — **IN PROGRESS.** New design; Inertia v3 instead of the REST API;
   composables-first Vue + TS. See [`docs/app-rewrite.md`](docs/app-rewrite.md); public go-live in
   [`docs/self-hosting/04-going-public.md`](docs/self-hosting/04-going-public.md) (generic) and
   `docs/host.local/go-live.md` (**untracked**, real values + status).

Phase 1 was done first — no point deploying new app code onto the old host.

## Load-bearing decisions

**Server**

- Latest stable Debian, minimal install; **LVM** with a **large `/var`** and a small `/home`.
- **Nginx + php-fpm**, HTTPS via **Let's Encrypt (certbot)**; media library at **`/var/media`**.
- **Internet-facing by design** on **443/HTTPS** (clean links), but **only the web ports (80/443) are
  forwarded** — SSH, Samba, and the database stay **LAN-only and must never be exposed.**
- **Back up the media collection before any wipe** — it's the only thing whose loss is permanent (the
  DB is rebuilt from it via artisan in ~40 s). Backups go to a **separate physical drive**, so a
  system-disk reinstall can't touch them. Wipe/repartition only after a **verified** backup — "the
  archive exists" is not "the archive restores". (Concrete disks/labels: `docs/host.local/`.)

**App**

- **Inertia.js v3** — controllers pass props straight to Vue pages. **No REST API, no Vue Router, no
  Axios layer.**
- **Vue 3 + TypeScript, composables-first.** **No Pinia for now** (may not need a global store at all).
- Keep Vite, SCSS, and the `vidstack` player; port the `app:update` library-scan artisan chain.
- **Headline v2 features**: user-specific playlists, listen history / most-played, improved
  search/filtering, and **background playback** (auto-advance when the tab isn't focused).

**Auth & sharing**

- Reuse **Fortify** (from the owner's other project). **Open registration disabled** → onboard via
  **one-time, expiring invite tokens**.
- **Signed / temporary URLs** let friends play a shared song/album **without an account** (the headline
  use case).
- **2FA is optional — each user's choice, never forced.** **Drop** the legacy web-server Basic Auth.

## Conventions for Claude

- **Phase order matters**: server before app.
- **Destructive ops on the server are high-stakes** — anything that wipes/repartitions comes _after_
  verified backups. Confirm before irreversible steps.
- **Prefer the new idioms** (Inertia + composables) over the legacy API / Vue-Router / store-everything
  patterns, even when porting.

**Tests — write them.** Every change that adds or alters behaviour gets sensible test coverage, at
the cheapest layer that can actually answer the question. Three layers, none interchangeable:

- **Server → PHPUnit** (`php artisan test`) in `tests/Unit/` + `tests/Feature/`. Anything the server
  decides: authorization, validation, query shaping, and the Inertia props a page receives
  (`assertInertia`).
- **Frontend unit → Vitest** (`npm run test:unit`), colocated **beside the source** as `*.test.ts`
  (`utils/formatting.test.ts` next to `utils/formatting.ts`) — the same rule as page-local
  components. Pure logic, composables, and a component's own behaviour.
- **End-to-end → Playwright** (`npm run test:e2e`) in `tests/e2e/` (`guest/` = no session, `app/` =
  signed in). Anything that needs a real browser: navigation, history, the URL as state, CSP, media
  playback, keyboard journeys.

Two rules worth stating outright, because both cost real effort when got wrong: **don't
component-test Inertia pages in Vitest merely to re-check their props** — `assertInertia` already
covers that contract, so test a page for what PHP cannot see (locale formatting, an untagged field
disappearing) and cover the journey with a Playwright spec. And **don't fake what only a browser
has** — if a test needs layout, scroll position or a real `IntersectionObserver`, mocking it just
asserts the mock; that work belongs in Playwright.

`npm run build` gates on lint and `vue-tsc`, **not** on tests — run the suites yourself before
calling a change done. Setup, helpers, layer-choice guidance and the traps (module-singleton leakage,
Fortify's cache-backed login throttle, the stale `public/hot` that blanks every asset, the randomly
re-seeded E2E library) are documented in [`docs/testing.md`](docs/testing.md) — **read it before
writing tests in this repo.**

**Validation & authorization — FORM REQUESTS, never inline** (ported from cantrip.me, 2026-08-08).
Every endpoint that validates input or guards a subject gets a class in
`app/Http/Requests/<Area>/<Verb><Thing>Request.php`, type-hinted in the action. Controllers do not
call `$request->validate()`, do not wrap anything in `precognitive()`, and do not `abort_if` /
`abort_unless` on the routed model — a controller says what happens, the request says what is
allowed to reach it.

- **`rules()` / `messages()` / `attributes()`** live there. Precognition needs nothing extra: a
  FormRequest filters its own rules to `Precognition-Validate-Only` and short-circuits with a 204
  when they pass, so the old `precognitive(fn () => $request->validate(…))` wrapper is redundant.
- **`authorize()`** is where ownership and subject-kind checks go. The routed model is already
  resolved (binding is substituted in middleware), so read it with `$this->route('playlist')`.
- **Override `failedAuthorization()` to `abort(404)`** wherever a 403 would confirm that a row
  exists — this instance is internet-facing and shared, so "you may not edit that" is a
  disclosure. 403 is only right when the caller already demonstrably knows the subject exists.
- **`prepareForValidation()` cleans input BEFORE the rules see it** (trimming, `''` → `null`), so
  `unique` and `max` measure what will actually be stored, and `validated()` is what the controller
  writes.
- **Share along the axis that repeats** — traits in `Requests/<Area>/Concerns/`, not a base class:
  fields are shared by create+update while ownership is shared by edit+update, and one inheritance
  chain cannot express both. Declare the seam `abstract` rather than resolving a collision with
  `insteadof`.

Three things deliberately stay inline, because they are neither validation nor authorization: a
resource that turns out to have no file (`abort_if($path === null, …)` in the cover routes — only
the service knows, after the model is in hand), `Dashboard\DeleteAccountController`'s manual
password check (a `ValidationException` there would not reach its `fetch()` caller as JSON — its
docblock explains), and `app/Actions/Fortify/*` (Fortify resolves those itself and hands them
arrays, so there is no request to inject).

**Linting the frontend** — use **`npm run lint`** (runs ESLint then Stylelint, both with `--fix`).
Don't invoke `eslint` / `stylelint` directly. `npm run build` runs the same lint first, so a lint
error fails the build before anything compiles. **Always run `npm run lint` after editing any
frontend file (Vue / TS / SCSS) — before calling a change done — so the build stays green.**

**Pages (Inertia)** — every page is its own directory with a `*Page` entry file:
`pages/Home/HomePage.vue` (not `pages/Home.vue`). Page-local parts (components, composables, tests) sit
**directly beside** the page file — **never in a `components/` sub-directory.** A page's own children are
already scoped by living in its folder; bucketing them one level deeper only adds a hop when reading the
page. Sub-folders are for a *nested route* (`Songs/Song/SongPage.vue`) or a self-contained feature block
(`Dashboard/TwoFactor/`), named after the thing — not after the kind of file.
Controllers render the explicit path — `Inertia::render('Home/HomePage', …)` — and prefer an invokable
(`__invoke`) controller for single-action pages. Full rationale:
[`docs/app-rewrite.md`](docs/app-rewrite.md) → *Frontend conventions*.

**Design tokens (SCSS)** — three layers, two hard rules. **Every** token group is identical:
**global tokens → contextual partial (per component) → consumed by SCSS/Vue.** Applies today to
`colors/`, `sizes/`, `z-indexes/`, `typography/`, `timings/`; future groups (`shadows/`, …) are created
the same way. Full guide: [`resources/app/styles/abstracts/README.md`](resources/app/styles/abstracts/README.md).

- **Rule 1 — never `@use`/read a global token (`_global-*-tokens.scss`) outside its token group.** Globals
  are the raw palette/scale (`$grey`, `$radius`, `$scale`, …) and stay private.
- **Rule 2 — contextual _colour_ tokens pick globals; they never mint a colour.** The only maths allowed
  on a global colour is a trivial **opacity** tweak (`color.adjust($alpha: …)`). Any new hue —
  lighten / darken / saturate / shift (`color.scale`, non-alpha `color.adjust`) — is pre-computed **in the
  global palette** as a named entry and consumed via `light-dark()` / `map.get()`. That's why `$retro`
  stores each hue as a baked `("light": …, "dark": …)` pair and the WCAG-tuned control glow is its own
  named entry (`c3`), not a per-component re-scale of `c2`. Sizes/z-indexes usually **pick from a
  scale** (`map.get($scale, …)`) and round/step off `$base` — but, unlike colours, aren't confined to it:
  a size token may also hold a plain literal (`2rem`) or a CSS keyword (`auto`) when that's what the
  component actually needs. It's still one named decision in one place; only the **colours** rule is
  hard (never mint a colour outside the global palette).
- To give something a colour, size, or z-index, **create a contextual partial**
  (`colors/components/_button.scss`, `z-indexes/components/_main.scss`) that `@use`s the globals and
  **picks/themes** the value (`light-dark()`, `map.get($scale, …)`, opacity-only `color.adjust()`), then
  `@forward` it from that folder's `_index.scss` (one line).
- **Tokens are scoped to a component, never to a page.** The `pages/` layer exists and is
  **deliberately empty**: whatever a page looks like it owns belongs to one of its own components (the
  song page's hero is the shared `components/_hero-section.scss`), and where a page needs a value of its own it reads
  the token of the component that already defines it (`s.$c-card "gap"` for the gap between blocks)
  rather than minting a duplicate to keep in step.
- Components and pages **consume only contextual tokens** via the entrypoint: `@use "Abstracts/colors" as c;`
  → `c.$c-button`; `@use "Abstracts/sizes" as s;` → `s.$c-button`; `@use "Abstracts/z-indexes" as z;`
  → `z.$c-main` (`c-*` = component). Timings use `@use "Abstracts/timings" as ti;` → `ti.$c-*`.

**Motion (transitions & animations)** — **every `transition` must live inside
`@media (prefers-reduced-motion: no-preference) { … }`**, so a user who asks to reduce motion gets none.
The guard is written positively (motion is *opt-in* via `no-preference`) rather than as a `reduce`
opt-out, so motion is also off wherever the preference is unknown/unsupported. Continuous decorative
`animation`s (e.g. the rotating icon) take the same guard. Durations always come from the `timings/`
tokens (`ti.$c-*`), **never raw `ms`/`s`**. One deliberate exception (by design, not omission): the
**loading spinner keeps turning even under reduced motion** — a frozen spinner reads as broken — but it
runs *much slower by default* and only switches to the lively duration under `no-preference`.

**Comments (docblocks)** — **every named function, method, and constructor in `.php`, `.ts` and `.vue`
files carries a docblock stating both *what* it does and *why*.** The "why" is the load-bearing half —
the part the signature doesn't already tell you: a design-doc reference, a race it guards, a legacy quirk
it ports, an ordering that matters. The bar is **no named declaration left uncommented**; after editing,
re-check (grep `function ` and eyeball that the line above each hit is a comment).

- **Prose, not just tags — because Pint strips tags.** Pint's default preset prunes "superfluous"
  `@param`/`@return` tags (types it can already read from the signature), so a comment made *only* of
  tags silently vanishes on the next format. Put the explanation in a sentence; keep `@param` only for
  the things a type can't say (an array *shape*, units, "already truncated by the caller").
- **Closures and other non-named functions need not be commented** (inline callbacks, `fn () => …`,
  `DB::transaction(fn …)`, `RateLimiter::for(…)`, a `->map()` body). They *may* carry a comment when
  genuinely subtle, but they're normally explained by the surrounding code and the enclosing function's
  docblock — annotating every one is just ceremony.
- **Trivial declarations get a one-liner, not a paragraph** — a DI-promotion constructor
  (`__construct(private readonly Foo $foo)`), a plain accessor, a value-object/DTO constructor sitting
  directly under a thorough class docblock. Keep it to a single `/** … */` line, and make that line carry
  a *real* reason (why the dependency is injected, why the DTO is all-`readonly`, what an accessor totals)
  rather than restating the class docblock right above it. A comment that only repeats is noise: drop the
  paragraph, keep the one useful sentence.
- **Vue / TS SFCs — the same rule in SFC shapes.** Every `.vue` file opens its `<script setup>` with a
  **component banner** (`/**** … ****/`) saying what the component is and why / how it behaves — a
  `<style>`-only file with no `<script setup>` needs none. Every **`defineProps` field** carries an inline
  `/** … */`, as does every **exported `type`**. **Named operations** — `computed`s, event handlers,
  `const fn = () => …`, composable functions — get a what/why docblock (trivial ones a one-liner). A bare
  one-off state `ref` (`const open = ref(false)`) and inline `<template>` callbacks fall under the closures
  exemption above. A composable `.ts` module documents its exported function *and* its return type. (There
  is no Pint on the frontend, but `npm run lint` runs on every frontend edit — see *Linting the frontend*.)

## Docs

> **The split is generic-vs-specific, and it is enforced by `.gitignore`.** This repo is public.
> **Reproducible** server documentation — anything another person could follow on their own hardware —
> is **tracked** in `docs/self-hosting/` and uses placeholders (`<your-domain>`, `<server-lan-ip>`).
> Anything describing **this one box** — hostname, LAN topology, MACs, the DynDNS host, the real
> domain, secret locations — goes in **`docs/host.local/`**, which is gitignored as a whole
> directory.
>
> **Never put a real host name, LAN address, MAC, or the live domain in a tracked file.** When adding
> server material, ask which half it is: the transferable lesson goes in `self-hosting/`, the concrete
> state in `host.local/`. Most changes touch both.
>
> _(Until 2026-07-19 this all lived in an untracked sibling folder `../mixtape-ops/`. A gitignored
> directory does the same job without the docs being one level away from the code they describe.)_

**Self-hosting guide (tracked — `docs/self-hosting/`):** the full path from bare hardware to a public
instance, written for someone else's server.

- `README.md` — overview, the order to work in, and a **gotchas index** (symptom → cause → where).
- `01-requirements.md` — hardware, OS, LVM, stack, network/exposure, security posture + the *why*.
- `02-host-setup.md` — Debian, networking, firewall, SSH, PostgreSQL, nginx/PHP, Samba, LAN TLS.
- `03-production-deploy.md` — the `mixtape-deploy` ownership model, Step-0 build, routine deploys.
- `04-going-public.md` — domain/CNAME, port-forward, firewall widen, certbot, mail + SPF/DKIM/DMARC,
  fail2ban, backup alerting. **Auth must be in force before any exposure.**
- `files/` — installable configs (nginx vhost, fpm pool, rate-limit zones, sudoers, deploy script,
  `.env` template), all with placeholders. Also the two **workstation**-side files: `mt.sh` (ssh
  wrapper — `mt artisan down --dev`, `mt artisan migrate --prod`) and its zsh completion `_mt`.

**This box (untracked — `docs/host.local/`):**

- `infrastructure.md` — the concrete live box: LAN topology, disks, services, secret **locations**.
- `go-live.md` — the go-live runbook with real values and per-step status.
- `live-configs/` — copies synced from the server, with real hostnames and cert paths.

**App (Phase 2 — next):**

- [`docs/app-rewrite.md`](docs/app-rewrite.md) — the rewrite: stack, goals, features, access model, legacy map.
- [`docs/testing.md`](docs/testing.md) — the three test layers (PHPUnit / Vitest / Playwright): where
  tests live, how each is set up, which layer to reach for, and the traps each one hides.
- [`docs/player.md`](docs/player.md) — the player (**built 2026-08-03**): why a native `<audio>`
  rather than vidstack, the stream route's Range + `X-Accel-Redirect` halves, the background-playback
  constraint no library removes, and the four things the build itself settled.
- [`docs/play-queue.md`](docs/play-queue.md) — the play queue (**split out of `player.md` 2026-08-06**):
  what it holds, the panel and reordering, **shuffle** (the bag, the walk, what resets it, why none of
  it is stored — and why a small queue looks deterministic), and its **storage** — the trimmed payload,
  the separate pointer key, writes coalesced behind a flush-on-hide, and what a browser's storage
  budget actually allows.
