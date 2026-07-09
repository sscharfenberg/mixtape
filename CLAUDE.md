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
   Spec + design in [`docs/server-requirements.md`](docs/server-requirements.md).
2. ⬜ **Rewrite the app** — **NEXT.** New design; Inertia v3 instead of the REST API; composables-first
   Vue + TS. See [`docs/app-rewrite.md`](docs/app-rewrite.md); public go-live in
   [`docs/phase-2-go-live.md`](docs/phase-2-go-live.md).

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

**Design tokens (SCSS)** — three layers, one hard rule. **Every** token group is identical:
**global tokens → contextual partial (components/pages) → consumed by SCSS/Vue.** Applies today to
`colors/`, `sizes/`, `z-indexes/`, `typography/`, `timings/`; future groups (`shadows/`, …) are created
the same way. Full guide: [`resources/app/styles/abstracts/README.md`](resources/app/styles/abstracts/README.md).

- **Never `@use`/read a global token (`_global-*-tokens.scss`) outside its token group.** Globals are the
  raw palette/scale (`$grey`, `$radius`, `$scale`, …) and stay private.
- To give a component/page a colour, size, or z-index, **create a contextual partial**
  (`colors/components/_button.scss`, `sizes/pages/_home.scss`, `z-indexes/components/_main.scss`) that
  `@use`s the globals and derives the value (`light-dark()`, `color.adjust()`, `map.get($scale, …)`),
  then `@forward` it from that folder's `_index.scss` (one line).
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

**Server (Phase 1 — built):**

- [`docs/server-requirements.md`](docs/server-requirements.md) — **server requirements & design** (role, hardware, OS, LVM, stack, network/exposure, security, backups + the *why*). Safe to commit.
- `docs/debbie-infrastructure.local.md` — **LOCAL / gitignored**: the *concrete* live box (LAN topology, disks, services, secret **locations**). Read this for the real values.

**App (Phase 2 — next):**

- [`docs/app-rewrite.md`](docs/app-rewrite.md) — the rewrite: stack, goals, features, access model, legacy map.
- [`docs/phase-2-go-live.md`](docs/phase-2-go-live.md) — ordered **go-live runbook**: Let's Encrypt TLS, real-domain CNAME→DynDNS, Mailtrap + SPF/DKIM/DMARC, Fritzbox forward + firewall widen, backup alerting. **Auth must be in force before any exposure.**
