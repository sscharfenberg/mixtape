# The player

The build plan for actually playing audio. Written 2026-08-02, after the queue scaffold
landed (`6d8a0fa` → `5fba7cd`) and the vidstack-vs-native question was settled.

Read [`data-model.md`](data-model.md) → _The play queue_ first — it decides the shape of the
queue and its persistence, which this builds on rather than revisits.

## What we are building

- Play / pause, previous, next.
- A timeline that fills as the track plays, showing the **cursor position** and the track's
  **total playing time**, and scrubbable.
- A **repeat** toggle: when the queue ends with repeat on, wrap to the first track; with it
  off, stop on the last one.
- Playback that survives the **tab being backgrounded** — and, as far as the platform
  allows, the **phone's screen being off**.

## The decision: a native `<audio>`, not vidstack

`app-rewrite.md` listed vidstack as "keep (or re-evaluate)". Re-evaluated: **none of the
above needs it.** Every item is `HTMLAudioElement` plus the Media Session API.

What vidstack is actually for is adaptive streaming (HLS/DASH) and a prebuilt skin. We
serve local MP3s, so the streaming half is dead weight, and the skin is the half we would
fight — the legacy app carried a whole `vendor/_vidstack.scss` to wrestle it, and this
codebase has a far stricter design system than that one did. It may also want
`media-src blob:` in the production CSP; a native `<audio src>` satisfies the existing
`media-src 'self'` unchanged (see `self-hosting/files/mixtape.prod.nginx.conf`).

Crucially it would **not** help with the hard part. vidstack wraps the same
`HTMLAudioElement` for progressive audio, so it inherits every background-playback
constraint below. There is no library that does not.

## The one genuine risk, and how we find out early

Background playback splits in two, and only one half is safe:

- **Tab backgrounded, or another window on top — fine everywhere.** Browsers exempt playing
  audio from background throttling. But `setInterval` is throttled to roughly once a minute
  in a hidden tab and `requestAnimationFrame` stops dead, so **auto-advance must ride the
  `ended` event and never a timer**, and the progress bar must **re-sync from `currentTime`**
  when the tab returns rather than assuming it kept counting.
- **Phone with the screen off — uncertain, and no library changes it.** Audio keeps playing,
  but iOS suspends the page's JavaScript on lock. The current track finishes and the `ended`
  handler that would start the next one may not run until unlock. Android Chrome behaves
  better.

So **step 3 below is deliberately ordered before the UI**: get a bare `<audio>` plus Media
Session onto a real phone and watch what happens at a track boundary with the screen off.
If iOS stalls, we would rather know while there is no transport UI built on top of the
assumption. The **Media Session API** is what gives lock-screen / notification controls and
OS-invoked `nexttrack` handlers; it is a plain browser API, no dependency.

## Build order

### 1. The stream route

`GET /music/songs/{song}/stream`, following `SongCoverController` exactly: behind auth, the
same music-only `TrackType` check, `->setPrivate()` caching (this instance is
internet-facing, so a shared proxy must never hold a track). `Track::absolutePath()`
resolves the file.

Two things it must get right:

- **HTTP Range.** Without `206 Partial Content`, dragging the timeline past what is buffered
  simply fails. Laravel's `response()->file()` does not do this on its own.
- **`X-Accel-Redirect` on nginx**, with a direct-file fallback for local dev. Streaming a
  96 GB collection through php-fpm ties up a worker for the length of every song; nginx
  serves the bytes and handles Range natively. Needs an `internal;` location added to the
  vhost in `self-hosting/files/`.

PHPUnit: auth, the 404s (missing track, audiobook chapter, file absent from disk), a full
`200`, and a `206` with the right `Content-Range`.

### 2. Icons

Author `pause.svg` and `repeat.svg` in the same `0 -960 960 960` viewBox style as
`play.svg`, then `npm run icons`. Prev/next already use `first-page` / `last-page`, which
are the standard `|◀ ▶|` skip glyphs. **The sprite is gitignored**, so debbie needs
`npm run icons` on deploy — see [[phase-2-toast-flash-pattern]] in the memory for that trap.

### 3. `usePlayerAudio`

One `HTMLAudioElement`, owned by `PlayerBar` so it survives Inertia page swaps. Exposes
`isPlaying`, `currentTime`, `play` / `pause` / `toggle` / `seek`.

- **Auto-advance off `ended`** — see the risk section. Calls the queue's `next()`.
- **Duration comes from the queue track, not the element.** A VBR MP3 with no Xing/Info
  header reports `Infinity` until fully downloaded; we already hold exact durations from
  getID3, and `QueueTrack.duration` carries them, so the total is right from the first frame.
- **Media Session** metadata + action handlers wired here.
- Playback must start from a **user gesture** — no autoplay on load, including when a stored
  queue is hydrated.

### 4. Repeat

A flag on `usePlayerQueue`, persisted with the queue (`data-model.md` already reserves
`(+ shuffle/repeat later)` in the payload). `next()` returns `false` at the end today — that
is the hook: with repeat on, wrap to index `0` instead. The toggle goes in `PlayQueueMenu`,
which exists to hold exactly this.

**Played tracks stay in the queue and the pointer moves** — they are not consumed. That is
what makes `previous()` and `jumpTo()` work, and it is the shape `data-model.md` specifies.

### 5. The transport UI

Real play/pause (swapping glyph), prev, next, and the timeline: fill, cursor timestamp,
total. Scrubbable, writing `currentTime`. Tokens per the usual three-layer rule; motion
behind `prefers-reduced-motion: no-preference`.

### 6. Verify

Browser: audio plays, the timeline advances, seeking works against the Range route,
auto-advance fires at a track boundary. Then lint, `npm run build`, Vitest, PHPUnit,
Playwright. **The screen-off question needs a real phone** — that one is the owner's to
confirm, on the device that matters.

## Already in place

`usePlayerQueue` (operations, localStorage, user-scoped), `PlayQueue` + `PlayQueueMenu` +
`PlayQueueToggle`, `PlayerBar` as a shell with inert play/pause and working prev/next, and
the enqueue button on the song page. The `player_states` table and model exist but nothing
reads or writes them — server sync is **not** part of this plan.
