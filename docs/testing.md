# Testing

MixTape has **three test layers**, and they are deliberately not interchangeable. Each one exists to answer
a question the others structurally cannot.

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

`npm run build` gates on lint and `vue-tsc`, **not** on tests — the suites are their own step, so an asset
build never waits on a browser. Run them yourself before calling a change done.

---

## Where tests live

### Server — `tests/Unit/`, `tests/Feature/`

Standard Laravel layout, mirroring the namespace of the thing under test
(`tests/Feature/Music/SongPageTest.php`). `tests/Fixtures/` holds shared fixture files. The two suites are
declared explicitly in `phpunit.xml`, so a new directory under `tests/` is **not** picked up automatically —
which is what keeps `tests/e2e/` out of PHPUnit's way.

Feature tests are where the Inertia contract is pinned, via `assertInertia`. That matters for deciding what
to test elsewhere: **the props a controller passes to a page are already covered here**, so re-asserting them
from a mounted Vue component is duplicated effort with worse tooling.

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

The split is by **directory, not by clearing cookies**, so an auth-gate test can never pass by accidentally
inheriting a stored session.

---

## How the frontend unit layer is set up

`vitest.config.ts` is a **separate config**, not a `test:` key on `vite.config.ts` — that one is a factory
pulling in `laravel-vite-plugin` and the image optimizer, none of which a unit run should load. The one
thing both configs must agree on, the import aliases, is imported from `resources/build/aliases.ts` rather
than copied. (`tsconfig.json`'s `paths` has to be kept in step by hand; TypeScript cannot read that
module.)

Key settings and why:

- **`environment: "happy-dom"`** — faster to boot than jsdom and carries what the components reach for. What
  it does *not* have is **layout**: no box metrics, no real `IntersectionObserver` behaviour. Anything
  depending on those belongs in Playwright, not in a richer fake.
- **`env: { TZ: "UTC" }`** — `formatDateTime` renders in the *viewer's* zone by design, so without a pin its
  expectations depend on the machine running the suite.
- **`globals: false`** — specs import `{ describe, it, expect }` from `vitest`, so `tsconfig.json` needs no
  `"vitest/globals"`. Test files are matched by the existing `resources` include, so they are
  **type-checked and linted like any other source file**.
- **`setupFiles`** — `resources/app/testing/setup.ts`. It installs `enableAutoUnmount(afterEach)` (not
  optional: components watching shared state otherwise stay alive for the whole file) plus polyfills for the
  handful of APIs happy-dom omits.
- CSS is **not** compiled. `<style lang="scss">` blocks are stubbed; tests assert markup, classes and
  behaviour, never computed styling.

### Test support — `resources/app/testing/` (alias `Testing/`)

Never imported by the app, so it never reaches a bundle.

| File | What it gives you |
| --- | --- |
| `mount.ts` | `mountApp()` — mounts with the **real** de/en catalogs and the global `v-tooltip` directive. Plus `translate()` and `iconNames()`. |
| `inertia.ts` | A stand-in for `@inertiajs/vue3`: `setPage`, `resetInertia`, `routerCalls`, `emitRouterEvent`, and stubs for `Link` / `Head` / `Form` / `router` / `usePage`. |
| `setup.ts` | Auto-unmount and the happy-dom polyfills. |

The catalogs are the real ones on purpose: a stub `t()` that echoes its key would sail straight past a
renamed or deleted translation, which is exactly the regression worth catching.

**Inertia has to be mocked as a whole module.** The real `usePage()` closes over a module-level page ref —
nothing is passed through the component tree, so there is no provider or `inject` seam a test could use. Opt
in per file:

```ts
vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));
```

---

## How the end-to-end layer is set up

`playwright.config.ts` drives the **real app**: Laravel over a throwaway sqlite, seeded fresh each run.
Nothing is manual — `globalSetup` handles all of it, in this order:

1. Deal with `public/hot` (see the traps below).
2. Build assets if `public/build/manifest.json` is missing.
3. Rebuild `storage/e2e-media/` — a playable file behind every row that is about to be seeded.
4. Truncate `storage/e2e.sqlite`, `config:clear`, `migrate:fresh --seed`.
5. Clear the login rate limiter.

