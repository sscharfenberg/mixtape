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
  `.env` template), all with placeholders.

**This box (untracked — `docs/host.local/`):**

- `infrastructure.md` — the concrete live box: LAN topology, disks, services, secret **locations**.
- `go-live.md` — the go-live runbook with real values and per-step status.
- `live-configs/` — copies synced from the server, with real hostnames and cert paths.

**App (Phase 2 — next):**

- [`docs/app-rewrite.md`](docs/app-rewrite.md) — the rewrite: stack, goals, features, access model, legacy map.
