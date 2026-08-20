# The player

Everything that makes sound: the stream route, the audio element, the transport, and background
playback.

Read alongside:

- [`play-queue.md`](play-queue.md) — what the queue holds, how it is stored, the panel, reordering, and
  shuffle. The player takes the loaded track from it and nothing more.
- [`data-model.md`](data-model.md) → *The play queue* — the shape both files build on.

## The decision: a native `<audio>`, not a player library

Everything the player does is `HTMLAudioElement` plus the Media Session API. A library like
`vidstack` is built for **adaptive streaming** (HLS/DASH) and a prebuilt skin: MixTape serves local
MP3s, so the streaming half is dead weight, and the skin is the half you would fight — this codebase
has a strict design-token system, and wrestling a vendor stylesheet into it costs more than writing a
transport.

It would also **not** have helped with the hard part: any such library wraps the same
`HTMLAudioElement` for progressive audio, so it inherits every background-playback constraint below.
There is no library that does not.

The CSP question falls out the same way. A native `<audio src>` pointed at a same-origin route
satisfies `media-src 'self'` unchanged, where a MediaSource-based library would want `media-src blob:`.
`tests/e2e/app/player.spec.ts` injects the production policy verbatim onto the document and plays a
track under it, so narrowing that directive fails the suite.

## Background playback — the one genuine risk, and what holds

This splits in two, and both halves work:

- **Tab backgrounded, or another window on top.** Browsers exempt playing audio from background
  throttling. But `setInterval` is throttled to roughly once a minute in a hidden tab and
  `requestAnimationFrame` stops dead, so **auto-advance rides the `ended` event and never a timer**,
  and the progress bar **re-reads `currentTime`** rather than assuming it kept counting (there is a
  `visibilitychange` re-sync for exactly the stale reading a throttled `timeupdate` leaves behind).
- **Phone with the screen off.** Verified on Android / Chrome: the playing track runs to its end with
  the screen off **and the next one starts by itself**. That is the whole headline feature, and it is
  the one thing a desktop test suite structurally cannot answer. What earns it is `ended`-driven
  auto-advance plus wired Media Session metadata and handlers, which is what lets the OS keep the page
  alive as a media session rather than as a backgrounded tab.

**iOS is out of scope.** The worry there would be iOS suspending page JavaScript on lock, which would
strand the `ended` handler until unlock. Nobody targets an Apple device on this instance; if one ever
turns up, that is what to look for, and `/dev/audio-probe` (below) is how to measure it.

## How it fits together

### The stream route

**`SongStreamController`** (`GET /music/songs/{song}/stream`) sits behind `auth` with the same
music-only `TrackType` check the cover route uses, marks its response `setPrivate()` (this instance is
internet-facing, so a shared proxy must never hold a track), and resolves the file through
`Track::absolutePath()`. Audiobook chapters have their own equivalents under `/audiobooks/chapters/…`.

It sends the bytes **two ways**, chosen by `config('mixtape.stream.internal_prefix')`:

- **Set** (a real server, `MIXTAPE_STREAM_INTERNAL_PREFIX=/internal-media`) → an empty body plus
  `X-Accel-Redirect`, and nginx serves the file from an `internal;` location. Streaming a large
  collection through php-fpm would hold a worker for the length of every song. The URI is built from the
  **area key** (`TrackType::libraryPathKey()`) rather than by subtracting the media root from an
  absolute path, so each area is one `internal;` location whose `alias` is its `MIXTAPE_*_PATH` and
  there is no prefix arithmetic to get wrong. Every segment is `rawurlencode`d, because nginx
  URL-decodes the target and a real collection is full of spaces, umlauts and `#`.
- **Unset** (local development, the test suite, `php -S`) → PHP sends the file, and Symfony answers
  `Range`.

**Symfony already does HTTP Range**, which is worth knowing before writing any of it by hand:
`BinaryFileResponse::prepare()` reads the `Range` header and answers `206` with a `Content-Range` (and
`416` for an unsatisfiable one), and Laravel calls `prepare()` on every response. Nothing had to be
written — only tested.

Deploying the nginx hand-off has several ways to go wrong, none of which looks like the others; they
are catalogued in
[`self-hosting/03-production-deploy.md` → *Media hand-off*](self-hosting/03-production-deploy.md#media-hand-off-x-accel-redirect).
The one worth repeating in an app document: **a blank `.env` value is an empty string, never null**, so
guard an env-driven flag with `trim((string) config(…)) === ''` and never `=== null`.

### `usePlayerAudio`

A **module singleton** — there must be exactly one element making sound — that takes the `<audio>`
**`PlayerBar` renders**, via `attach()`. In the DOM rather than `new Audio()` because a real element is
what iOS treats as a first-class media element and what a browser test can inspect.

Four things in it are load-bearing:

1. **`isPlaying` is INTENT, not `element.paused`.** Browsers fire `pause` immediately *before* `ended`,
   so a state-derived flag reads "paused" exactly when the `ended` handler needs to know the listener
   still wants music — and playback stops after one track. `play()` / `pause()` set the intent; the
   `pause` event only clears it when the element did not merely reach the end; a `play()` the browser
   refuses puts it back. This is the single most load-bearing decision in the module, and it has a test
   that fails without it.
2. **Repeat-one moves no pointer.** `next()` reports success on a one-track queue on repeat without
   `currentIndex` changing, so the `watch(current)` that normally loads the next track never fires. The
   player compares the index before and after and reloads itself.
3. **Re-assigning the same `src` re-downloads the file.** Setting `src` runs the media load algorithm
   again, so repeat-one — and the same song sitting twice in a queue, which is a normal thing to do —
   rewinds via `currentTime = 0` instead, keeping the buffer it already had.
4. **Duration comes from `QueueTrack.duration`, not the element.** A VBR MP3 with no Xing/Info header
   reports `Infinity` until fully downloaded, and getID3 already measured every file at scan time.

Media Session metadata, position state and action handlers are wired here, so media keys, the lock
screen and a car head unit all work.

**The element carries `preload="none"`**, and that is not a micro-optimisation. `preload="metadata"` is
**not one request**: a hydrated queue sets `src` on every page load, and to answer "metadata" the engine
range-hops the file — front for the Xing header, tail for the ID3v1 tag — **opening a new HTTP request
per range** and aborting the one in flight once it has enough. On a long track that is several requests
and many megabytes per reload with nothing playing, and because the stream route is behind auth, those
are full PHP requests rather than static hits. Nothing on the page wants them: the timeline's total
comes from the queue, and a restored position is applied on `loadedmetadata`, which under
`preload="none"` simply arrives when playback starts.

That last clause is load-bearing in one direction nobody expects: playback may start on a track the
position was never measured on, because a reader is free to start a different album instead of the
restored queue. So the resume travels with the id of the track it belongs to and is applied only if the
element's loaded track matches — [`play-queue.md`](play-queue.md) → *Picking up mid-track* has all three
hops of that pairing.

What is given up is the warm buffer on that first press — one round trip — and scrubbing before
pressing play actually gets *better*, because the fetch then starts at the seek point rather than at
zero. It is pinned by a Playwright spec (*cues a restored queue without fetching a byte of it*) rather
than a Vitest one: happy-dom has no network behind an `<audio src>`, so there the attribute would be
all there was to check, and the consequence is the thing worth asserting. None of this touches playback
itself — the engine still opens a fresh request per seek and per resume-after-suspend, which is what
Range is for.

### The play position goes the other way

The position is the one number that travels *against* the imports: it lives on the element this module
owns, and `usePlayerAudio` imports `usePlayerQueue`, so the queue cannot read it. The player registers
a getter (`bindPositionSource()`) on attach — the same handshake `bindVolumeElement` and
`bindSpeedElement` are, reversed — and `takeRestoredPosition()` gives this module the stored value
exactly once, applied on `loadedmetadata` under a 30-second guard at both ends of the track. That keeps
**one writer** for the persisted payload; the alternative, a player persisting its own key, is two
modules writing one server row.

The heartbeat that keeps the stored position fresh is counted in **played seconds off `timeupdate`**,
never a timer, and its interval is the operator's setting (`config/mixtape.php` →
`player.position_heartbeat`). [`play-queue.md`](play-queue.md) → *Picking up mid-track* has the rest.

