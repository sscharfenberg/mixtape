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
| Resuming mid-track (position synced + restored)               | ✅ 2026-08-07 — `play-queue.md` owns it        |
| Listen history — what counts as a play, and counting it       | ✅ 2026-08-07 — _What counts as a play_ below  |
| Play counts on the artist / genre / album pages + listings    | ✅ 2026-08-08 — _What counts as a play_ below  |
| A play pip on all four Music-page widgets                     | ✅ 2026-08-08 — _What counts as a play_ below  |
| `popular` re-ranked by the reader's own listens               | ✅ 2026-08-08 — _What counts as a play_ below  |
| Counts that refresh in place when a track finishes            | ✅ 2026-08-08 — _Keeping the number honest_    |
| Real playback in a browser                                    | ✅ Playwright, incl. under the prod CSP        |
| Screen-off on a real phone                                    | ✅ **Android / Chrome, 2026-08-07** — below    |
| Screen-off with the element ROUTED through Web Audio          | ✅ **2026-08-09** — `/dev/audio-probe`, below  |

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

**It watches the GESTURE, not the level** — `usePlayerVolume.changes`, a counter ticked only where
somebody asked for a change. Watching the value looked equivalent and was not: the level is also
written when the stored one is RESTORED, on the first bind, which happens in PlayerBar's
`onMounted` — after this component's setup, so the watcher was already listening and **every page
load opened with a volume box** (reported 2026-08-08). The counter also keeps the two behaviours
that were right: `M` shows the box (muting is a volume gesture with even less on screen than the
arrows), and ↑ at 100% shows nothing, because clamping to the level you already had is not a
change.

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

## What counts as a play

Built 2026-08-07, after the owner settled three questions no amount of code could answer.

**A play is `min(half the track, 4 minutes)` of it, HEARD.** Last.fm's rule, and the right shape
for a library of songs with the occasional hour-long mix: half a track is a real listen, and the
cap means a DJ set does not need thirty minutes of anybody's life before it registers.

