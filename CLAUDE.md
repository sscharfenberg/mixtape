# CLAUDE.md — MixTape v2

Steering context for this repo. Kept lean on purpose — detailed design lives in the `docs/` folder
(linked at the bottom).

## What this is

**MixTape** is a self-hosted web app that organizes a personal mp3 / audiobook collection and plays it
in the browser. It runs on the home server **"debbie"** and is **intentionally reachable from the
internet** — a DynDNS name points at the Fritzbox router, which port-forwards a non-standard port to
debbie — so the owner can share links to music with family and friends. Access is gated by auth.

**mixtape.v2** is a **ground-up rewrite** of the existing app. The legacy code is the sibling folder
**`../MixTape`** (newer than the public GitHub repo) — read it for behaviour, the data model, artisan
commands, and `config/collection.php`, then re-implement on the new stack. It is **reference only**;
this repo starts clean.

## Two phases (Phase 1 first)

1. ✅ **Rebuild debbie** — **DONE & verified 2026-06-28.** Fresh Debian on plain LVM (large `/var`),
   hardened, services up (PostgreSQL 17 / php-fpm 8.4 / nginx / Samba), collection restored, PoC proven.
   Spec + design in `../mixtape-ops/server-requirements.md` (**untracked**, see *Docs*).
2. ⬜ **Rewrite the app** — **NEXT.** New design; Inertia v3 instead of the REST API; composables-first
   Vue + TS. See [`docs/app-rewrite.md`](docs/app-rewrite.md); public go-live in
   `../mixtape-ops/phase-2-go-live.md` (**untracked**).

Phase 1 was done first — no point deploying new app code onto the old host.

## Load-bearing decisions

**Server (debbie)**

- Latest stable Debian, minimal install; **LVM** with a **large `/var`** and a small `/home`.
- **Nginx + php-fpm**, HTTPS via **Let's Encrypt (certbot)**; media library at **`/var/media`**.
- **Internet-facing by design** on **443/HTTPS** (clean links), but **only the web ports (80/443) are
  forwarded** — SSH, Samba, and the database stay **LAN-only and must never be exposed.**
- **Back up the media collection before wiping** (the DB is rebuilt from it via artisan in ~40 s, and
  there's no user data yet, so it needs no backup). The reinstall wipes **only the NVMe** (plain LVM, no
  disk encryption); the 2 TB USB drive (`NAS-Backup`) is the backup target. Wipe/repartition only after
  a verified backup.

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
- **Destructive ops on debbie are high-stakes** — anything that wipes/repartitions comes _after_
  verified backups. Confirm before irreversible steps.
- **Prefer the new idioms** (Inertia + composables) over the legacy API / Vue-Router / store-everything
  patterns, even when porting.

**Linting the frontend** — use **`npm run lint`** (runs ESLint then Stylelint, both with `--fix`).
Don't invoke `eslint` / `stylelint` directly. `npm run build` runs the same lint first, so a lint
error fails the build before anything compiles. **Always run `npm run lint` after editing any
frontend file (Vue / TS / SCSS) — before calling a change done — so the build stays green.**

**Pages (Inertia)** — every page is its own directory with a `*Page` entry file:
`pages/Home/HomePage.vue` (not `pages/Home.vue`), holding page-local `components/`, composables, tests.
Controllers render the explicit path — `Inertia::render('Home/HomePage', …)` — and prefer an invokable
(`__invoke`) controller for single-action pages. Full rationale:
[`docs/app-rewrite.md`](docs/app-rewrite.md) → *Frontend conventions*.

**Design tokens (SCSS)** — three layers, two hard rules. **Every** token group is identical:
**global tokens → contextual partial (components/pages) → consumed by SCSS/Vue.** Applies today to
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
- To give a component/page a colour, size, or z-index, **create a contextual partial**
  (`colors/components/_button.scss`, `sizes/pages/_home.scss`, `z-indexes/components/_main.scss`) that
  `@use`s the globals and **picks/themes** the value (`light-dark()`, `map.get($scale, …)`, opacity-only
  `color.adjust()`), then `@forward` it from that folder's `_index.scss` (one line).
- Components/pages **consume only contextual tokens** via the entrypoint: `@use "Abstracts/colors" as c;`
  → `c.$c-button`; `@use "Abstracts/sizes" as s;` → `s.$c-button`; `@use "Abstracts/z-indexes" as z;`
  → `z.$c-main` (`c-*` = component, `p-*` = page). Timings use `@use "Abstracts/timings" as ti;` → `ti.$c-*`.

**Motion (transitions & animations)** — **every `transition` must live inside
`@media (prefers-reduced-motion: no-preference) { … }`**, so a user who asks to reduce motion gets none.
The guard is written positively (motion is *opt-in* via `no-preference`) rather than as a `reduce`
opt-out, so motion is also off wherever the preference is unknown/unsupported. Continuous decorative
`animation`s (e.g. the rotating icon) take the same guard. Durations always come from the `timings/`
tokens (`ti.$c-*`), **never raw `ms`/`s`**. One deliberate exception (by design, not omission): the
**loading spinner keeps turning even under reduced motion** — a frozen spinner reads as broken — but it
runs *much slower by default* and only switches to the lively duration under `no-preference`.

## Docs

> **Home-infrastructure docs are deliberately NOT git-tracked.** This repo is public and documents
> the *app*, not how one home server was built. Anything describing debbie — host spec, network,
> exposure, deploy procedure — lives in the **`../mixtape-ops/`** sibling folder (untracked, alongside
> the legacy `../MixTape` clone). Don't move it back in, and don't add host names, LAN addresses, or
> server runbooks to tracked files.

**Server / operations (untracked — `../mixtape-ops/`):**

- `server-requirements.md` — server requirements & design (role, hardware, OS, LVM, stack,
  network/exposure, security, backups + the *why*).
- `phase-2-go-live.md` — ordered **go-live runbook**: TLS, real-domain CNAME→DynDNS, Mailtrap +
  SPF/DKIM/DMARC, router forward + firewall widen, backup alerting. **Auth must be in force before
  any exposure.**
- `RUNBOOK-step0.md` + `mixtape.prod.nginx.conf` / `mixtape-prod.pool.conf` / `env.prod.template` /
  `mixtape-deploy.sudoers` / `mixtape-prod-deploy.sh` — how the production site is built and deployed.
- `docs/debbie-infrastructure.local.md` — still in `docs/` but **gitignored** (`*.local.md`): the
  *concrete* live box (LAN topology, disks, services, secret **locations**).

**App (Phase 2 — next):**

- [`docs/app-rewrite.md`](docs/app-rewrite.md) — the rewrite: stack, goals, features, access model, legacy map.
