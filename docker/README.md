# Local Docker dev stack

A self-contained way to run and develop MixTape **without the home server**
(debbie is LAN-only). Everything runs on your laptop: Postgres, a seeded demo
library, PHP-FPM, nginx, and a live-reloading Vite dev server. This is a
**development** setup only — production on debbie deploys via git
(`docs/self-hosting/`), not Docker.

## Quick start

```sh
docker compose up --build
```

First boot builds the PHP image, installs deps as needed, migrates, and **seeds a
demo library** (a fresh DB only). Then open:

- App: <http://localhost:8080> — log in as **`Ashaltiriak` / `passwort`**
- Vite dev server: <http://localhost:5173> (used by the app for HMR)
- Postgres: `localhost:5432` (db `mixtape`, user/pass `mixtape`)

Stop with `Ctrl-C`, or `docker compose down` (add `-v` to also wipe the DB +
`node_modules` volumes for a clean slate).

## How it fits together

| Service | Image | Purpose |
|---------|-------|---------|
| `db`    | `postgres:17` | Schema + demo data. PG17's ICU satisfies the `case_insensitive` collation the migrations create. |
| `app`   | `docker/app.Dockerfile` (PHP-FPM 8.4) | Laravel. Source bind-mounted → live edits. `docker/entrypoint.sh` installs/migrates/seeds. |
| `web`   | `nginx:1.27` | Serves `public/`, forwards PHP to `app:9000`. The `:8080` entrypoint. |
| `vite`  | `node:26` | `npm run dev` (HMR) on `:5173`. |

- **Your real `.env` is not touched.** Dev overrides live in `docker/app.env` and
  are injected as container env vars, which win over the bind-mounted `.env`
  (Laravel's Dotenv won't override an already-set variable). So at-home settings
  (sqlite, the SSH-tunnel DB port) stay as they are.
- **`node_modules` is a named volume**, so the container's Linux-native install
  (sharp, lightningcss, sass-embedded, …) never clashes with your Mac's copy.
- **`vendor` is reused from the bind mount** when present (it's portable PHP);
  the entrypoint only runs `composer install` if it's genuinely missing.

## Common tasks

Run these on your **host** (the Mac), in a terminal at the project root — the
folder with `docker-compose.yml`. The `exec <service>` prefix runs the command
inside that already-running container, so the stack must be up first.

PHP / Laravel / composer commands go to the `app` service:

```sh
docker compose exec app php artisan migrate:fresh --seed   # reset the demo DB
docker compose exec app php artisan test                   # test suite (sqlite :memory:)
docker compose exec app php artisan tinker
docker compose exec app composer install                   # after composer.lock changes
docker compose logs -f app                                 # watch app logs (mail goes here too)
```

Node / npm commands go to the `vite` service (the `app` image has no Node):

```sh
docker compose exec vite npm run icons                     # rebuild the SVG icon sprite
```

`npm run icons` writes to `storage/app/public/sprite.svg` (served at
`/storage/sprite.svg` via the `public/storage` symlink). The file lands on your
Mac through the bind mount and is gitignored — re-run it only after you add or
change an icon in `resources/app/assets/icons/`.

## Working with real data (optional)

The seeded library has no audio files on disk, so **playback won't stream** — it's
for UI/data work. To use a real (small) collection you copied to your laptop:

1. Uncomment the media bind mount in `docker-compose.yml` under **both** `app`
   and `vite` (e.g. `- /Users/you/media:/media:ro`).
2. Point the paths in `docker/app.env` at it, e.g. `MIXTAPE_MUSIC_PATH=/media/music`.
3. `docker compose up -d` then `docker compose exec app php artisan app:update`.

Or restore a Postgres dump you took from debbie before leaving:

```sh
docker compose exec -T db psql -U mixtape -d mixtape < your-dump.sql
```

## Troubleshooting

- **Port already in use** — change the host side of the mapping in
  `docker-compose.yml` (`8080:80`, `5432:5432`, `5173:5173`).
- **HMR not picking up file changes** — Docker Desktop's VirtioFS usually
  delivers fs events, but if edits don't hot-reload, restart the `vite` service.
- **Stale config after switching from at-home dev** — the entrypoint clears
  config/route/view caches on every start; if something looks off,
  `docker compose exec app php artisan optimize:clear`.
