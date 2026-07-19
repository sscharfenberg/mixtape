# Application rewrite (Phase 2)

> Phase 2 of MixTape v2. See [../CLAUDE.md](../CLAUDE.md) for the overview. Docs for the host it
> deploys to are kept outside this repo — see [../CLAUDE.md](../CLAUDE.md) → *Docs*.

The legacy app (`../MixTape`) is already Laravel 12 + Vue 3.5 + TypeScript — but it's a **REST API**
consumed by a **separate Vue-Router SPA**. The rewrite changes the **architecture** (Inertia instead of
an API), the **structure** (composables-first), the **design**, and adds **real auth**. Since the
legacy app is already Vue 3 + TS + `<script setup>`, this is _not_ about "adding TypeScript".

## Target stack (informed by the legacy versions)

| Layer         | Legacy (`../MixTape`)                      | v2 target                                           |
| ------------- | ------------------------------------------ | --------------------------------------------------- |
| Backend       | Laravel 12, PHP ^8.2                       | **Laravel 13** (latest), PHP 8.4                    |
| Auth          | none real (`.htpasswd` at proxy)           | Fortify (opt-in 2FA), invites + signed links        |
| Bridge        | **REST API** (`api.php`) + Axios           | **Inertia.js v3** (controller props -> Vue pages)   |
| Routing       | Vue Router 4 (client SPA)                  | Server routes + Inertia pages (**drop Vue Router**) |
| Frontend      | Vue 3.5 `<script setup>`, TS 5.9           | Same Vue 3 + TS, **composables-first**              |
| State         | Pinia 3 (8 domain stores)                  | **Dropped for now** (may not need it at all)        |
| Build         | Vite 7, sass-embedded, laravel-vite-plugin | Same                                                |
| Player        | `vidstack`                                 | Keep (or re-evaluate)                               |
| Images / tags | `intervention/image`, `wapmorgan/mp3info`  | Keep                                                |
| Tooling       | ESLint 9, Prettier 3.6, Stylelint 16, Pint | Keep                                                |

## Rewrite goals

- **New design** (visual refresh — direction TBD).
- **Inertia-first data flow**: pages receive server props; forms use Inertia's form helpers; **do not
  re-introduce a bespoke API layer** or client-side routing.
- **Composables**: factor shared logic out of stores/components into composables.
- **Typed throughout**: preserve strict TS.
- Preserve the app's core value and the maintenance flows below.

## Frontend conventions

**Pages live in their own directory, with a `*Page` entry file.** Each Inertia page is a folder under
`resources/app/pages/` named after the page, containing a `<Name>Page.vue` entry plus any page-local
parts (`components/`, composables, tests):

```
resources/app/pages/
  Home/
    HomePage.vue        <- route entry
    components/         <- page-local components (when needed)
    useHomeData.ts      <- page-local composable (when needed)
```

- **The `Page` suffix marks the route entry** — in a populated folder it's instantly clear which file
  is the page vs. its co-located children, and it reads unambiguously in Vue devtools / stack traces.
- **Controllers render the explicit path**: `Inertia::render('Home/HomePage', [...])`. Kept explicit
  (not resolver magic) so it stays greppable end-to-end — search `HomePage` and you find both the file
  and the controller that renders it. The resolver in `main.ts` maps the name straight to
  `./pages/<name>.vue`.
- **Prefer an invokable controller** (`__invoke`) for a single-action page; group related actions in
  one controller otherwise.

## New and improved features (v2)

Most of these are **user-scoped**, so they build directly on the new per-user auth model:

- **User-specific playlists** — playlists belong to a user, not the whole instance. Extends the legacy
  `Playlist` / `PlaylistEntry` model with an owner (`user_id`); each account manages its own.
- **Improved search / filtering** — richer search across music and audiobooks (beyond the legacy
  fixed-limit lookups), with filters by artist / genre / etc.
- **Background playback** — keep playing and **auto-advance to the next track when the tab isn't
  focused** (today it stalls unless the tab is focused). Drive the advance off the audio element's
  `ended` event (not focus-dependent timers, which browsers throttle in background tabs), and wire up
  the **Media Session API** for OS / lock-screen controls + now-playing metadata.
- **Listen history & stats** — record plays per user (new `plays` table: who / what / when) and surface
  **most-played** tracks, albums, and artists. Per-user, optionally aggregated globally.

## Authentication & access model

The audience is **explicitly not public** — only family and friends, who receive links. The site is
internet-facing, so access is controlled, but "here's a song I like" must not force everyone to create
an account. Two tiers:

- **Invited account holders (full app).** Reuse **Fortify** (session login, password reset) from the
  owner's other project. **Open registration is disabled**; accounts are created via **one-time,
  expiring invite tokens** — the owner generates an invite link, the recipient uses it once to set a
  password. All library and management routes sit behind `auth`.
- **Share-link recipients (no account).** For casual "listen to this" sharing, use Laravel
  **signed / temporary URLs** scoped to a single song / album / playlist. They play without login, are
  tamper-proof, and expire. This is the headline use case and stays friction-free.

Rules:

- **2FA is optional — the user's choice, never forced** (not for friends, not for the owner). Fortify's
  TOTP 2FA is available to anyone who opts in.
- **Drop the web-server HTTP Basic Auth.** It's redundant once Fortify handles auth, and it would block
  the signed share-links (recipients would hit the Basic Auth wall first).
- Store invite tokens **hashed**, single-use, **expiring after 7 days** (`used_at` marker).
- Signed share-links default to a **30-day expiry** (with an optional "no expiry" per link) and are
  **revocable** at any time.
- **Transactional mail** (Fortify password resets, email verification, invite links) is sent through a
  relay — **Mailtrap** (free tier, as on `cantrip.me`) — **never** from debbie's dynamic residential IP.
  Deliverability relies on **SPF/DKIM/DMARC TXT records on the real domain**; the domain/DNS + mail
  setup is in [`self-hosting/04-going-public.md`](self-hosting/04-going-public.md) (this box's real
  values live in the untracked `debbie.local/` — see [../CLAUDE.md](../CLAUDE.md) → *Docs*).

## What to preserve from the legacy app (port behaviour, not architecture)

- **Data model** (UUID PKs except `User`): Artist, Album, Song, Genre · Audiobook, Track, Author,
  Narrator · Playlist, PlaylistEntry · User, GlobalProperties.
- **Library scan flow** — the artisan chain that finds mp3s on the Samba share → CSV → DB:
  - `app:update` (orchestrator: cleanup → music CSV → music DB → audiobook CSV → audiobook DB)
  - `app:csv:music` / `app:csv:audiobook` (find `*.mp3` → CSV)
  - `app:db:music` / `app:db:audiobook` (CSV → DB, via `MusicLibraryService` / `AudiobookLibraryService`)
  - `app:clean` (delete junk file masks from Samba; prune Laravel storage/public + downloads disks)
- **`config/collection.php`** settings: media paths, cleanup masks (`Thumbs.db`, `._*`, `.DS_Store`,
  …), download size limit (~200 MiB), thumbnail/cover widths, DB field lengths, search/UI limits.
- German locale (`APP_LOCALE=de`).

## Legacy reference

- Local clone: **`../MixTape`** (sibling of this folder). Read it for behaviour, the data model,
  artisan commands, `config/collection.php`, and the existing Vue/Pinia structure — then re-implement on
  the new stack. It is **reference only**; this repo starts clean.
- Useful entry points: `routes/web.php` + `routes/api.php`, `app/Models/`, `app/Console/Commands/`,
  `app/Services/` (library services), `config/collection.php`, `resources/app/` (main.ts, views,
  components, stores, router).