### What the player takes from the queue

The loaded track and nothing more: `current` for the metadata and the stream URL, `next()` /
`previous()` to move, `repeat` to know whether the end wraps. All four belong to `usePlayerQueue`.

**The two skip buttons ask the QUEUE whether they are enabled** (`hasNext` / `hasPrevious`) rather than
comparing the index themselves, because under a random order "is there a track behind this one" is a
fact about the shuffle walk — the composable's private state — not about whether the index is above
zero.

### `PlayerBar`

One grid with two shapes: on a phone the timeline takes a line of its own below the cover, title and
transport; from the `landscape` step up it moves into the row. So the bar's height is **not a constant**
— which is why it is measured with a `ResizeObserver` and published as `--app-player-height` rather
than read off a token.

### `PlayerTimeline`

Three stacked layers — rail, buffer segments, played fill — under a **transparent native
`<input type="range">`**. Native, because a div with pointer handlers means re-implementing keyboard
support, drag capture, touch and the whole ARIA slider contract. The input is `hit` tall (a real touch
target) over a 6px rail (what the eye wants).

- **A drag updates a *local* position and only `change` commits the seek.** One seek per pixel would be
  one Range request per pixel.
- **The buffer is drawn as segments, not a single width**, because after a seek past the buffer there
  genuinely are two stretches with a hole between them. Seeing them is the point: a listener on a home
  uplink can tell whether dragging ahead costs a download.

### `PlayerSettings`

A gear in the bar opening a popover with two rows, separated by the same rule a `popover-list` draws
between its entries: **play order** (in order / shuffled) and **at the end** (stop / repeat). Both are
queue state, so [`play-queue.md`](play-queue.md) owns what they do; this file owns where the control
sits.

- **It is in the bar, not in the queue panel's menu.** The panel is behind a toggle on a phone and gone
  entirely once the queue is emptied, so a setting you want *while listening* would be hidden in both
  cases — and it would sit one row above "clear the queue", a harmless toggle against a destructive
  verb.
- **Between the volume button and the transport**, so the bar reads position → level → order → the
  buttons. It sits closest to the skip buttons because that is what these two settings change: what
  "next" will play, and whether there is a next at all.
- **Bubbles, not checkboxes** — `Components/UI/OptionBubbles`, shared with the header's colour-scheme
  switch. Each row is a choice between two *named modes* ("shuffle off" is "in order", with its own
  glyph), which a lone checkbox cannot say; and being a native radiogroup, it gets arrow-key navigation
  for nothing. The glyphs for the "off" states are Material's `trending_flat` and `line_end`, since
  Material ships no `shuffle_off` / `repeat_off` — a straight arrow for "straight through", a line
  ending in a dot for "the line stops here".
- **The panel opens upward** via the same anchor override `PlayerVolume` documents.
- **The options are grid columns, not flex children**, because the pill is *drawn* rather than
  measured — a `100% / var(--count)` slice at `--selected` slices along — so it only sits over the
  option it marks if the options are genuinely equal. Flex shares out free space, never width: with
  `flex-grow: 1` each option is its own content plus a share of the remainder, and even `flex: 1 1 0`
  is clamped back up by each item's automatic minimum size. Measured on the sleep row's
  "Aus · 15 · 30 · 60" — 42.81px against 35.2px, putting the first option 2.9px off-centre inside its
  own pill and the last one true. Every group before that one was icons or "1× 2× 3×", all the same
  width, which is why it stayed hidden.

> **Popover width on a phone is a trap this panel found.** The shared popover style capped every
> floating panel at `50dvw`. This panel is `width: auto` over `white-space: nowrap` rows and measures
> ~250px with German labels, against a cap of ~206px on a 412px-wide phone: it clipped its own controls
> against the right edge and grew a horizontal scrollbar inside itself. Half the screen is a rule that
> only makes sense on a screen with halves to spare, so the cap is the viewport minus a gutter — and
> every `ch`-width popover was one notch behind the same problem (24ch is wider than 50dvw under
> ~400px).
>
> **360px needs a second fix.** The gear's right edge sits 222px in, so a 250px panel anchored to it
> runs off the **left** of the screen and no flip helps — both flips move a panel to the other side of
> its trigger, which does nothing for a panel wider than the room that trigger leaves. A last-resort
> `@position-try --popover-flush-inline` pins it a gutter in from the viewport instead, trading
> alignment with the button for every control being reachable.

