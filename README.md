# MixTape

**MixTape** is a self-hosted web app that organises a personal MP3 / audiobook
collection and plays it in the browser. It runs on a home server and is
intentionally reachable from the internet (behind auth) so the owner can share
links to music with family and friends.

This repository is **mixtape.v2** — a ground-up rewrite of the original app on a
new stack.

## Status

- **Phase 1 — server** ✅ Done. A fresh, hardened Debian host running
  PostgreSQL 17 / php-fpm 8.4 / nginx / Samba, with TLS and the media collection
  restored. See [`docs/server-requirements.md`](docs/server-requirements.md).
- **Phase 2 — app rewrite** 🚧 In progress. Inertia v3 + Vue 3 + TypeScript,
  composables-first, with Fortify auth and invite-only onboarding. See
  [`docs/app-rewrite.md`](docs/app-rewrite.md).

## Stack

- **Backend** — Laravel 13, [Inertia.js](https://inertiajs.com) v3 (no REST API),
  Laravel Fortify for authentication.
- **Frontend** — Vue 3 + TypeScript, SCSS with a layered design-token system,
  the `vidstack` player. Built with Vite.
- **Database** — PostgreSQL 17.

## Access model

Open registration is disabled. New accounts are created only by redeeming a
**one-time, expiring invite link** minted with
[`php artisan app:invite`](docs/artisan-commands.md#appinvite), and a new account
must **confirm its e-mail address** before it can log in. Shared songs / albums
can additionally be played via signed temporary URLs without an account.
Two-factor auth is available but never forced.

## Documentation

- [`docs/app-rewrite.md`](docs/app-rewrite.md) — the rewrite: stack, goals,
  features, access model.
- [`docs/artisan-commands.md`](docs/artisan-commands.md) — project-specific
  `app:*` artisan commands.
- [`docs/server-requirements.md`](docs/server-requirements.md) — server
  requirements & design.
- [`docs/phase-2-go-live.md`](docs/phase-2-go-live.md) — the go-live runbook.
- [`CLAUDE.md`](CLAUDE.md) — steering context & conventions (frontend lint, page
  structure, design tokens, motion).

## Development

```bash
composer install
npm install
npm run dev          # Vite dev server
php artisan migrate  # against a configured database
```

Frontend linting runs ESLint + Stylelint together (and gates `npm run build`):

```bash
npm run lint
```
