# The player

The build plan for actually playing audio, and the record of what building it settled. Written
2026-08-02 after the queue scaffold landed (`6d8a0fa` → `5fba7cd`) and the vidstack-vs-native
question was closed; **built 2026-08-03.**

**The queue itself lives in [`play-queue.md`](play-queue.md)** — what it holds, how it is stored, the
panel, and reordering. It was split out of this file on 2026-08-06, when the storage rework gave it
more behaviour than a section here could carry. Read [`data-model.md`](data-model.md) → _The play
queue_ for the shape both files build on rather than revisit.

## Status: built

| Piece                                                         | State                                         |
| ------------------------------------------------------------- | --------------------------------------------- |
| `GET /music/songs/{song}/stream` (Range + `X-Accel-Redirect`) | ✅ `SongStreamController`                      |
| `pause` / `repeat` icons                                      | ✅ (sprite is gitignored — see _Deploy_ below) |
| `usePlayerAudio`                                              | ✅                                             |
| Transport UI + timeline **+ buffer indicator**                | ✅ `PlayerBar`, `PlayerTimeline`               |
| Volume popover                                                | ✅ `PlayerVolume`                              |
| Playback-settings popover (order / at the end)                | ✅ `PlayerSettings`, 2026-08-06                |
| Real playback in a browser                                    | ✅ Playwright, incl. under the prod CSP        |
| Screen-off on a real phone                                    | ⬜ **the owner's, on the device that matters** |

**Not built, deliberately:** any UI for a stream that fails — a track whose file vanished between
scans stops the player and returns the glyph to _play_; it raises no toast and does not skip on. The
queue's own gaps (server sync, shuffle, the play-history beacon) are listed in
[`play-queue.md`](play-queue.md).

## What we built

