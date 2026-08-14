# MixTape

**MixTape is a self-hosted web app that organises a personal MP3 and audiobook collection and plays it
in the browser.** It runs on one always-on machine you own, and it is deliberately reachable from the
internet, so you can send someone a link to a song without giving them an account.

## Why it exists

A large local music collection is a pile of tagged files. Everything that makes it *usable* — browsing
by artist, finding a record, keeping a playlist, remembering where you got to in a 673-chapter
audiobook, playing it on a phone with the screen off — is a database and a UI over those files. The
commercial answer is to give the collection to a streaming service. This is the other answer.

Three things follow from that, and they shape almost every decision in the codebase:

- **The files are the truth, not the database.** The database is an index, rebuilt from disk by one
  artisan command. It is *derived* — which is why identity has to survive a rename and a re-tag, why
  the collection is the only thing whose loss is permanent, and why the schema is content-addressed
  rather than path-addressed. See [`docs/data-model.md`](docs/data-model.md).
- **It is internet-facing on purpose, for an audience of family and friends.** So access is controlled
  (invite-only accounts, optional 2FA) but sharing must not require an account: a share link is a
  revocable, expiring capability that plays one subject and nothing else. See
  [`docs/sharing.md`](docs/sharing.md).
- **It runs on a home server behind a domestic uplink.** That is why audio is handed off to nginx
  rather than streamed through PHP, why the player shows you what it has buffered, and why "does this
  keep playing with the phone's screen off" is a headline feature rather than a nicety. See
  [`docs/player.md`](docs/player.md).

## Stack

Laravel 13 / PHP 8.4 · **Inertia.js v3** (no REST API, no client-side router) · Vue 3 + TypeScript,
composables-first (no Pinia) · SCSS with a layered design-token system · Vite · PostgreSQL 17 ·
Laravel Fortify for authentication.

Read [`docs/architecture.md`](docs/architecture.md) first — it explains the wiring, the page and
component conventions, and the rules that are load-bearing rather than stylistic.

## Access model

Open registration is disabled. New accounts are created only by redeeming a **one-time, expiring
invite link** minted with [`php artisan app:invite`](docs/artisan-commands.md#appinvite), and a new
account must confirm its email address before it can log in. Two-factor auth is available and never
forced.

Anything in the library can additionally be handed out as a **share link** — a `/s/{uuid}` URL that
plays one song, album, audiobook, artist or playlist with no account, expires after seven days, and
can be revoked at any time.

## Running it

**Locally, with Docker** — Postgres, PHP-FPM, nginx, a seeded demo library and a live-reloading Vite
server, all in one command. See [`docker/README.md`](docker/README.md).

**Locally, without Docker:**

```bash
composer install
npm install
php artisan migrate       # against a configured database
npm run dev               # Vite dev server
```

**On your own server** — the whole path from bare hardware to a public, TLS-secured, invite-only
instance is [`docs/self-hosting/README.md`](docs/self-hosting/README.md).

Useful scripts:

```bash
npm run lint          # ESLint + Stylelint, both with --fix. Gates `npm run build`
npm run build         # lint + vue-tsc + Vite build
npm run icons         # rebuild the SVG icon sprite (gitignored, NOT part of the build)
php artisan test      # server suite
npm run test:unit     # frontend unit suite
npm run test:e2e      # end-to-end suite
```

## Documentation

### Start here

| | |
| --- | --- |
| [`docs/architecture.md`](docs/architecture.md) | How the app is wired: the stack and its rules, the access model, the page/component conventions, playlists and downloads |
| [`docs/data-model.md`](docs/data-model.md) | The schema and the reasoning: content-hash identity, the unified `tracks` / `collections` tables, foreign keys, indexes, playlists vs. the play queue |
| [`docs/testing.md`](docs/testing.md) | The three test layers, which one to reach for, and the traps each hides |
| [`CLAUDE.md`](CLAUDE.md) | Repo conventions: form requests, rate limiting, design tokens, motion, comments, linting |

### Features

| | |
| --- | --- |
| [`docs/player.md`](docs/player.md) | The audio element, the stream route, the transport and keyboard map, playback speed, background playback, what counts as a play |
| [`docs/play-queue.md`](docs/play-queue.md) | What the queue holds, shuffle, its storage and server sync, the panel and reordering |
| [`docs/now-playing.md`](docs/now-playing.md) | The Now Playing page and its EQ visualiser |
| [`docs/audiobooks.md`](docs/audiobooks.md) | The audiobooks area, and per-book resume |
| [`docs/search.md`](docs/search.md) | Cross-kind search: the own-name rule, ranking, the kind registry |
| [`docs/sharing.md`](docs/sharing.md) | Account-free share links: the grant, the `/s/` space, minting, revoking, link previews |
| [`docs/i18n.md`](docs/i18n.md) | German / English, and how to add a language |
| [`docs/artisan-commands.md`](docs/artisan-commands.md) | The project's `app:*` commands — the library scan, invites, the encoding audit |

### Self-hosting

| | |
| --- | --- |
| [`docs/self-hosting/README.md`](docs/self-hosting/README.md) | Overview, the order to work in, and a symptom → cause index |
| [`01-requirements.md`](docs/self-hosting/01-requirements.md) | Hardware, OS, storage layout, stack, network and exposure model, security posture |
| [`02-host-setup.md`](docs/self-hosting/02-host-setup.md) | Debian, networking, firewall, SSH, PostgreSQL, nginx/PHP, Samba, TLS on the LAN |
| [`03-production-deploy.md`](docs/self-hosting/03-production-deploy.md) | The deploy ownership model, first deploy, routine deploys, rate limiting, scheduled jobs |
| [`04-going-public.md`](docs/self-hosting/04-going-public.md) | Domain, port-forward, Let's Encrypt, transactional mail, login hardening, backup alerting |
| [`docs/self-hosting/files/`](docs/self-hosting/files) | Installable configs — nginx vhosts, fpm pool, sudoers, deploy scripts, systemd units, `.env` template |
| [`docker/README.md`](docker/README.md) | The local Docker development stack |

### Frontend reference

| | |
| --- | --- |
| [`styles/abstracts/README.md`](resources/app/styles/abstracts/README.md) | The design-token system: global → contextual → consumed, and the two hard rules |
| [`DataTable/README.md`](resources/app/components/DataTable/README.md) | The listing surface: sort, search, paginate, all server-driven through the URL |
| [`UI/Card/README.md`](resources/app/components/UI/Card/README.md) | The surface every block on a page sits on |
| [`UI/Widget/README.md`](resources/app/components/UI/Widget/README.md) | A card with a heading and a mode toggle |
| [`UI/TabbedNavigation/README.md`](resources/app/components/UI/TabbedNavigation/README.md) | Tabs, their ARIA contract, and keeping the open tab in the URL |
| [`UI/Tooltip/README.md`](resources/app/components/UI/Tooltip/README.md) | The `v-tooltip` directive and its single shared layer |
| [`Music/CoverImage/README.md`](resources/app/components/Music/CoverImage/README.md) | All artwork goes through it — never hand-roll an `<img>` for a cover |
| [`Music/Discography/README.md`](resources/app/components/Music/Discography/README.md) | A short, unpaginated cover grid for a block inside a page |