### The volume slider has two resolutions

A native range only has one. `step` is a hundredth, so a **drag** can land on any percent — that is
what a slider is for. The arrow keys would inherit that same step and need twenty presses to cross a
quarter of the scale, so the input takes `keydown` and moves by 5% instead, importing
`usePlayerShortcuts`' own constant rather than copying the figure. The two are the same gesture on
either side of one guard (those shortcuts stand aside for a focused range input), and a listener notices
the drift immediately: the arrows moving the level by 1% inside the popover and 5% everywhere else.

## Playback speed

`usePlayerSpeed`, a third row in the settings popover, offering 1× / 2× / 3× and remembered across
visits. Split out of `usePlayerAudio` for the reason `usePlayerVolume` was: one element property, one
storage key, and nothing to do with the intent flag or the queue pointer. `usePlayerAudio` still owns
the element and hands it over via `bindSpeedElement()`.

**Two numbers, deliberately.** `speed` is the SETTING — what the popover shows, what is persisted
(`mixtape.speed.v1`, not user-scoped, like the volume key). `effectiveRate` is what the element is
doing: the setting, doubled while Space is held. Collapse them and two things break silently — a hold
persists 6× as the reader's stated preference, and releasing at a 3× setting drops them to 1× instead
of back to 3×. **The skim is therefore RELATIVE:** 3× skims at 6× and lands back on 3×.

A stored value is validated against the offered list rather than range-checked, because it is assigned
straight to `element.playbackRate` and a `2.5` from a future build would leave the popover with no
option lit.

**The row shows both numbers.** The pill marks the setting and never moves for a skim — it could not
anyway, since 3× skims to 6× and that is not one of the options. Beside it, a `▸ 6×` readout says what
is actually playing, visible only while a skim is on. Four details:

- It is **reserved, not conditional** (`visibility`, not `v-if`). The popover is `width: auto`, so a
  readout that came and went would resize the panel under a reader holding a key down.
- It sits **before** the bubbles, which is the only placement keeping all three rows' controls flush to
  one right edge.
- The `▸` is load-bearing: without it, `6× 1× 2× 3×` reads as four options.
- It is `aria-hidden`, because the bar's badge is already a `role="status"` announcing the same figure,
  and two live regions means hearing the change twice.

**Reaching that readout is narrower than it looks.** With the popover open, focus is normally on a
radio — and a focused radio owns Space, so the obvious "open the gear, hold Space" gesture correctly
does *not* skim:

| Action | Focus lands on | Space held |
| --- | --- | --- |
| Open the gear | `<input>` (a radio) | no skim — guarded, correctly |
| Click a bubble | `<input>` | no skim |
| Click the row's label text | the `<dialog>` | **skims** (2× → 4×) |

That is a consequence of the guards being right, not a bug in them: Space on a focused radio belongs to
the radio. The bar's badge is the readout that is always visible.

### Is 3× a problem? Measured, not assumed

YouTube gates its 3×/4× behind Premium, so the question comes up. **In the browser there is no wall.**
Measured in this project's own Chromium against a synthesised 440 Hz tone:

| Asked | Effective rate | Peak RMS | Peak frequency |
| --- | --- | --- | --- |
| 1× | 1.00 | 0.6912 | 441 Hz |
| 2× | 1.99 | 0.6912 | 441 Hz |
| 3× | 2.99 | 0.6912 | 441 Hz |
| 4× | 3.98 | 0.6912 | 441 Hz |
| 6× | 6.05 | 0.6912 | 441 Hz |

The rate is honoured exactly, the output level never changes (the engine does not mute), and
`preservesPitch` holds — the peak stays at 441 Hz instead of sliding to 1323 Hz at 3×. So 3 × the
skim's 2 = 6 is inside what the engine does properly.

Two honest caveats. A pure sine is the **easiest** case for a time-stretcher; how it handles transients
in real music is a matter of taste rather than capability — which is why the speeds are a short chosen
list rather than a slider. And this was Chromium on macOS; other engines were not measured, which is
why `preservesPitch` is written on every rate change.

**What YouTube's gate is probably about is bandwidth, not the codec.** Playing at 3× consumes the
stream three times as fast, so a 320 kbit/s MP3 becomes ~960 kbit/s sustained per listener. That is not
nothing here either: this app serves from a home uplink, which is the same reason the timeline carries a
buffer indicator at all.

## The volume readout

`PlayerVolumeHud`: a box in the middle of the viewport showing the new level as a percentage, subtle
grey, click-through, gone after a couple of seconds.

**It exists for the keyboard.** ↑/↓ change the volume from anywhere on the page, and without it the one
gesture with no control attached to it is also the one with no feedback — the only thing that moves is
a slider inside a *closed* popover. A drag on the slider raises the box too, which costs nothing: the
popover has its own readout, but the pointer is usually sitting on top of it.

**It is teleported to `<body>`, and that is load-bearing rather than tidy.** `PlayerBar` is the obvious
parent and where it is written, but the bar carries `backdrop-filter: blur(12px)` to frost itself — and
**a filtered element becomes the containing block for its `position: fixed` descendants.** Left inside
the bar, "the middle of the viewport" resolves to the middle of a 60px strip along the bottom edge.
Anything else fixed that the bar ever renders meets the same trap.

**It watches the GESTURE, not the level** — `usePlayerVolume.changes`, a counter ticked only where
somebody asked for a change. Watching the value looks equivalent and is not: the level is also written
when the stored one is **restored**, on the first bind, which happens in `PlayerBar`'s `onMounted` —
after this component's setup, so the watcher is already listening and **every page load opens with a
volume box.** The counter also keeps the two behaviours that are right: `M` shows the box (muting is a
volume gesture with even less on screen than the arrows), and ↑ at 100% shows nothing, because clamping
to the level you already had is not a change.

**No ARIA, deliberately.** The box is `aria-hidden`: a screen reader announcing every step of a
five-press climb would be reading noise, and the slider it mirrors already carries `aria-valuetext`,
which answers "how loud is it?" for anyone navigating the control rather than the page. That is the
opposite call from the speed badge, which *is* a `role="status"` — a rate change is one event worth one
sentence, where a level is a stream of them.