Then the app is served on **:8100** — deliberately not 8000, so a hand-started `artisan serve` survives —
and the `setup` project signs in once per seeded account and saves the sessions for the `app` project to
reuse.

**It needs a seeded database and playable audio — but no real media library.** The rows come from the
seeder; the *files* are written by `seedMediaFiles`, which drops a copy of the committed one-second mp3
fixture at every path the seeder claims and points `MIXTAPE_MUSIC_PATH` at that throwaway directory. Nothing
runs `app:update`, and neither the development nor the production database is ever touched.

Two deliberate asymmetries in that fixture, both load-bearing:

- **Audio is real, artwork is not.** The mp3 carries no picture and no folder image is written, so cover URLs
  still 404 — which is what drives `CoverImage`'s placeholder fallback.
- **The audio is one second long while the rows claim two to eight minutes.** That is a feature: the most
  valuable thing a browser can prove about the player is that the queue advances *by itself* when a track
  ends, and a track that ends in a second makes that assertion fast and deterministic. The consequence is
  that the durations **disagree**, so a player spec must not assert a position derived from the rail's width
  — that geometry belongs in Vitest, where the numbers are whatever the test says. It also means every
  threshold measured in *heard seconds* (play counting, the 30-second resume guard) is unreachable here by
  construction; those live in Vitest and PHPUnit.

The fixture is **`database/seeders/E2ESeeder.php`**, and it is deterministic on purpose: fixed ids, names,
durations and timestamps, no `fake()` and no `now()`, so re-seeding produces identical rows and a spec can
assert an exact result. It is also *shaped* for the tests — read its docblock before changing it, and **add,
never reshape**, because specs name its rows by hand:

| Property | Why a spec needs it |
| --- | --- |
| More music tracks than the default page size | So paging is real |
| Unique durations | Ordering by duration is total — a tie would have two correct answers |
| One track with no duration / composer / publisher / bit rate | Proves a missing field *disappears* rather than rendering `0:00` or `null` |
| An artist named `Sigur Rós` | Accent-folded search: typing `Ros` must find `Rós` |
| A title appearing exactly once | An unambiguous search assertion |
| Every genre holds ≥2 tracks | A genre's songs tab can always be sorted |
| Mixed `cover` flags, no cover file on disk | Exercises CoverImage's 404 → placeholder fallback |
| Two shares with fixed ids, one live and one expired | A guest spec cannot mint one, since minting is behind `auth` |
| One account per spec file that writes user-scoped state | See *user-scoped state* below |

Note that **login is by `name`, not email** (`Fortify::username() === 'name'`).

### Support helpers — `tests/e2e/support/`

| File | Role |
| --- | --- |
| `environment.ts` | Ports, paths, the server env overrides, the generated media area, and the stand-up/teardown primitives. |
| `globalSetup.ts` / `globalTeardown.ts` | Run them, in the right order. |
| `auth.setup.ts` | The `setup` project: signs in for real and stores a session per account in `SPEC_USERS`. |
| `actions.ts` | `signIn`, `columnValues`, `pageHeading`, `clockToSeconds`, `fold`, `expectOnTablePage`, `countDocumentRequests`, `openQueuePanel`, `enqueueFromHero`, `stopQueueSync`, `settled`. |

---

## Choosing a layer

Roughly, in order of preference — the cheapest layer that can actually answer the question:

1. **Pure logic** (formatters, predicates, a composable's state machine) → **Vitest**, no mounting.
2. **A component's own behaviour** (props in, markup/events out; conditional rendering; a11y attributes) →
   **Vitest** with `mountApp`.
3. **Anything the server decides** (authorization, validation, query shaping, which props a page receives) →
   **PHPUnit feature test**.
4. **Anything that needs a real browser** — navigation, history, the URL as state, CSP, media playback,
   focus and keyboard journeys → **Playwright**.

Two rules that fall out of this:

- **Don't component-test Inertia pages in Vitest** just to check their props. That contract is already
  covered by `assertInertia`. Test a page in Vitest for what PHP *cannot* see — that raw
  seconds/bytes/ISO-8601 are formatted in the reader's locale, that an untagged field disappears instead of
  rendering `null` — and cover the journey itself with a Playwright spec.
- **Don't fake what only a browser has.** If a test needs layout, scroll position or a real
  `IntersectionObserver`, mocking it means asserting the mock. `useStickyNav` and the tooltip's anchor
  positioning are left to Playwright for exactly this reason.

## Conventions

- **Name the behaviour, not the mechanics.** `it("drops the denominator when a multi-disc rip numbers past
  its own disc")`, not `it("returns a string")`.
- **Say *why* in the file's opening comment** — the risk the file is guarding. The docblock rule (CLAUDE.md →
  *Comments*) applies to test helpers as well.
