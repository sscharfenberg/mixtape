# The player

The build plan for actually playing audio, and the record of what building it settled. Written
2026-08-02 after the queue scaffold landed (`6d8a0fa` → `5fba7cd`) and the vidstack-vs-native
question was closed; **built 2026-08-03.**

Read [`data-model.md`](data-model.md) → _The play queue_ first — it decides the shape of the
queue and its persistence, which this builds on rather than revisits.

## Status: built

| Piece                                                         | State                                             |
| ------------------------------------------------------------- | ------------------------------------------------- |
| `GET /music/songs/{song}/stream` (Range + `X-Accel-Redirect`) | ✅ `SongStreamController`, 11 PHPUnit tests       |
| `pause` / `repeat` icons                                      | ✅ (sprite is gitignored — see _Deploy_ below)    |
| `usePlayerAudio`                                              | ✅ 30 Vitest tests                                |
| Repeat on the queue                                           | ✅ `usePlayerQueue` + `PlayQueueMenu` toggle      |
| Transport UI + timeline **+ buffer indicator**                | ✅ `PlayerBar`, `PlayerTimeline`, 28 Vitest tests |
| Real playback in a browser                                    | ✅ 13 Playwright specs, incl. under the prod CSP  |
| Reordering the queue (drag + Alt+↑/↓)                         | ✅ `useQueueReorder`, 20 Vitest + 6 Playwright    |
| Screen-off on a real phone                                    | ⬜ **the owner's, on the device that matters**    |

**Not built, deliberately:** server-side queue sync (`player_states` is still write-nobody-reads —
`data-model.md` owns that plan), shuffle, a play-history beacon, and any UI for a stream that fails
(a track whose file vanished between scans stops the player and returns the glyph to _play_; it
raises no toast and does not skip on).

## What we built