- Play / pause, previous, next.
- A timeline showing the **cursor position** and the track's **total playing time**, scrubbable —
  plus a **buffer indicator** (the owner's addition to this plan): the stretches the browser
  already holds, so a listener can see whether dragging ahead costs a download over a home uplink.
- A **settings popover** in the bar (2026-08-06) holding the two questions that are about the queue
  rather than the track: **Reihenfolge** — in order or shuffled — and **Wiederholung** — stop at the
  end or repeat. Both are queue state, so [`play-queue.md`](play-queue.md) owns what they do — its
  _Playing in a random order_ has the shuffle algorithm, what resets it, and why nothing about it is
  stored; this file owns where the control sits and why.
- Playback that survives the **tab being backgrounded** — and, as far as the platform allows, the
  **phone's screen being off**.

## The decision: a native `<audio>`, not vidstack

`app-rewrite.md` listed vidstack as "keep (or re-evaluate)". Re-evaluated: **none of the
above needs it.** Every item is `HTMLAudioElement` plus the Media Session API.

What vidstack is actually for is adaptive streaming (HLS/DASH) and a prebuilt skin. We
serve local MP3s, so the streaming half is dead weight, and the skin is the half we would
fight — the legacy app carried a whole `vendor/_vidstack.scss` to wrestle it, and this
codebase has a far stricter design system than that one did.

It would also **not** have helped with the hard part: vidstack wraps the same
`HTMLAudioElement` for progressive audio, so it inherits every background-playback
constraint below. There is no library that does not.

**The CSP question is closed.** vidstack would likely have wanted `media-src blob:`; a native
`<audio src>` pointed at a same-origin route satisfies the live `media-src 'self'` unchanged. That
is no longer an argument — `tests/e2e/app/player.spec.ts` injects the production policy verbatim
onto the document and plays a track under it, and the test fails if the directive is narrowed. The
open item in `04-going-public.md` is answered.

## The one genuine risk, and what we found

Background playback splits in two, and only one half is safe:

- **Tab backgrounded, or another window on top — fine everywhere.** Browsers exempt playing
  audio from background throttling. But `setInterval` is throttled to roughly once a minute
  in a hidden tab and `requestAnimationFrame` stops dead, so **auto-advance rides the
  `ended` event and never a timer**, and the progress bar **re-reads `currentTime`** rather
  than assuming it kept counting (there is a `visibilitychange` re-sync for exactly the stale
  reading a throttled `timeupdate` leaves behind).
- **Phone with the screen off — still uncertain, and no library changes it.** Audio keeps
  playing, but iOS suspends the page's JavaScript on lock. The current track finishes and the
  `ended` handler that would start the next one may not run until unlock. Android Chrome behaves
  better. **This is the one check the desktop suite cannot make**; Media Session metadata and
  handlers are wired, which is what gives the OS a chance to drive the queue itself.

## What the build learned

Four things worth writing down, because each cost time and none is obvious from the plan:

1. **Symfony already does HTTP Range.** This plan asserted that `response()->file()` does not.
   It does: `BinaryFileResponse::prepare()` reads the `Range` header and answers `206` with a
   `Content-Range` (and `416` for an unsatisfiable one), and Laravel calls `prepare()` on every
   response. Nothing had to be written by hand — only tested.
2. **`isPlaying` has to be INTENT, not `element.paused`.** Browsers fire `pause` immediately
   _before_ `ended`, so a state-derived flag reads "paused" exactly when the `ended` handler needs
   to know the listener still wants music — and playback stops after one track. `play()`/`pause()`
   set the intent; the `pause` event only clears it when the element did not merely reach the end;
   a `play()` the browser refuses puts it back. This is the single most load-bearing decision in
   `usePlayerAudio`, and it has a test that fails without it.
3. **Repeat-one moves no pointer.** `next()` reports success on a one-track queue on repeat
   without `currentIndex` changing, so the `watch(current)` that normally loads the next track
   never fires. The player compares the index before and after and reloads itself.
4. **Re-assigning the same `src` re-downloads the file.** Setting `src` runs the media load
   algorithm again, so repeat-one — and the same song sitting twice in a queue, which is a normal
   thing to do — rewinds via `currentTime = 0` instead, keeping the buffer it already had.

## How it fits together

**`SongStreamController`** (`GET /music/songs/{song}/stream`) follows `SongCoverController`: behind
auth, the same music-only `TrackType` check, `->setPrivate()` caching (this instance is
internet-facing, so a shared proxy must never hold a track), `Track::absolutePath()` for the file.
It sends the bytes **two ways**, chosen by `config('mixtape.stream.internal_prefix')`:

- **Set** (the live box, `MIXTAPE_STREAM_INTERNAL_PREFIX=/internal-media`) → an empty body plus
  `X-Accel-Redirect`, and nginx serves the file from an `internal;` location. Streaming a 96 GB
  collection through php-fpm would hold a worker for the length of every song. The URI is built
  from the **area key** (`TrackType::libraryPathKey()`) rather than by subtracting the media root
  from an absolute path, so each area is one `internal;` location whose `alias` is its
  `MIXTAPE_*_PATH` and there is no prefix arithmetic to get wrong. Every segment is
  `rawurlencode`d, because nginx URL-decodes the target and this collection is full of spaces,
  umlauts and `#`.
- **Unset** (dev, the test suite, `php -S`) → PHP sends the file, Symfony answers Range.

**`usePlayerAudio`** is a module singleton — there must be exactly one element making sound — that
takes the `<audio>` **`PlayerBar` renders** via `attach()`. In the DOM rather than `new Audio()`
because a real element is what iOS treats as a first-class media element and what a browser test
can inspect. Duration comes from **`QueueTrack.duration`**, not the element: a VBR MP3 with no
Xing/Info header reports `Infinity` until fully downloaded, and getID3 already measured every file
at scan time. Media Session metadata, position state and action handlers are wired here.

**What the player takes from the queue** is the loaded track and nothing more: `current` for the
metadata and the stream URL, `next()` / `previous()` to move, `repeat` to know whether the end wraps.
All four belong to `usePlayerQueue` — [`play-queue.md`](play-queue.md) owns them, including why
played tracks stay in the list, why the pointer follows the song rather than the index, and how the
whole thing is stored.

**`PlayerTimeline`** draws three stacked layers — rail, buffer segments, played fill — under a
transparent native `<input type="range">`. Native, because a div with pointer handlers means
re-implementing keyboard support, drag capture, touch and the ARIA slider contract. The input is
`hit` tall (a touch target) over a 6px rail (what the eye wants). A drag updates a **local**
position and only `change` commits the seek: one seek per pixel is one Range request per pixel.
The buffer is drawn as **segments**, not a single width, because after a seek past the buffer there
genuinely are two stretches with a hole between them.

**`PlayerSettings`** (built 2026-08-06) is a gear in the bar opening a popover with two rows,
separated by the same rule a `popover-list` draws between its entries: **Reihenfolge** (the play
order) and **Wiederholung** (what happens at the end). Three decisions in it worth keeping:

- **It is in the bar, not in the queue panel's menu**, which is where repeat lived first. The panel
  is behind a toggle on a phone and gone entirely once the queue is emptied, so a setting you want
  _while listening_ was hidden in both cases — and repeat sat one row above "clear the queue", a
  harmless toggle against a destructive verb.
- **Between the timeline and the volume button**, so the bar reads position → order → level → the
  buttons that change position. Both settings are about the queue, so they belong beside the thing
  that shows progress through it rather than after a control that is only about this one track.
- **Bubbles, not checkboxes** — `Components/UI/OptionBubbles`, the pattern the header's
  colour-scheme switch established and, since 2026-08-06, shares: `ThemeSwitch` was migrated onto it
  (169 lines to 69, its three token partials deleted), which is what proved the control handles three
  options as well as two. Each row is a choice between two _named modes_ ("shuffle off" is
  "in order", with its own glyph), which a lone checkbox cannot say; and being a native radiogroup, it
  gets arrow-key navigation for nothing. The two new glyphs are Material's `trending_flat` and
  `line_end`, since Material ships no `shuffle_off` / `repeat_off` — a straight arrow for "straight
  through" and a line ending in a dot for "the line stops here". The panel **opens upward** via the
  same anchor override `PlayerVolume` documents, and for the same reason.

**`PlayerBar`** is one grid with two shapes: on a phone the timeline takes a line of its own below
the cover, title and transport; from `landscape` up it moves into the row. So the bar's height is
not a constant — which is why it is measured with a `ResizeObserver` and published as
`--app-player-height` rather than read off a token.

**The two skip buttons ask the QUEUE whether they are enabled** (`hasNext` / `hasPrevious`) rather
than comparing the index themselves, which moved there when shuffle arrived: under a random order
"is there a track behind this one" is a fact about the shuffle walk, which is the composable's
private state, not about whether the index is above zero.

## Tests, and which layer answers what

- **PHPUnit** (`tests/Feature/Music/SongStreamTest.php`) — auth, the 404s (missing file, audiobook
  chapter, file absent from disk, with and without the nginx hand-off), a full `200` with
  `Accept-Ranges`, a `206` whose **bytes** are asserted, an open-ended range, a `416`, and the
  encoded `X-Accel-Redirect` for a path carrying umlauts, `#`, `&` and `+`.
- **Vitest** — the player's whole state machine against happy-dom's `<audio>` (which is real enough:
  `play()`/`pause()` flip `paused` and fire their events). The track boundary, repeat-one, the
  pointer moving for reasons that are not playback, duration fallback, buffered ranges becoming
  geometry, scrub-on-release. The queue's own suites — the pointer, the stored shape, the write path,
  the reorder — are in [`play-queue.md`](play-queue.md).
- **Playwright** (`tests/e2e/app/player.spec.ts`) — real playback, plus the three things about the
  settings popover that need a browser: that the gear really lands **between** the timeline and the
  volume button (a grid area's box), that the panel opens **upward**, and that the pill really
  **travels** (its offset is a `calc()` off two custom properties, which happy-dom does not resolve).
  The shuffle spec there asserts a **set**, not a sequence — three tracks each heard once and then the
  queue stops — which is what makes a random control testable without stubbing the randomness. The
  E2E fixture now writes a
  **copy of the committed one-second mp3 at every path `E2ESeeder` claims** (`seedMediaFiles`), so
  the stream route serves real audio. One second is a feature: auto-advance is the headline
  behaviour and a track that ends in a second makes it fast and deterministic. The consequence is
  that the file's length and the row's claimed duration **disagree**, so nothing there asserts a
  position derived from the rail's width — that geometry is Vitest's.

## Deploy

- **The sprite is gitignored**, so the **dev** box needs `npm run icons` for `pause` and `repeat`.
  Prod does not: its deploy script already runs it. See [[phase-2-toast-flash-pattern]] in the memory
  for that trap.
- **Add `MIXTAPE_STREAM_INTERNAL_PREFIX=/internal-media` to the prod `.env`** and install the
  `internal;` locations from `self-hosting/files/mixtape.prod.nginx.conf`. **Add the locations and
  reload nginx first**, then flip the `.env`: in between, every track is broken. The full procedure,
  including the `curl` that proves `internal` works before anything is flipped, is
  [`03-production-deploy.md` → *Media hand-off*](self-hosting/03-production-deploy.md#media-hand-off-x-accel-redirect).

  **Do the same on the dev box** (`self-hosting/files/mixtape.dev.nginx.conf` ships the block). Not for
  speed — for rehearsal: a workstation running `php -S` has no nginx to interpret the header, so dev is
  the only place the accelerated path can be exercised before production meets it.

  **Prod caches its config**, so the `.env` edit does nothing on its own. Add the key *before* a deploy
  (the deploy script runs `optimize:clear` + `config:cache` regardless) or run those two by hand after.

  The ways to get it wrong fail differently, and none of them looks like the others:

  - **Prefix set, no matching `internal;` location** → **500**, and the only trace is in nginx's
    error log: `rewrite or internal redirection cycle while internally redirecting to
    "/index.php"`. **Nothing appears in Laravel's log at all**, because no PHP exception is thrown —
    nginx internally redirects to a URI nothing serves, the vhost's `location /` catches it,
    `try_files` bounces it back into `index.php`, and nginx refuses to redirect there twice in one
    request. Observed on the dev site 2026-08-03; expect the same shape for a mistyped `alias`,
    though a location that matches with a genuinely missing file is nginx's own 404 instead.
  - **Prefix set with no nginx in front at all** (`artisan serve`, `php -S`) → nothing interprets
    the header, so the browser gets a literal **`200` with an empty body** under
    `Content-Type: audio/mpeg`.
  - **A blank `.env` value is an empty STRING, not null.** This is what actually broke the dev site:
    `MIXTAPE_STREAM_INTERNAL_PREFIX=` read as `""`, a `=== null` guard treated that as configured,
    and every stream 500'd with the cycle above. The guard is now
    `trim((string) config(…)) === ''`, matching how an unconfigured library area is detected
    (`LibraryScanService::scanArea`) — **use that idiom for any future config flag in this app**, and
    never `=== null` against a value that comes from `env()`.

  To tell which side actually served a track, read the `ETag`: ours is Symfony's `setAutoEtag()`
  content hash, nginx's static handler writes `"<hex-mtime>-<hex-size>"` next to its own
  `Last-Modified`. `Content-Length` is **not** a discriminator — it is a real byte count either way.
