# Architecture

How MixTape is put together, and why. Start here if you are new to the codebase; the feature
documents linked from [`../README.md`](../README.md) go deeper on individual subsystems.

## Stack

| Layer | Choice |
| --- | --- |
| Backend | Laravel 13, PHP 8.4 |
| Auth | Laravel Fortify (opt-in TOTP 2FA), invite-only registration, share links |
| Server ↔ client | **Inertia.js v3** — controllers pass props straight to Vue pages |
| Routing | Server routes only; **no Vue Router** |
| Frontend | Vue 3 + TypeScript, `<script setup>`, composables-first |
| State | **No Pinia** — module-singleton composables where global state is genuinely needed |
| Build | Vite, sass-embedded, `laravel-vite-plugin` |
| Player | A native `<audio>` element (see [`player.md`](player.md)) |
| Images / tags | `intervention/image`, `getID3` |
| Database | PostgreSQL 17 |
| Tooling | ESLint, Prettier, Stylelint, Pint |

Three of those are load-bearing enough to state as rules:

- **There is no REST API, and adding one is not the way to solve a problem here.** Controllers render
  Inertia pages; pages that need more data ask for it with a partial reload (`router.reload({ only:
  [...] })`). The three endpoints that answer JSON do so for a specific reason each — minting a share
  link, syncing the player state, and the search typeahead — and every one of those reasons is that an
  Inertia visit would re-render the page component under a reader who is mid-interaction.
- **No client-side routing.** A URL is a server route; the browser's address bar is server state.
- **No global store.** Where state has to outlive a page — the play queue, toasts, the tooltip layer —
  it lives in a module-level `ref` inside a composable. That is enough for this app and it keeps the
  data flow one-directional: props down from the server, module state only for what the server cannot
  own.

## Access model

The audience is **explicitly not public** — family and friends, who receive links — but the instance
is internet-facing, so access is controlled. Two tiers:

**Invited account holders** get the whole app. Fortify handles session login (by `users.name`, not
email), password reset and optional 2FA. Open registration is disabled: accounts are created by
redeeming a **one-time, expiring invite token** minted with
[`php artisan app:invite`](artisan-commands.md#appinvite). Every library and management route sits
behind `auth`.

**Share-link recipients** get no account at all. A `shares` row *is* the capability: an unguessable
UUID in the URL, scoped to one song, album, audiobook, artist or playlist, which plays without login
and expires. This is the headline use case and it stays friction-free. Full design:
[`sharing.md`](sharing.md).

The rules that follow from that:

- **A sign-in lands where the music is, not on the dashboard.** The destination follows what the
  library actually holds — the bigger of the two areas, or the public landing page for an instance
  with no media at all (`App\Services\Auth\LandingPage`). It is only the default: a reader bounced
  to the login form from a deep link still gets that link back. Registering and resetting a password
  still land on the dashboard, because both have just done something *to the account*.
- **2FA is optional — the user's choice, never forced.** Not for friends, not for the owner.
- **No web-server HTTP Basic Auth.** It is redundant once Fortify handles auth, and it would block
  share links: recipients would hit the Basic Auth wall first.
- **Invite tokens are stored hashed**, single-use, expiring after seven days.
- **A share link is a row, not a signature.** Exactly one of `track_id` / `collection_id` /
  `artist_id` / `playlist_id`, enforced by a CHECK — real FKs rather than a polymorphic pair, so a
  rescan that drops a track or an album cascades its shares away instead of leaving them pointing at
  nothing. The UUID primary key *is* the secret and is stored **unhashed**, unlike an invite: a share
  is re-copied from the owner's list long after minting, and a digest cannot be re-displayed.
- **Any signed-in user may mint a share for any library subject; a playlist only its owner.** Sharing
  does not chain — the `/s/` space holds no mint route at all. (Forwarding the *link* is always
  possible; a bearer capability travels. The expiry bounds that, not a check.)
- **A share grants listening and nothing else** — no mp3, no zip, no counterpart to either download
  route under `/s/`. Guest listens are not recorded either, so `plays.user_id` stays NOT NULL and
  most-played keeps meaning the household's own listening.
- **Its own URL space** (`/s/{share}/…`), every sub-route resolving its subject *through* the share
  row, so containment is structural rather than conditional: a share of one album cannot address a
  track outside it, because the route has no way to name one. `routes/web.php` therefore keeps saying
  that everything under `/music` sits behind `auth`, full stop, and that stays verifiable by reading
  it.
- **Transactional mail goes through a relay** — never from the server's dynamic residential IP, which
  is blocklisted and has no PTR record. Deliverability relies on SPF/DKIM/DMARC TXT records on a real
  domain; the setup is in [`self-hosting/04-going-public.md`](self-hosting/04-going-public.md).

## The header's areas, and what earns a place in it

Every top-level area is conditional. `useSiteAreas` builds the list, and each entry answers a
different question:

| Area | Shown when | Where the answer comes from |
| --- | --- | --- |
| Music | the library holds music | `library.music`, a shared prop |
| Audiobooks | the library holds audiobooks | `library.audiobook`, a shared prop |
| Playlists | either of the above | the same two flags |
| Now playing | the play queue holds something | `usePlayerQueue`, in the browser |

**An empty area is a link to a page that says nothing**, and an instance may legitimately hold one
kind and not the other — so the server answers with one `SELECT DISTINCT type FROM tracks`
(`HandleInertiaRequests`), on the leading half of the `(type, created_at)` index. It is deliberately
not cached: the invalidation would have to ride the nightly scan, and the first failure mode of a
stale cache is the worst one available here — importing a library and finding the menu still empty.

**A guest is asked nothing.** `SiteMenu` renders only for a signed-in user — every area is behind
`auth` — so the flags are computed only when there is one. That is also what keeps the login page
renderable with **no database at all**, which matters concretely: the end-to-end harness waits for the
server to answer before it migrates, so a shared prop reading `tracks` would deadlock the whole suite
behind a table that does not exist yet. The readiness probe therefore asks `/up`, Laravel's health
route, rather than a page — readiness means "PHP is answering", not "the app has data".

**Now playing is the odd one out**, and has to be: the queue is client state so the player survives
Inertia swapping pages, so no request can know whether that link belongs. It therefore appears and
disappears mid-visit, which is why it sits **last** — a link that comes and goes shifts whatever
follows it, and nothing follows this one. The page itself stays reachable whatever the queue holds: a
URL that 404s depending on a browser's localStorage would be a worse answer than a page saying the
queue is empty.

## Frontend conventions

### Pages live in their own directory, with a `*Page` entry file

Each Inertia page is a folder under `resources/app/pages/` named after the page, containing a
`<Name>Page.vue` entry plus any page-local parts — components, composables, tests — **flat, beside
the page file**:

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
- **The `Page` suffix marks the route entry**, so in a populated folder it is instantly clear which
  file is the page and which are its co-located children, and it reads unambiguously in Vue devtools
  and stack traces.
- **Controllers render the explicit path**: `Inertia::render('Home/HomePage', [...])`. Kept explicit
  rather than resolved by magic, so it stays greppable end to end — search `HomePage` and you find
  both the file and the controller that renders it. The resolver in `main.ts` maps the name straight
  to `./pages/<name>.vue`.
- **Prefer an invokable controller** (`__invoke`) for a single-action page; group related actions in
  one controller otherwise.

### Every page declares its breadcrumb trail

`Breadcrumb.vue` is mounted once in `FullLayout`; a page calls `setBreadcrumbs([…])` (from
`Composables/useBreadcrumbs`) in its `<script setup>` — parents carry an `href`, the current page
doesn't:

```ts
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.songs", href: "/music/songs", icon: "song" },
    { label: props.song.name } // raw label — data, not a catalog key
]);
```

The trail is **declared, not derived from the URL**: only the page knows the *names* in its path and
which parents this visitor can actually reach. A page that declares nothing shows nothing — which is
what `Guest/WelcomePage` (`/`) wants, since every trail already opens with a home chip pointing there.

Under the hood it travels as an **Inertia layout prop** (`setLayoutProps`, typed via the
`layoutProps` augmentation in `resources/types/inertia.d.ts`), which `FullLayout` receives and hands
to `Breadcrumb`. That is a choice about *timing* rather than plumbing: the trail has to be emptied
between pages, and Inertia resets layout props inside `swapComponent` — the exact moment the incoming
page replaces the outgoing one. Clearing it on the router's `start` event instead blanks the trail the
instant a link is clicked, unmounts the `<nav>` and makes the whole page jump while the request is
still in flight. Layout props **replace** the trail; they never blank it.
`tests/e2e/app/navigation.spec.ts` samples every animation frame to keep it that way.

### Internal navigation links carry `prefetch`

`<Link href="…" prefetch>` fetches on hover-intent (75ms) and caches for 30s, so the click that
follows usually has its response already in hand — no progress bar, no wait. Add it to any new
`<Link>` that navigates within the app; leave it off non-GET links (`/logout`). DataTable's clickable
rows are the exception that needs code: they navigate through `router.visit` with no `<Link>` at all,
so `DataTableBody` re-implements the same hover-intent by hand. `main.ts` opts every real navigation
into the **View Transitions API** in a `router.on("before")` hook — a property of navigating rather
than of any one link, and the only place that also covers those rows — so the outgoing page
cross-fades into the incoming one. It is gated on `prefers-reduced-motion: no-preference` in JS,
because the fade is generated by the browser and there is no rule of ours to put behind a media query.

> **Never prefetch a link that leads to a form.** A hover prefetch whose response lands *after* you
> have navigated to that same URL is applied to the page you are now on — `Response.handlePrefetch()`
> calls `handle()` whenever the URL matches the current location — and that swap does not preserve
> state, so Inertia's Vue adapter re-keys the page component: `setup()` runs again and every `ref` on
> the page goes back to its prop. In a form that is **silent data loss**, because Inertia's `<Form>`
> serialises the DOM at submit, so the value the server sent is what gets saved. A click that outruns
> the hover timer is what triggers it — the click sends its own request, so the prefetch is never
> consumed — and warming a form buys ~150ms on a page a reader then sits on for seconds. It is not
> worth it.
>
> **`useRemember` is the complement, not the alternative.** It makes a page's own state survive being
> re-created from any cause (`router.remember` → `replaceState` updates the in-memory state
> synchronously, so a remount a tick later still restores it). `PlaylistMetadataPage` uses it.
> **Never remember a secret**: it is written into the history entry, so a password or a 2FA code must
> stay out of it — for those forms, not prefetching is the whole fix.

> **Inertia fires the same `before` / `start` / `finish` events for a prefetch as for a real visit.**
> Anything that paints loading chrome on those events has to check `visit.prefetch` first, or merely
> running the pointer across a table flashes a spinner over rows nobody was going to.
>
> **And prefetching removes events as well as adding them**, which is subtler and matters for any
> progress indicator:
>
> | the click… | events for that visit |
> | --- | --- |
> | outran the hover timer, so nothing was prefetched | `before` → **`start`** → `finish` → `navigate` |
> | landed while the prefetch was **in flight** | `before` → `finish` → `navigate` |
> | landed after the prefetch **completed** (cache hit) | `before` → `navigate` |
>
> `start` fires only where nothing was warmed, and `finish` does not fire for a cache hit at all. A
> bar armed on `start` is therefore missing in **exactly** the case it exists for — the middle row, a
> real wait on a request nobody told the reader about. **Arm on `before`, disarm on `finish` AND
> `navigate`**: `before` is the only event all three share, and `navigate` is the only stop the cache
> hit sends.

### The layout is where anything that outlives a page goes

Inertia swaps the *page* component on navigation and keeps `FullLayout`, so the play queue, the player
bar and the audio element live there and nowhere else — a player inside a page would stop the music on
every click. The body is a two-column grid: the page, and a **240px** queue column that exists only
while the queue does. 240px is a fixed sidebar rather than a proportional "right third", because a
literal third is 850px of queue on a wide monitor *and* squeezes `<main>` under the DataTable's
container breakpoint. Below the `landscape` step there is no width to give it, so the panel becomes a
bottom sheet over the lower half of the viewport instead. The footer and the player bar are
alternatives: the bar takes the footer's place once the queue has a **current track** — not when audio
is playing, or pausing would take the play button off screen with it.

### Shared components document themselves

A component with a contract worth explaining carries a `README.md` beside it. The ones a page author
meets most often:

| Component | Rule | Docs |
| --- | --- | --- |
| `DataTable` | The listing surface: sort / search / paginate, all server-driven through the URL. | [`components/DataTable/README.md`](../resources/app/components/DataTable/README.md) |
| `Card` | The surface every block on a page sits on. | [`components/UI/Card/README.md`](../resources/app/components/UI/Card/README.md) |
| `Widget` | A card with a heading and a mode toggle, for the dashboard-style grids. | [`components/UI/Widget/README.md`](../resources/app/components/UI/Widget/README.md) |
| `TabbedNavigation` | Tabs declare their panels as named slots; the component owns visibility and the whole ARIA contract. Bind `Composables/useTabParam` to keep the open tab in `?tab=`. | [`components/UI/TabbedNavigation/README.md`](../resources/app/components/UI/TabbedNavigation/README.md) |
| `Tooltip` | The `v-tooltip` directive and its single shared layer. | [`components/UI/Tooltip/README.md`](../resources/app/components/UI/Tooltip/README.md) |
| `CoverImage` | **All** artwork goes through it — never hand-roll an `<img>` for a cover. | [`components/Music/CoverImage/README.md`](../resources/app/components/Music/CoverImage/README.md) |
| `Discography` | A short, unpaginated cover grid, for a block *inside* a page rather than a listing of its own. | [`components/Music/Discography/README.md`](../resources/app/components/Music/Discography/README.md) |

Two constraints from those READMEs are worth repeating here, because they bite at the *page* level
rather than inside any one component:

- **Only one thing on a page may be a server-driven `DataTable`.** `DataTableService` reads `sort` /
  `dir` / `page` / `search` **unprefixed**, so two of them drive each other from the same params — and
  on a tabbed page every panel renders at once. Size the second block's presentation to its data
  instead: the artist page's albums tab is a plain `Discography` because the biggest discography in a
  personal collection is a couple of dozen rows.
- **Never size slotted content from the outside.** Vue puts the slot scope id on a slotted component's
  **root element**, so a `:slotted` selector reaches straight through the component and outranks the
  sizing it declares for itself. Reaching in deliberately to *paint* is fine; setting **size** is a
  trap.

## Features, and where each is documented

| Subsystem | Document |
| --- | --- |
| Schema, identity, indexes | [`data-model.md`](data-model.md) |
| The audio element, transport, background playback | [`player.md`](player.md) |
| The play queue, shuffle, its storage | [`play-queue.md`](play-queue.md) |
| The Now Playing page | [`now-playing.md`](now-playing.md) |
| The audiobooks area and per-book resume | [`audiobooks.md`](audiobooks.md) |
| Cross-kind search | [`search.md`](search.md) |
| Account-free share links | [`sharing.md`](sharing.md) |
| German / English | [`i18n.md`](i18n.md) |
| The three test layers | [`testing.md`](testing.md) |
| `app:*` commands | [`artisan-commands.md`](artisan-commands.md) |
| Running your own instance | [`self-hosting/README.md`](self-hosting/README.md) |

Two features are worth describing here because they cut across pages rather than belonging to one.

### Playlists

Playlists belong to a **user**, not to the instance: `playlists.user_id`, unique on `(user_id,
name)`, so your "Rock" and mine are different lists. `playlist_tracks` holds a real `track_id` FK in
a `position` order the reader arranged, which is only possible because library identity is stable
(see [`data-model.md`](data-model.md)).

A reader adds to a playlist from a detail page's `ActionPanel`, or from the play queue's menu. The two
send different things and that difference is forced: a detail page sends a **subject** ("this artist")
and lets the server resolve its tracks, while the queue sends **track ids**, because the queue is
client state in an order the reader arranged and the server's copy of it is written late on purpose.

Playlists can be exported as `.m3u`. A Windows-1252 export exists for one real reason — some car head
units render a UTF-8 playlist as mojibake — and it cannot name a file whose path holds a character
that encoding lacks. [`app:audit`](artisan-commands.md#appaudit) reports those paths so they can
be renamed once instead of warned about forever.

The export asks three questions — the `.m3u` flavour, the encoding, and what to put in front of every
path — and **all three are answered by the device that will play the file**, not by the playlist and
not by this server. A car head unit wants a simple list in Windows-1252 with paths relative to the
stick it is plugged into; a Mac wants an extended list in UTF-8 under its `/Volumes` mount. So the
three travel together as an **export preset**, owned by a user and managed at
`/dashboard/export-presets`, and the export dialog opens on the one marked default. A preset **seeds
the dialog, it does not lock it**: the three fields stay editable, and the picker stops claiming a
preset as soon as one of them is edited away from it. A reader who keeps no presets gets the
configured `mixtape.playlists.export.path_prefix` and the dialog as it was before presets existed.

The same dialog exports **one playlist or all of them** — from a playlist's own page, from a row's
menu on the listing, and from that page's "export all". All of them is a **`.zip` holding one
`.m3u` per playlist**, and that is a browser constraint rather than a preference: a page gets one
navigation, and the workarounds run into Chrome's own "allow multiple downloads?" prompt, after
which a refusal loses every file silently. One archive is one download.

### Downloads

A song downloads as its own mp3; an album as a `.zip`. The gate is deliberately the page's own:
**whoever may look at a subject may keep a copy of it** — with share links the deliberate exception,
since a share grants listening and nothing else. Four decisions worth keeping:

- **The album zip is the shelf, not a track list.** The tracks come from the **database** and the
  non-audio files (`folder.jpg`, booklet PDFs, a stray `.m3u8`) from their directories — that split is
  what stops a folder shared with another album handing over its music. Multi-disc sets keep their
  `[Disc 1]` / `[Disc 2]` structure, since flattening collapses two `01 - …` into one name.
- **It streams; nothing is written to disk.** PHP's `ZipArchive` can only write to a file, and against
  a real collection that is a bad trade: a 1.1 GB album costs a full read, a full write and a second
  full read, plus 20–30s before the browser shows anything — and `/tmp` on a server like this is
  usually a tmpfs, i.e. RAM. It would also have to be swept. `App\Services\Media\ZipStream` writes the
  archive straight out.
- **Stored, not deflated** — mp3/JPEG/PDF are already compressed. The gain is not just CPU: with no
  compression the archive's size is exact before a byte is written, so the response carries a real
  `Content-Length` and the browser gets a progress bar. CRCs come from `hash_file` ahead of each
  entry, so the headers are ordinary ones (no data descriptors, best reader compatibility).
- **No size limit.** A gigabyte is a download the browser can show progress for; the cost is one
  php-fpm worker for the transfer, which a zip cannot hand to nginx the way the song routes do
  (`X-Accel-Redirect` needs a file that exists). Acceptable for a deliberate, occasional act on a home
  server — and the reason the album route is throttled far below the song routes.
