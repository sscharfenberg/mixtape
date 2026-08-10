# Testing

> Part of MixTape v2 (Phase 2). See [../CLAUDE.md](../CLAUDE.md) for the overview and
> [app-rewrite.md](app-rewrite.md) for the surrounding frontend conventions.

MixTape has **three test layers**, and they are deliberately not interchangeable. Each one exists to
answer a question the others structurally cannot:

| Layer | Tool | Lives in | Answers |
| --- | --- | --- | --- |
| **Server** | PHPUnit | `tests/Unit/`, `tests/Feature/` | Does the request produce the right response, the right database state, and the right Inertia props? |
| **Frontend unit** | Vitest | beside the source, `*.test.ts` | Does this function / composable / component behave correctly, in isolation? |
| **End-to-end** | Playwright | `tests/e2e/` | Does the real app, in a real browser, actually work? |

```bash
php artisan test        # server
npm run test:unit       # frontend unit  (npm run test:watch to iterate)
npm run test:e2e        # end-to-end     (npm run test:e2e:ui for the debugger)
npm test                # unit + e2e
```

`npm run build` gates on lint and `vue-tsc`, **not** on tests — the suites are their own step, so an
asset build never waits on a browser.

---

## Where tests live

### Server — `tests/Unit/`, `tests/Feature/`

Standard Laravel layout, mirroring the namespace of the thing under test
(`tests/Feature/Music/SongPageTest.php`). `tests/Fixtures/` holds shared fixture files; the two
suites are declared explicitly in `phpunit.xml`, so a new directory under `tests/` is **not** picked
up automatically — which is what keeps `tests/e2e/` out of PHPUnit's way.

Feature tests are where the Inertia contract is pinned, via `assertInertia`. That matters for
deciding what to test elsewhere: **the props a controller passes to a page are already covered
here**, so re-asserting them from a mounted Vue component is duplicated effort with worse tooling.

### Frontend unit — beside the code

Tests sit **next to the source they cover**, following the same rule as page-local components and
composables (CLAUDE.md → *Pages*):

```
resources/app/utils/formatting.ts
resources/app/utils/formatting.test.ts
resources/app/composables/useToast.ts
resources/app/composables/useToast.test.ts
resources/app/components/UI/Breadcrumb.vue
resources/app/components/UI/Breadcrumb.test.ts
```

There is no `tests/` tree for them and no `__tests__/` folders. Vitest globs
`resources/app/**/*.{test,spec}.ts`.

### End-to-end — `tests/e2e/`

```
tests/e2e/
├── guest/       specs that run with NO session
├── app/         specs that run signed in
└── support/     config-adjacent helpers, not specs
```

The split is by **directory, not by clearing cookies**, so an auth-gate test can never pass by
accidentally inheriting a stored session.

---

## How the frontend unit layer is set up

`vitest.config.ts` is a **separate config**, not a `test:` key on `vite.config.ts` — that one is a
factory pulling in `laravel-vite-plugin` and the image optimizer, none of which a unit run should
load. The one thing both configs must agree on, the import aliases, is imported from
`resources/build/aliases.ts` rather than copied. (`tsconfig.json`'s `paths` has to be kept in step by
hand; TypeScript cannot read that module.)

Key settings and why:

- **`environment: "happy-dom"`** — faster to boot than jsdom and carries what the components reach
  for. What it does *not* have is **layout**: no box metrics, no real `IntersectionObserver`
  behaviour. Anything depending on those belongs in Playwright, not in a richer fake.
- **`env: { TZ: "UTC" }`** — `formatDateTime` renders in the *viewer's* zone by design, so without a
  pin its expectations depend on the machine running the suite.
- **`globals: false`** — specs import `{ describe, it, expect }` from `vitest`, so `tsconfig.json`
  needs no `"vitest/globals"`. Test files are matched by the existing `resources` include, so they
  are **type-checked and linted like any other source file**.