**Heard, not reached — and that is the whole design.** A cursor rule ("has `currentTime` passed
halfway?") is three lines and turns a drag of the timeline into a play, so scrubbing an album would
mark every track on it as listened. What is accumulated instead is the ground the cursor covered by
PLAYING: positive deltas between `seeked` events, which makes a seek free in both directions. Two
consequences fall out, both wanted — skipping at 90% still counts (the threshold was crossed long
before), and a track played at 3× counts after 80 seconds of your time, because the seconds counted
are the track's.

**A jump is discounted when it STARTS.** `timeupdate` fires whenever the position changes —
including during a seek — and nothing promises it arrives after `seeked`, so both that event and
`seeking` move the mark, and the two places that move the cursor deliberately (the resume, and a
scrub committed through `seek()`) move it themselves rather than waiting to be told. The bug that
taught this: one 645-second track played to five minutes in recorded FOUR plays, one per page load,
because the restored position arrived as a single 250-second delta of "time heard" and sailed past
the four-minute threshold every time.

**It rides `timeupdate`, never a timer**, for the reason the position heartbeat does: a hidden tab
throttles timers to once a minute while media events keep coming, and a tab left playing in the
background is exactly the listening worth recording. There is deliberately no upper bound on a
single delta — a sparse reading in a hidden tab is still seconds that were heard.

**Repeat is not guarded.** `load()` clears the flag and repeat-one rewinds through it, so ten loops
are ten plays, which is what ten loops are. The pathological case — something left on repeat all
night — is a question for the ranking query (distinct days played, say) rather than a reason to
throw away what happened. That is only affordable because plays are EVENTS: fifteen listens are
fifteen rows, about four kilobytes, and a household of five listening three hours a day writes
~25 MB a year against a 96 GB collection on the same disk. A counter would save that and delete
every question with "when" in it.

**The server stamps the time.** `played_at` is `now()`, unlike the queue's `updatedAt`, which has
to be the client's precisely because it is compared against another copy the client holds. The
beacon fires live, so the server's clock is within a round trip of the truth and cannot be skewed
by a device whose own is wrong.

**Nothing in it is music-only.** `plays` is keyed on the unified `tracks` table, the route
validates existence rather than type, and `PlayCounts` counts whatever it is given — so an
audiobook chapter is as countable as a song the day chapters become playable. Only the SENTENCES
are per-subject: "you played this song 3 times" is the wrong noun for a chapter, so those live with
the page that says them.

**What the song page shows**, from `PlayCounts::forTrack`: two tiles in the hero's metadata row,
**VON DIR 3×** and **VON ANDEREN 5×**, each omitted when its count is zero — a fresh library would
otherwise be a wall of "0×" saying only that the feature exists. They are `FactPair`s, the same
tile the artist and the year beside them are: a count is a labelled value, and the sentences this
started as read as broken tiles in that row. The tile format also retires a plural rule — "1×" is
right in both languages where a sentence wanted "einmal" in one of them. The glyph is the ear
(`plays`, its own file rather than the audio card's `channels` — two facts on one page sharing a
picture is how a reader learns the picture means nothing) on both tiles: the label says whose
listening it is, and the icon says what kind of fact it is. Each tile carries a **tooltip** and the
same sentence as an `aria-describedby` description, because the figure alone cannot say what counts
as a play, whether repeats count, or whether the same recording on another album counts here — and
because a tooltip on its own explains the number to everyone except the readers who cannot see it.

**The counts are by `track_id`** — listens to this row, and no other. That was the reverse until
**2026-08-08**: a song counted every copy of its recording (`content_hash`), on the grounds that
the album track and the best-of track are one song, which is what `data-model.md` settled for
most-played in open decision #5. The owner reversed it, and it leaves the app **more** consistent
rather than less: `MusicController`'s most-played songs always ranked by `withCount('plays')`,
i.e. by id, so the song page was the only place in the app claiming the other rule. It also
removed arithmetic no reader could reproduce — an album whose track figures summed to more than
the album's own, because each track was quietly counting its twin elsewhere. What it costs is
real: play a song from the best-of and its entry on the album shows nothing. That is the honest
reading — these are two files, and the page is about the file. **`data-model.md` decision #5 is
now out of step with the code** and should be revisited when most-played is built for real.

### The same counts for an artist, a genre and an album (2026-08-08)

The artist, genre and album heroes carry the same two tiles, and the three listings carry a
sortable **VON DIR GESPIELT** column. `PlayCounts` grew `forArtist` / `forGenre` / `forAlbum`
beside `forTrack`, and the frontend grew `Components/Music/PlayCountFacts` — one component for
all four heroes, since only the tooltip's noun differs. The song page was refitted onto it, so
the zero rule, the glyph and the descriptions now exist once.

**A SUBJECT COUNTS BY `track_id`** — listening events — and since the song page was moved onto
the same rule (above) that is now the app's only rule. Every play row belongs to exactly one
track and so to exactly one artist, which makes a straight join already exact; hash-matching
would count one recording twice for any artist holding two copies of it, which is the normal case
in this collection (the album and the best-of). The property it buys is that **the figures add
up**: an album's count is the sum of its tracks', which `SubjectPlayCountsTest` asserts directly
rather than by inspection — it plays a third copy of one track filed on another record and checks
that the album's total ignores it.

**The listings show only the READER'S OWN listens** (the owner's call), making it the app's only
per-viewer column: this instance is shared with family and friends, and a browse list sorts
usefully on what *you* have played. The yours/others split stays on the detail page, where a tile
can label it. A count of zero comes over as `0` — the sort needs it — and the page draws it as a
dash, because a column of "0×" on a library not yet lived in reads as broken data.

**The Music page's four widgets carry a play pip too** — same figure, same rule, and it **drops
out at zero** where the counts beside it (albums, songs, artists) always render. Those are facts
about the collection, where 0 is an answer; this one is about the reader, and "you have never
played this" on every card of a library not yet lived in is noise.