- **Prefer a real failure over a green one.** A test that cannot fail is worse than no test; when a new suite
  lands, seed a mutation and confirm it is caught.
- **Never let a flaky test settle in.** Retries are off on purpose. Run a new end-to-end spec several times
  before trusting it. The fixture is fixed, so a spec that passes once should pass every time — which means
  an intermittent failure is a real race, not bad luck with the data.

---

## Timeouts, workers, and the serial dev server

`artisan serve` is PHP's built-in server: **strictly serial, one connection at a time**, shared by every
Playwright worker. That single fact explains most of what a flaky end-to-end run looks like here, so it is
worth stating with numbers.

Polling `/up` — Laravel's health route, which touches nothing, so a slow answer can only mean the server was
busy elsewhere — every 200ms through a whole run:

| | median | p90 | p99 | worst | over 1s | over 2s |
| --- | --- | --- | --- | --- | --- | --- |
| a normal three-worker run | 20ms | 421ms | 1.2s | **3.8s** | 13 | 3 |

A trace of a real failure says the same from the browser's side: multiple seconds of `wait` (time to first
byte) on a **static font** and on a **404**, released in batches as the queue drained. The app was never
wrong; it was waiting.

Three consequences, each a rule:

**Assertion budgets are generous on purpose.** Playwright's default 5s left about a second of headroom over
the worst measured stall, so anything that cost the machine a moment turned a correct app into a red run.
`playwright.config.ts` allows assertions 15s (four times the worst stall measured) and tests 60s. It hides
nothing: an assertion resolves the instant it is true, so a green run costs the same, and a broken app still
fails — which is why it is this rather than `retries`.

**A spec-local `{ timeout: … }` shadows all of that**, and the specs most likely to carry one are the ones
that need the budget most: "wait for real audio to start or advance" is the most load-sensitive assertion in
the suite, because what it waits for is the machine actually decoding. Those pass no `timeout` at all and
inherit the configured budget, so there is one number to tune instead of twelve. An explicit timeout is for a
*different intent* — waiting for something to go **away**, where a long budget would just be a long wait.

**`PHP_CLI_SERVER_WORKERS` is the obvious fix and it is worse.** A serial server invites more workers, and
on the real suite four of them scored **6 failures against 1**, with the worst stall up from 3.8s to
**10.2s**. The median improves and the *tail* is what fails tests: four PHP processes contend for one sqlite
file and a blocked writer waits out `DB_BUSY_TIMEOUT`. Do not enable it without measuring the tail — and a
synthetic probe will not show this, because `curl` closes its connection immediately and never reproduces
what a browser holding one open does.

**Two workers rather than three, because it is free.** Six interleaved full runs, three at each setting: 5.6
minutes average at two against 5.5 at three. No cost, because the suite waits on the serial server rather
than on CPU — so the third worker buys nothing and can only add contention.

**And a one-shot read cannot wait, whatever the timeout is.**
`expect((await locator.allInnerTexts()).map(…)).toStrictEqual([…])` resolves the array FIRST and asserts on a
plain value, so nothing retries — read straight after a `reload()` it sees an empty page, and no timeout
could ever help it. **`await expect.poll(fn)`** is the fix; it retries within the same budget a web-first
assertion gets. Any `expect(await …)` that follows a navigation, a reload or a click is the next flake.

---

## Traps

Each of these failed in a way that pointed somewhere other than its cause. They are handled in code; this is
the record of *why* that code exists.

### Frontend unit

- **Module singletons leak between tests.** `useToast` and `useTooltipLayer` are module-level state (the
  no-Pinia store), so drain them in `beforeEach`. A still-mounted wrapper also re-renders when the singleton
  changes — assert one case fully, `unmount()`, then set up the next. (`useBreadcrumbs` writes to Inertia's
  layout-prop store instead, which `resetInertia()` empties; assert what a page published with
  `getLayoutProps().breadcrumbs`.)