**Only the leave is animated.** Arriving is feedback for a key that has just been pressed, so it has to
be there instantly; an ease-in would still be arriving when the next press lands. The fade out says
"this timed out" rather than "this blinked" — and under `prefers-reduced-motion` there is no transition
at all.

**It centres on the layout viewport, which is not the window.** A fixed box resolves `left: 50%` against
the viewport *minus* the scrollbar, and this app reserves one on the root permanently. So on a 1280px
window the box centres on 632.5 — half of 1265, dead centre of everything the reader can see, and 7.5px
off the window's middle. The end-to-end assertion subtracts the scrollbar rather than hard-coding it;
measuring against `window.innerWidth` reports a correctly centred box as wrong.

## The sleep timer

`useSleepTimer`: stop the music after a while. A fourth row in the settings popover — **off, 15, 30, 60
minutes**, plus **end of chapter** while an audiobook is playing — which fades the last five minutes to
nothing and then pauses.

**Choosing a duration IS the confirmation.** Arming a timer suggests a dialog with a submit, and it is
the wrong shape twice over. Every other setting in that popover takes effect on the click that chooses
it, so a row that instead opened one would be a different interaction wearing an identical row; and a
dialog opened from inside a `[popover]` puts two top-layer surfaces on screen, where Escape and
light-dismiss then argue about which of them they close. The tooltips carry what the dialog's prose
would have — that a duration fades before it stops — where a reader meets it while deciding rather than
in a paragraph they dismissed the first time.

**Three surfaces, because a timer is two different things over its life.** For the first twenty-five
minutes it is a *setting*, and the only sign of it is a **moon on the corner of the gear** — a bedtime
feature must not put a ticking clock on screen, which is a thing a listener lies there and watches. The
numbers live where somebody has gone looking for them: the row's own readout, in the slot and the voice
the speed row's live rate established. Once the fade starts it becomes an *event* — something audible is
changing that nobody just asked for — and the bar floats a **pill** with a real countdown, which is a
**button**: one press cancels and gives the level back, the whole "no, I'm still awake" case. The pill
is also the reason the badges above the bar are a flex row rather than each pinned to the corner; a 3×
audiobook with a timer running is an ordinary Tuesday.

> The moon is `dark.svg`, the theme switch's own glyph, rather than a second crescent in the sprite.
> That icon only ever renders inside the user menu's labelled light/dark/system group, so the two never
> appear together without a label saying which is which.

**The fade must never write the level.** It is an *attenuation* — a separate factor `usePlayerVolume`
multiplies in on the way to the element, so `volume` itself does not move. Expressed as "turn it down
every second" instead, it would **persist** its way to near-silence (leaving a listener at 2% the next
morning with nothing to explain it) and bump `changes`, popping the volume HUD over the page once a
second for five minutes. It is multiplied inside `applyVolume` rather than at the one call site that
sets it, so that turning the volume up mid-fade — or a track change re-asserting the level — cannot
cancel it until the next tick.

**The clock is the authority, never a count of ticks**, and this is the decision the whole module turns
on. Both things that drive it are throttled in a backgrounded tab — `setInterval` to roughly once a
minute, `timeupdate` to a trickle — and a phone with the screen off is precisely the case the feature
exists for. Everything is therefore derived from a deadline compared against `Date.now()`: throttling
costs granularity (the countdown stutters while hidden, the fade moves in steps) and nothing else.
**Two tick sources**, because neither covers it alone: `timeupdate` keeps firing while the tab is hidden
and the audio plays, which is when the fade and the stop have to be right, and the interval covers a
*paused* player, where no media event fires at all and the countdown would otherwise freeze on screen
and expire the instant somebody pressed play again.

**The curve is squared, not linear.** Loudness tracks amplitude logarithmically, so a `volume` walked
evenly to zero sounds like nothing happens for four minutes and then falls off a cliff. Squaring the
remaining fraction spends the fade evenly in decibels — halfway through it is down about 12 dB. At
expiry the player is **paused and then** the level restored, in that order: restoring first would play
the last instant of a five-minute fade at full volume, which is the one sound the feature exists to
prevent.

**End of chapter is a different mode, not a duration in disguise.** It waits for the track to end and
stops there, with **no fade at all** — a chapter can be three minutes long, so "the last five" would
begin before it did, and a boundary is already the gentlest possible stop. `usePlayerAudio.handleEnded`
asks for it exactly once per boundary (`consumeTrackEndStop`, consumed rather than read, or a flag left
set would stop the queue again at the next chapter), and stops **without** setting `queueFinished`: the
queue has not run out, somebody asked to be left here, and "end of queue" on a book with 600 chapters
left is a lie the Now Playing badge would then repeat. It is offered only where a chapter boundary is
somewhere worth stopping — for a three-minute song, "stop at the end of this track" is a short timer
wearing a costume — which is what `QueueTrack.isChapter` is for. **Carried on the row rather than
sniffed out of `streamUrl`**, which would work today and break the moment a row plays from a share link;
stored only when true, like the cover flag beside it.

**Nothing is persisted**, unlike the level and the speed. A restored queue comes back paused —
playback never starts without a gesture — so a timer that survived a reload would count down against
silence and either expire against nothing or stop the music seconds after it was asked to start. A
sleep timer is a fact about tonight, not a preference. For the same reason it is **cancelled when the
player lets go of the element**: the queue was emptied, the bar carrying its mark has gone, and a timer
still counting would be invisible state with an interval behind it.

## The listening history

`GET /history` (route `history`, behind auth): the events in `plays`, which the app has been
writing since the player was built and until now only ever read as an aggregate — the most-played
widgets count these rows without ever saying what any of them were.

**It pages over DAYS, not over plays**, and that is the whole shape of it. Read as a flat feed a
history answers "what did I play recently" and nothing else; read as days it answers "what did I
put on last Saturday", which is the question somebody arrives with. So the unit of the accordion,
of the pager and of `LIMIT` is a day that had listening in it — **twenty-five of them a page,
fixed** (`fixedPageSize` on the shared pager drops its size Select: a size control on a list with
one column is a setting nobody came here to make). Every play on the page travels with it and the
Accordion's panels are `v-if`, so nothing is built until a day is opened — one request per page
rather than one per day, against a payload bounded by how much a person can physically listen to.