**Adding that pip forced `popular` to be rebuilt, and the bug report is the clearest statement of
why.** The three widgets that offer the mode ranked it three-quarters wrong once a play count was
visible beside it:

| Widget          | `popular` was                | is now                                     |
| --------------- | ---------------------------- | ------------------------------------------ |
| Songs           | most played, **household**, gated at **>1 play** | the reader's own listens, any song they have played |
| Artists, genres | **most minutes of audio**    | the reader's own listens, **then** minutes |

Two separate complaints, one cause. The genres card showed *"Heavy Metal 2×, Melodic Death Metal
—, Progressive Metal 1×, Black Metal —"*: it was sorting by total duration (which tracks song
count closely, hence "this looks like it sorts by number of songs"), and an order that contradicts
the numbers printed on it reads as a broken sort. And the songs card said **"not enough data"**
while three songs had plays, because the `>1` gate treated a single listen as noise — a theory
that hid the answer exactly when the data was thin enough to matter.

**Ranked by the reader, not the household**, and that is the fix rather than a preference: the pip
beside it is the reader's own, so a household ranking would keep putting a card showing "1×" above
one showing "5×" with nothing on screen to explain it. `popular` now means one thing everywhere —
*what you have played most*.

**Minutes stay as the artists/genres second key**, which is what keeps those cards populated: they
default to `popular`, and a strict play ranking would open both on a nearly empty card until a lot
has been listened to. A played entry can never sit below an unplayed one; the unplayed tail keeps
the old, useful "most audio" order. The songs card takes no such fallback — an unplayed song has
no business in a most-played list — so its "not enough data" note survives, now for the one case
that deserves it: nothing has been played at all.

**The ORDER BY uses the grouped subquery, the pip the correlated one**, in the same method. That
is not inconsistency: the sort is computed for every row before the limit applies (the grouped
shape's case), the pip for the four rows that survive it (the correlated shape's case). The joined
count is **COALESCEd** in the ORDER BY, which is load-bearing rather than tidy — an unplayed
artist has no row in the subquery, and Postgres sorts NULLs FIRST under DESC, which would float
exactly the artists nobody has played to the top of "most played". SQLite sorts them last, so the
suite would never have shown it.

**Both query shapes exist on purpose, and which is right depends on the row count.** The widgets
use a **correlated** subquery (`PlayCounts::ownCountForArtist` and siblings) because they show
four rows and order by something else, so the engine evaluates it four times. The listings use
the **grouped** one (`ownPerArtist`) because they SORT by it, so every row must be counted before
the sort can run. Using either in the other's place is the expensive mistake in one direction or
the other.

**The listings use a grouped `leftJoinSub`, not the correlated subquery every other column on
those pages uses**, and that was measured rather than assumed. A sortable column must be computed
for every parent row before the sort can run, and a correlated count re-probes `plays` once per
parent. Against a synthetic 500k-row `plays` table on the dev box (12,074 tracks / 639 artists /
139 genres / 955 albums):

| Query                                             | 500k plays | 100k plays |
| ------------------------------------------------- | ---------- | ---------- |
| Artists listing before this change (baseline)     | 11 ms      | 11 ms      |
| One detail page, own + others                     | **0.4 ms** | 0.4 ms     |
| Genres sorted by plays, correlated subqueries     | **914 ms** | —          |
| Genres sorted by plays, grouped `leftJoinSub`     | **123 ms** | 30 ms      |
| Artists / albums sorted by plays, grouped         | 123 / 134 ms | 30 ms    |

So the cost is linear in the `plays` table — roughly **25–30 ms per 100k rows** — and stays
predictable for years at the write rate `PlayController` projects. The correlated shape stays
right for `songs_count`, which rides `tracks.artist_id` and touches nothing else. A display-only
column (sort left on duration) would have cost 22 ms, because Postgres then computes it for the
25 rows on the page only; making it **sortable** is what buys the whole-table pass.