- **A pending flush is test state.** `resetPlayerQueueForTests()` cancels the queue's write timer and clears
  its dirty set, because a write left scheduled by one spec fires during the next one and drops the previous
  queue into that spec's storage.
- **`vi.spyOn` returns the spy that is *already* installed**, and nothing here restores spies between tests —
  so a second `setItem` spy arrives pre-loaded with the previous spec's writes and a key-split assertion
  looks broken when it is not. Call `vi.restoreAllMocks()` in `beforeEach`.
- **happy-dom has no `localStorage`** (it does have `sessionStorage`), no `execCommand` and no Popover API.
  Polyfilled in `setup.ts`, *unconditionally*, because Node's own experimental `localStorage` prints a
  warning when merely **read**.
- **`attributes("xlink:href")` is always `undefined`** — the DOM keys namespaced attributes by local name
  (`href`). Use `iconNames()`.
- **`findAll("button")` sweeps up child components' buttons** (the pager's page-size `Select`) — scope to the
  component's own class.
- **`setValue()` on `<input type="range">` writes the value and dispatches nothing.** So the handler under
  test never runs and the assertion silently passes against an unchanged component. Set `element.value` and
  `trigger("input")` yourself (`PlayerTimeline.test.ts` has the helper).
- **happy-dom's `<audio>` is real enough to test a player against** — `play()` / `pause()` flip `paused` and
  fire their events, `currentTime` is writable, and media events can be dispatched by hand. What it has no
  decoder for is `buffered` (always empty) and `duration` (`NaN`); override those per test with
  `Object.defineProperty`, and leave anything that needs real bytes to Playwright.
- **Match a popover entry by its variant, not its position.** A positional `.popover-list-item` selector
  silently starts hitting a different entry the moment the menu gains one — and in Playwright it becomes a
  strict-mode violation instead. Use `--caution` / `--selected` or the accessible name.
- **DataTable renders BOTH layouts at once** — the desktop `<table>` and the narrow card list — from the same
  `#cell-*` slots. So an unscoped `findAll(".genre-songs__link")` returns every cell link twice, and "this row
  has one outbound link" passes against two. Scope cell assertions to `tbody`.
- **A teleported component is not inside its parent's wrapper.** `Modal` renders into `<body>`, so
  `wrapper.find()` reaches straight past it — and, worse, can match something with the same selector back in
  the host page (`DeleteAccount` has a submit button of its own). Query `document` for anything inside a modal
  or a toast.
- **`flushPromises()` does not settle a dynamic `import()`.** `LanguageSwitch` opens with one, so asserting
  after a single flush lands mid-handler — and the rest of it then runs *after* teardown, putting its `fetch`
  on the NEXT test's mock. That test fails, pointing nowhere near the cause. Warm the import in `beforeAll`
  and use `vi.waitFor` for the assertion.
- **A component that fetches on mount will hit a real port.** `TwoFactorModal` loads its QR code in
  `onMounted`, which surfaces as an `ECONNREFUSED` printed *after* a green run. Stub `fetch` in any file that
  mounts one.
- The project targets `lib: ES2020`, so **`Array.prototype.at()` is unavailable** in tests.

### Server

- **The test client sends `Accept-Language: en-us,en;q=0.5` whether you asked for it or not.** Symfony
  supplies it as a default server variable, and `ConfigureLocale` quite correctly honours the browser's
  stated preference — so **any assertion against rendered copy is silently English**, including one written
  for this app's German default, and it fails looking like a missing translation. Setting
  `config('app.locale')` does not help: the middleware's browser arm never reaches the fallback, and
  `app()->setLocale()` writes the config key back, so a value read after the request is the one the
  middleware chose rather than the one you set. Pin the header on the request (`SocialCardTest::visit()` is
  the pattern). It only bites tests that read the response **body** — `assertInertia` compares props, which
  are raw.
- **sqlite's default collation is `BINARY` — case-SENSITIVE — where production is not.** The taxonomy and
  collection `name` columns carry a case-insensitive ICU collation on Postgres. Leave the sqlite side at its
  default and, for two names differing only in case, the suite does the **opposite** of production: Postgres
  reuses the existing row (keeping its old spelling), sqlite mints a second row and prunes the first, so the
  name looks right and the id silently changed. Neither is wrong-looking on its own, which is why a test can
  pass while asserting the wrong engine's answer. Both migrations pin `nocase` on sqlite, and
  `LibraryScanServiceTest` asserts that precondition out loud so it cannot rot. **When a behaviour turns on
  string EQUALITY rather than on a `like`, check which driver's rules the test is really exercising.**
