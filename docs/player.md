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
| The level over the page while it changes                      | ✅ `PlayerVolumeHud`, 2026-08-07               |
| Playback-settings popover (order / at the end)                | ✅ `PlayerSettings`, 2026-08-06                |
| Keyboard shortcuts, incl. hold-Space to skim                   | ✅ `usePlayerShortcuts`, 2026-08-07            |
| Playback speed 1× / 2× / 3×, persisted                        | ✅ `usePlayerSpeed`, 2026-08-07                |
| A stream that fails, and what it says                         | ✅ `Utils/playbackError`, 2026-08-07           |
| Real playback in a browser                                    | ✅ Playwright, incl. under the prod CSP        |
| Screen-off on a real phone                                    | ✅ **Android / Chrome, 2026-08-07** — below    |

**Still not built, deliberately:** the queue does not SKIP ON past a track that will not play. It
says which one failed and stops there, because a file that vanished between scans is worth noticing
rather than stepping over — a queue that quietly walked past every broken track would leave a
collection rotting silently. The queue's own gaps (server sync, the play-history beacon) are listed
in [`play-queue.md`](play-queue.md).

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
- **Keyboard shortcuts** (2026-08-07) — see below.
- A **playback speed** — 1× / 2× / 3× — in the settings popover (2026-08-07), remembered
  across visits. See *Playback speed* below.
- A **message when a track will not play** (2026-08-07), naming it. See *When a stream fails* below.
- The **level, big, in the middle of the screen** while it is being changed (2026-08-07) — the
  keyboard's only feedback. See *The volume readout* below.

## Playback speed

`usePlayerSpeed`, a third row in the settings popover. Split out of `usePlayerAudio` for the
reason `usePlayerVolume` was: one element property, one storage key, and nothing to do with the
intent flag or the queue pointer. `usePlayerAudio` still owns the element and hands it over via
`bindSpeedElement()`.

**Two numbers, deliberately.** `speed` is the SETTING — what the popover shows, what is
persisted (`mixtape.speed.v1`, not user-scoped, like the volume key). `effectiveRate` is what the
element is doing: the setting, doubled while Space is held. Collapse them and two things break
silently — a hold persists 6× as the reader's stated preference, and releasing at a 3× setting
drops them to 1× instead of back to 3×. The skim is therefore RELATIVE: 3× skims at 6× and lands
back on 3×.

A stored value is validated against the offered list rather than range-checked, because it is
assigned straight to `element.playbackRate` and a `2.5` from a future build would leave the
popover with no option lit.

**The row shows both numbers.** The pill marks the SETTING and never moves for a skim — it
could not anyway, since 3× skims to 6× and that is not one of the options. Beside it, a `▸ 6×`
readout says what is actually playing, visible only while a skim is on. Three details:

- It is **reserved, not conditional** (`visibility`, not `v-if`). The popover is `width: auto`,
  so a readout that came and went would resize the panel under a reader holding a key down.
- It sits **before** the bubbles, which is the only placement keeping all three rows' controls
  flush to one right edge.
- The `▸` is load-bearing: without it, `6× 1× 2× 3×` reads as four options.
- It is `aria-hidden`, because the bar's badge is already a `role="status"` announcing the same
  figure — two live regions means hearing the change twice.

**Reaching it is narrower than it looks**, and this is measured rather than assumed. With the
popover open, focus is normally on a radio — and a focused radio owns Space, so the obvious
"open the gear, hold Space" gesture correctly does *not* skim:

| Action | Focus lands on | Space held |
| --- | --- | --- |
| Open the gear | `<input>` (a radio) | no skim — guarded, correctly |
| Click a bubble | `<input>` | no skim |
| Click the row's label text | the `<dialog>` | **skims** (2× → 4×) |

So the readout is reachable, but only after a click on the panel's own non-interactive text.
That is a consequence of the guards being right, not a bug in them — Space on a focused radio
belongs to the radio. The bar's badge is the readout that is always visible.

### Is 3× a problem? No — measured, not assumed

YouTube gates its 3×/4× behind Premium, so the question came up. **In the browser there is no
wall.** Measured in this project's own Chromium against a synthesised 440 Hz tone:

| Asked | Effective rate | Peak RMS | Peak frequency |
| ----- | -------------- | -------- | -------------- |
| 1×    | 1.00           | 0.6912   | 441 Hz         |
| 2×    | 1.99           | 0.6912   | 441 Hz         |
| 3×    | 2.99           | 0.6912   | 441 Hz         |
| 4×    | 3.98           | 0.6912   | 441 Hz         |
| 6×    | 6.05           | 0.6912   | 441 Hz         |

