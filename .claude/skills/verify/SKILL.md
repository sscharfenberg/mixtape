---
name: verify
description: Stand up MixTape locally and drive it in a real browser to verify a change end-to-end.
---

# Verifying MixTape changes in the browser

Runtime verification for this repo (Laravel 13 + Inertia v3 + Vue 3 + TS). **Playwright
drives the browser and stands the app up** — there is no manual `artisan serve`, no sqlite
juggling and no hand-rolled DevTools-Protocol script any more. All of that lives in
`playwright.config.ts` + `tests/e2e/support/`.

## The fastest path

```bash
npm run test:e2e            # whole suite, headless
npm run test:e2e -- --headed          # watch it happen
npm run test:e2e -- --ui              # time-travel debugger, pick individual tests
npm run test:e2e -- tests/e2e/app/tabs.spec.ts       # one file
npm run test:e2e -- -g "opens a song"                # one test by name
npx playwright show-report            # last run's HTML report
npx playwright show-trace test-results/<dir>/trace.zip   # a failure, step by step
```

Everything is automatic: a throwaway sqlite at `storage/e2e.sqlite` is truncated and
`migrate:fresh --seed`ed, assets are built if `public/build/manifest.json` is missing, the
app is served on **:8100** (deliberately not 8000, so a hand-started `artisan serve`
survives), and a signed-in session is minted once and reused.

Seed login: **`Ashaltiriak` / `passwort`** (email pre-verified). **Login is by `name`, not
email** — `Fortify::username() === 'name'`.

## Verifying a change that has no test yet

Write a throwaway spec rather than a script. Put it in `tests/e2e/app/` (signed in) or
`tests/e2e/guest/` (no session) — the project is chosen by directory, so a guest spec can
never accidentally inherit a stored login.

```ts
import { expect, test } from "@playwright/test";
import { columnValues, pageHeading } from "../support/actions";

test("the thing I just changed", async ({ page }) => {
    await page.goto("/music/songs");
    await expect(page.locator("tbody tr").first()).toBeVisible();
    await page.locator("tbody tr").first().click();
    await expect(pageHeading(page)).toBeVisible();
    await page.screenshot({ path: "/tmp/evidence.png", fullPage: true });
});
```

Delete it afterwards, or keep it if it earns its place. Console errors and failed requests
are worth asserting on while you are there:

```ts
const errors: string[] = [];
page.on("pageerror", e => errors.push(e.message));
page.on("console", m => m.type() === "error" && errors.push(m.text()));
// ...
expect(errors).toEqual([]);
```

## Helpers worth knowing (`tests/e2e/support/actions.ts`)

- `signIn(page)` — the login form, for real. Fields are addressed by `id`, because
  `getByLabel(/Passwort/)` is **ambiguous**: it matches the input *and* the "Passwort
  anzeigen" reveal button, which is a strict-mode violation.
- `columnValues(page, "Titel")` — a DataTable column, found **by header**. Do not index
  columns positionally: the songs table leads with the title, but the albums table and a
  genre's songs tab both lead with a cover cell.
- `pageHeading(page)` — the page's own `<h1>`. A bare `getByRole("heading", {level: 1})`
  is ambiguous: the app header always renders the wordmark as an `<h1>` too.
- `clockToSeconds`, `expectOnTablePage`, `countDocumentRequests`.

## Gotchas

- **Assert ordering on a numeric column, never a text one.** The server sorts through the
  database's collation and the app's accent-folded `name_fold` columns; JavaScript's
  `localeCompare` does not reproduce that, so a title-order assertion is testing Node's
  collation rather than the app's. Sort by duration and compare seconds.
- **The E2E library is a FIXED fixture** — `database/seeders/E2ESeeder.php`, seeded
  explicitly instead of the default `--seed` (which would run the deliberately random
  `LibrarySeeder`). So you can name a song: "Paranoid Android" appears exactly once,
  "Fitter Happier" is the one untagged track, "Sigur Rós" is there for accent folding.
  Read that seeder's docblock before changing it — specs depend on its shape.
- **A sortable `<th>` contains hidden text.** Its innerText is really
  `"Album\nNach Album aufsteigend sortiert"` — the sort state, announced for screen
  readers. Match the first line only (`columnValues` already does).
- **`framenavigated` fires for `history.replaceState`.** It cannot be used to prove a tab
  change costs no round trip; count `document` requests instead (`countDocumentRequests`).
- **Wait on the pager, not on "a row is visible", after a page turn.** The old page's rows
  are still on screen and satisfy that immediately, so the assertion races and reads the
  wrong page. Use `expectOnTablePage`.
