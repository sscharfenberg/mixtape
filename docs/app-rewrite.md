# Application rewrite (Phase 2)

> Phase 2 of MixTape v2. See [../CLAUDE.md](../CLAUDE.md) for the overview. Docs for the host it
> deploys to are kept outside this repo — see [../CLAUDE.md](../CLAUDE.md) → _Docs_.

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
| Player        | `vidstack`                                 | **Dropped** — native `<audio>` (see `player.md`)    |
| Images / tags | `intervention/image`, `wapmorgan/mp3info`  | Keep                                                |
| Tooling       | ESLint 9, Prettier 3.6, Stylelint 16, Pint | Keep                                                |

## Rewrite goals

- **New design** (visual refresh — direction TBD).
- **Inertia-first data flow**: pages receive server props; forms use Inertia's form helpers; **do not
  re-introduce a bespoke API layer** or client-side routing.
- **Composables**: factor shared logic out of stores/components into composables.
- **Typed throughout**: preserve strict TS.
- Preserve the app's core value and the maintenance flows below.

## The header's areas, and what earns a place in it

Every top-level area is conditional (2026-08-08). `useSiteAreas` builds the list, and each entry
answers a different question:

| Area | Shown when | Where the answer comes from |
| --- | --- | --- |
| Music | the library holds music | `library.music`, a shared prop |
| Audiobooks | the library holds audiobooks | `library.audiobook`, a shared prop |
| Playlists | either of the above | the same two flags |
| Now playing | the play queue holds something | `usePlayerQueue`, in the browser |

**An empty area is a link to a page that says nothing**, and this instance may legitimately hold one
kind and not the other — so the server answers with one `SELECT DISTINCT type FROM tracks`
(`HandleInertiaRequests`), on the leading half of the `(type, created_at)` index. It is deliberately
not cached: the invalidation would ride the nightly scan, and the first failure mode of a stale
cache is the worst one this has — importing a library and finding the menu still empty.

**A guest is asked nothing.** `SiteMenu` renders only for a signed-in user — every area is behind
`auth` — so the flags are computed only when there is one. That is also what keeps the login page
renderable with **no database at all**, which is not hypothetical: the E2E harness waits for the
server to answer before it migrates, so a shared prop reading `tracks` deadlocked the whole suite
behind a table that did not exist yet (CI, 2026-08-08). The readiness probe now asks `/up`, Laravel's
health route, rather than a page — readiness means "PHP is answering", not "the app has data".

**Now playing is the odd one out**, and has to be: the queue is client state so the player survives
Inertia swapping pages, so no request can know whether that link belongs. It therefore appears and
disappears mid-visit, which is why it sits LAST — a link that comes and goes shifts whatever follows
it, and nothing follows this one. The page itself stays reachable whatever the queue holds: a URL
that 404s depending on a browser's localStorage would be a worse answer than a page saying the queue
is empty.

**Podcasts were an area until 2026-08-08 and are gone entirely** — pages, route, icon, i18n, the
`podcast` / `podcast_show` enum cases, the library path and the type CHECKs (one migration narrows
them; the originals were edited so a fresh install never mints the wide one). A podcast is something
you listen to on the service that publishes it, not a folder of mp3s anybody downloads, so the
scaffold was never going to be finished.

## Frontend conventions

**Pages live in their own directory, with a `*Page` entry file.** Each Inertia page is a folder under
`resources/app/pages/` named after the page, containing a `<Name>Page.vue` entry plus any page-local
parts — components, composables, tests — **flat, beside the page file**:

```
resources/app/pages/
  Home/
    HomePage.vue        <- route entry
    HomePageHero.vue    <- page-local component, beside the page
    useHomeData.ts      <- page-local composable
  Music/Songs/
    SongsPage.vue       <- /music/songs
    Song/
      SongPage.vue      <- /music/songs/{song} — a sub-folder because it's a nested ROUTE
      SongPageHero.vue
```

- **No `components/` sub-directory inside a page.** Living in the page's folder is already what scopes
  a part to that page; a generic bucket one level deeper adds a hop to every read and says nothing the
  file name doesn't. A **named** sub-folder is fine where it means something — a nested route
  (`Songs/Song/`) or a self-contained feature block (`Dashboard/TwoFactor/`) — i.e. named after the
  thing, never after the kind of file.
