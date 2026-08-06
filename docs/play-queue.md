# The play queue

What the queue holds, how it is stored, and the panel that shows it. Split out of
[`player.md`](player.md) on **2026-08-06**, when the storage rework gave the queue more behaviour of
its own than a section in the player's document could carry.

Read alongside:

- [`data-model.md`](data-model.md) → _The play queue_ — decides the **shape** (live on the client,
  persisted per user on the server) and still owns the plan for the server half.
- [`player.md`](player.md) — everything that makes sound: the stream route, `usePlayerAudio`, the
  timeline, background playback.

This file is the client half **as built**.

## Status

| Piece                                            | State                                                      |
| ------------------------------------------------ | ---------------------------------------------------------- |
| `usePlayerQueue` (module singleton, user-scoped) | ✅ 2026-08-03                                               |
| The `PlayQueue` panel, its menu and toggle       | ✅ 2026-08-03                                               |
| Repeat                                           | ✅ a flag on the queue, toggled from the panel's menu       |
| Reordering (drag + Alt+↑/↓)                      | ✅ 2026-08-04 — `useQueueReorder`                           |
| Trimmed, split and coalesced storage             | ✅ 2026-08-06 — _Storage_ below                             |
| Enqueue from anywhere but the song page          | ⬜ one call site today (`SongPage`), so no bulk enqueue yet |
| Server sync (`player_states`)                    | ⬜ migrated, read by nobody — `data-model.md` owns the plan |
| Shuffle, play-history beacon                     | ⬜                                                          |
| A visible failure when a write is refused        | ⬜ swallowed silently today — see _Known edges_             |

## What it is

`usePlayerQueue` is a **module singleton** — module-level refs, no Pinia, the same pattern as
`useToast` and `useTooltipLayer` — because three things that sit nowhere near each other in the tree
read one queue: the `PlayQueue` panel and the `PlayerBar` (both mounted once in `FullLayout`) and any
page with an enqueue button.

It **has to** be client state rather than a server round-trip per track change, and the reason is
playback: auto-advance drives off the audio element's `ended` event, which lives in the browser, and
the player has to keep running while Inertia swaps pages underneath it.

**Tracks are held whole, not as bare ids.** There is no REST API by design, so nothing exists for a
client-side queue to call to turn an id back into a title — and the panel has to draw itself the
moment the page loads. The cost is that a renamed track shows its old title until it is re-queued,
which is the right trade for a list assembled minutes ago.

**Played tracks stay in the queue and the pointer moves.** They are not consumed. That is what makes
`previous()` and `jumpTo()` work, and it is the shape `data-model.md` specifies.

**Every list operation carries the pointer with the track that was _loaded_, not the index it sat
at.** Remove a row above the playing one, or drag one past it, and an index-preserving implementation
silently switches what the player holds while looking perfectly correct in the panel. Operations:
`playNow` (replace), `enqueue` (append), `playNext` (insert after current), `remove`, `reorder`,
`jumpTo`, `next`, `previous`, `toggleRepeat`, `clear`.

**Repeat is queue state, not player state**, because what happens after the last track is a fact
about the _list_. It survives `clear()`, since "I listen on repeat" is a habit rather than a property
of whatever is queued right now.

## Storage

Reworked 2026-08-06, prompted by a plain question: how many songs can the queue actually hold? The
answer was "fewer than the library, on the browser that counts most strictly", and the write path
turned out to be the more interesting half.

### What is stored, and what is derived

A `QueueTrack` names the same song **four times over** — once as `id`, then again inside `href`,
`streamUrl` and `coverUrl`, all three of which are just routes built from that id. Stored verbatim,
that repetition was most of a browser's entire storage budget:

| stored form                           | chars per track |
| ------------------------------------- | --------------- |
| whole `QueueTrack` (until 2026-08-06) | 374             |
| trimmed (`id` + tags + `hasCover`)    | **164**         |

`toPersisted` / `fromPersisted` own that translation and are the only pair that knows the stored
shape; everything else in the app sees a full `QueueTrack`. Three details are load-bearing:

- **The cover collapses to a flag, not to nothing.** "No cover at all" and "a cover at the usual
  place" are different facts, and the panel draws a placeholder for the first — derived
  unconditionally, a coverless track would point an `<img>` at a 404 on every reload.