The rate is honoured exactly, the output level never changes (the engine does not mute), and
`preservesPitch` holds — the peak stays at 441 Hz instead of sliding to 1323 Hz at 3×. So 3 × the
skim's 2 = 6 is inside what the engine does properly.

Two honest caveats. A pure sine is the EASIEST case for a time-stretcher; how it handles
transients in real music is a matter of taste, not capability — which is why the speeds are a
short chosen list rather than a slider. And this is Chromium on macOS; other engines were not
measured. `preservesPitch` is written on every rate change for that reason.

**What YouTube's gate is probably about is bandwidth, not the codec.** Playing at 3× consumes the
stream three times as fast, so a 320 kbit/s MP3 becomes ~960 kbit/s sustained per listener. At
YouTube's scale that is an infrastructure line item. It is not nothing here either: this app
serves from a home uplink to family and friends, which is the same reason the timeline carries a
buffer indicator at all.

## The volume readout

`PlayerVolumeHud`, built 2026-08-07 to the owner's brief: a box in the middle of the viewport
showing the new level as a percentage, subtle grey, click-through, gone after a couple of seconds.

**It exists for the keyboard.** ↑/↓ change the volume from anywhere on the page, and the only thing
that moved was a slider inside a *closed* popover — so the one gesture with no control attached to it
was also the one with no feedback. A drag on the slider raises the box too, which costs nothing: the
popover has its own readout, but the pointer is usually sitting on top of it.

**It is teleported to `<body>`, and that is load-bearing rather than tidy.** `PlayerBar` is the
obvious parent and where it is written, but the bar carries `backdrop-filter: blur(12px)` to frost
itself — and a filtered element becomes the **containing block for its `position: fixed`
descendants**. Left inside the bar, "the middle of the viewport" resolves to the middle of a 60px
strip along the bottom edge. Anything else fixed that the bar ever renders meets the same trap.

**It watches the percentage, not the level**, which is what makes `M` show up here too: muting is a
volume gesture with even less on screen than the arrows, and it moves the figure to 0 and back. The
one press that shows nothing is a key at a ceiling — ↑ at 100% clamps to the level it already had, so
nothing changed and nothing is announced. By then the box is up anyway from the press before it.

**No ARIA, deliberately.** The box is `aria-hidden`: a screen reader announcing every step of a
five-press climb would be reading noise, and the slider it mirrors already carries `aria-valuetext`,
which answers "how loud is it?" for anyone navigating the control rather than the page. (That is the
opposite call from the speed badge, which *is* a `role="status"` — a rate change is one event worth
one sentence, where a level is a stream of them.)

**Only the leave is animated.** Arriving is feedback for a key that has just been pressed, so it has
to be there instantly; an ease-in would still be arriving when the next press lands. The fade out is
the box's own doing and says "this timed out" rather than "this blinked" — under
`prefers-reduced-motion` there is no transition at all.

**It centres on the layout viewport, which is not the window.** A fixed box resolves `left: 50%`
against the viewport *minus* the scrollbar, and this app reserves one on the root permanently. So on
a 1280px window the box centres on 632.5 — half of 1265, dead centre of everything the reader can
see, and 7.5px off the window's middle. The E2E assertion subtracts the scrollbar rather than
hard-coding it; measuring against `window.innerWidth` reports a correctly centred box as wrong.

## When a stream fails

`Utils/playbackError`, built 2026-08-07. Until then the player answered a dead track by stopping and
putting the glyph back on _play_ — which is honest, and **indistinguishable from a player somebody
paused**. A file that vanished between library scans therefore looked like the app ignoring a click.

The toast (`useToast`, the same singleton the flash messages use) names the track and says which kind
of failure it was. Everything below is one small module beside `mediaSession`, and for the same
reason: it is one-way output, it holds nothing reactive, and it never touches the element — the
player hands it the element's `MediaError` and the track that was loading.

**Two messages, off `MediaError.code`.** `MEDIA_ERR_NETWORK` is worth trying again; anything else is
a file that is gone or unreadable, which is a re-scan rather than a retry. The distinction is the
whole reason this is not a one-line `addToast` at the call site.

**`MEDIA_ERR_ABORTED` says nothing at all**, because that one is this app's own doing: re-pointing
the element at the next track and letting go of the file when the queue empties both cancel a
download in flight. A toast there would fire on ordinary use.

**One failure is announced once, and this needed thought.** A failed load reports itself twice — the
element fires `error`, and the `play()` promise for the same source rejects a tick later — and both
paths must report, because each is the only one that fires in some situation. The tie-break is the
`MediaError`'s **identity**: a browser mints one object per failure, so the same object is the same
failure, while a fresh load that fails again brings a new one.

