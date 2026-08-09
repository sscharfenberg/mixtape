# The Now Playing page

`/now-playing` (route `now-playing`, `NowPlayingController`) — **built 2026-08-09**, four rows over
the live queue. This file was written as a plan before any of it existed and has been kept as the
record: the arguments below are why it is shaped as it is, and the two struck-through fallbacks are
what the measurement made unnecessary.

Read alongside:

- [`play-queue.md`](play-queue.md) — what the queue holds, how shuffle actually works, and why the
  stored track is as small as it is. Most of the constraints below come from there.
- [`player.md`](player.md) — the element, the transport, background playback.

## Status

| Piece                                          | State                                                            |
| ---------------------------------------------- | ---------------------------------------------------------------- |
| The page: four rows                            | ✅ 2026-08-09                                                     |
| Prev / next, with the next track NAMED         | ✅ 2026-08-09 — the shuffle pre-draw, _below_                     |
| The track's facts (cover, title, artist, album, genre, runtime) | ✅ 2026-08-09 — genre fetched, the rest from the queue |
| EQ visualiser                                  | ✅ 2026-08-09 — wired directly, the probe having cleared it       |
| The queue, on the page                         | ✅ 2026-08-09 — a second presentation, not a second queue         |

**The four rows**, in the order the owner asked for and each answering a different question: what is
playing (the hero), what it sounds like (the visualiser), what is either side (two cards that step
there when pressed), and what is lined up (the queue).

**The page takes no props and probably never will take many.** What it is about — the queue and the
loaded track — lives in the browser, because playback has to survive Inertia swapping pages
(`usePlayerQueue`). Anything the server sends is a second, staler copy of that. The one exception is
argued under _The track's own facts_.

## 1. Prev / next, and what comes next

Ordered mode is free: the next track is `tracks[currentIndex + 1]`, or `tracks[0]` when repeat wraps.

**Shuffle is the whole problem, and the reason is that there is no next track until you press next.**
`shuffledNext()` draws at the moment of the press — one `Math.random()` over the rows not yet played
this pass (play-queue.md → _What one press of next does_). Nothing exists to display beforehand.

Three ways out, in ascending order of change:

| approach                       | what the page can show          | cost                                                                  |
| ------------------------------ | ------------------------------- | --------------------------------------------------------------------- |
| **Say "shuffle"**              | nothing — a dice glyph, a word  | none. Honest, and useless exactly where the readout is most wanted     |
| **Pre-draw one ahead**         | the next track                  | one ref + the reset list; statistically identical to today             |
| **Materialise the whole pass** | the next several                | rewrites the walk and every reset rule that is argued in play-queue.md |

**Built: pre-draw one ahead** (`shufflePick` in `usePlayerQueue`). The pick is drawn when the
*current* track loads, held, and consumed by `next()`, which then draws a replacement. Same bag, same
one-draw-per-step, same distribution — the draw simply happens earlier.

`shuffledNext()` **keeps the promise**: the pre-drawn row plays unless it is no longer drawable, in
which case it falls back to a fresh draw rather than replaying something. That candidacy test is what
makes honouring it safe rather than blind.

**The test that can actually fail** is worth knowing about, because every other shuffle assertion in
that file cannot. They stub one `Math.random` for the whole test, so a pre-draw and a re-roll at the
press agree by construction and a broken promise sails through. `"plays the promised track even when
a re-roll would have picked a different one"` changes the die *between* the two — verified to fail
when the promise is removed.

Two things must hold or both transport buttons feel broken:

- **The retrace path still wins.** After stepping back, `next` must replay what was actually heard,
  not the pre-draw. `shuffledNext()` already checks the walk ahead of the cursor first; the pre-draw
  is only consulted when that path is empty — which is also what makes "what's next" correct in both
  cases, since a retrace target is knowable too.
- **The pre-draw is invalidated by everything that renumbers rows** — `remove`, `reorder`,
  `playNext`, `playNow`, `toggleShuffle`, `clear`. That list already exists for the walk (play-queue.md
  → _What resets it_); the pre-draw joins it rather than growing a second one. An `enqueue` does not
  renumber, so it keeps the pre-draw and simply widens the pool for the next draw.