- **A URL the id cannot imply is stored verbatim.** `isDerivable()` compares the parsed **path**, not
  the string, because the props are inconsistent by design: `coverUrl` arrives absolute (Laravel's
  `route()` default) while `streamUrl` arrives relative (`absolute: false`). Anything with a foreign
  origin or a query string — which is exactly what a **signed share link** is — fails the check and
  survives intact. Trimming blindly would have come back unsigned and 403'd on play, in a feature
  that is not even built yet.
- **Field names stay readable.** Single letters would save another ~30 characters a track and are not
  worth a stored payload nobody can read in devtools when a queue comes back wrong.

A derived `coverUrl` comes back **root-relative** where the fresh one was absolute. Same target —
both an `<img src>` and MediaSession artwork resolve against the document — and deliberately not
re-absolutised: this module has no business minting an origin.

### Two keys, because the halves change at different rates

| key                      | holds                     | written when                           | size               |
| ------------------------ | ------------------------- | -------------------------------------- | ------------------ |
| `mixtape.queue`          | the list                  | someone queues, drags, removes         | 164 chars × tracks |
| `mixtape.queue.position` | `currentIndex` + `repeat` | every track change, incl. auto-advance | **94 chars**, flat |

Sharing one key meant a four-minute song ending rewrote the whole queue to move one integer by one:

| queued | auto-advance write, before | after    |
| ------ | -------------------------- | -------- |
| 200    | 73 KiB                     | 94 chars |
| 2,000  | 730 KiB                    | 94 chars |
| 12,058 | 4,404 KiB                  | 94 chars |

`repeat` rides with the pointer rather than the list because it changes at the same rate — a click,
not a queue edit.

**The pointer is advice, not truth.** The pair can drift: a quota error on the list, a browser
profile copied half-way. So it is refused on its own terms (wrong user, older shape, not a number)
and whatever survives is **clamped into the list that was actually read**. Refusing it leaves the
queue cued at its first track, which is a far better failure than dropping a restored queue over one
integer. A pointer saying 5 against a one-track list loads track 1, not silence.

**The keys carry no version — the payload does.** Both previous shapes lived under versioned keys
(`mixtape.queue.v1`, `.v2`), and that is what made them dead weight: a refused payload under an
abandoned name is an orphan nothing ever deletes, holding its share of a ~5 MB origin budget for the
life of the profile. Under one stable key the same refusal self-heals — `hydrate` rejects the old
shape and the next write overwrites it. (`mixtape.queue.v2` is the last orphan; delete it by hand or
ignore it.) A shape change still bumps `PERSISTED_VERSION` rather than attempting a migration.

### Written late, and flushed on the way out

`commit()` no longer writes. It marks which key is behind and schedules one flush, so the cost of an
operation stops scaling with how much is queued, and a burst — two enqueues and a drag — costs one
list write plus one pointer write between them.

- **A trailing-edge timer, not a resetting debounce** (500 ms, started by the _first_ dirty mark). A
  resetting debounce would keep postponing while somebody drags rows for a minute, leaving storage a
  minute stale the whole time. This way staleness is bounded by the delay.
- **`flushQueueWrites()` is called when the tab goes away**, on both `pagehide` and
  `visibilitychange → hidden`. `pagehide` covers navigation and close without disqualifying the page
  from the back/forward cache the way `unload` would; `visibilitychange` is the one iOS reliably
  delivers before it discards a backgrounded tab.
- **That listener is not belt-and-braces, it is the correctness half.** A hidden tab has its timers
  throttled to once a second and, after five minutes, once a minute — and this player keeps playing
  while hidden **on purpose**. Without the listener, an auto-advance in a background tab could be
  minutes from disk when the tab closes.
- `flushQueueWrites()` is also where the **debounced POST to `player_states`** lands when the server
  half is built. It is the one place that knows what changed and when.

### What it refuses on the way in

`hydrate()` is called once, from `FullLayout` — not at module load, because it needs Inertia's shared
props to know whose queue it is reading. It discards rather than adopts:

- **a payload whose `userId` is not the current one.** This instance is deliberately shared with
  family and friends, so one browser genuinely sees several accounts; adopting the wrong one is the
  difference between "resume where I left off" and "here is somebody else's listening". A guest on a
  share link (`userId: null`) is a distinct owner, not a wildcard.