### Keeping the number on screen honest

The counts arrive as Inertia props rendered *before* the listener had heard anything, so a track
finishing on the very page showing its count used to leave the figure a request behind until the
next navigation — which reads as the feature being broken. Two small pieces close it:

- **`usePlayEvents`** — a module singleton holding a counter, ticked by `playBeacon` **only on
  `response.ok`**. `fetch` resolves just as happily for a 419 or a 500 as for the 204 that means a
  row was written, and a page told to refresh after a failed write re-fetches the number it
  already has, which is indistinguishable from a count that refuses to move.
- **`PlayCountFacts` watches it and calls `router.reload({ only: ["plays"] })`.** That is the
  reason every controller sends these counts as their own top-level prop rather than folding them
  into `artist` / `album` / `genre`: `only` can then name them. `reload` forces `preserveState`
  and `preserveScroll` (they are applied *after* the caller's options, so they cannot be
  overridden), so an open popover, a table's sort and the reader's place on the page all survive.

**It asks on every play rather than guessing whether the page cares.** The obvious guard — "only
if the played track is the one on screen" — is right for a song page and wrong for the other
three: an artist, genre or album counts every listen to any of *its* tracks, and the browser has
no idea which artist the played track belonged to (the queue holds titles, not taxonomy). One
rule for all four, and the server recomputing is definitionally right.

**Detail pages only.** On a listing the figure lives inside the `table` prop, so refreshing it
would re-run the whole DataTable query to move one number on a row the reader is probably not
looking at. Listings refresh on navigation.

**None of this is in the E2E suite**, for the reason the rest of the play counting is not: the
fixture's audio is one second long against rows claiming minutes, so the threshold can never be
reached there. `PlayCountFacts.test.ts` and `playBeacon.test.ts` own the behaviour;
`SubjectPlayCountsTest` owns the counting and the sort.

**Not in the E2E suite, deliberately.** The fixture's audio is one second long against rows
claiming minutes, so a threshold measured in heard seconds can never be reached there. The
behaviour is Vitest's; the storage and the counting are PHPUnit's.

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
| `Q`                | show / hide the queue panel                      |

Media keys, the lock screen and a car head unit were already wired (`mediaSession.ts`) and are
unaffected — this is for a keyboard without transport keys.

`Q` is the only key here that moves no audio, and the only one that is not an alias for
something else — the queue panel has no other shortcut. It earns its place in *this* keymap
because the listener is bound exactly when the panel can be drawn: FullLayout mounts the bar on
`v-if="current"`, and a queue with a current track is a queue with something to show. `N` / `P`
are also much more useful once you can see what they are stepping through.

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

## The Web Audio probe — `/dev/audio-probe`

A **permanent dev-only diagnostic**, behind `auth`, linked from nowhere and registered only outside
production. It answers one question: **does audio keep playing when the screen goes off, with the
`<audio>` element routed through an `AudioContext`?**

That question is not academic. Reading levels off a playing element needs
`createMediaElementSource()`, which **permanently** redirects that element's output into a graph —
`disconnect()` yields silence rather than ordinary playback, and a second call on the same element
throws. If a browser suspends the context while the page is hidden, the music stops in the worst
possible way: the element still reports playing, the timeline still advances, the lock screen still
says playing, and there is no sound.

**How to run it:** pick *routed*, press start, hear sound, lock the phone for a minute or two, come
back and read the verdict. Then repeat with *direct* as a control — direct playback is known to
survive, so if that fails too the phone or the network is at fault and the routed result says
nothing.

**What it measures, and why that shape:** wall clock against audio clock. Nothing on screen can be
watched while the screen is off and `requestAnimationFrame` stops dead, so the page records
`Date.now()` and `currentTime` as it goes away and reads both on return. "Away 215.0s — audio
advanced 215.0s → PLAYED THROUGH", or the stall. Alongside it: a journal of every media event and
every `AudioContext` state change (a context going `suspended` while hidden and `running` on return
is the smoking gun, with its timing), a 15-second sampler — throttled to about once a minute while
hidden, which is exactly the evidence that says *when* a clock stopped — and a **peak level**,
because flat bars alone cannot distinguish silence from a graph wired to nothing.