- **sqlite compares uuids as strings**, so a malformed one finds nothing where Postgres answers `invalid
  input syntax for type uuid` — a 500 from a query string anyone can type. Any endpoint putting a
  request-supplied id into a `whereIn` against a uuid column needs a FormRequest rule, and the PHP suite
  cannot prove why. Same class of difference as `lockForUpdate`, which sqlite also cannot exercise.
- **`?param=` reaches a FormRequest as `null`, not as `''`.** `ConvertEmptyStringsToNull` is global
  middleware, so an empty query parameter is already null by the time `prepareForValidation` runs — and a
  `sometimes|array` rule on it then answers *"kinds must be an array"* for a URL that was plainly not
  filtering at all. Normalise through `(string)` before splitting, so absent, `''` and null are one case
  rather than three.

### End-to-end

**Harness and infrastructure**

- **A stale `public/hot` blanks every asset.** Written by `npm run dev` and *not* removed when that server
  stops, so it usually outlives it; while it exists `@vite` points every asset at the URL it names and
  ignores the manifest — and every selector times out. Global setup parks a stale marker at
  `public/hot.e2e-backup` and restores it in teardown *and* at the next run's start, so a killed run
  self-heals. A **live** dev server is left alone.
- **…and a LIVE one means the run is not testing the built code at all** — it is testing whatever that dev
  server transforms, which is usually the same thing and sometimes is not. A dev server started before a new
  page existed served a *stale transform* of it, so a freshly-added guard was simply absent from the browser
  while `public/build` had it, and the page on screen disagreed with the source, the unit tests and the
  manifest all at once. Nothing about that failure points at the dev server. **When a browser check
  contradicts a Vitest assertion, look at where the module came from first** — `page.on("response", …)` and
  check the origin. To verify against the built assets, move `public/hot` aside for the run and put it
  straight back: `mv public/hot public/hot.mine && npx playwright test …; mv public/hot.mine public/hot`.
- **A cached config beats real environment variables**, silently pointing the run at the remote Postgres.
  Hence `config:clear` before migrating.
- **Seed with `E2ESeeder`, never the default `--seed`.** `DatabaseSeeder` runs `LibrarySeeder`, which is
  deliberately random (factories, `random_int`, `inRandomOrder`) and re-rolled on every run — right for a
  developer wanting a plausible library, wrong for a browser test, which then cannot name a song and meets
  thin edge cases unpredictably (a genre with one track, a page with a single row) as tests that fail once in
  twenty runs.
- **The web-server probe must not need the database.** Playwright brings the server up and waits for
  `webServer.url` BEFORE global setup migrates, so a probe pointed at a page fails the moment that page
  starts reading a table — which is exactly how a shared prop counting the library's media kinds can take the
  whole suite down on CI while passing locally against a database left over from the last run. The probe is
  `/up`, Laravel's health route. To reproduce that class of failure locally, `rm storage/e2e.sqlite` first: a
  stale one hides it completely.
- **Fortify throttles login at five attempts a minute per `username|ip`, counted in the CACHE** — so
  `migrate:fresh` does *not* reset it. A run signs in several times, so two runs inside a minute start
  getting 429s, which present as the login form silently doing nothing. Global setup clears it; **keep the
  number of real logins per run under five.**
- **`PRAGMA busy_timeout` must be set before `prepare()`, not after.** The app holds the same sqlite file
  open and `prepare` takes a lock of its own, so a timeout set after the prepares is not in force for the
  statement most likely to collide — it surfaces as a bare "database is locked" from a helper that looks
  like it handled exactly that.

**User-scoped state a spec writes**