- **Lazy, hidden images are legitimately "incomplete".** Covers are `loading="lazy"` and
  the discography renders row *and* card artwork with one of them `display:none` — a
  hidden lazy image is never fetched. Only a **visible** image that failed is a broken one,
  and check it after `waitForLoadState("networkidle")`.
- **A stale `public/hot` blanks every asset.** The file is written by `npm run dev` and is
  *not* removed when that server stops, so it usually outlives it; while it exists `@vite`
  points every asset at the URL it names and ignores the built manifest. Global setup
  handles this: a **live** dev server is left alone (it serves assets fine), a **stale**
  marker is parked at `public/hot.e2e-backup` and restored in teardown — and restored at
  the *start* of the next run too, so a killed run self-heals. If you ever see a run where
  every selector times out, this is the first thing to check.
- **A cached config beats real environment variables.** Global setup runs `config:clear`
  before migrating for exactly this reason: a stale `bootstrap/cache/config.php` silently
  points the whole run at the **remote Postgres**, surfacing as a connection error from a
  command that plainly asked for sqlite.
- **`env $ENV …` applies NOTHING in zsh** (this repo's shell) — it does not word-split
  unquoted expansions, so `ENV="A=1 B=2"; env $ENV cmd` sets a variable *named* `A` to
  `"1 B=2"` and never sets `B`. Playwright passes env as a real object so this cannot bite
  inside the suite, but it still applies to any artisan command you run by hand: use
  `env "${MT_ENV[@]}" …` with an array, or inline assignments.
- **`shouldRenderJsonWhen`** (bootstrap/app.php) renders JSON only for `api/*`,
  Precognition, or `wantsJson()`. A guest hitting a JSON `/user/*` route gets a **302
  redirect**, not a 401 — assert accordingly.
- **OTP field styling is global** (`styles/components/form/_otp.scss`), not scoped:
  `vue-input-otp` renders its container/boxes outside the `.vue` style scope, so scoped
  rules never reach them. Same for any third-party-rendered DOM.
- **2FA TOTP**: compute in Node (base32-decode + HMAC-SHA1, no library needed). Fortify's
  `verifyKeyNewer` allows a ±1 step window **and** rejects a replayed code, so on a flaky
  boundary use a code from the **next** 30s window rather than resubmitting the same one.
- **Login and the 2FA challenge are a fetch-JSON flow** (`useLogin.ts`): a 2FA user's
  `/login` returns `{ two_factor: true }` and the challenge stays on the login page, so
  assert on rendered state rather than on a navigation.

## Verifying playback

The fixture now writes **real audio**: `seedMediaFiles` drops a copy of the committed
one-second mp3 at every path `E2ESeeder` claims, and `MIXTAPE_MUSIC_PATH` points at that
throwaway directory. So the stream route really streams, and `tests/e2e/app/player.spec.ts`
covers play/pause, auto-advance at a track boundary, repeat, seeking, the buffer indicator,
and playback under the **production CSP** (injected onto the document via `page.route`, so
the live policy is exercised without nginx).

Two things to know before writing a player check of your own:

- **The audio is one second long while the rows claim minutes.** Deliberate — a track that
  ends in a second makes auto-advance fast and deterministic — but it means the file's
  length and the row's duration DISAGREE. Never assert a position derived from the rail's
  width; that geometry is Vitest's. Anything that needs playback to still be running should
  turn **repeat** on first, or it races the end of the track.
- **Read the element, not the UI's claim about it.** `page.evaluate` on
  `document.querySelector("audio")` gives `paused`, `currentTime` and `buffered` — an
  `<audio>` without `controls` is `display:none`, so visibility-based APIs do not apply.
- For a screenshot that looks like a real track, generate a longer file by repeating the
  fixture's audio frames — and blank its CBR `Info` header first, or the decoder trusts that
  one-second frame count and stops there.

## What is NOT covered here

**The phone with its screen off** is the one check this machine cannot make. It is verified by
hand on a real Android phone — the playing track runs to its end with the screen off and the
next one starts by itself — and `/dev/audio-probe` is how to ask again (see `docs/player.md`).
iOS is not a target.

One trap worth knowing, because it will bite any layout check here: **a popover
must be still before it is measured.** Panels open with a `rotateY`, transforms are included
in `getBoundingClientRect`, and `:popover-open` is true from the first frame — so a box read
right after the click is a few pixels from where it lands, and the assertion passes or fails
on machine speed. `player.spec.ts`'s `openPopover` waits for two identical boxes in a row.