It plays the library's **longest** music track, deliberately: one that ended while the screen was
off would stop legitimately and read as a stall.

**It shares nothing with the real player** — its own element, its own context, no `usePlayerAudio`
— so what it proves is a fact about the browser rather than about this app's wiring. It does read
the queue, only to warn when one is loaded: the real `PlayerBar` would then have a second `<audio>`
on the page and every reading would be ambiguous.

**The answer so far (2026-08-09, owner's Android):** routed audio survives — away 215s, audio
advanced 215s. That is what cleared the Now Playing visualiser to wire the analyser directly, with
no toggle and no second element. See [`now-playing.md`](now-playing.md) for what each outcome would
have decided.

**Kept rather than deleted** once it had answered: it is the way to ask again for a new phone, a
browser update, or a listener reporting silence.

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

Five things worth writing down, because each cost time and none is obvious from the plan:

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
5. **`preload="metadata"` is not one request** (found 2026-08-08, on the live dev site). A hydrated
   queue sets `src` on every page load, and to answer "metadata" the engine range-hops the file —
   front for the Xing header, tail for the ID3v1 tag — opening a **new HTTP request per range** and
   aborting the one in flight once it has enough. On an 83-minute, 161 MB track that was **five
   requests and up to 13 MB per reload with nothing playing**, four `206`s and one nginx `499`
   (client closed request, which DevTools renders as *canceled*) — and because the stream route is
   behind auth, five full PHP requests, not one. Nothing on the page wanted them: the timeline's
   total comes from the queue (`duration` prefers the getID3 figure the scanner stored, and falls
   back to the element only when there is none), and a restored position is applied on
   `loadedmetadata`, which under `preload="none"` simply arrives when playback starts. The bar now
   carries **`preload="none"`**, so a reload costs nothing until someone presses play. What is given
   up is the warm buffer on that first press — one round trip over the internet, nothing on the LAN
   — and scrubbing before pressing play gets *better*, because the fetch then starts at the seek
   point rather than at zero. It is pinned by a Playwright spec (*cues a restored queue without
   fetching a byte of it*) rather than a Vitest one: happy-dom has no network behind an `<audio
   src>`, so there the attribute would be all there was to check, and the consequence is the thing
   worth asserting. None of this touches playback itself — the engine still opens a fresh request
   per seek and per resume-after-suspend, which is what Range is for.

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

**The play position goes the other way**, and it is the one number that does: the queue persists it
but cannot read it, since it lives on the element this module owns. `bindPositionSource()` hands the
queue a getter on attach — the same handshake `bindVolumeElement`/`bindSpeedElement` are, reversed —
and `takeRestoredPosition()` gives this module the stored value exactly once, applied on
`loadedmetadata` under a 30-second guard at both ends of the track. The heartbeat that keeps it
fresh is counted in played seconds off `timeupdate` (never a timer, which a background tab throttles)
and its interval is the operator's, in `config/mixtape.php` → `player.position_heartbeat`.
[`play-queue.md`](play-queue.md) → _Picking up mid-track_ has the rest.

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

**The volume slider has two resolutions, and a native range only has one.** `step` is a hundredth,
so a DRAG can land on any percent — that is what a slider is for. The arrows would inherit that same
step and need twenty presses to cross a quarter of the scale, so the input takes `keydown` and moves
by 5% instead, importing `usePlayerShortcuts`' own constant rather than copying the figure. The two
are the same gesture on either side of one guard — those shortcuts stand aside for a focused range
input — and they have drifted apart once already, which a listener sees as the arrows moving the
level by 1% inside the popover and 5% everywhere else.

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
