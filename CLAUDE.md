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

**Rate limiting — every `throttle:` gets a third argument** (ported from a real 429, 2026-08-10).
`throttle:max,decay` keys its bucket on the **caller alone** — the user's id, or the IP for a guest
— with the route playing no part, so unprefixed throttles all share **one counter per reader** and
the route with the lowest ceiling is refused first, for traffic that never touched it. Name the
bucket after the route (`throttle:10,1,album-download`). Named limiters (`throttle:login`,
`throttle:auth-mail`, `throttle:two-factor`) already have their own keys and need nothing.
`tests/Feature/RateLimitBucketsTest.php` fails the suite if a numeric throttle turns up bare.

**…and `throttle` is OUR middleware** (aliased in `bootstrap/app.php` to
`App\Http\Middleware\ThrottleRequests`), which counts a form's **validate-only Precognition
traffic in a bucket of its own** at five times the route's ceiling. Nothing to write per route —
but know that it is happening, because it changes what a number on a route means: `throttle:30,1,…`
in front of a Precognition form is 30 **saves**, not 30 requests. A ceiling that was inflated to
leave room for live validation (`register`, `password-reset`) is now only covering writes and can
be reconsidered on its own terms. Two traps it exists because of, both measured 2026-08-10: a
Precognition form validates against **the route it submits to with the same verb**, so typing spends
the save's allowance (a 2-field form costs three per save); and a *named* limiter cannot separate
them by branching inside the callback, because its counter is keyed `md5($name . $limit->key)` — two
arms passing the same `by()` are one bucket with two ceilings. `isPrecognitive()` is also the wrong
question in a limiter: it reads an attribute set by `HandlePrecognitiveRequests`, which runs *after*
the throttle. Pinned in `tests/Feature/PrecognitionThrottleTest.php`.

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

**Never `prefetch` a link that leads to a FORM** (learned from a real bug, 2026-08-10). A hover
prefetch whose response lands *after* you have navigated to that same URL is applied to the page you
are now on — `Response.handlePrefetch()` calls `handle()` whenever the URL matches the current
location — and that swap does not preserve state, so Inertia's Vue adapter **re-keys the page
component**: `setup()` runs again and every `ref` on the page goes back to its prop. In a form that is
silent data loss, because Inertia's `<Form>` serialises the DOM at submit, so **the value the server
sent is what gets saved.** It cost one full E2E run in five for weeks and presented as a stale row on
a listing, nowhere near the cause; the `<form>` element was measured being replaced 12–20ms after a
field was typed into. A click that outruns Inertia's hover timer is what does it — the click sends its
own request, so the prefetch is never consumed — which Playwright does on every `click()` and a fast
human does often enough. Warming a form buys ~150ms on a page a reader then sits on for seconds.

- **`useRemember` is the complement, not the alternative** — it makes a page's own state survive
  being re-created from any cause (`router.remember` → `replaceState` updates the in-memory state
  synchronously, so a remount a tick later still restores it). `PlaylistMetadataPage` uses it.
  **Never remember a secret**: it is written into the history entry, so a password or a 2FA code must
  stay out of it — for those forms, not prefetching is the whole fix.
- **Where this is still live:** `LabelledLink` prefetches every GET link it renders
  (`:prefetch="method === 'get'"` — the decision is made from the link's *own* shape, never from what
  is at the other end), which today warms `/forgot` and `/resend-verification`; `UserMenu` prefetches
  `/login` and `/forgot` with its own `<Link prefetch>`. All of those are forms, and the login one
  holds a password. `/playlists/create` is a plain `<Link>` and is fine. `docs/testing.md` → the traps
  index has how the mechanism was pinned down.

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
- [`docs/now-playing.md`](docs/now-playing.md) — the Now Playing page (**built 2026-08-09**): four
  rows over the live queue. Why "what plays next" needed the shuffle **pre-draw**, why the extra track
  facts are fetched rather than stored on the queue, and the EQ visualiser — **measured, not argued**:
  `/dev/audio-probe` proved routed audio survives screen-off (away 215s, advanced 215s), so the
  analyser is wired directly with no toggle. Also records what no test can see (Playwright runs
  Chromium muted, so the bars never move in CI).