**Always shown** (the owner's call), rather than behind a setting.

## 2. The track's own facts

**The constraint is the stored queue.** `QueueTrack` holds `id`, `name`, `artist`, `album`,
`coverUrl`, `duration`, `href`, `streamUrl` — and nothing else. **No year, no genre, no codec, no
bitrate, no size.** That trim is load-bearing: it took a stored track from ~374 characters to ~164,
which is what moves the tightest browser's ceiling from roughly 7,000 queued tracks to roughly
16,000 (play-queue.md → _Storage_).

So the extra facts cannot be added to the queue. It would pay the cost 12,000 times over for
something displayed **one track at a time**.

**The design instead:**

1. The page renders the big display **straight from the queue** — title, cover, artist, album and
   duration are all already in hand, so nothing is ever blank and nothing waits on a request.
2. It asks the server for the remaining facts **for the current track only**, as a partial reload
   keyed on that track's id, re-fired when the loaded track changes.

This is the one place the page takes a prop, and it earns it: the payload is one track's worth, it
only exists while somebody is actually looking at the page, and `SongController` already shapes every
one of those facts.

**Settled: cover, title, artist, album, genre, runtime** for all three, and on the hero also the
**year**, the **play counts**, a **status badge** and a link **to the song page** — with artist,
album and genre all LINKED.

Which makes the round trip wider than a genre. `App\Services\Player\NowPlayingFacts` returns, per
track id: the genre, the three URLs, the year and the play counts. Every one of them is missing from
`QueueTrack` for the same reason, and the links are the interesting case — the queue holds artist
and album as plain STRINGS, and which pages exist is the server's to know. Absent ids and nulls read
the same way: the line is dropped, and never a dead link.

**The badge has three states, not two.** "Paused" and "the queue is finished" look identical to the
element — it is stopped either way — but they are entirely different things to a listener: one is
waiting for a press, the other has nothing left to press.

**It reads `queueFinished`, not `hasNext`, and the first attempt got that wrong.** On the LAST track
`hasNext` is false however you arrived there, so pausing at the end of a queue announced "end of
queue" when the listener had simply pressed pause — reported by the owner within minutes.
`usePlayerAudio` now records the real event, in `handleEnded` where `next()` returns false, and
clears it on any `play` or `load`. That moment is the only place the two can be told apart.

It sits **beside the "to the song page" link** rather than among the fact tiles, because it
describes the PLAYER rather than the song — among the facts it read as one more thing about the
track. And it is an OUTLINE pill of the page's own rather than the shared `Badge`, which is built
for the dashboard and brought a filled slab and a `backdrop-filter` with it.

The ids are validated in a FormRequest, and not for tidiness — they go into a `whereIn` against a
uuid column, and Postgres answers a malformed one with `invalid input syntax for type uuid`, a 500
from a query string anyone can type. **The PHP suite cannot see that**: sqlite compares uuids as
strings and finds nothing. Same class of difference as the `lockForUpdate` trap `CreatePlaylistTest`
records.

## 3. The EQ visualiser

**Technically straightforward, and it WAS gated on one measurement — since taken.** An `AnalyserNode` fed by a
`MediaElementAudioSourceNode` over the existing `<audio>`, `getByteFrequencyData()` into a canvas.
Same origin, so no CORS; no CSP change; the code is perhaps sixty lines.

**The risk is that routing an element is permanent and global.** `createMediaElementSource()`
redirects that element's output into the graph. `disconnect()` yields silence rather than ordinary
playback, and a second `createMediaElementSource()` on the same element throws. And this app's
`<audio>` lives in `PlayerBar`, in the persistent layout — it survives every navigation and is
destroyed only when the queue empties. **One visit to a page that wires it routes the audio for the
rest of the session.**

So "wire it only while the Now Playing page is open" is not an available design. The only true
un-route is to throw the element away and build a new one, which mid-playback means re-setting `src`,
re-issuing the range request, restoring `currentTime` and re-arming the Media Session, with an
audible gap.

**Worst case if a browser suspends the context while the page is hidden:** the music stops, and it
stops in the worst possible way — the element still reports playing, the timeline still advances, the
lock screen still says playing, and there is no sound. Screen-off playback on Android is a headline
feature of this app (verified 2026-08-07), so this could not be reasoned about — it had to be
measured, which is what the probe below did.

### The probe: `/dev/audio-probe`

Built 2026-08-09, dev-only, behind `auth`, not linked from anywhere — and **kept** (the owner's
call). It was written as throwaway and earned a place: it is the only way to re-answer this
question for a new phone, a browser update, or a listener reporting silence. It lives in
[`player.md`](player.md) → _The Web Audio probe_ now, which is where a tool about the audio element
belongs; what stays here is the measurement it produced.

It plays the library's longest music track (longest so the track cannot *end* while the screen is off
and read as a stall) either **routed** through an AudioContext or **direct** as a control, and
measures the only thing that survives an invisible page: **wall clock against audio clock**. It
records `Date.now()` and `currentTime` as the page goes away, reads both on return, and reports
"away 124.0s — audio advanced 123.8s → PLAYED THROUGH" or the stall. Alongside it: a journal of every
media event and every `AudioContext` state change, a 15-second sampler (throttled to about once a
minute while hidden, which is exactly the evidence that says whether the clock was still moving), and
a **peak level** readout, because flat bars alone cannot distinguish silence from a graph wired to
nothing.

**How to run it:** routed → start → hear sound → lock the phone for a minute or two → unlock → read
the verdict. Then repeat with **direct**. Direct playback is already known to survive, so if direct
fails too the phone or the network is at fault and the routed result says nothing.

### The result (2026-08-09): routed audio survives