- **`setupFiles`** — `resources/app/testing/setup.ts`. It installs `enableAutoUnmount(afterEach)`
  (not optional: components watching shared state otherwise stay alive for the whole file) plus
  polyfills for the handful of APIs happy-dom omits.
- CSS is **not** compiled. `<style lang="scss">` blocks are stubbed; tests assert markup, classes and
  behaviour, never computed styling.

### Test support — `resources/app/testing/` (alias `Testing/`)

Never imported by the app, so it never reaches a bundle.

| File | What it gives you |
| --- | --- |
| `mount.ts` | `mountApp()` — mounts with the **real** de/en catalogs and the global `v-tooltip` directive. Plus `translate()` and `iconNames()`. |
| `inertia.ts` | A stand-in for `@inertiajs/vue3`: `setPage`, `resetInertia`, `routerCalls`, `emitRouterEvent`, and stubs for `Link` / `Head` / `Form` / `router` / `usePage`. |
| `setup.ts` | Auto-unmount and the happy-dom polyfills. |

The catalogs are the real ones on purpose: a stub `t()` that echoes its key would sail straight past
a renamed or deleted translation, which is exactly the regression worth catching.

**Inertia has to be mocked as a whole module.** The real `usePage()` closes over a module-level page
ref — nothing is passed through the component tree, so there is no provider or `inject` seam a test
could use. Opt in per file:

```ts
vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));
```

---

## How the end-to-end layer is set up

`playwright.config.ts` drives the **real app**: Laravel over a throwaway sqlite, seeded fresh each
run. Nothing is manual — `globalSetup` handles all of it, in this order:

1. Deal with `public/hot` (see the traps below).
2. Build assets if `public/build/manifest.json` is missing.
3. Rebuild `storage/e2e-media/` — a playable file behind every row that is about to be seeded.
4. Truncate `storage/e2e.sqlite`, `config:clear`, `migrate:fresh --seed`.
5. Clear the login rate limiter.

Then the app is served on **:8100** — deliberately not 8000, so a hand-started `artisan serve`
survives — and the `setup` project signs in once and saves the session for the `app` project to
reuse.

**It needs a seeded database and playable audio — but no real media library.** The rows come from the
seeder; the *files* are written by `seedMediaFiles`, which drops a copy of the committed one-second
mp3 fixture at every path the seeder claims and points `MIXTAPE_MUSIC_PATH` at that throwaway
directory. Nothing runs `app:update`, and neither the dev nor the production database is ever
touched.

Two deliberate asymmetries in that fixture, both load-bearing:

- **Audio is real, artwork is not.** The mp3 carries no picture and no folder image is written, so
  cover URLs still 404 — which is what drives `CoverImage`'s placeholder fallback.
- **The audio is one second long while the rows claim two to eight minutes.** That is a feature: the
  most valuable thing a browser can prove about the player is that the queue advances *by itself*
  when a track ends, and a track that ends in a second makes that assertion fast and deterministic.
  The consequence is that the durations **disagree**, so a player spec must not assert a position
  derived from the rail's width — that geometry belongs in Vitest, where the numbers are whatever
  the test says.

The fixture is **`database/seeders/E2ESeeder.php`**, and it is deterministic on purpose: fixed ids,
names, durations and timestamps, no `fake()` and no `now()`, so re-seeding produces identical rows
and a spec can assert an exact result. It is also *shaped* for the tests — read its docblock before
changing it, because several specs depend on specific properties:

| Property | Why a spec needs it |
| --- | --- |
| 65 music tracks | More than the default page size of 50, so paging is real |
| Unique durations | Ordering by duration is total — a tie would have two correct answers |
| One track with no duration / composer / publisher / bit rate | Proves a missing field *disappears* rather than rendering `0:00` or `null` |
| An artist named `Sigur Rós` | Accent-folded search: typing `Ros` must find `Rós` |
| A title appearing exactly once (`Paranoid Android`) | An unambiguous search assertion |
| Every genre holds ≥2 tracks | A genre's songs tab can always be sorted |
| Mixed `cover` flags, no cover file on disk | Exercises CoverImage's 404 → placeholder fallback |