- **a payload written by an older shape**, and
- **a single unusable row** — a row with no id is dropped on its own rather than costing the other
  200 theirs.

### How much fits

The budget is per **origin**, small, and counted differently per browser — which is the part worth
writing down, because the spec mandates nothing:

| browser       | limit                                                                | counting                                         |
| ------------- | -------------------------------------------------------------------- | ------------------------------------------------ |
| Chrome / Edge | 10 MiB per storage area (`kPerStorageAreaQuota`) + 100 KiB allowance | bytes                                            |
| Firefox       | 5 MiB, `dom.storage.default_quota` (user-tunable)                    | `len(key) + len(value)` in UTF-16 units          |
| Safari        | 5 MB                                                                 | has counted UTF-16 code units → **~2.5 M chars** |

Against the tightest of those, the trim moved the ceiling from **~7,000 queued tracks to ~16,000** —
past the whole 12,058-track collection, which now stores as **1.9 MB**. Quota has stopped being the
binding constraint; the panel's DOM will complain first.

**What still evicts a queue is Safari, not size.** With cross-site tracking prevention on, an origin
with no user interaction in the last seven days of browser use loses everything script wrote. On an
instance visited occasionally by family, the queue simply disappears — which is an argument for the
server half in `data-model.md`, not for anything this file can fix.

## The panel

`PlayQueue` renders the list; `PlayQueueToggle` in the header opens it on a phone, where it floats
over the content instead of taking a column. Covers are `loading="lazy"`, and the panel scrolls the
loaded track into view a row clear of the edge it approached.

**Rows are plain text, deliberately.** The title used to be a real `Link` to the song's page, and in
a panel whose every other pixel plays the track that is a trap. The row's hit area is an **empty
button positioned across it**, which is also what gives the play overlay a real bounding box: its
focus ring traces the row, and a browser test can click it.

### Reordering (built 2026-08-04)

Two gestures over one operation. `reorder(from, to)` already existed on `usePlayerQueue`, tested and
called by nothing — so this was only ever a gesture problem, and both gestures go through it rather
than touching `tracks`: it is what carries the pointer with the loaded track and what persists the
new order.

- **Dragging, by SortableJS used directly** — no Vue wrapper. `vuedraggable` /
  `vue-draggable-plus` exist to own the list through `v-model`, which is exactly what must not happen
  when the queue is a module singleton that persists itself; in controlled mode a wrapper earns
  nothing, and this app keeps very few runtime dependencies. Native HTML5 DnD was out from the start:
  no events on touch, and the panel has a phone mode. What SortableJS actually pays for is touch,
  auto-scroll near the ends of the list, and `handle`.
  - **`forceFallback: true`**, so its own pointer path runs everywhere instead of native dragging on
    desktop and the fallback elsewhere. One code path, drag visuals we style (`--dragging` on the
    clone, `--ghost` on the gap it left), no reliance on a browser agreeing to start a native drag
    from inside a `<button>` — and it is the path Playwright can drive with plain mouse moves.
  - **The DOM move is undone before the queue is told.** Sortable has already moved the `<li>` when
    `onEnd` fires, which leaves two writers on one list; restoring it first means Vue's re-render is
    the only thing that reorders anything. This is what the wrappers do internally, and skipping it is
    how a wrapper-less integration duplicates or loses a row.
  - `delay` + `delayOnTouchOnly` (a long-press on touch only), or a finger dragging a row steals the
    gesture that scrolls the list. `animation` is `0` under reduced motion and otherwise `150`,
    mirroring `ti.$c-play-queue` — a JS option cannot read the Sass token, so the two are kept in step
    by hand.