- **Two workers on one session lose each other's flash messages.** Inertia carries validation errors *and*
  flash messages in the session, and Laravel does not lock it: every request reads the whole payload at the
  start and writes the whole payload at the end, so of two concurrent requests on one cookie, the later write
  silently discards whatever the earlier one flashed. A spec that submits an invalid form then asserts the
  error message therefore fails **on the assertion**, with the right URL and a clean form — which reads as a
  broken feature, not as a harness problem. Measured on the playlists spec: 10/10 green on one worker, 2-in-14
  red on three, 42/42 green on three once each worker had a session of its own.

  **A spec that writes through the app and then reads the flash needs an account of its own AND a
  file-scope mode.** The account removes the other specs; the mode removes its own tests, which under
  `fullyParallel: true` race each other just as happily.

  **Either mode groups the file onto one worker.** Playwright walks a test's ancestor suites for the
  outermost one whose mode is `serial` *or* `default`, and when it finds either, every test beneath it
  runs in a single worker in declaration order — the project being marked parallel for `fullyParallel`
  does not override that. So `mode: "default"` *is* enough to stop a file racing itself. What `serial`
  adds on top is that a failure **skips the rest of the file**: choose it where a failed write would
  make every later test in the file report a consequence rather than a cause (`playlists.spec.ts`),
  and `default` where the tests merely need not to overlap.
- **A fresh browser context is no longer a fresh PLAYER.** The queue syncs to `player_states`, so it follows
  the *user*, and a spec inherits whatever queue another one left. A spec that touches the queue needs all
  four of these, and each was found by the failure the last one left behind:
  1. **its own seeded account** — `test.use({ storageState: specStorageState("queue") })`;
  2. **`test.describe.configure({ mode: "default" })`**, because `fullyParallel` parallelises at the TEST
     level, so without it the file's tests run concurrently against the account they share;
  3. **`clearServerQueue("queue")` in `beforeEach`** — it writes an empty queue straight into sqlite,
     deliberately not through the app's PUT (an out-of-band request needs a session cookie *and* a CSRF token
     that still matches it, and a remember-me session gets a fresh one, so the request ends up redirected and
     answering 405);
  4. **`stopQueueSync(page)` in `afterEach`**, because a tab flushes its queue as it closes with
     `keepalive` — that request outlives the test and can land after the next one has reset.

  The symptom of missing any of them is a row count one too high, on a different test every run.
  `clearServerQueue` also **watches that its write stays written** — a request already past the abort lands a
  moment later, so it overwrites and re-reads until two reads agree.
- **The rule above is not about the queue.** It is about **user-scoped state a spec writes**, and the queue
  was only the first kind. A spec that creates playlists adds rows to the shared account's listing, which
  moves the coordinates another spec's DRAG test computes from its own three rows — so a new spec breaks a
  file it never touches, in a way that reads as a broken drag. Steps 1 and 2 (own account +
  `mode: "default"`) are the parts that generalise: a fixture nothing else can reach cannot be disturbed by
  what your spec leaves behind. Add the account to `SPEC_USERS` and to `E2ESeeder::seedSpecUsers` — the setup
  project mints a session for every entry automatically.
- **…and a third kind of user-scoped state is the SESSION ITSELF.** Nothing a spec *stores* need be the
  problem — Inertia's validation errors and flash messages live in the session, and two workers sharing one
  cookie lose one of the two writes. See the flash trap above, including why an account alone is not enough.
- **`page.close({ runBeforeUnload: true })` does NOT wait for the page to close** — Playwright's own docs say
  so, and the default (`false`) is the one that "does not run any unload handlers and waits for the page to be
  closed". Passing `true` in order to make the queue's flush happen *inside the test that owned it* has the
  opposite effect: the flush fires at an unknowable moment afterwards, often during the NEXT test's
  `beforeEach`. **And the server's stale-stamp guard cannot refuse that one**, which is the half worth
  remembering: `flushQueueWrites` stamps `updatedAt` with `Date.now()` at FLUSH time, not when the queue
  changed, so a flush that lands after a reset carries a newer stamp than the reset did and wins. It surfaces
  as one spec queueing three and the next test in the file counting five where it had queued two — one failure
  in a full run, in a test that touches none of it. Close without unload handlers, so the last breath never
  happens.

**Selectors, actions and timing**

- **A `deep` watcher fires on every RE-RUN, not on a change** — and only this layer can see it. Vue's `deep`
  flag sets `forceTrigger`, so `watch(getter, cb, { deep: true })` calls back whenever any dependency is
  replaced, identical contents or not. The DataTable used one to "clear the selection on sort/search/filter,
  preserve it across page changes"; since every visit is `preserveState: true` the component survived paging
  but the fresh `response` prop re-ran the effect, so paging silently wiped the selection — the exact
  opposite of the line above it. There is no navigation and no server in happy-dom, so the whole distinction
  is unobservable there. **When the question is "did this value actually change?", compare a serialised
  value; `deep` answers a different question.**