Seed login: **`Ashaltiriak` / `passwort`**, and note that **login is by `name`, not email**
(`Fortify::username() === 'name'`).

### Support helpers — `tests/e2e/support/`

| File | Role |
| --- | --- |
| `environment.ts` | Ports, paths, the server env overrides, the generated media area, and the stand-up/teardown primitives. |
| `globalSetup.ts` / `globalTeardown.ts` | Run them, in the right order. |
| `auth.setup.ts` | The `setup` project: signs in for real and stores the session. |
| `actions.ts` | `signIn`, `columnValues`, `pageHeading`, `clockToSeconds`, `fold`, `expectOnTablePage`, `countDocumentRequests`, `openQueuePanel`, `enqueueFromHero`, `stopQueueSync`, `settled`. |

---

## Traps

Each of these failed in a way that pointed somewhere other than its cause. They are handled in code;
this is the record of *why* that code exists.

**Frontend unit**

- **Module singletons leak between tests.** `useToast` and `useTooltipLayer` are module-level state
  (the no-Pinia store), so drain them in `beforeEach`. A still-mounted wrapper also re-renders when
  the singleton changes — assert one case fully, `unmount()`, then set up the next. (`useBreadcrumbs`
  used to be one of these; it now writes to Inertia's layout-prop store, which `resetInertia()`
  empties. Assert what a page published with `getLayoutProps().breadcrumbs`.)
- **happy-dom has no `localStorage`** (it does have `sessionStorage`), no `execCommand` and no
  Popover API. Polyfilled in `setup.ts`. The polyfill is installed *unconditionally* because Node's
  own experimental `localStorage` prints a warning when merely **read**.
- **`attributes("xlink:href")` is always `undefined`** — the DOM keys namespaced attributes by local
  name (`href`). Use `iconNames()`.
- **`findAll("button")` sweeps up child components' buttons** (the pager's page-size `Select`) —
  scope to the component's own class.
- **`setValue()` on `<input type="range">` writes the value and dispatches nothing.** So the handler
  under test never runs and the assertion silently passes against an unchanged component. Set
  `element.value` and `trigger("input")` yourself (`PlayerTimeline.test.ts` has the helper).
- **happy-dom's `<audio>` is real enough to test a player against** — `play()`/`pause()` flip
  `paused` and fire their events, `currentTime` is writable, and media events can be dispatched by
  hand. What it has no decoder for is `buffered` (always empty) and `duration` (`NaN`); override
  those per test with `Object.defineProperty`, and leave anything that needs real bytes to
  Playwright.
- **Match a popover entry by its variant, not its position.** `.popover-list-item` matched exactly
  one thing until the queue menu gained a repeat toggle above "clear the queue"; a positional
  selector then silently started toggling repeat, and in Playwright it becomes a strict-mode
  violation instead. Use `--caution` / `--selected` or the accessible name.
- **DataTable renders BOTH layouts at once** — the desktop `<table>` and the narrow card list — from
  the same `#cell-*` slots. So an unscoped `findAll(".genre-songs__link")` returns every cell link
  twice, and "this row has one outbound link" passes against two. Scope cell assertions to `tbody`.