- **The `Page` suffix marks the route entry** — in a populated folder it's instantly clear which file
  is the page vs. its co-located children, and it reads unambiguously in Vue devtools / stack traces.
- **Controllers render the explicit path**: `Inertia::render('Home/HomePage', [...])`. Kept explicit
  (not resolver magic) so it stays greppable end-to-end — search `HomePage` and you find both the file
  and the controller that renders it. The resolver in `main.ts` maps the name straight to
  `./pages/<name>.vue`.
- **Prefer an invokable controller** (`__invoke`) for a single-action page; group related actions in
  one controller otherwise.

**Every page declares its breadcrumb trail.** `Breadcrumb.vue` is mounted once in `FullLayout`; a page
calls `setBreadcrumbs([…])` (from `Composables/useBreadcrumbs`) in its `<script setup>` — parents carry
an `href`, the current page doesn't:

```ts
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.songs", href: "/music/songs", icon: "song" },
    { label: props.song.name } // raw label — data, not a catalog key
]);
```

The trail is **declared, not derived from the URL**: only the page knows the _names_ in its path and
which parents this visitor can actually reach. A page that declares nothing shows nothing — which is
exactly what `Guest/WelcomePage` (`/`) wants, since every trail already opens with a home chip
pointing there.

Under the hood it travels as an **Inertia layout prop** (`setLayoutProps`, typed via the `layoutProps`
augmentation in `resources/types/inertia.d.ts`), which `FullLayout` receives and hands to
`Breadcrumb`. That is a deliberate choice about _timing_, not plumbing: the trail has to be emptied
between pages, and Inertia resets layout props inside `swapComponent` — the exact moment the incoming
page replaces the outgoing one. The earlier module-level ref was cleared on the router's `start`
event instead, which blanked the trail the instant a link was clicked, unmounted the `<nav>` and made
the whole page jump while the request was still in flight. Layout props replace the trail; they never
blank it. `tests/e2e/app/navigation.spec.ts` samples every animation frame to keep it that way.

**Internal navigation links carry `prefetch`.** `<Link href="…" prefetch>` fetches on hover-intent
(75ms) and caches for 30s, so the click that follows usually has its response already in hand — no
progress bar, no wait. Add it to any new `<Link>` that navigates within the app; leave it off non-GET
links (`/logout`). DataTable's clickable rows are the exception that needs code: they navigate through
`router.visit` with no `<Link>` at all, so `DataTableBody` re-implements the same hover-intent by
hand. `main.ts` opts every real navigation into the **View Transitions API** in a `router.on("before")`
hook — a property of navigating rather than of any one link, and the only place that also covers those
rows — so the outgoing page cross-fades into the incoming one. It is gated on
`prefers-reduced-motion: no-preference` in JS, because the fade is generated by the browser and there
is no rule of ours to put behind a media query.

> **The trap:** Inertia fires the same `before` / `start` / `finish` events for a **prefetch** as for a
> real visit. Anything that paints loading chrome on those events has to check `visit.prefetch` first
> — the progress bar in `main.ts` and DataTable's overlay both did not, and merely running the pointer
> across a table flashed a spinner over rows nobody was going to.

**The layout is where anything that outlives a page goes.** Inertia swaps the *page* component on
navigation and keeps `FullLayout`, so the play queue, the player bar and (later) the audio element
live there and nowhere else — a player inside a page would stop the music on every click. The body is
a two-column grid: the page, and a **240px** queue column that exists only while the queue does. 240px
is a fixed sidebar rather than the "right third" it started as, because a literal third is 850px of
queue on a wide monitor *and* squeezes `<main>` under the DataTable's container breakpoint. Below the
`landscape` step there is no width to give it, so the panel becomes a bottom sheet over the lower half
of the viewport instead. The footer and the player bar are alternatives: the bar takes the footer's
place once the queue has a **current track** — not when audio is playing, or pausing would take the
play button off screen with it.

**Shared components document themselves.** A component with a contract worth explaining carries a
`README.md` beside it; this file records the _convention_, not the API. The ones a page author meets
most often:

| Component          | Rule                                                                                                                                                                 | Docs                                                                                                    |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `DataTable`        | The listing surface: sort / search / paginate, all server-driven through the URL.                                                                                    | [`components/DataTable/README.md`](../resources/app/components/DataTable/README.md)                     |
| `TabbedNavigation` | Tabs declare their panels as named slots; the component owns visibility and the whole ARIA contract. Bind `Composables/useTabParam` to keep the open tab in `?tab=`. | [`components/UI/TabbedNavigation/README.md`](../resources/app/components/UI/TabbedNavigation/README.md) |
| `CoverImage`       | **All** artwork goes through it — never hand-roll an `<img>` for a cover.                                                                                            | [`components/Music/CoverImage/README.md`](../resources/app/components/Music/CoverImage/README.md)       |
| `Discography`      | A short, unpaginated list of albums, for a block _inside_ a page rather than a listing of its own.                                                                   | [`components/Music/Discography/README.md`](../resources/app/components/Music/Discography/README.md)     |

Two constraints from those READMEs are worth repeating here, because they bite at the _page_ level
rather than inside any one component:

- **Only one thing on a page may be a server-driven `DataTable`.** `DataTableService` reads `sort` /
  `dir` / `page` / `search` **unprefixed**, so two of them drive each other from the same params — and
  on a tabbed page every panel renders at once. Size the second block's presentation to its data
  instead: the artist page's albums tab is a plain `Discography` because the biggest discography is 26
  rows (`ArtistController` carries the reasoning).
- **Never size slotted content from the outside.** Vue puts the slot scope id on a slotted component's
  **root element**, so a `:slotted` selector reaches straight through the component and outranks the
  sizing it declares for itself. `HeroSection` did exactly this to `CoverImage` and quietly won.
  Reaching in deliberately to _paint_ is fine; setting **size** is a trap.

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
- **Downloads (built 2026-08-10)** — a song as its own mp3, an album as a .zip. The gate is
  deliberately the page's own: whoever may look at a subject may keep a copy of it (share links will
  widen both together). Four decisions worth keeping:
    - **The album zip is the shelf, not a track list.** The tracks come from the DATABASE and the
      non-audio files (`folder.jpg`, the 27 booklet PDFs, a stray `.m3u8`) from their directories —
      that split is what stops a folder shared with another album handing over its music. Multi-disc
      sets keep their `[Disc 1]` / `[Disc 2]` structure, since flattening collapses two "01 - …"
      into one name. Legacy rebuilt the files under invented names and shipped exactly one image.
    - **It streams; nothing is written to disk.** PHP's `ZipArchive` can only write to a file, and
      measured against this collection that is a bad trade: the largest album is 1.1 GB, so a temp
      file costs a full read, a full write and a second full read, plus 20–30 s before the browser
      shows anything — and `/tmp` on the live box is a **16 GB tmpfs**, i.e. RAM. It also has to be
      swept, which is why legacy pruned its whole download disk before every export.
      `App\Services\Media\ZipStream` writes the archive straight out.
    - **Stored, not deflated** — mp3/JPEG/PDF are already compressed. The gain is not just CPU: with
      no compression the archive's size is exact before a byte is written, so the response carries a
      real `Content-Length` and the browser gets a progress bar. CRCs come from `hash_file` ahead of
      each entry, so the headers are ordinary ones (no data descriptors, best reader compatibility).
    - **No size limit**, unlike legacy's ~200 MiB threshold that hid the link. A gigabyte is a
      download the browser can show progress for; the cost is one php-fpm worker for the transfer,
      which a zip cannot hand to nginx the way the song routes do (`X-Accel-Redirect` needs a file
      that exists). Acceptable for a deliberate, occasional act on a home server — and the reason
      the album route is throttled far below the song routes.
  Two things moved to make room for the button. The hero's tinted control box became its own
  `ActionPanel` (it was `AddToPlaylist`'s, which renders **nothing** for a reader with no
  playlists — and the download must not vanish with it); and every `throttle:` in the app grew a
  bucket name, which is a bug fix rather than tidying — see *Rate limiting* in
  [../CLAUDE.md](../CLAUDE.md).

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
  relay — **Mailtrap** (free tier, as on `cantrip.me`) — **never** from the server's dynamic residential IP.
  Deliverability relies on **SPF/DKIM/DMARC TXT records on the real domain**; the domain/DNS + mail
  setup is in [`self-hosting/04-going-public.md`](self-hosting/04-going-public.md) (this box's real
  values live in the untracked `host.local/` — see [../CLAUDE.md](../CLAUDE.md) → _Docs_).

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