- **A hidden input is not the control.** `Checkbox.vue` renders the native `<input>` at `opacity: 0` and zero
  size and styles the adjacent `<label>` as the box, so clicking the input fails as "element is not visible"
  — which reads as the column being absent rather than as the selector aiming one node off. Target the label.
- **A selector that exists on BOTH pages resolves before the navigation finishes.** `waitForURL` returns when
  Inertia updates the address, which is *before* the component has swapped — so a locator matching something
  the old page also has answers with the old page's copy. A page-heading helper broadened from
  `.hero-section__title` to `main h2` broke eleven specs at once, all of them looking like the player had
  loaded the wrong track, because every listing has an `<h2>` too. Scope such a helper to something only the
  destination can have (`main:has(.hero-section) h2`), which restores the wait the narrower selector gave for
  free.
- **After a navigation, `page.mouse` fires into the VIEW TRANSITION and nothing happens.** `main.ts` opts
  every navigation into the View Transitions API, and while one runs the browser paints
  `::view-transition-*` — a pseudo tree belonging to the **root** — over the whole page in the top layer. Hit
  testing lands on that, so `document.elementFromPoint` returns the `<html>` element at *every* coordinate on
  the page, including (5, 5), while `getBoundingClientRect()` on the element you were aiming at reports
  exactly the rectangle you expect. Measured: immediately after navigating every probe answers `HTML`; 700ms
  later the same points answer with the real element. Locator actions ride it out, retrying until the element
  genuinely receives pointer events — **anything through `page.mouse` does not**, it fires once into the
  snapshot. Call `settled(page)` after the navigation and before any raw pointer work (a drag by a grip, a
  click at a measured offset). It cost an hour twice before being understood: once as a SortableJS drag that
  silently refused to start, once as a click on a row that plainly had a stretched link under it — both
  presenting as broken *features* rather than as mis-timed input. Prefer a locator action with `{ position }`
  over raw coordinates where one will do; it waits *and* verifies the element is what receives the press.
- **A stretched overlay button refuses every action aimed at what it covers.** Two components make their
  whole surface one target that way — a queue row (`.play-queue__load`) and a neighbour card on Now Playing
  (`.neighbour__step`) — so `hover()` or `click()` on the title, a fact chip or the artist line fails
  actionability with *"…intercepts pointer events"*, which reads as a broken selector rather than as a
  deliberate design. Aim at the overlay, or at the card itself (a descendant satisfies the hit-target check).
  The pattern exists because a `<button>` cannot hold a heading — ARIA prunes its descendants — so expect
  more of it, not less.
- **A zero-height `[popover]` layer CLIPS its own panel, and the failure reads as a broken selector.** The
  overlay pattern this app uses twice — a full-bleed fixed "layer" with `pointer-events: none`, holding an
  absolutely-positioned panel — depends on the layer having a real height. Give it `bottom: auto` and its
  height is 0 (its only child is out of flow), and the UA stylesheet's `[popover] { overflow: auto }` then
  clips the panel away. Playwright still sees the panel as **visible** (it has a bounding box), so
  `toBeVisible()` passes and every `click()` inside it fails with *"…intercepts pointer events"* naming the
  header or `.app-body`. Both halves are the fix: span the layer to the window (it passes clicks through
  anyway) **and** set `overflow: visible`.
- **A popover must be STILL before it is measured.** Panels open with a `rotateY`, and a transform is
  included in `getBoundingClientRect` — so a box read on the click is a couple of pixels from where it lands.
  `:popover-open` and visibility are both true from the first frame, so neither is the thing to wait for. Use
  `openPopover` (`player.spec.ts`), which waits for two identical boxes in a row; without it a geometry
  assertion fails by 1.3px on one run and 2.9px on the next, against positioning code that has not changed.