- **A teleported component is not inside its parent's wrapper.** `Modal` renders into `<body>`, so
  `wrapper.find()` reaches straight past it — and, worse, can match something with the same selector
  back in the host page (`DeleteAccount` has a submit button of its own, which is what
  `find("button[type=submit]")` returned instead of the modal's). Query `document` for anything
  inside a modal or a toast.
- **`flushPromises()` does not settle a dynamic `import()`.** `LanguageSwitch` opens with one, so
  asserting after a single flush lands mid-handler — and the rest of it then runs *after* teardown,
  putting its `fetch` on the NEXT test's mock. That test fails, pointing nowhere near the cause. Warm
  the import in `beforeAll` and use `vi.waitFor` for the assertion.
- **A component that fetches on mount will hit a real port.** `TwoFactorModal` loads its QR code in
  `onMounted`, which surfaces as an `ECONNREFUSED` printed *after* a green run. Stub `fetch` in any
  file that mounts one.
- The project targets `lib: ES2020`, so **`Array.prototype.at()` is unavailable** in tests.

**End-to-end**

- **Fortify throttles login at five attempts a minute per `username|ip`, counted in the CACHE** — so
  `migrate:fresh` does *not* reset it. A run signs in several times, so two runs inside a minute
  start getting 429s, which present as the login form silently doing nothing. Global setup clears
  it; **keep the number of real logins per run under five.**
- **A stale `public/hot` blanks every asset.** Written by `npm run dev` and *not* removed when that
  server stops, so it usually outlives it; while it exists `@vite` points every asset at the URL it
  names and ignores the manifest — and every selector times out. Global setup parks a stale marker
  at `public/hot.e2e-backup` and restores it in teardown *and* at the next run's start, so a killed
  run self-heals. A **live** dev server is left alone.
- **A cached config beats real environment variables**, silently pointing the run at the remote
  Postgres. Hence `config:clear` before migrating.
- **Seed with `E2ESeeder`, never the default `--seed`.** `DatabaseSeeder` runs `LibrarySeeder`,
  which is deliberately random (factories, `random_int`, `inRandomOrder`) and re-rolled on every
  run — right for a developer wanting a plausible library, wrong for a browser test, which then
  cannot name a song and meets thin edge cases unpredictably (a genre with one track, a page with a
  single row) as tests that fail once in twenty runs. `E2ESeeder` is fixed: same ids, names,
  durations and timestamps every time.
- **Assert ordering on a numeric column, never text.** The server sorts through the database's
  collation and the app's accent-folded `name_fold` columns; `localeCompare` does not reproduce
  that. Sort by duration and compare seconds.
- **A sortable `<th>` contains hidden text** — its innerText is really
  `"Album\nNach Album aufsteigend sortiert"`, the sort state announced for screen readers. Match the
  first line only (`columnValues` does).
- **Column 1 is not always the title** — the albums listing and a genre's songs tab both lead with a
  cover cell. Find columns by header.
- **`getByRole("heading", { level: 1 })` is ambiguous** — the app header renders the wordmark as an
  `<h1>` too. Use `pageHeading()`.
- **`framenavigated` fires for `history.replaceState`**, so it cannot prove a tab change costs no
  round trip. Count `document` requests (`countDocumentRequests`).
- **A `waitForResponse` on url + method matches a PRECOGNITION request, not your write.** Every
  Precognition form validates against its OWN endpoint with its own verb — measured on the playlist
  form: `PUT /playlists/{id}`, `Precognition: true`, `Precognition-Validate-Only: description`, fired
  by the `change` event that `fill()` itself dispatches. So the matcher resolves on a request that
  saves nothing, the test walks on believing the write landed, and it fails later on stale-looking
  data with the cause several steps behind it. Match the write: `X-Inertia` present and no
  `precognition` header (`isWrite()` in `playlists.spec.ts`). The same applies to counting requests
  against a rate limit — a two-field form spends **three** of the route's budget per save, since
  `throttle:` sits in front of the precognition middleware.
- **A hover `prefetch` can re-create the page you are already on**, which loses any state the page
  holds — and in a form, saves the value the server sent instead of the one just typed. A click that
  outruns Inertia's hover timer sends its own request, so the prefetch is neither cached nor
  consumed; when its response lands, `Response.handlePrefetch()` calls `handle()` because the URL
  now matches the current location, and the swap re-keys the page component. Playwright's `click()`
  hovers and clicks in one motion, so a test hits this far more often than a human does: it cost one
  full run in five for weeks, presenting as a stale row on a listing. Found by probing the `<form>`
  element's identity every 10ms (it changed 12–20ms after `fill()`), and by holding a prefetch back
  two seconds on purpose to prove the response alone was innocent.
- **A stretched overlay button refuses every action aimed at what it covers.** Two components make
  their whole surface one target that way — a queue row (`.play-queue__load`) and a neighbour card
  on Now Playing (`.neighbour__step`) — so `hover()` or `click()` on the title, a fact chip or the
  artist line fails actionability with *"…intercepts pointer events"*, which reads as a broken
  selector rather than as a deliberate design. Aim at the overlay, or at the card itself (a
  descendant satisfies the hit-target check). The pattern exists because a `<button>` cannot hold a
  heading — ARIA prunes its descendants — so expect more of it, not less.
- **Lazy, hidden images are legitimately "incomplete"** — covers are `loading="lazy"` and the
  discography renders row *and* card artwork with one `display:none`. Only a **visible** image that
  failed is broken, and check after `waitForLoadState("networkidle")`.
- **`getByLabel(/Passwort/)` is ambiguous** — it matches the input *and* the "Passwort anzeigen"
  reveal button. `signIn()` uses ids.
- **The web-server probe must not need the database.** Playwright brings the server up and waits
  for `webServer.url` BEFORE global setup migrates, so a probe pointed at a page fails the moment
  that page starts reading a table — which is exactly how a shared prop counting the library's media
  kinds took the whole suite down on CI while passing locally against a database left over from the
  last run (2026-08-08). The probe is `/up`, Laravel's health route. To reproduce that class of
  failure locally, `rm storage/e2e.sqlite` first: a stale one hides it completely.
- **A fresh browser context is no longer a fresh PLAYER.** Since the queue syncs to
  `player_states` (2026-08-07) it follows the *user*, so a spec inherits whatever queue another one
  left. A spec that touches the queue needs all four of these, and each was found by the failure
  the last one left behind:
  1. **its own seeded account** — `test.use({ storageState: specStorageState("queue") })`;
  2. **`test.describe.configure({ mode: "default" })`**, because `fullyParallel` parallelises at the
     TEST level, so without it the file's tests run concurrently against the account they share;
  3. **`clearServerQueue("queue")` in `beforeEach`** — it writes an empty queue straight into
     sqlite, deliberately not through the app's PUT (an out-of-band request needs a session cookie
     *and* a CSRF token that still matches it, and a remember-me session gets a fresh one, so the
     request ends up redirected and answering 405);
  4. **`stopQueueSync(page)` in `afterEach`**, because a tab flushes its queue as it closes with
     `keepalive` — that request outlives the test and can land after the next one has reset.
  The symptom of missing any of them is a row count one too high, on a different test every run.
  `clearServerQueue` also **watches that its write stays written** — a request already past the
  abort lands a moment later, so it overwrites and re-reads until two reads agree.