**A repeat press is deliberately not a repeat report.** The element keeps its one `MediaError` for as
long as a dead source is loaded, so a second press produces no `error` event at all — only a rejected
promise for a failure already announced. Pressing play and getting nothing whatsoever is the exact
silence this module was built to remove, so `play()` clears the memory first: ask again, get an
answer again.

The `play()` rejection is the reason the element is consulted rather than the rejection itself. A
refusal is usually the **autoplay policy** — nothing is broken, the next press works — and only a
source the element has given up on leaves a `MediaError` behind. That check is what keeps an ordinary
page load quiet.

## Keyboard shortcuts

`usePlayerShortcuts`, bound on the document by PlayerBar.

| Key                | Does                                             |
| ------------------ | ------------------------------------------------ |
| `Space` / `K`      | play / pause                                     |
| **`Space` held**   | **doubles the chosen speed while held** — see below |
| `←` / `→`, `J`/`L` | seek ∓5 s                                        |
| `⇧←` / `⇧→`, `P`/`N` | previous / next track                          |
| `↑` / `↓`          | volume ±5 % — the level shows in the middle of the screen |
| `M`                | mute                                             |
| `S` / `R`          | shuffle / repeat                                 |

Media keys, the lock screen and a car head unit were already wired (`mediaSession.ts`) and are
unaffected — this is for a keyboard without transport keys.

The skim is RELATIVE to the chosen speed (see *Playback speed* above), so a listener at 3× skims
at 6× and lands back on 3× rather than on 1×.

**Space toggles on key-UP, and that is forced rather than chosen.** One key carries two gestures,
and a hold cannot be recognised until it has lasted a while: dispatching the toggle on key-down
would mean every skim began by pausing the track it wants to skim through. The cost is that
play/pause lands when you let go — for a real tap that is your own release time, and is not
perceptible. A release that ended a skim does **not** toggle.

The hold engages only while audio is **already playing** (holding Space on a paused player is just a
slow tap), and `preservesPitch` is set alongside the rate — without it the skim is an octave up and
unlistenable rather than merely quick. **The key-up can go missing**: switch windows mid-hold and
the release is delivered elsewhere, so `blur` and `visibilitychange` both end the skim, or the track
stays at 2× with no key down and no way back but pressing Space again.

**The listener lives on PlayerBar, and that placement IS the scoping rule.** FullLayout renders the
bar with `v-if="current"`, so with an empty queue there is no document listener at all and `Space`
scrolls the page exactly as it always did. An app that quietly took `Space` from every page forever
would be a worse bug than any of the ones the guards below fix.

**Four guards give the keys back**, in the order they would bite:

1. **Text entry** — a space in a password would otherwise pause the music, with nothing to connect
   the two. Every field here is a real `<input>` (`FormInput` renders one), so the check catches
   them all; the letters are guarded too, because `M` in a passphrase is the same bug.
2. **Focused controls** — the half a "not while typing" rule misses. `Space` **activates** a focused
   button and toggles a focused checkbox, so submitting a form with the keyboard would also toggle
   playback; the arrows drive a range input (the volume rail and the timeline are both one), a radio
   group (the widget mode toggle) and TabbedNavigation's tabs. The list is
   [`Utils/interactive`](../resources/app/utils/interactive.ts), **shared with DataTable's row
   navigation** — the same judgement, and two copies would drift.
3. **`defaultPrevented`** — the general form of 2, for anything a selector cannot name.
4. **Modifiers** — Ctrl/Cmd belong to the browser, Alt is the queue's reorder gesture. Shift is
   part of the keymap, not a reason to bail.

Discoverability is the transport tooltips only, which name the key beside the label. The
`aria-label` stays the plain label deliberately: a key hint is a visual convenience, and reading
"Nächster Titel Shift Pfeil rechts" aloud on every focus is worse than not knowing the shortcut.

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

## The one genuine risk, and what we found — settled

Background playback splits in two, and **both halves now hold**:

- **Tab backgrounded, or another window on top — fine everywhere.** Browsers exempt playing
  audio from background throttling. But `setInterval` is throttled to roughly once a minute
  in a hidden tab and `requestAnimationFrame` stops dead, so **auto-advance rides the
  `ended` event and never a timer**, and the progress bar **re-reads `currentTime`** rather
  than assuming it kept counting (there is a `visibilitychange` re-sync for exactly the stale
  reading a throttled `timeupdate` leaves behind).
