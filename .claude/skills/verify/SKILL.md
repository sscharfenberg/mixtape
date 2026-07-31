---
name: verify
description: Stand up MixTape locally and drive it in a real browser to verify a change end-to-end.
---

# Verifying MixTape changes in the browser

Runtime verification recipe for this repo (Laravel 13 + Inertia v3 + Vue 3 + TS).
No Playwright / chromium-cli / puppeteer is installed — drive Chrome directly over
the DevTools Protocol from Node (Node ≥ 22 exposes global `WebSocket` + `fetch`).

## Stand up the app (throwaway, no remote box)

The committed `.env` points at the remote dev box (its own host, Postgres, SMTP, secure
cookies). Do **not** edit `.env` — override via real env vars (Laravel's dotenv is
immutable, so real env vars win). Use a throwaway sqlite file and the **built** assets
(run `npm run build` once; with no `public/hot` file, `@vite` serves from the manifest,
so you don't need the Vite dev server and avoid its proxied origin/HMR settings).

Keep the overrides in a shell **array** and expand it quoted — see the `env $ENV` gotcha
below for why the obvious string form silently does nothing in zsh.

```bash
DB=/tmp/mixtape-verify.sqlite; : > "$DB"
MT_ENV=(
  APP_ENV=local APP_DEBUG=true APP_URL=http://localhost:8000
  DB_CONNECTION=sqlite DB_DATABASE="$DB"
  SESSION_DRIVER=file SESSION_SECURE_COOKIE=false MAIL_MAILER=log
)
env "${MT_ENV[@]}" php artisan config:clear
env "${MT_ENV[@]}" php artisan migrate:fresh --seed   # seeds Ashaltiriak / passwort (pre-verified)
npm run build                                          # if frontend changed; leaves public/build
[ -f public/hot ] && mv public/hot /tmp/hot.bak        # see the public/hot gotcha below
env "${MT_ENV[@]}" php artisan serve --port=8000 --no-reload &   # detached; curl /login for 200
```

Confirm the overrides actually landed before trusting anything downstream — a silently
ignored `DB_CONNECTION` means you are driving the **remote dev box**, not sqlite:

```bash
env "${MT_ENV[@]}" php artisan tinker --execute="echo config('database.default');"   # => sqlite
```

Seed login: **`Ashaltiriak` / `passwort`** (email pre-verified). Login is by `name`, not email.

## Tear down

Leaving `public/hot` moved aside breaks the user's next `npm run dev` asset URLs, so
restore it even if the run failed:

```bash
pkill -f "artisan serve --port=8000"; pkill -f "remote-debugging-port=9222"
[ -f /tmp/hot.bak ] && mv /tmp/hot.bak public/hot
rm -f /tmp/mixtape-verify.sqlite
```

## Drive it (headless Chrome + CDP)

Launch Chrome headless with `--remote-debugging-port`, `GET /json` for a page target's
`webSocketDebuggerUrl`, connect a `WebSocket`, then use `Runtime.evaluate` (DOM drive),
`Input.insertText` (typing), and `Page.captureScreenshot` (evidence). Set `<input>`
values via the native value setter + dispatch `input`/`change` so Vue `v-model` updates:

```js
const s = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
s.call(el, val); el.dispatchEvent(new Event('input', { bubbles: true }));
```

A worked example driving the whole 2FA lifecycle lives in git history / the job scratch
as `verify2fa.mjs` (login → enable → QR+secret → TOTP confirm → recovery codes → logout
→ challenge → disable).

## Gotchas

- **`env $ENV …` silently applies NOTHING in zsh** — this repo's shell. zsh does not
  word-split unquoted parameter expansions (bash does), so `ENV="A=1 B=2"; env $ENV cmd`
  passes ONE argument and `env` sets a variable *named* `A` to the value `"1 B=2"`; `B` is
  never set at all. Applied to this recipe that means `APP_ENV` swallows the whole string
  and `DB_CONNECTION` is never set, so artisan quietly falls back to `.env` and hits the
  remote Postgres — presenting as `SQLSTATE[08006] connection refused … Database:
  mixtape_dev` from a command that looks like it asked for sqlite. Use the quoted array
  above (`env "${MT_ENV[@]}" …`, portable to bash), or repeat the assignments inline.
  Also note `ENV` itself is a poor name: in zsh/ksh it names a startup file to source,
  hence `MT_ENV`.
- **A stale `public/hot` hijacks the assets.** The file is written by `npm run dev` and
  gitignored, but it is NOT removed when that server stops — so it often survives. While
  it exists `@vite` points every asset at the dev-server URL it names (on a remote dev box,
  `VITE_SERVER_ORIGIN`) and ignores your fresh `npm run build` manifest entirely. Check
  whether anything is actually listening before touching it (`nc -z localhost 5173`) —
  if the user has `npm run dev` running, leave it alone and drive that instead; if it is
  stale, move it aside and **restore it in teardown**.
- **`shouldRenderJsonWhen`** (bootstrap/app.php) renders JSON only for `api/*`,
  Precognition, or `wantsJson()`. A guest hitting a JSON `/user/*` route gets a **302
  redirect**, not 401 — assert accordingly.
- **OTP field styling is global** (`styles/components/form/_otp.scss`), not scoped:
  `vue-input-otp` renders its container/boxes outside the `.vue` style scope, so scoped
  rules never reach them. Same applies to any third-party-rendered DOM.
- **2FA TOTP**: compute in Node (no lib needed — base32-decode + HMAC-SHA1). Fortify's
  `verifyKeyNewer` allows a ±1 step window **and** rejects a replayed (already-used)
  code, so on a flaky boundary, retry with a code from the **next** 30s window rather
  than resubmitting the same one.
- Login/2FA-challenge use a **fetch-JSON** flow (useLogin.ts) — a 2FA user's `/login`
  returns `{ two_factor: true }` and the challenge stays on the login page.