- **The rule above is not about the queue.** It is about **user-scoped state a spec writes**, and
  the queue was only the first kind. `add-to-playlist.spec.ts` creates playlists, and rows added to
  the shared account's listing moved the coordinates `playlists.spec.ts`'s DRAG test computes from
  its own three rows — so a new spec broke a file it never touches, in a way that read as a broken
  drag. Steps 1 and 2 above (own account + `mode: "default"`) are the parts that generalise; a
  fixture nothing else can reach cannot be disturbed by what your spec leaves behind. Add the
  account to `SPEC_USERS` and to `E2ESeeder::seedSpecUsers` — the setup project mints a session for
  every entry automatically.
- **`PRAGMA busy_timeout` must be set before `prepare()`, not after.** The app holds the same
  sqlite file open and `prepare` takes a lock of its own, so a timeout set after the prepares is
  not in force for the statement most likely to collide — it surfaces as a bare "database is
  locked" from a helper that looks like it handled exactly that.
- **Never poll an `m:ss` readout to prove time is passing.** The fixture's audio is ONE SECOND
  long, so the player's clock says `0:00` for the whole of it, and the only window saying anything
  else is the sliver between 1.0s and the `ended` that wraps. "Plays, and moves the timeline"
  polled that readout and was a coin flip — heads on an idle machine, tails under a full suite,
  about once in fifty runs. Assert the range input's `inputValue()` instead: same number,
  unrounded, and it is what a screen reader is given anyway.