- [`docs/search.md`](docs/search.md) — search (**designed 2026-08-13, not built**): one engine, a
  header overlay and a field on the Music page, and no per-widget boxes. The rule everything turns on
  is that **a row matches its OWN name** — measured: "black" is 77 songs by title against 1,238 once
  artist/album/genre count, a tenth of the library — so the wide search stays where it already lives,
  in the listings, reached by "see all in Songs" (`?search=`). Also: fixed groups rather than
  cross-kind scoring, four ranking tiers with a **total** tie-break (`LIMIT 5` over a partial order
  flickers), a JSON endpoint because a typeahead must not re-render the page (the prefetch rule), and
  the one migration it needs — `playlists` was left out of the `name_fold` set.
- [`docs/sharing.md`](docs/sharing.md) — share links (designed 2026-08-10; **minting and the `/s/`
  guest space both built 2026-08-11**, the owner's list and revoking 2026-08-12, pruning and
  **playlist shares** 2026-08-13): play one song / album / artist / playlist with **no account**,
  which now works end to end. Why the plan moved off signed URLs onto a `shares` row (a signature is an
  assertion, not a record, so nothing can revoke it), the four-FK subject and its CHECK, and the
  `/s/{share}` space whose containment is **structural** — a share cannot name a track outside its
  grant, so `/music` stays wholly behind `auth`. Records the two seams the code already had:
  `PlaylistSubject::column()`, so a share grants the same tracks "play this" does (the artist trap —
  `tracks.artist_id` is *not* `collections.album_artist_id`), and the queue's per-track `streamUrl`
  override, which means the player needs no change at all.

  **One class owns the grant** — `App\Services\Shares\ShareGrant`. The guest page is drawn from
  `tracks()` and both media routes admit a track through `contains()`, over the same `query()`;
  written twice they drift, and the drift reads as a player stopping silently on one song out of
  ninety. **Anything new under `/s/` asks it rather than re-deriving the set.**

  **A PLAYLIST is the exception to both of those sentences** (built 2026-08-13). Its tracks are
  `playlist_tracks` rows in the reader's own `position` order, so `ShareSubject::grant()` answers
  **null** for it and `ShareGrant` joins the pivot — and `ShareGrant::entries()` must sort on that
  position, because `QueuePayload::fromQuery`'s album-then-disc-then-track order would silently
  rewrite a hand-made list. Nothing is snapshotted: the grant is resolved per request, so a shared
  playlist follows its owner's edits on the guest's next reload (the owner's requirement) and a
  removed track stops streaming at once. It is also the **only subject with an owner** — the mint
  request narrows its `exists` rule by `user_id`, a 422 rather than a 403, so refusing does not
  confirm the playlist exists.

  **`/dashboard/shared` sends TWO lists** (2026-08-13) — `shares` and `expiredShares`, sorted in
  opposite directions, with no `expired` flag on the row because the list it arrives in is that
  answer. **The word "abgelaufen" on a dead row is a button**: `PATCH /shares/{share}/renew` gives
  it another seven days, which is what makes the thirty-day grace period worth having (the same URL
  a reader already sent starts working again). **A LIVE link cannot be renewed** — that would be the
  extension `store`'s re-hand rule exists to prevent — and both refusals answer 404.

  **No genre shares, ever** (a genre is a shelf, not a thing somebody chose to send) — stated in
  `App\Enums\ShareSubject`, which simply has no case for one, so none can be minted by a
  hand-written request. Audiobook shares stay blocked on the Audiobooks area existing.

  Two things about the guest page worth knowing before touching the player: it renders in
  **`ShareLayout`** (FullLayout minus the breadcrumb, minus persistence), and that second half is why
  `usePlayerQueue` has an **ephemeral mode** — a signed-in owner opening their own link must not have
  their real queue overwritten with `/s/…` URLs that die in seven days.