- **Phone with the screen off — verified working, 2026-08-07.** The owner's check, on Android /
  Chrome, on the device that matters: the playing track runs to its end with the screen off **and
  the next one starts by itself**. That is the whole headline feature, and it is the one thing the
  desktop suite structurally cannot answer — the plan called it the single genuine risk and it is
  now closed. What earned it is `ended`-driven auto-advance plus wired Media Session metadata and
  handlers, which is what lets the OS keep the page alive as a media session rather than a
  backgrounded tab.

  **iOS is out of scope, not outstanding.** The worry this section used to carry was iOS suspending
  page JavaScript on lock, which would strand the `ended` handler until unlock. The owner has no
  Apple device and does not target one, so it is nobody's open item — if an iPhone ever turns up,
  this is the paragraph that says what to look for.

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
- **Between the volume button and the transport** (the owner's placement, moved there from beside the
  timeline), so the bar reads position → level → order → the buttons. It sits closest to the skip
  buttons because that is what these two settings change: what "next" will play, and whether there is
  a next at all.
- **Bubbles, not checkboxes** — `Components/UI/OptionBubbles`, the pattern the header's
  colour-scheme switch established and, since 2026-08-06, shares: `ThemeSwitch` was migrated onto it
  (169 lines to 69, its three token partials deleted), which is what proved the control handles three
  options as well as two. Each row is a choice between two _named modes_ ("shuffle off" is
  "in order", with its own glyph), which a lone checkbox cannot say; and being a native radiogroup, it
  gets arrow-key navigation for nothing. The two new glyphs are Material's `trending_flat` and
  `line_end`, since Material ships no `shuffle_off` / `repeat_off` — a straight arrow for "straight
  through" and a line ending in a dot for "the line stops here". The panel **opens upward** via the
  same anchor override `PlayerVolume` documents, and for the same reason.
- **It is what found the popover width bug** (reported from Android Chrome, 2026-08-07, and
  reproduced in Chrome's Pixel 7 emulation). The shared popover style capped every floating panel at
  `50dvw`; this panel is `width: auto` over `white-space: nowrap` rows and measures **250px** with
  German labels, against a cap of **206px** on a 412px-wide phone. It clipped its own controls
  against the right edge and grew a horizontal scrollbar inside itself. Half the screen is a rule
  that only makes sense on a screen with halves to spare, so the cap is now the viewport minus a
  gutter, and every `ch`-width popover was one notch behind the same problem (24ch is wider than
  50dvw under ~400px). **360px needed a second fix**: the gear's right edge sits 222px in, so a 250px
  panel anchored to it runs off the LEFT of the screen and no flip helps — both flips move a panel to
  the other side of its trigger, which does nothing for a panel wider than the room that trigger
  leaves. A last-resort `@position-try --popover-flush-inline` pins it a gutter in from the viewport
  instead, trading alignment with the button for every control being reachable.

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
  geometry, scrub-on-release. `playbackError.test.ts` covers the failure message on its own — the
  classification, the silence for an aborted load, one failure announced once however many times it
  is reported, and a fresh answer after a fresh press — while `usePlayerAudio`'s own spec checks only
  the wiring: that the element's error and the track it was loading both reach the reporter, that a
  press on a dead track is answered, and that an autoplay refusal stays quiet. `PlayerVolumeHud`'s
  spec is about WHEN the box is on screen — three of its four cases are about silence (a page load
  must not raise it, a clamped press must not raise it, and it has to go away on its own). The
  queue's own suites — the pointer, the stored shape, the write path, the reorder — are in
  [`play-queue.md`](play-queue.md).
- **Playwright** (`tests/e2e/app/player.spec.ts`) — real playback, plus the things about the
  settings popover that need a browser: that the gear really lands **between** the timeline and the
  volume button (a grid area's box), that the panel opens **upward**, that the pill really
  **travels** (its offset is a `calc()` off two custom properties, which happy-dom does not resolve),
  and — since 2026-08-07 — that the panel **fits on a phone at 412 and at 360**, which are two
  different cases rather than one repeated (see `PlayerSettings` above). A **failed stream** is here
  too: `page.route` answers the stream with a 404, because a `MediaError` is minted by the media
  stack from a real HTTP response and neither the code nor the event can be faked honestly.

  The **volume readout** is covered in `tests/e2e/app/shortcuts.spec.ts` instead, beside the arrows
  that raise it, and all three of its assertions are things only an engine knows: where a fixed box
  teleported out of a filtered parent actually lands, that `elementFromPoint` in the middle of the
  screen answers with something *behind* it, and that it times out on a real clock.

  **Every popover box is measured only after `openPopover`**, a helper that waits for two identical
  bounding boxes in a row. The panel opens with a `rotateY`, a transform is included in
  `getBoundingClientRect`, and `:popover-open` is true from the first frame — so a box read on the
  click is a couple of pixels from where it lands. That made the geometry assertions a coin flip
  which happened to keep landing heads: "opens upward" failed by 1.3px on one run and 2.9px on the
  next, both against positioning code that had not changed.
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