- **After a navigation, `page.mouse` fires into the VIEW TRANSITION and nothing happens.** `main.ts`
  opts every navigation into the View Transitions API, and while one runs the browser paints
  `::view-transition-*` — a pseudo tree belonging to the **root** — over the whole page in the top
  layer. Hit testing lands on that, so `document.elementFromPoint` returns the `<html>` element at
  *every* coordinate on the page, including (5, 5), while `getBoundingClientRect()` on the element
  you were aiming at reports exactly the rectangle you expect. Measured: immediately after
  navigating every probe answers `HTML`; 700ms later the same points answer with the real element.
  Locator actions ride it out, retrying until the element genuinely receives pointer events —
  **anything through `page.mouse` does not**, it fires once into the snapshot. Call `settled(page)`
  after the navigation and before any raw pointer work (a drag by a grip, a click at a measured
  offset). It cost an hour twice before being understood: once as a SortableJS drag that silently
  refused to start, once as a click on a row that plainly had a stretched link under it — both
  presenting as broken *features* rather than as mis-timed input. Prefer a locator action with
  `{ position }` over raw coordinates where one will do; it waits *and* verifies the element is
  what receives the press.
- **A popover must be STILL before it is measured.** Panels open with a `rotateY`, and a transform is
  included in `getBoundingClientRect` — so a box read on the click is a couple of pixels from where
  it lands. `:popover-open` and visibility are both true from the first frame, so neither is the
  thing to wait for. The player's geometry assertions were a coin flip until `openPopover`
  (`player.spec.ts`) started waiting for two identical boxes in a row: the same assertion failed by
  1.3px on one run and 2.9px on the next, against positioning code that had not changed.

---

## Choosing a layer

Roughly, in order of preference — the cheapest layer that can actually answer the question:

1. **Pure logic** (formatters, predicates, a composable's state machine) → **Vitest**, no mounting.
2. **A component's own behaviour** (props in, markup/events out; conditional rendering; a11y
   attributes) → **Vitest** with `mountApp`.
3. **Anything the server decides** (authorization, validation, query shaping, which props a page
   receives) → **PHPUnit feature test**.
4. **Anything that needs a real browser** — navigation, history, the URL as state, CSP, media
   playback, focus and keyboard journeys → **Playwright**.

Two rules that fall out of this:

- **Don't component-test Inertia pages in Vitest** just to check their props. That contract is
  already covered by `assertInertia`. Test a page in Vitest for what PHP *cannot* see — that raw
  seconds/bytes/ISO-8601 are formatted in the reader's locale, that an untagged field disappears
  instead of rendering `null` — and cover the journey itself with a Playwright spec.
- **Don't fake what only a browser has.** If a test needs layout, scroll position or a real
  `IntersectionObserver`, mocking it means asserting the mock. `useStickyNav` and the tooltip's
  anchor positioning are left to Playwright for exactly this reason.

## Conventions

- **Name the behaviour, not the mechanics.** `it("drops the denominator when a multi-disc rip numbers
  past its own disc")`, not `it("returns a string")`.
- **Say *why* in the file's opening comment** — the risk the file is guarding. The docblock rule
  (CLAUDE.md → *Comments*) applies to test helpers as well.
- **Prefer a real failure over a green one.** A test that cannot fail is worse than no test; when a
  new suite lands, seed a mutation and confirm it is caught.
- **Never let a flaky test settle in.** Retries are off on purpose. Run a new E2E spec several times
  before trusting it. The fixture is fixed, so a spec that passes once should pass every time —
  which means an intermittent failure is a real race, not bad luck with the data.