- **Alt+↑/↓ moves the _focused_ row** one place. Alt is what keeps it out of the way: a bare arrow
  scrolls the panel. The keystroke is only consumed when a move actually happens, so the ends of the
  queue do not silently eat one. **Focus has to be put back by hand** — the row's `v-for` key carries
  its index (it must: the same song may be queued twice), so the element holding focus is replaced by
  the move, and without re-focusing the same control in the row's new position the journey ends after
  a single press.
  - **"Focused" is the whole story of the one bug this shipped with.** Hover is not focus, so
    pointing at a row and pressing the keys does nothing — reported from a Mac as "it doesn't work",
    and it worked the moment the grip was clicked. Two things came out of that. The grip's hint now
    **says to click it first** (`player.queue.moveHint`), because nothing else on screen did. And the
    grip **focuses itself on `pointerdown`**, because macOS Safari and Firefox deliberately leave a
    clicked `<button>` unfocused (platform convention) — without it the instruction would only be true
    in Chrome. Chromium does not treat that programmatic focus as `:focus-visible`, so a mouse click
    still draws no focus ring.
  - **The keys are named for the keyboard in front of the reader** — ⌥↑/↓ on an Apple one, Alt+↑/↓
    elsewhere (`utils/platform.ts`). A hint that says "Alt" to someone looking at a ⌥ key is naming a
    key they cannot find. Only the WORDS branch: the handler still reads `event.altKey`, which is one
    bit with two names printed on it, and `aria-keyshortcuts` keeps ARIA's canonical
    `Alt+ArrowUp Alt+ArrowDown` for assistive tech to announce in its own words.

**The handle is the cover with the drag glyph beneath it** — one 24px-wide grip strip, the owner's
design. At 280px the row has no horizontal room to give (the title ellipsises first), so a leading or
trailing handle column would have cost the title 24px; stacking costs it nothing and grows the row
from 47px to **54px** instead. The glyph alone would have been a 16px target, which is not something
to aim a drag at on a phone. What it costs: the cover had to leave the play button to become the
grip, so **tapping the cover no longer plays the track** — the other ~90% of the row still does.

## Tests, and which layer answers what

- **Vitest** — `usePlayerQueue.test.ts` covers the two things that are easy to get subtly wrong and
  invisible when they are: **the pointer** following the song rather than the index, and **whose queue
  it is**. Since 2026-08-06 it also pins the storage contract, which a round-trip alone cannot: the
  trimmed payload is read **raw** (a round-trip would still pass if every dropped field were quietly
  stored), the write is asserted by **spying on `setItem`** — that a burst coalesces, and that an
  auto-advance touches only the pointer key — and the flush is driven through fake timers and real
  `pagehide` / `visibilitychange` events.
  `useQueueReorder.test.ts` proves the contract _around_ SortableJS with the library **mocked on
  purpose** — a drag is a stream of pointer events over elements with real geometry, so a "drag" here
  would assert the mock's own arithmetic. What it checks is the options it is handed, the DOM move
  being undone before the queue is told, the instance following the list in and out of the DOM (it is
  behind a `v-if`, so `onMounted` would work once and then stop after a clear), and every Alt+↑/↓
  decision.
- **Playwright** (`tests/e2e/app/queue.spec.ts`) — the real browser half: the panel appearing and
  emptying, its two layouts and the phone toggle, real drags, the keyboard journey, and scrolling the
  loaded row into view. The one that quietly guards the storage rework is **"survives a navigation,
  because the new order is persisted"** — it drags a row and then does a full page load, so it
  exercises the actual `pagehide` flush and rehydration rather than a mocked timer.

**Two traps found writing these**, both worth knowing beyond this file:

- **`vi.spyOn` returns the spy that is _already_ installed**, and nothing in this repo restores spies
  between tests — so a second `setItem` spy arrived pre-loaded with the previous spec's writes and the
  key split looked broken when it was not. The file's `beforeEach` now calls `vi.restoreAllMocks()`.
- **A pending flush is test state.** `resetPlayerQueueForTests()` cancels the timer and clears the
  dirty set, because a write left scheduled by one spec fires during the next one and drops the
  previous queue into that spec's storage.

## Known edges, and what is deliberately absent

- **A refused write is silent.** `flushQueueWrites()` swallows `QuotaExceededError` per key — a full
  or disabled storage must not take the player down — and the previous payload survives, so a reload
  can restore an older, shorter list with no hint anything was dropped. Nothing surfaces it and
  nothing caps the queue's length. Post-trim that would be a **DOM guard rather than a storage one**;
  the panel renders every row (no virtualization) and will feel it long before 16,000 tracks.
- **Bulk enqueue does not exist yet.** `SongPage` is the only call site, one song per click, so none
  of the sizes above are reachable today. "Play this album / artist" is the moment they become one
  click, and the moment to revisit the panel's DOM.
- **Server sync, shuffle, and the play-history beacon** are all still `data-model.md`'s plan. The
  queue stays per-browser until the first of those lands.