**`date(played_at)` is portable**, which is why the grouping is spelled that way rather than with
`to_char` or `strftime`: Postgres serves this app, sqlite serves its tests, and both answer
`date(x)` with `YYYY-MM-DD`. Verified against both. The day boundary is the **application's**
(`config('app.timezone')`), not the browser's — a per-reader boundary would mean sending a
timezone up with the request, and the only listening it would move is between local midnight and
the app's, where keeping an evening together in one section is arguably the better answer anyway.

**Two queries, and the second is a range.** Having the page's days, their plays could be fetched
with `whereIn(date(played_at), …)` — a scan, since no index serves an expression. But a page's days
are *consecutive* in the descending sequence of days that have plays, so everything between the
oldest and the newest of them belongs to one of them: a `BETWEEN` on `played_at` answers the same
rows and rides `plays (user_id, played_at)`, the index the migration calls "a user's history feed".

**A row is for reading, not for playing.** Every other listing of tracks in this app can start
audio; this one deliberately cannot — a history that could add to itself would be the wrong page —
so a row is a link, and it carries no stream URL, no cover and no duration. It carries one thing a
queue entry has no use for: **a chapter's author** (an audiobook's author hangs off the chapter —
[`audiobooks.md`](audiobooks.md)), which is the field the two shapes genuinely disagree about and
exactly what a history row wants beside a title. `kind` travels with it, so one `creator` key
serves both and the row's glyphs follow it.