- Play / pause, previous, next.
- A timeline showing the **cursor position** and the track's **total playing time**, scrubbable —
  plus a **buffer indicator** (the owner's addition to this plan): the stretches the browser
  already holds, so a listener can see whether dragging ahead costs a download over a home uplink.
- A **repeat** toggle: with the queue on repeat, the end wraps to the first track; with it off,
  playback stops on the last one.
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

**Repeat** is a flag on `usePlayerQueue`, persisted with the queue — what happens after the last
track is a fact about the _list_. It survives `clear()`, because "I listen on repeat" is a habit
rather than a property of whatever is queued right now. The storage key went to
**`mixtape.queue.v2`** (tracks gained `streamUrl`, the payload gained `repeat`); a v1 payload is
discarded rather than migrated, since a v1 track has no stream URL and would sit in the panel
looking playable.

**Played tracks stay in the queue and the pointer moves** — they are not consumed. That is what
makes `previous()` and `jumpTo()` work, and it is the shape `data-model.md` specifies.

**`PlayerTimeline`** draws three stacked layers — rail, buffer segments, played fill — under a
transparent native `<input type="range">`. Native, because a div with pointer handlers means
re-implementing keyboard support, drag capture, touch and the ARIA slider contract. The input is
`hit` tall (a touch target) over a 6px rail (what the eye wants). A drag updates a **local**
position and only `change` commits the seek: one seek per pixel is one Range request per pixel.
The buffer is drawn as **segments**, not a single width, because after a seek past the buffer there
genuinely are two stretches with a hole between them.

**Reordering the queue** (built 2026-08-04) is two gestures over one operation. `reorder(from, to)`
already existed on `usePlayerQueue`, tested and called by nothing — so this was only ever a gesture
problem, and both gestures go through it rather than touching `tracks`: it is what carries the
pointer with the track that was **loaded** (drag something above the playing row and an index-based
pointer would hand the player a different song) and what persists the new order.

- **Dragging**, by **SortableJS used directly** — no Vue wrapper. `vuedraggable` /
  `vue-draggable-plus` exist to own the list through `v-model`, which is exactly what must not
  happen when the queue is a module singleton that persists itself; in controlled mode a wrapper
  earns nothing, and this app keeps very few runtime dependencies. Native HTML5 DnD was out from
  the start: no events on touch, and the panel has a phone mode. What SortableJS is actually paying
  for is touch, auto-scroll near the ends of the scrolling list, and `handle`.
  - **`forceFallback: true`**, so its own pointer path runs on every device instead of native
    dragging on desktop and the fallback elsewhere. One code path, drag visuals we style
    (`--dragging` on the clone, `--ghost` on the gap it left), no reliance on a browser agreeing to
    start a native drag from inside a `<button>` — and it is the path Playwright can drive with
    plain mouse moves.
  - **The DOM move is undone before the queue is told.** Sortable has already moved the `<li>` when
    `onEnd` fires, which leaves two writers on one list; restoring it first means Vue's re-render is
    the only thing that reorders anything. This is what the wrappers do internally, and skipping it
    is how a wrapper-less integration duplicates or loses a row.
  - `delay` + `delayOnTouchOnly` (a long-press on touch only), or a finger dragging a row steals the
    gesture that scrolls the list. `animation` is `0` under reduced motion and otherwise `150`,
    mirroring `ti.$c-play-queue` — a JS option cannot read the Sass token, so the two are kept in
    step by hand.
- **Alt+↑/↓** moves the **focused** row one place. Alt is what keeps it out of the way: a bare arrow
  is how the panel is scrolled. The keystroke is only consumed when a move actually happens, so the
  ends of the queue do not silently eat one. **Focus has to be put back by hand** — the row's
  `v-for` key carries its index (it must: the same song may be queued twice), so the element holding
  focus is replaced by the move, and without re-focusing the same control in the row's new position
  the journey ends after a single press.
  - **"Focused" is the whole story of the one bug this shipped with.** Hover is not focus, so
    pointing at a row and pressing the keys does nothing — reported from a Mac as "it doesn't work",
    and it worked the moment the grip was clicked. Two things came out of that. The grip's hint now
    **says to click it first** (`player.queue.moveHint`), because nothing else on screen did. And the
    grip **focuses itself on `pointerdown`**, because macOS Safari and Firefox deliberately leave a
    clicked `<button>` unfocused (platform convention) — without it the instruction would only be
    true in Chrome. Chromium does not treat that programmatic focus as `:focus-visible`, so a mouse
    click still draws no focus ring.
  - **The keys are named for the keyboard in front of the reader** — ⌥↑/↓ on an Apple one, Alt+↑/↓
    elsewhere (`utils/platform.ts`). A hint that says "Alt" to someone looking at a ⌥ key is naming
    a key they cannot find. Only the WORDS branch: the handler still reads `event.altKey`, which is
    one bit with two names printed on it, and `aria-keyshortcuts` keeps ARIA's canonical
    `Alt+ArrowUp Alt+ArrowDown` for assistive tech to announce in its own words.

**The handle is the cover with the drag glyph beneath it** — one 24px-wide grip strip, the owner's
design. At 280px the row has no horizontal room to give (the title ellipsises first), so a leading
or trailing handle column would have cost the title 24px; stacking costs it nothing and grows the
row from 47px to **54px** instead. The glyph alone would have been a 16px target, which is not
something to aim a drag at on a phone. What it costs: the cover had to leave the play button to
become the grip, so **tapping the cover no longer plays the track** — the other ~90% of the row
still does. That also turned the play overlay from a `::after` on a button wrapping the cover into
an **empty button positioned across the row**: same hit area with one box instead of two, and it has
a real bounding box, so its focus ring traces the row and a browser test can click it.

**`PlayerBar`** is one grid with two shapes: on a phone the timeline takes a line of its own below
the cover, title and transport; from `landscape` up it moves into the row. So the bar's height is
not a constant — which is why it is measured with a `ResizeObserver` and published as
`--app-player-height` rather than read off a token.

## Tests, and which layer answers what

- **PHPUnit** (`tests/Feature/Music/SongStreamTest.php`) — auth, the 404s (missing file, audiobook
  chapter, file absent from disk, with and without the nginx hand-off), a full `200` with
  `Accept-Ranges`, a `206` whose **bytes** are asserted, an open-ended range, a `416`, and the
  encoded `X-Accel-Redirect` for a path carrying umlauts, `#`, `&` and `+`.
- **Vitest** — the player's whole state machine against happy-dom's `<audio>` (which is real enough:
  `play()`/`pause()` flip `paused` and fire their events). The track boundary, repeat-one, the
  pointer moving for reasons that are not playback, duration fallback, buffered ranges becoming
  geometry, scrub-on-release. For the reorder, **SortableJS is mocked on purpose**: a drag is a
  stream of pointer events over elements with real geometry, so a "drag" here would assert the
  mock's own arithmetic. What this layer proves is the contract around the library — the options it
  is handed, the DOM move being undone before the queue is told, the instance following the list in
  and out of the DOM (it is behind a `v-if`, so `onMounted` would work once and then stop after a
  clear), and every Alt+↑/↓ decision.
- **Playwright** (`tests/e2e/app/player.spec.ts`) — real playback. The E2E fixture now writes a
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