- **A computed COLOUR read straight after a hover or a focus is mid-transition.** Every interactive surface
  here transitions `background-color` over ~150ms, and `getComputedStyle` during that window returns the
  interpolated value — so a probe taken immediately after `hover()` reports the RESTING colour and the rule
  looks as though it never applied. It survives being "confirmed" by swapping the target colour for `red`:
  the reading comes back one frame of red, which reads as noise rather than as a clue. **Wait out the
  transition (or assert with `expect.poll`) before reading a colour**, the same rule as measuring a popover's
  box.
- **`waitForResponse` is not `waitForRepaint`.** A helper that returns as soon as the search endpoint answers
  lets a one-shot `page.evaluate` read the DOM before Vue has painted the rows — it fails as *"expected
  Künstler, got undefined"* in a full parallel run and passes every time in isolation. A `locator` assertion
  retries and would ride it out; a single `evaluate` gets one look. **Wait for something the answer put on
  screen** before reading the DOM by hand.
- **A `waitForResponse` on url + method matches a PRECOGNITION request, not your write.** Every Precognition
  form validates against its OWN endpoint with its own verb — on a playlist form, `PUT /playlists/{id}` with
  `Precognition: true` and `Precognition-Validate-Only: description`, fired by the `change` event that
  `fill()` itself dispatches. So the matcher resolves on a request that saves nothing, the test walks on
  believing the write landed, and it fails later on stale-looking data with the cause several steps behind
  it. Match the write: `X-Inertia` present and no `precognition` header (`isWrite()` in `playlists.spec.ts`).
  The same applies to counting requests against a rate limit — a two-field form spends **three** of the
  route's budget per save, since `throttle:` sits in front of the precognition middleware.
- **A hover `prefetch` can re-create the page you are already on**, which loses any state the page holds —
  and in a form, saves the value the server sent instead of the one just typed. A click that outruns Inertia's
  hover timer sends its own request, so the prefetch is neither cached nor consumed; when its response lands,
  `Response.handlePrefetch()` calls `handle()` because the URL now matches the current location, and the swap
  re-keys the page component. Playwright's `click()` hovers and clicks in one motion, so a test hits this far
  more often than a human does: it cost one full run in five for weeks, presenting as a stale row on a
  listing. Found by probing the `<form>` element's identity every 10ms (it changed 12–20ms after `fill()`),
  and by holding a prefetch back two seconds on purpose to prove the response alone was innocent. The rule
  that came out of it is in [`architecture.md`](architecture.md) → *Internal navigation links*.
- **`framenavigated` fires for `history.replaceState`**, so it cannot prove a tab change costs no round trip.
  Count `document` requests (`countDocumentRequests`).

**Reading the page**

- **Assert ordering on a numeric column, never text.** The server sorts through the database's collation and
  the app's accent-folded `name_fold` columns; `localeCompare` does not reproduce that. Sort by duration and
  compare seconds.
- **A sortable `<th>` contains hidden text** — its innerText is really `"Album\nSorted by album,
  ascending"`, the sort state announced for screen readers. Match the first line only (`columnValues` does).
- **Column 1 is not always the title** — the albums listing and a genre's songs tab both lead with a cover
  cell. Find columns by header.
- **`getByRole("heading", { level: 1 })` is ambiguous** — the app header renders the wordmark as an `<h1>`
  too. Use `pageHeading()`.
- **`getByLabel(/Passwort/)` is ambiguous** — it matches the input *and* the "show password" reveal button.
  `signIn()` uses ids.
- **`allInnerTexts()` reports `text-transform`, `allTextContents()` does not.** A heading styled
  `text-transform: uppercase` comes back as `"KÜNSTLER"`, so an assertion against the word as the catalog
  spells it fails looking like a missing translation. Use `allTextContents()` whenever the expectation comes
  from an i18n key.
- **Never poll an `m:ss` readout to prove time is passing.** The fixture's audio is ONE SECOND long, so the
  player's clock says `0:00` for the whole of it, and the only window saying anything else is the sliver
  between 1.0s and the `ended` that wraps. Polling that readout is a coin flip — heads on an idle machine,
  tails under a full suite. Assert the range input's `inputValue()` instead: same number, unrounded, and it is
  what a screen reader is given anyway.
- **Lazy, hidden images are legitimately "incomplete"** — covers are `loading="lazy"` and the discography
  renders row *and* card artwork with one `display: none`. Only a **visible** image that failed is broken,
  and check after `waitForLoadState("networkidle")`.