> **`Track::$type` is cast to the enum.** `QueuePayload::entry()`'s `TrackType::tryFrom((string)
> $track->type)` looks like the line to copy and is not: that one shapes a raw query row where the
> column is still text. Off a model it throws — "Object of class App\Enums\TrackType could not be
> converted to string" — so compare the enum directly.

**The way in is gated**, like the share links': a `hasPlays` shared boolean decides whether the user
menu draws the entry at all, so an account that has never finished a track never meets a page that
could only tell it so. The page itself still answers, with an empty state, for a typed URL.

## Keyboard shortcuts

`usePlayerShortcuts`, bound on the document by `PlayerBar`.

| Key | Does |
| --- | --- |
| `Space` / `K` | play / pause |
| **`Space` held** | **doubles the chosen speed while held** |
| `←` / `→`, `J` / `L` | seek ∓5s |
| `⇧←` / `⇧→`, `P` / `N` | previous / next track |
| `↑` / `↓` | volume ±5% — the level shows in the middle of the screen |
| `M` | mute |
| `S` / `R` | shuffle / repeat |
| `Q` | show / hide the queue panel |

Media keys, the lock screen and a car head unit are wired separately (`mediaSession.ts`) and are
unaffected — this keymap is for a keyboard without transport keys.

`Q` is the only key here that moves no audio, and the only one that is not an alias for something else.
It earns its place because the listener is bound exactly when the panel can be drawn: `FullLayout`
mounts the bar on `v-if="current"`, and a queue with a current track is a queue with something to show.
`N` / `P` are also much more useful once you can see what they are stepping through.

**Space toggles on key-UP, and that is forced rather than chosen.** One key carries two gestures, and a
hold cannot be recognised until it has lasted a while: dispatching the toggle on key-down would mean
every skim began by pausing the track it wants to skim through. The cost is that play/pause lands when
you let go — for a real tap that is your own release time, and is not perceptible. A release that ended
a skim does **not** toggle.

The hold engages only while audio is **already playing** (holding Space on a paused player is just a
slow tap), and `preservesPitch` is set alongside the rate — without it the skim is an octave up and
unlistenable rather than merely quick. **The key-up can go missing**: switch windows mid-hold and the
release is delivered elsewhere, so `blur` and `visibilitychange` both end the skim, or the track stays
at 2× with no key down and no way back but pressing Space again.

**The listener lives on `PlayerBar`, and that placement IS the scoping rule.** `FullLayout` renders the
bar with `v-if="current"`, so with an empty queue there is no document listener at all and `Space`
scrolls the page exactly as it always did. An app that quietly took `Space` from every page forever
would be a worse bug than any of the ones the guards below fix.

**Four guards give the keys back**, in the order they would bite:

1. **Text entry** — a space in a password would otherwise pause the music, with nothing on screen to
   connect the two. Every field here is a real `<input>` (`FormInput` renders one), so the check catches
   them all; the letter keys are guarded too, because `M` in a passphrase is the same bug.
2. **Focused controls** — the half a "not while typing" rule misses. `Space` **activates** a focused
   button and toggles a focused checkbox, so submitting a form with the keyboard would also toggle
   playback; the arrows drive a range input (the volume rail and the timeline are both one), a radio
   group (the widget mode toggle) and `TabbedNavigation`'s tabs. The list is
   [`Utils/interactive`](../resources/app/utils/interactive.ts), **shared with DataTable's row
   navigation** — the same judgement, and two copies would drift.
3. **`defaultPrevented`** — the general form of 2, for anything a selector cannot name.
4. **Modifiers** — Ctrl/Cmd belong to the browser, Alt is the queue's reorder gesture. Shift is part of
   the keymap, not a reason to bail.

Discoverability is the transport tooltips only, which name the key beside the label. The `aria-label`
stays the plain label deliberately: a key hint is a visual convenience, and reading "next track shift
arrow right" aloud on every focus is worse than not knowing the shortcut.

## When a stream fails

`Utils/playbackError`. Without it the player answers a dead track by stopping and putting the glyph
back on *play* — which is honest, and **indistinguishable from a player somebody paused**. A file that
vanished between library scans then looks like the app ignoring a click.

The toast (`useToast`, the same singleton the flash messages use) names the track and says which kind
of failure it was. It is one small module beside `mediaSession`, and for the same reason: it is one-way
output, it holds nothing reactive, and it never touches the element — the player hands it the element's
`MediaError` and the track that was loading.

- **Two messages, off `MediaError.code`.** `MEDIA_ERR_NETWORK` is worth trying again; anything else is
  a file that is gone or unreadable, which is a re-scan rather than a retry. That distinction is the
  whole reason this is not a one-line `addToast` at the call site.
- **`MEDIA_ERR_ABORTED` says nothing at all**, because that one is the app's own doing: re-pointing the
  element at the next track, and letting go of the file when the queue empties, both cancel a download
  in flight. A toast there would fire on ordinary use.
- **One failure is announced once.** A failed load reports itself twice — the element fires `error`, and
  the `play()` promise for the same source rejects a tick later — and both paths must report, because
  each is the only one that fires in some situation. The tie-break is the `MediaError`'s **identity**: a
  browser mints one object per failure, so the same object is the same failure, while a fresh load that
  fails again brings a new one.
- **A repeat press is deliberately not a repeat report.** The element keeps its one `MediaError` for as
  long as a dead source is loaded, so a second press produces no `error` event at all — only a rejected
  promise for a failure already announced. Pressing play and getting nothing whatsoever is the exact
  silence this module exists to remove, so `play()` clears the memory first: ask again, get an answer
  again.

The `play()` rejection is why the element is consulted rather than the rejection itself. A refusal is
usually the **autoplay policy** — nothing is broken, the next press works — and only a source the
element has given up on leaves a `MediaError` behind. That check is what keeps an ordinary page load
quiet.

**The queue does not skip on past a track that will not play.** It says which one failed and stops
there, because a file that vanished between scans is worth noticing rather than stepping over; a queue
that quietly walked past every broken track would leave a collection rotting silently.

## What counts as a play

**A play is `min(half the track, 4 minutes)` of it, HEARD.** Last.fm's rule, and the right shape for a
library of songs with the occasional hour-long mix: half a track is a real listen, and the cap means a
DJ set does not need thirty minutes of anybody's life before it registers.

**Heard, not reached — and that is the whole design.** A cursor rule ("has `currentTime` passed
halfway?") is three lines and turns a drag of the timeline into a play, so scrubbing an album would mark
every track on it as listened. What is accumulated instead is the ground the cursor covered by
**playing**: positive deltas between `seeked` events, which makes a seek free in both directions. Two
consequences fall out, both wanted — skipping at 90% still counts (the threshold was crossed long
before), and a track played at 3× counts after 80 seconds of your time, because the seconds counted are
the track's.

**A jump is discounted when it STARTS.** `timeupdate` fires whenever the position changes — including
during a seek — and nothing promises it arrives after `seeked`, so both that event and `seeking` move
the mark, and the two places that move the cursor deliberately (the resume, and a scrub committed
through `seek()`) move it themselves rather than waiting to be told. The failure this prevents: one
645-second track played to five minutes recording **four** plays, one per page load, because the
restored position arrived as a single 250-second delta of "time heard" and sailed past the four-minute
threshold every time.

**It rides `timeupdate`, never a timer**, for the reason the position heartbeat does: a hidden tab
throttles timers to once a minute while media events keep coming, and a tab left playing in the
background is exactly the listening worth recording. There is deliberately no upper bound on a single
delta — a sparse reading in a hidden tab is still seconds that were heard.

**Repeat is not guarded.** `load()` clears the flag and repeat-one rewinds through it, so ten loops are
ten plays, which is what ten loops are. The pathological case — something left on repeat all night —
is a question for the ranking query (distinct days played, say) rather than a reason to throw away what
happened. That is only affordable because plays are **events**; see [`data-model.md`](data-model.md) →
*Listen history* for the arithmetic.

**The server stamps the time.** `played_at` is `now()`, unlike the queue's `updatedAt`, which has to be
the client's precisely because it is compared against another copy the client holds. The beacon fires
live, so the server's clock is within a round trip of the truth and cannot be skewed by a device whose
own is wrong.

**Nothing in it is music-only.** `plays` is keyed on the unified `tracks` table, the route validates
existence rather than type, and `PlayCounts` counts whatever it is given — so an audiobook chapter is
as countable as a song. Only the **sentences** are per-subject: "you played this song 3 times" is the
wrong noun for a chapter, so those live with the page that says them.

### What the pages show

`PlayCounts` answers `forTrack` / `forArtist` / `forGenre` / `forAlbum`, and one frontend component
(`Components/Music/PlayCountFacts`) draws all four heroes, since only the tooltip's noun differs. Two
tiles in the hero's metadata row — **from you 3×** and **from others 5×** — each omitted when its count
is zero, because a fresh library would otherwise be a wall of "0×" saying only that the feature exists.

They are `FactPair`s, the same tile the artist and the year beside them are: a count is a labelled
value, and full sentences read as broken tiles in that row. The tile format also retires a plural rule
— "1×" is right in both languages where a sentence wants "einmal" in one of them. The glyph is the ear
(`plays`, its own file rather than the audio card's `channels` — two facts on one page sharing a picture
is how a reader learns the picture means nothing) on both tiles: the label says whose listening it is,
the icon says what kind of fact it is. Each tile carries a **tooltip** and the same sentence as an
`aria-describedby` description, because the figure alone cannot say what counts as a play, whether
repeats count, or whether the same recording on another album counts here — and because a tooltip on
its own explains the number to everyone except the readers who cannot see it.

**A subject counts by `track_id`** — listening events. Every play row belongs to exactly one track and
so to exactly one artist, which makes a straight join already exact. The property it buys is that **the
figures add up**: an album's count is the sum of its tracks', which `SubjectPlayCountsTest` asserts
directly rather than by inspection. See [`data-model.md`](data-model.md) → *Listen history* for why
matching on `content_hash` instead loses.

**The three listings show only the READER'S OWN listens**, making the sortable "played by you" column
the app's only per-viewer column: this instance is shared with family and friends, and a browse list
sorts usefully on what *you* have played. The yours/others split stays on the detail page, where a tile
can label it. A count of zero comes over as `0` — the sort needs it — and the page draws it as a dash,
because a column of "0×" on a library not yet lived in reads as broken data.

**The Music page's four widgets carry a play pip too** — same figure, same rule, and it **drops out at
zero** where the counts beside it (albums, songs, artists) always render. Those are facts about the
collection, where 0 is an answer; this one is about the reader, and "you have never played this" on every
card is noise.

### `popular` means one thing everywhere: what you have played most

All four widgets offer a `popular` mode, it is **one query** in all four
(`MusicController::mostPlayed`), and it answers one question: **what has this reader played most?**
Nothing else rides behind it. Three shapes of it are wrong once a play count is visible beside the
rows:

| Instead of | the trouble with it |
| --- | --- |
| most played **household-wide** | the pip beside the row is the reader's OWN, so a household order puts "1×" above "5×" with nothing on screen to explain it |
| gated at **more than one play** | a card saying "not enough data" while three songs have plays treats a single listen as noise, and hides the answer exactly when the data is thin enough to matter |
| **most minutes of audio** as a second key | it ranks the biggest shelf, not the best-loved thing — and it puts unplayed rows above played ones, which is an order the numbers contradict |

The third is the one that keeps suggesting itself, because the artists and genres cards **default** to
`popular`: a second key on total duration would keep them populated on a library nobody has listened to
yet. It buys that by ranking something the word does not mean. A genres card reading
*"Heavy Metal 2×, Melodic Death Metal —, Progressive Metal 1×, Black Metal —"* is sorted by total
duration, which tracks song count closely — hence the complaint that it "looks like it sorts by number
of songs" — and **an order that contradicts the numbers printed on it reads as a broken sort.**

So **an entry with no plays does not appear at all**, on any of the four cards, and a card with nothing
to rank **says so**: an empty `popular` set renders as "not enough data" rather than as the generic
empty line, which is a statement about listening and not about the collection. On the artists and genres
cards, whose default mode this is, that is what a brand-new instance opens on — deliberately, over four
rows in an order nobody asked for.

**The filter and the order are the same join**, which is what makes that structural rather than
remembered: an INNER `joinSub` against the grouped set, which holds a row only for something the reader
has played. There is nothing to `COALESCE` and no `NULL` to sort, so the trap below cannot reach this
page — an unplayed artist has no row in the subquery, and **Postgres sorts NULLs FIRST under DESC**,
which under a `LEFT` join would float exactly the artists nobody has played to the top of "most played".
SQLite sorts them last, so a test suite on sqlite can never show it.

**The join key is also the tie-break**, and it has to be: equal counts are the normal case on a young
`plays` table, and the card's refresh button re-runs the query, so under `LIMIT 4` a partial order can
answer with a different four each press — which reads as the random mode leaking into this one.
`SearchRanking` owes its tie-break to the same trap.

**Both play-count query shapes exist on purpose, and which is right depends on the row count.** The pip
is a **correlated** subquery (`PlayCounts::ownCountForArtist` and siblings), because a widget shows four
rows and orders by something else, so the engine evaluates it four times. Anything that SORTS by the
number — the listings, and every `popular` mode — takes the **grouped** one (`ownPerArtist`,
`ownPerTrack`, …), since every candidate row must be counted before the sort can run. Using either in
the other's place is the expensive mistake in one direction or the other.

Measured against a synthetic 500k-row `plays` table on a dev box (12,074 tracks / 639 artists / 139
genres / 955 albums):

| Query | 500k plays | 100k plays |
| --- | --- | --- |
| Artists listing with no play column (baseline) | 11 ms | 11 ms |
| One detail page, own + others | **0.4 ms** | 0.4 ms |
| Genres sorted by plays, correlated subqueries | **914 ms** | — |
| Genres sorted by plays, grouped `leftJoinSub` | **123 ms** | 30 ms |
| Artists / albums sorted by plays, grouped | 123 / 134 ms | 30 ms |

So the cost is linear in the `plays` table — roughly **25–30 ms per 100k rows** — and stays predictable
for years at the write rate a household produces. The correlated shape stays right for `songs_count`,
which rides `tracks.artist_id` and touches nothing else. A display-only column (sort left on duration)
would cost 22 ms, because Postgres then computes it for the 25 rows on the page only; making it
**sortable** is what buys the whole-table pass.

### Keeping the number on screen honest

The counts arrive as Inertia props rendered *before* the listener had heard anything, so a track
finishing on the very page showing its count would leave the figure a request behind until the next
navigation — which reads as the feature being broken. Two small pieces close it:

- **`usePlayEvents`** — a module singleton holding a counter, ticked by `playBeacon` **only on
  `response.ok`**. `fetch` resolves just as happily for a 419 or a 500 as for the 204 that means a row
  was written, and a page told to refresh after a failed write re-fetches the number it already has,
  which is indistinguishable from a count that refuses to move.
- **`PlayCountFacts` watches it and calls `router.reload({ only: ["plays"] })`.** That is why every
  controller sends these counts as their own top-level prop rather than folding them into `artist` /
  `album` / `genre`: `only` can then name them. `reload` forces `preserveState` and `preserveScroll`
  (applied *after* the caller's options, so they cannot be overridden), so an open popover, a table's
  sort and the reader's place on the page all survive.

**It asks on every play rather than guessing whether the page cares.** The obvious guard — "only if the
played track is the one on screen" — is right for a song page and wrong for the other three: an artist,
genre or album counts every listen to any of *its* tracks, and the browser has no idea which artist the
played track belonged to (the queue holds titles, not taxonomy). One rule for all four, and the server
recomputing is definitionally right.

**Detail pages only.** On a listing the figure lives inside the `table` prop, so refreshing it would
re-run the whole DataTable query to move one number on a row the reader is probably not looking at.
Listings refresh on navigation.

**None of the play counting is in the end-to-end suite**, deliberately: the fixture's audio is one
second long against rows claiming minutes, so a threshold measured in heard seconds can never be
reached there. The behaviour is Vitest's; the storage and the counting are PHPUnit's.

## The Web Audio probe — `/dev/audio-probe`

A **permanent dev-only diagnostic**, behind `auth`, linked from nowhere and registered only outside
production. It answers one question: **does audio keep playing when the screen goes off, with the
`<audio>` element routed through an `AudioContext`?**

That question is not academic. Reading levels off a playing element needs `createMediaElementSource()`,
which **permanently** redirects that element's output into a graph — `disconnect()` yields silence
rather than ordinary playback, and a second call on the same element throws. If a browser suspends the
context while the page is hidden, the music stops in the worst possible way: the element still reports
playing, the timeline still advances, the lock screen still says playing, and there is no sound.

**How to run it:** pick *routed*, press start, hear sound, lock the phone for a minute or two, come
back and read the verdict. Then repeat with *direct* as a control — direct playback is known to
survive, so if that fails too the phone or the network is at fault and the routed result says nothing.

**What it measures, and why that shape:** wall clock against audio clock. Nothing on screen can be
watched while the screen is off and `requestAnimationFrame` stops dead, so the page records `Date.now()`
and `currentTime` as it goes away and reads both on return — *"away 215.0s — audio advanced 215.0s →
PLAYED THROUGH"*, or the stall. Alongside it: a journal of every media event and every `AudioContext`
state change (a context going `suspended` while hidden and `running` on return is the smoking gun, with
its timing), a 15-second sampler — throttled to about once a minute while hidden, which is exactly the
evidence that says *when* a clock stopped — and a **peak level**, because flat bars alone cannot
distinguish silence from a graph wired to nothing.

It plays the library's **longest** music track, deliberately: one that ended while the screen was off
would stop legitimately and read as a stall.

**It shares nothing with the real player** — its own element, its own context, no `usePlayerAudio` — so
what it proves is a fact about the browser rather than about this app's wiring. It does read the queue,
only to warn when one is loaded: the real `PlayerBar` would then have a second `<audio>` on the page
and every reading would be ambiguous.

**The answer, on an Android phone:** routed audio survives — away 215s, audio advanced 215s. That is
what cleared the Now Playing visualiser to wire the analyser directly, with no toggle and no second
element; see [`now-playing.md`](now-playing.md) for what each outcome would have decided. The probe is
**kept rather than deleted** once it has answered: it is the way to ask again for a new phone, a browser
update, or a listener reporting silence.

## Tests, and which layer answers what

- **PHPUnit** (`tests/Feature/Music/SongStreamTest.php`) — auth, the 404s (missing file, an audiobook
  chapter on the music route, a file absent from disk, with and without the nginx hand-off), a full
  `200` with `Accept-Ranges`, a `206` whose **bytes** are asserted, an open-ended range, a `416`, and
  the encoded `X-Accel-Redirect` for a path carrying umlauts, `#`, `&` and `+`.