**Away 215 seconds, audio advanced 215 seconds — played through.** Owner's Android phone, screen
locked, element routed through an `AudioContext`. The context was not suspended, the graph kept
pulling, and the audio clock kept perfect pace with the wall clock over three and a half minutes.

**So the EQ is wired directly.** No toggle, no second element, no risk to manage, and none of the
machinery below is needed:

- ~~**Fallback A — off by default, behind a toggle**~~ in the settings popover, wired lazily on
  first enable so a listener who never turned it on was never routed. Unnecessary: nobody needs
  protecting from a hazard that is not there, and an off-by-default visualiser is a feature most
  people would never find.
- ~~**Fallback B — a second, muted `<audio>`**~~ loading the same URL purely to drive the analyser,
  so the playing element was never touched. Unnecessary, and expensive: a full second download of
  every track, which is cheap on a LAN, not cheap on mobile data, and genuinely bad on a 160 MB
  audiobook chapter — plus two elements that drift, so the bars would have run slightly out of time.

Both are recorded rather than deleted, because the reasoning is what would otherwise be re-derived
the next time somebody worries about this.

**The control run was not needed.** DIRECT existed to explain a *failure* — to separate "Web Audio
broke it" from "the phone or the network broke it". A positive result explains itself.

**What the measurement does and does not cover.** One device, one browser, one lock of three and a
half minutes. That is the right population rather than a thin one: screen-off playback is the
owner's requirement, the owner has no Apple device, and this is the phone it has to work on. If the question ever reopens — a new phone, a browser
update, a listener reporting silence — the probe is how to answer it again, which is why it is a
fixture now rather than a thing to delete.

### As built

`Components/Player/Visualizer` over `Composables/useAudioAnalyser`. **48 bands**, one definition
shared by both (`ANALYSER_BANDS`) so the bar count and the reading count cannot drift; 24 was the
first try and gave 50px-wide blocks on a desktop, which reads as a bar chart rather than an EQ.
Each bar carries its **own** bottom-up ramp — magenta, violet, blue — so the colour says what the
height says: a quiet bar shows only the warm end, a loud one reaches the cool top. One
`drop-shadow` over the finished row rather than 48 box-shadows, which would overlap into a hard
band where the bars are close.

**Reduced motion is honoured by not asking at all.** The animation is JavaScript writing a height
per frame, so no media query can stop it — the component declines to activate, renders its idle
baseline permanently, and as a side effect that reader's audio is never routed either. And the
baseline is a real couple of pixels rather than zero: an EQ of no height is an invisible gap that
reads as something failing to load, not as silence.

**No `transition` on the height**, which is the one place in this repo where that is deliberate
rather than an oversight: the value is rewritten every frame from the analyser, so easing it would
smooth the *data* and leave the bars lagging behind what you hear.

**A browser with no Web Audio at all** loses the bars and nothing else — `route()` returns before
touching `AudioContext`, so the element is never routed and playback is untouched.

### What no test can see

**The bars moving.** Playwright launches Chromium **muted**, so the analyser reads zeros however
loudly a file is playing — measured 2026-08-09 with `live` true, the audio clock advancing normally,
and every bar sitting on its 2% baseline. A browser test can prove the graph is *wired*
(`--live` appears only after `createMediaElementSource` has run against a running context) and never
that it produces a spectrum. The gradient was checked by forcing heights from a stylesheet and
looking at it in both themes.

That same run confirmed the thing that actually matters: **the audio is unharmed by being routed** —
`paused: false`, the clock advancing, and the queue auto-advancing through tracks with the graph in
the path.

## 4. The queue on the page

A **second presentation, not a second queue**: `pages/NowPlaying/NowPlayingQueue` reads
`usePlayerQueue` and drives `useQueueReorder` exactly as the panel does, and only the drawing
differs. The same split `SiteMenuLinks` and `SiteMenuPopover` already make over one `useSiteAreas`.

Sharing the row markup was considered and rejected for a concrete reason: **the panel is 280px
wide**, and its row is tuned for that to the point of deliberately having no room for a per-track
runtime. One component with a width mode would be two layouts wearing one name, and the panel's
constraints would go on deciding what a full-width page may show. So this row shows the runtime and
the album the panel cannot.

`useQueueReorder` takes the list element as an argument rather than being a singleton, so two
mounted instances each get their own Sortable over their own `<ol>` — and both go through the one
`reorder()`, which is what keeps them agreeing.

## What is left

Nothing is blocked. Ideas that were weighed and left out, so they are not re-derived:

- **A large transport of its own** — the third open question, never answered, and the page was built
  without one. The `PlayerBar` is on screen the whole time, and a second set of controls would be
  two places to press play. Worth revisiting only if the bar proves too small on a phone.
- **More facts** — year, play counts, disc/track position. All available from `SongController`'s
  shape and all one line each; left out because the brief named six and a listening page is not an
  inspection page.
- **`/dev/audio-probe` stays.** It has answered and its answer is above; it is kept as the way to
  ask again. Documented in [`player.md`](player.md).
