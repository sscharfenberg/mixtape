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

```bash
DB=/tmp/mixtape-verify.sqlite; : > "$DB"
ENV="APP_ENV=local APP_DEBUG=true APP_URL=http://localhost:8000 \
DB_CONNECTION=sqlite DB_DATABASE=$DB SESSION_DRIVER=file SESSION_SECURE_COOKIE=false MAIL_MAILER=log"
env $ENV php artisan config:clear
env $ENV php artisan migrate:fresh --seed        # seeds Ashaltiriak / passwort (pre-verified)
npm run build                                     # if frontend changed; leaves public/build + no public/hot
env $ENV php artisan serve --port=8000 --no-reload &   # run detached; curl /login to confirm HTTP 200
```

Seed login: **`Ashaltiriak` / `passwort`** (email pre-verified). Login is by `name`, not email.

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