- **Vitest** — the player's whole state machine against happy-dom's `<audio>`, which is real enough:
  `play()` / `pause()` flip `paused` and fire their events. The track boundary, repeat-one, the pointer
  moving for reasons that are not playback, the duration fallback, buffered ranges becoming geometry,
  scrub-on-release. `playbackError.test.ts` covers the failure message on its own — the classification,
  the silence for an aborted load, one failure announced once however many times it is reported, and a
  fresh answer after a fresh press — while `usePlayerAudio`'s own spec checks only the wiring.
  `PlayerVolumeHud`'s spec is about **when** the box is on screen, and three of its four cases are about
  silence (a page load must not raise it, a clamped press must not raise it, and it has to go away on
  its own). The **sleep timer** is here in full, because everything it does is a number against a clock:
  fake timers carry the system time, so a fifteen-minute countdown is one call. Its two headline claims
  each have a test of their own — that the level is never written (the stored value and the gesture
  counter are both asserted untouched mid-fade) and that a **jump** in the clock is read rather than
  counted, which is the hidden-tab case no other assertion would notice.

  > A spec that reaches a fade with `advanceTimersByTime` also runs **every other pending timer in the
  > app** — the queue's debounced server sync among them, which then fails to reach a server and reports
  > it through an i18n instance no component spec sets up. Move the clock and call the tick by hand
  > instead; the timer reads `Date.now()` whoever delivers the tick.
- **Playwright** (`tests/e2e/app/player.spec.ts`) — real playback, plus the things about the settings
  popover that need a browser: that the gear really lands **between** the timeline and the volume button
  (a grid area's box), that the panel opens **upward**, that the pill really **travels** (its offset is
  a `calc()` off two custom properties, which happy-dom does not resolve), and that the panel **fits on
  a phone at 412 and at 360**, which are two different cases rather than one repeated. A **failed
  stream** is here too: `page.route` answers the stream with a 404, because a `MediaError` is minted by
  the media stack from a real HTTP response and neither the code nor the event can be faked honestly.

  The **volume readout** is covered in `tests/e2e/app/shortcuts.spec.ts` instead, beside the arrows
  that raise it, and all three of its assertions are things only an engine knows: where a fixed box
  teleported out of a filtered parent actually lands, that `elementFromPoint` in the middle of the
  screen answers with something *behind* it, and that it times out on a real clock.

  **Every popover box is measured only after `openPopover`**, a helper that waits for two identical
  bounding boxes in a row. The panel opens with a `rotateY`, a transform is included in
  `getBoundingClientRect`, and `:popover-open` is true from the first frame — so a box read on the click
  is a couple of pixels from where it lands, which makes a geometry assertion a coin flip that happens
  to keep landing heads.

  The shuffle spec asserts a **set**, not a sequence — three tracks each heard once and then the queue
  stops — which is what makes a random control testable without stubbing the randomness.

The end-to-end fixture writes a **copy of a committed one-second mp3 at every path the seeder claims**,
so the stream route serves real audio. One second is a feature: auto-advance is the headline behaviour
and a track that ends in a second makes it fast and deterministic. The consequence is that the file's
length and the row's claimed duration **disagree**, so nothing there asserts a position derived from the
rail's width — that geometry is Vitest's. See [`testing.md`](testing.md).

## Deploy notes

- **The icon sprite is gitignored and is not produced by the Vite build**, so run `npm run icons` after
  adding an icon. A production deploy script runs it; a hand-built box needs it explicitly, and skipping
  it renders every icon empty with no error anywhere.
- **`MIXTAPE_STREAM_INTERNAL_PREFIX` and its nginx locations must be installed in that order**: add the
  `internal;` locations and reload nginx **first**, then set the `.env` value. In between, every track
  is broken. Full procedure, including the `curl` that proves `internal` works before anything is
  flipped:
  [`03-production-deploy.md` → *Media hand-off*](self-hosting/03-production-deploy.md#media-hand-off-x-accel-redirect).
- **Production caches its config**, so the `.env` edit does nothing on its own — add the key before a
  deploy, or run `optimize:clear` + `config:cache` by hand.
- **A development box wants the hand-off too**, not for speed but for rehearsal: a workstation running
  `php -S` has no nginx to interpret the header, so a LAN server is the only place the accelerated path
  can be exercised before production meets it.
