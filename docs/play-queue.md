# The play queue

What the queue holds, how it is stored, and the panel that shows it.

Read alongside:

- [`data-model.md`](data-model.md) → *The play queue* — the shape this builds on, and the `player_states`
  row it syncs to.
- [`player.md`](player.md) — everything that makes sound: the stream route, `usePlayerAudio`, the
  timeline, background playback.

## What it is

`usePlayerQueue` is a **module singleton** — module-level refs, no Pinia, the same pattern as `useToast`
and `useTooltipLayer` — because three things that sit nowhere near each other in the tree read one
queue: the `PlayQueue` panel and the `PlayerBar` (both mounted once in `FullLayout`) and any page with
an enqueue button.

It **has to** be client state rather than a server round-trip per track change, and the reason is
playback: auto-advance drives off the audio element's `ended` event, which lives in the browser, and the
player has to keep running while Inertia swaps pages underneath it.

**Tracks are held whole, not as bare ids.** There is no REST API by design, so nothing exists for a
client-side queue to call to turn an id back into a title — and the panel has to draw itself the moment
the page loads. The cost is that a renamed track shows its old title until it is re-queued, which is the
right trade for a list assembled minutes ago.

**Played tracks stay in the queue and the pointer moves.** They are not consumed. That is what makes
`previous()` and `jumpTo()` work.

**Every list operation carries the pointer with the track that was *loaded*, not the index it sat at.**
Remove a row above the playing one, or drag one past it, and an index-preserving implementation silently
switches what the player holds while looking perfectly correct in the panel. The operations: `playNow`
(replace), `enqueue` (append), `playNext` (insert after current), `remove`, `reorder`, `jumpTo`, `next`,
`previous`, `toggleRepeat`, `toggleShuffle`, `clear` — plus `hasNext` / `hasPrevious`, which the
transport's two skip buttons read instead of comparing the index themselves (under shuffle the answer is
a fact about the walk, not about the index).

**Repeat and shuffle are queue state, not player state**, because what order the list plays in and what
happens after its last track are facts about the *list*. Both survive `clear()`, since "I listen on
repeat" is a habit rather than a property of whatever is queued right now. Their **control** lives in the
player bar (`PlayerSettings`, see [`player.md`](player.md)) rather than in the panel's own menu, because
the panel is behind a toggle on a phone and gone entirely once the queue is emptied — so a setting you
want *while listening* would be hidden in both cases.

## Playing in a random order

Shuffle is a **play mode, not a reordering**: the list keeps the order you built and only the pointer
jumps. Shuffling `tracks` itself would be destructive — the order you dragged into place would be gone
the moment you tried the mode — and it would make "turn it off again" impossible to honour.

Everything below lives in `usePlayerQueue`: the mode is one flag (`shuffle`), and the behaviour is one
function (`shuffledNext`) plus one piece of bookkeeping, the **walk**.

### The walk

Two module-level refs, and they are the whole of shuffle's memory:

| | holds | why |
| --- | --- | --- |
| `shuffleWalk` | the rows played since this pass began, **in play order** | a bag to draw from, and a path to step back along |
| `shuffleCursor` | where in that walk the loaded track sits | so back and forward can move within what already played |

**Rows, not track ids.** The same song may legitimately be queued twice, and an id-keyed walk would
treat the two rows as one — marking the second copy played without playing it. The cost of positions is
that they are only valid while the rows keep their numbers, which is what the reset rules below are
about.

### What one press of next does

`shuffledNext()` tries three things, in this order:

1. **Retrace, if there is a path ahead of the cursor.** That only exists after stepping back, and
   replaying it is the point: a back-then-forward that landed on a *new* track would make both buttons
   feel broken.
2. **Otherwise draw at random from the rows not yet played this pass** — `unplayedIndices()`, one
   `Math.random()` over that pool. This is the bag: every track gets its turn before any repeats.
   Rolling freely instead plays the same song twice in ten, which is the most complained-about shuffle
   behaviour there is.
3. **When the pool is empty the pass is over.** With repeat on, the walk is cleared and a new pass
   begins — excluding the track that just ended wherever the queue holds another, so a wrap never
   immediately replays it. With repeat off, `next()` returns false and the player stops, exactly as it
   does at the end of an ordered queue.

### The draw happens one step ahead, so it can be shown

Drawing inside the press means there is no next track until somebody asks for one — and a page that
names what plays next could only lie. `shufflePick` holds the row a draw would take, decided when the
**current** row is recorded, and `shuffledNext()` takes it. Same bag, same one draw per step, same
distribution: only the moment moved.

Three things make it correct rather than merely earlier:

- **The promise is kept.** The pre-drawn row plays unless it is no longer among the candidates — a queue
  edit, a jump that played it — in which case it falls back to a fresh draw rather than replaying
  something.
- **Retrace still wins.** With a path ahead of the cursor, `next()` replays what was heard, and
  `nextTrack` reads that path rather than the pick. Otherwise a page would name a track the press would
  not play.
- **It is forgotten with the walk.** The pick is a row **number**, so every edit that renumbers rows
  invalidates it for exactly the reasons above; `resetShuffleWalk` clears it and `noteShuffleStep`
  redraws it. An APPEND keeps the walk but redraws the pick, because a pass that had run out now has
  somewhere to go — and `toggleRepeat` redraws for the same reason.

`nextTrack` / `previousTrack` expose the answer to callers, correct in both modes, and null exactly when
`hasNext` / `hasPrevious` are false.

`previous()` follows the cursor back one step — the track actually **heard** before this one, not the
row above, which under a random order has probably not played at all. A click in the panel is a
deliberate branch: it becomes the newest step and anything ahead of the cursor is dropped, so retracing
afterwards follows what really played.

### What resets it

| event | walk | why |
| --- | --- | --- |
| `toggleShuffle` (either direction) | reset, loaded track becomes step one | switching on must not replay what is playing; switching off and on again must not resume an hour-old pass |
| `remove`, `reorder`, `playNext`, `playNow` | reset | each **renumbers rows**, so recorded positions would name different songs |
| `enqueue` (append) | **kept** | an append renumbers nothing; the arrivals simply join the pool |
| `clear` | reset | there is no list left to have played |
| a page load (`hydrate`) | reset, restored track becomes step one | the walk is not stored — see below |

### Where the walk is stored: nowhere

The mode is persisted, the walk is not. What survives a refresh is only what is in the two storage keys
(*Storage* below): `shuffle: true` and `currentIndex`. So a reload restores **that shuffle is on** and
**which track is loaded**, then starts a fresh pass with that track as its only step.

Two consequences, and the second is the one worth arguing about:

- **`previous()` cannot cross a reload, or a pass boundary.** The walk starts with one entry, so there is
  nothing behind the loaded track. Same at a repeat wrap: the new pass starts empty, and the history
  honestly ends there.
- **"Each track once" only holds within one page life.** Refresh mid-pass and every row is eligible
  again, including what you just heard.

**This is why a small queue can appear to pick the same next track every time, and it is not a bug.**
After a reload the pool is every row *except* the loaded one, so a two-track queue has exactly one
candidate and next is forced; three is a coin flip. Measured on a six-track queue in a real browser,
same starting track, eight reloads: **three to four distinct picks per run**, with the most frequent
coming up three times in eight. Random draws from a small pool look repetitive, which is exactly what a
listener reports as "it always plays the same one".

Persisting the walk is the obvious fix if that ever grates: it is one integer per played row, so a
200-track queue mid-pass is under 800 characters against a list payload measured in megabytes. The catch
is the reason it is not done — the walk is keyed by row position, so a stored walk has to be discarded
whenever it does not belong to the list actually read back, or it will confidently name the wrong songs.

## Filling it: the hero menu

Every detail page's hero carries `Components/Music/SubjectActions` with two verbs, and the difference
between them is the whole point: **play** empties the queue and puts the subject in it, **enqueue**
appends and leaves what is playing alone. One component for all four pages, because only the noun in the
label and the tracks behind it differ.

**The tracks are fetched when a verb is pressed, not when the page loads.** Every one of those pages
paginates its songs table, so the rows on screen are never the whole subject — "play artist" means all of
them — and a big genre is a few hundred kilobytes of JSON. The controllers therefore declare
`queueTracks` as an **optional Inertia prop** (`Inertia::optional`, built by
`App\Services\Music\QueuePayload`) and the menu asks for it with a partial reload
(`router.reload({ only: ["queueTracks"] })`). There is no endpoint because this app has no REST API by
design, and a partial reload is the Inertia-native way to fetch more of a page. The payload is kept for
the life of the page, so play-then-enqueue costs one round trip rather than two.

Three consequences worth knowing:

- **Enqueuing from a hero is asynchronous**, unlike the panel's own operations. The end-to-end helper
  that presses it waits for the queue to grow, because reading the queue straight after the click reads
  it before the tracks have landed.
- **Order is album-then-disc-then-track**, whatever the subject, with undated records last — a listener
  pressing "play artist" expects records to arrive as records. `year_sort` folds a missing year to 0 so
  the descending sort cannot put undated material first.
- **`QueuePayload::entry()` routes its three URLs by track type.** A chapter addressed as a song 404s on
  the music guard, which presents as a book that resumes as silence.

## Following the listener, not the browser

The queue syncs to the `player_states` row, which is what turns "resume where I left off" from a fact
about a browser profile into a fact about a person.

**The two directions are shaped differently, on purpose.** Up goes **ids** — the server is where the
tracks came from, so a title sent up would only be a copy to go stale, and a queue of thousands stays
tens of kilobytes rather than megabytes. Down comes the **whole `QueueTrack`**, because the browser has
no REST API to turn an id back into a title, which is the same reason the queue holds whole tracks in the
first place.

`Services\Player\PlayerStatePayload` owns both ends. It borrows `QueuePayload`'s mapping so a restored
queue and one built by pressing "play this artist" cannot arrive shaped differently, and **re-sorts the
rows into the stored order afterwards** — that mapping sorts a subject album-then-disc-then-track, which
is the wrong order for a list somebody dragged into shape. It is deliberately **not** under
`Services\Music`, though it borrows from there: this same row carries audiobook chapters too.

**The write is `flushQueueWrites()`**, the one place that knows something changed and that it has
settled. Both writes ride the same coalescing, so a burst costs one PUT and a track change costs one
whether the queue holds three tracks or three thousand. **Local storage is written first, always** — it
is what the next load falls back on with no network, and the only copy a guest has at all.

- **A plain `fetch`, not an Inertia visit**, answering `204`. A visit would re-render a page nobody asked
  for and hand back props the player would have to ignore. It is also the one place in the app that sends
  the CSRF token by hand, off the shared prop.
- **`keepalive` only on the way out.** The flag buys a request that outlives the page and costs a 64 KB
  body cap — about 1,700 ids. Worth it from `pagehide`, pointless while a live page can finish the
  request itself.
- **Failure is swallowed**, exactly as a refused storage write is. A player that broke because a sync
  failed would be worse than a queue one change behind on another device. The *fact* is not swallowed,
  though — see *A refused write is not silent* below.

### Which copy wins, and the race that decides it

Every page load can offer two queues. The rule is **last write wins, by a stamp the CLIENT issues**:
`updatedAt` is written with the pointer, sent with every PUT, stored verbatim and handed straight back.
The server never rewrites it — a value stamped with `now()` on the way in would be comparing two
different clocks, and the newer copy would lose about as often as it won.

**Letting the server win outright fails in about a minute of real use**, and the failure is worth
keeping in mind: enqueue a track, then click a link. The sync PUT and the next page's HTML are two
requests racing, and when the page wins, the server hands back the queue *as it was before the enqueue* —
which then overwrites the good local copy. A track vanishes for no reason a listener could ever explain.

Two consequences worth stating plainly. A device with a badly wrong clock can win or lose an argument it
should not; that is the trade accepted at this scale, and the alternative is a revision counter
reconciled on every write. And a change made **offline** is lost the next time another device writes,
because the PUT that would have carried it never happened.

**Null is not an empty queue**, and the payload is careful about the difference: null means "nothing
stored", which the client reads as "keep what localStorage has". Return an empty queue instead and the
first page load after signing in on a second device wipes the queue on the first.

**A track the library no longer has is skipped, and the pointer follows it.** Files disappear between
scans; a queue that came back with holes would break the player, and one whose pointer stayed put would
resume on the wrong song.

**It rides down only on a FULL page load.** `hydrate()` runs once, from the persistent layout, so a
client-side visit already holds a live queue this prop could only contradict — and it would put a queue's
worth of JSON on every navigation to be thrown away.

**The server ignores a write older than the one it holds.** Two tabs open, fifty tracks queued in the
second, close the first — and without that guard the first tab's parting flush rolls the server back to
whatever it was holding when it was abandoned.

### Picking up mid-track

The queue restores the track; the position restores the minute. Both halves of the sync carry it
(`positionMs`, in the row and in the local pointer), and three decisions make it worth having rather than
annoying.

**The number travels against the imports.** It lives on the `<audio>` element, which `usePlayerAudio`
owns — and that module imports this one, so the queue cannot read it. The player registers a getter
(`bindPositionSource`) on attach, which is the same handshake `bindVolumeElement` and `bindSpeedElement`
already are, in reverse. That keeps ONE writer for the payload; the alternative, a player persisting its
own key, is two modules writing one server row.

**And it never travels alone: the getter reports WHICH TRACK the cursor belongs to** (`PlaybackReading`).
A position on its own cannot be checked, and there is a window in which it is wrong — the queue's pointer
moves the instant somebody replaces the queue, while the element switches source a tick later, so a
reading taken in between belongs to the track on its way out. Paired, the write refuses it (storing 0
rather than a stranger's minute mark) and the restore refuses it (a stored id that is not the track being
restored is not applied). Unpaired, it was stored against the incoming track and resumed there, which
reads as **the new song remembering the old song's progress** — and only sometimes, because the write is
coalesced, so whether the element had already switched decided it.

A stored pointer with **no** id at all is still honoured: it was written before the field existed, and
refusing it would throw away every reader's resume to fix a bug the write side has already fixed. Only a
mismatch is refused.

**When it is written: boundaries, plus a heartbeat.** Every deliberate exit writes it — pausing, changing
track, hiding the tab, closing it. The heartbeat covers the exits that are not deliberate, and it is
counted in **seconds of playback off `timeupdate`**, never by a timer: a timer is throttled to once a
minute in a backgrounded tab, which is exactly the tab whose position is worth keeping. It is the
operator's setting — `config/mixtape.php` → `player.position_heartbeat`, shared to the client through
Inertia, 30 by default, `0` to switch it off and rely on the boundaries alone.

**When it is honoured: not always.** A resume happens only if the listener is more than 30 seconds in
*and* more than 30 seconds from the end. Under that, "resume" means starting a song at 0:04, which is
noise; within it of the end, the track finishes almost immediately and the queue moves on, which reads as
the app skipping a song. The value is also **taken once** — it belongs to the track the page load came
back holding, and one left lying around would eventually seek an unrelated song to a stranger's minute
mark. It is applied on `loadedmetadata`, because a `currentTime` written before the element knows its
duration is silently ignored.

None of it is in the end-to-end suite, deliberately: the fixture's audio is one second long against rows
claiming minutes, so a thirty-second guard cannot be exercised there at all. The behaviour is Vitest's,
the storage is PHPUnit's.

## Storage

### What is stored, and what is derived

A `QueueTrack` names the same song **four times over** — once as `id`, then again inside `href`,
`streamUrl` and `coverUrl`, all three of which are just routes built from that id. Stored verbatim, that
repetition is most of a browser's entire storage budget:

| stored form | chars per track |
| --- | --- |
| whole `QueueTrack` | 374 |
| trimmed (`id` + tags + `hasCover`) | **164** |

`toPersisted` / `fromPersisted` own that translation and are the only pair that knows the stored shape;
everything else in the app sees a full `QueueTrack`. Three details are load-bearing:

- **The cover collapses to a flag, not to nothing.** "No cover at all" and "a cover at the usual place"
  are different facts, and the panel draws a placeholder for the first — derived unconditionally, a
  coverless track would point an `<img>` at a 404 on every reload.
- **A URL the id cannot imply is stored verbatim.** `isDerivable()` compares the parsed **path**, not the
  string, because the props are inconsistent by design: `coverUrl` arrives absolute (Laravel's `route()`
  default) while `streamUrl` arrives relative (`absolute: false`). Anything with a foreign origin or a
  query string — which is exactly what a **share link** is — fails the check and survives intact.
  Trimming blindly would hand a guest a URL that 403s on play.
- **Field names stay readable.** Single letters would save another ~30 characters a track and are not
  worth a stored payload nobody can read in devtools when a queue comes back wrong.

A derived `coverUrl` comes back **root-relative** where the fresh one was absolute. Same target — both an
`<img src>` and MediaSession artwork resolve against the document — and deliberately not re-absolutised:
this module has no business minting an origin.

### Two keys, because the halves change at different rates

| key | holds | written when | size |
| --- | --- | --- | --- |
| `mixtape.queue` | the list | someone queues, drags, removes | 164 chars × tracks |
| `mixtape.queue.position` | `currentIndex` + `repeat` + `shuffle` + `positionMs` | every track change, incl. auto-advance | **~110 chars**, flat |

Sharing one key means a four-minute song ending rewrites the whole queue to move one integer by one:

| queued | auto-advance write, one key | two keys |
| --- | --- | --- |
| 200 | 73 KiB | 94 chars |
| 2,000 | 730 KiB | 94 chars |
| 12,058 | 4,404 KiB | 94 chars |

`repeat`, `shuffle` and the position's own `trackId` ride with the pointer rather than the list because
they change at the same rate — a click or a track change, not a queue edit. A new pointer field can be
added **without bumping the shape**: the version is
shared with the list payload, so a bump would throw away every stored queue to gain one boolean, and a
field read as `=== true` simply reads as off in a pointer written before it existed. The shuffle **walk**
is not stored at all (see *Playing in a random order*).

**The pointer is advice, not truth.** The pair can drift: a quota error on the list, a browser profile
copied half-way. So it is refused on its own terms (wrong user, older shape, not a number, **a position
naming a track this is not**) and whatever survives is **clamped into the list that was actually read**. Refusing it leaves the queue cued at its
first track, which is a far better failure than dropping a restored queue over one integer. A pointer
saying 5 against a one-track list loads track 1, not silence.

**The keys carry no version — the payload does.** A versioned key (`mixtape.queue.v2`) makes a refused
payload an **orphan nothing ever deletes**, holding its share of a ~5 MB origin budget for the life of
the profile. Under one stable key the same refusal self-heals: `hydrate` rejects the old shape and the
next write overwrites it. A shape change still bumps `PERSISTED_VERSION` rather than attempting a
migration.

### Written late, and flushed on the way out

`commit()` does not write. It marks which key is behind and schedules one flush, so the cost of an
operation stops scaling with how much is queued, and a burst — two enqueues and a drag — costs one list
write plus one pointer write between them.

- **A trailing-edge timer, not a resetting debounce** (500ms, started by the *first* dirty mark). A
  resetting debounce would keep postponing while somebody drags rows for a minute, leaving storage a
  minute stale the whole time. This way staleness is bounded by the delay.
- **`flushQueueWrites()` is called when the tab goes away**, on both `pagehide` and `visibilitychange →
  hidden`. `pagehide` covers navigation and close without disqualifying the page from the
  back/forward cache the way `unload` would; `visibilitychange` is the one iOS reliably delivers before
  it discards a backgrounded tab.
- **That listener is not belt-and-braces, it is the correctness half.** A hidden tab has its timers
  throttled to once a second and, after five minutes, once a minute — and this player keeps playing while
  hidden **on purpose**. Without the listener, an auto-advance in a background tab could be minutes from
  disk when the tab closes.
- It is also where the **debounced PUT to `player_states`** lands, for the same reason: it is the one
  place that knows what changed and when.

### What it refuses on the way in

`hydrate()` is called once, from `FullLayout` — not at module load, because it needs Inertia's shared
props to know whose queue it is reading. It discards rather than adopts:

- **a payload whose `userId` is not the current one.** This instance is deliberately shared with family
  and friends, so one browser genuinely sees several accounts; adopting the wrong one is the difference
  between "resume where I left off" and "here is somebody else's listening". A guest on a share link
  (`userId: null`) is a distinct owner, not a wildcard.
- **a payload written by an older shape**, and
- **a single unusable row** — a row with no id is dropped on its own rather than costing the other 200
  theirs.

### Signing out

Every `streamUrl` in the queue is behind `auth`, so a queue left standing after a logout is a panel of
rows that answer a redirect — a player that has silently stopped working rather than one that has gone
away.

**Why it survives at all** is the same fact this whole file rests on: `FullLayout` is Inertia's
persistent layout and logging out is a client-side visit, so `setup()` never runs again. The module
singleton keeps the tracks, `hydrate()`'s one-shot guard is still set, and nothing reads `auth.user` on
its own. `FullLayout` therefore **watches `auth.user`** and calls `abandonQueue()` on any change.

**`clear()` is the wrong function here, and that is the whole design.** It commits — so it would store an
empty list under `userId: null` over the copy that is the only thing bringing the queue back on the next
sign-in, and, run a moment sooner, hand the same emptiness to `player_states`. `abandonQueue()` drops the
pending timer, clears the dirty set and empties the refs **without writing anything at all**. The
departing reader's stored copy is left exactly as it was; the `userId` refusal above is what keeps a
guest, or the next person to use the browser, from seeing it.

**The last change before the press is saved by `UserMenu`, not here.** Writes are coalesced behind a
500ms trailing timer, so a track dragged just before the click is still only in memory — and by the time
the response lands there is no cookie to authorise the PUT. The logout item flushes on the click, the
same flush-then-change ordering `beginEphemeralQueue` uses.

**The player, the panel and the header's toggle disappear as a consequence, not through gates of their
own** — they already key off `current` / `isEmpty`. That is deliberate rather than lazy: a guest reading
a share link has a legitimate player, and an `auth` gate on the bar would take it away from them.

**And it re-arms `hydrate()`**, which is what makes the return trip work: signing in is a visit too
(`useLogin` → `router.visit`), so without a second `hydrate()` the reader would face an empty queue until
their next full page load — `playerState` only rides down on one of those, but their localStorage copy
answers immediately. The watcher therefore abandons on *every* change of user and hydrates whenever the
new one is not a guest.

### How much fits

The budget is per **origin**, small, and counted differently per browser — which is the part worth
writing down, because the specification mandates nothing:

| browser | limit | counting |
| --- | --- | --- |
| Chrome / Edge | 10 MiB per storage area (`kPerStorageAreaQuota`) + 100 KiB allowance | bytes |
| Firefox | 5 MiB, `dom.storage.default_quota` (user-tunable) | `len(key) + len(value)` in UTF-16 units |
| Safari | 5 MB | has counted UTF-16 code units → **~2.5M chars** |

Against the tightest of those, the trim moves the ceiling from **~7,000 queued tracks to ~16,000** —
past a whole 12,058-track collection, which stores as **1.9 MB**. Quota has stopped being the binding
constraint; the panel's DOM will complain first.

**What still evicts a queue is Safari, not size.** With cross-site tracking prevention on, an origin with
no user interaction in the last seven days of browser use loses everything script wrote. On an instance
visited occasionally by family, the queue simply disappears — which is an argument for the server sync,
not something the client can fix.

## The panel

`PlayQueue` renders the list; `PlayQueueToggle` in the header opens it on a phone, where it floats over
the content instead of taking a column. Covers are `loading="lazy"`, and the panel scrolls the loaded
track into view a row clear of the edge it approached.

**It is a signed-in reader's affordance, and it says so structurally.** The guest share space mounts no
panel at all — `/s/{share}` puts the queue on the page instead — so the panel publishes its own presence
on mount (`notePlayQueuePanel`), and the header's toggle and the `Q` shortcut render and fire only when it
is there. Neither of them evaluates a rule of its own about which layout it is in: one condition, owned
by the thing the condition is about. See [`sharing.md`](sharing.md) → *The page is a player*.

**Rows are plain text, deliberately.** A real `Link` to the song's page, in a panel whose every other
pixel plays the track, is a trap. The row's hit area is an **empty button positioned across it**, which
is also what gives the play overlay a real bounding box: its focus ring traces the row, and a browser
test can click it.

**Its menu holds verbs only** — no setting sitting one row above a destructive one. There are two: **add
everything to a playlist** and **clear**, in that order, because clearing is the entry a mis-aimed click
must not land on.

**Add-to-playlist opens a modal** (`QueuePlaylistModal`), not another row — it is a decision with an
argument to it, and a select nested inside a popover would be a popover inside a popover with a button
that must close neither. It is also the one entry that has to `hidePopover()` by hand: `clear` empties
the queue, which unmounts the whole panel and takes its popover out of the DOM with it, while this one
leaves the panel standing.

**It sends track ids, where a detail-page hero sends a subject** — and that difference is forced. The
queue is client state in an order the reader arranged, and the server's copy of it is written late on
purpose, so asking `player_states` what is queued would sometimes answer with the queue from a minute
ago. The ids are read at the press rather than at the open, so a track that finished while the modal sat
there changes nothing.

One honest asymmetry follows: the modal offers **every** playlist, while a hero hides the ones that
already hold its subject. The server cannot compute that for a queue it has not been sent, and posting
the whole queue up just to draw a select would be the request the modal exists to make. The write is
unaffected — it skips what a playlist already holds and reports what actually landed — so the cost is
only that a reader may pick a playlist and be told "already in there".

### How it arrives

**A wipe, not a slide.** `clip-path: inset(0 0 0 100%)` → `inset(0)`, so the panel is revealed from the
trailing edge it is pinned to rather than travelling in from off-screen. Nothing translates, the rows are
already in place as the clip uncovers them, and there is no journey to sit through — which matters here
more than anywhere, because **the peek opens this panel by itself every time the queue grows**. It plays
twenty-odd times in a listening session, so it is `fast` (150ms) and no slower: a quarter-second would be
pleasant once and tiring by the tenth.

Two alternatives are worse: `scaleX` from the edge squashes every glyph on the way in, which is the
cheap-CSS tell; and the popover's own 90° door swing (`styles/components/popover/_content.scss`) is right
for a 24ch menu you asked for and a lot of rotating pixels at full height.

**The two entrances mean different things, and animate differently.** A press of the header toggle is a
*request* — the reader asked, so the panel arrives and gets out of the way. A **peek is an
announcement**: nobody asked, it appeared to say something was queued. So the peek adds a light running
down the panel's inner edge, once, and the deliberate open stays calm. `usePlayQueuePanel` already knows
which is which (the peek is the one with the timer); it is a class on the layer, not new state.

The sweep is a gradient bar translated down rather than an animated gradient behind `@property`: one
composited transform beats repainting a gradient sixty times a second on an element that appears this
often.

**The discrete pair goes on the LAYER, the visual transition on the PANEL**, and that split is the part
worth remembering. Closing a popover yanks it out of the top layer and sets `display: none` on the same
frame, cutting the exit off mid-gesture — `transition: display … allow-discrete, overlay …
allow-discrete` on the layer holds both until the wipe has finished. And `@starting-style` on the panel
is what gives the wipe a from-value at all: while the popover is shut the panel is not merely hidden but
**not rendered**, so without it the first style it ever has is the finished one.

Under `prefers-reduced-motion` there is no wipe and no sweep — `clip-path` lives *inside* the motion
guard rather than being reset under it, so nothing is clipping the panel at all.

### Reordering

Two gestures over one operation. Both go through `reorder(from, to)` on `usePlayerQueue` rather than
touching `tracks`: it is what carries the pointer with the loaded track and what persists the new order.

**Dragging, by SortableJS used directly** — no Vue wrapper. `vuedraggable` / `vue-draggable-plus` exist
to own the list through `v-model`, which is exactly what must not happen when the queue is a module
singleton that persists itself; in controlled mode a wrapper earns nothing, and this app keeps very few
runtime dependencies. Native HTML5 drag-and-drop was out from the start: no events on touch, and the
panel has a phone mode. What SortableJS actually pays for is touch, auto-scroll near the ends of the
list, and `handle`.

- **`forceFallback: true`**, so its own pointer path runs everywhere instead of native dragging on
  desktop and the fallback elsewhere. One code path, drag visuals we style (`--dragging` on the clone,
  `--ghost` on the gap it left), no reliance on a browser agreeing to start a native drag from inside a
  `<button>` — and it is the path Playwright can drive with plain mouse moves.
- **The DOM move is undone before the queue is told.** Sortable has already moved the `<li>` when `onEnd`
  fires, which leaves two writers on one list; restoring it first means Vue's re-render is the only thing
  that reorders anything. This is what the wrappers do internally, and skipping it is how a wrapper-less
  integration duplicates or loses a row.
- `delay` + `delayOnTouchOnly` (a long-press on touch only), or a finger dragging a row steals the
  gesture that scrolls the list. `animation` is `0` under reduced motion and otherwise `150`, mirroring
  `ti.$c-play-queue` — a JS option cannot read the Sass token, so the two are kept in step by hand.

**Alt+↑/↓ moves the *focused* row** one place. Alt is what keeps it out of the way: a bare arrow scrolls
the panel. The keystroke is only consumed when a move actually happens, so the ends of the queue do not
silently eat one. **Focus has to be put back by hand** — the row's `v-for` key carries its index (it
must: the same song may be queued twice), so the element holding focus is replaced by the move, and
without re-focusing the same control in the row's new position the journey ends after a single press.

- **"Focused" is the whole story of the one bug this gesture ships with.** Hover is not focus, so
  pointing at a row and pressing the keys does nothing — which reads as "it doesn't work", and it works
  the moment the grip is clicked. Two things follow. The grip's hint **says to click it first**
  (`player.queue.moveHint`), because nothing else on screen does. And the grip **focuses itself on
  `pointerdown`**, because macOS Safari and Firefox deliberately leave a clicked `<button>` unfocused
  (platform convention) — without it the instruction would only be true in Chrome. Chromium does not
  treat that programmatic focus as `:focus-visible`, so a mouse click still draws no focus ring.
- **The keys are named for the keyboard in front of the reader** — ⌥↑/↓ on an Apple one, Alt+↑/↓
  elsewhere (`utils/platform.ts`). A hint that says "Alt" to someone looking at a ⌥ key is naming a key
  they cannot find. Only the WORDS branch: the handler still reads `event.altKey`, which is one bit with
  two names printed on it, and `aria-keyshortcuts` keeps ARIA's canonical `Alt+ArrowUp Alt+ArrowDown` for
  assistive tech to announce in its own words.

**The handle is the cover with the drag glyph beneath it** — one 24px-wide grip strip. At 280px the row
has no horizontal room to give (the title ellipsises first), so a leading or trailing handle column would
cost the title 24px; stacking costs it nothing and grows the row from 47px to **54px** instead. The glyph
alone would have been a 16px target, which is not something to aim a drag at on a phone. What it costs:
the cover holds the grip rather than a play button, so **tapping the cover does not play the track** —
the other ~90% of the row still does.

## Tests, and which layer answers what

- **Vitest** — the shuffle specs assert what a listener would complain about rather than the draw itself:
  every track once per pass, a new pass that does not replay what just ended, back going to the track
  actually heard, forward retracing it, and the pass being forgotten by a remove but kept by an append.
  `Math.random` is stubbed to always take the first candidate, which turns "a random pick" into an
  assertable one — what matters is which POOL it came from.

  `usePlayerQueue.test.ts` covers the two things that are easy to get subtly wrong and invisible when
  they are: **the pointer** following the song rather than the index, and **whose queue it is**. It also
  pins the storage contract, which a round-trip alone cannot: the trimmed payload is read **raw** (a
  round-trip would still pass if every dropped field were quietly stored), the write is asserted by
  **spying on `setItem`** — that a burst coalesces, and that an auto-advance touches only the pointer key
  — and the flush is driven through fake timers and real `pagehide` / `visibilitychange` events.

  `PlayerSettings.test.ts` covers the wiring the composable's own spec cannot see, including the trap the
  component exists to avoid: the queue exposes toggles, not setters, so re-choosing the option already
  lit would flip the mode back off if the bridge did not compare first.

  `useQueueReorder.test.ts` proves the contract *around* SortableJS with the library **mocked on
  purpose** — a drag is a stream of pointer events over elements with real geometry, so a "drag" here
  would assert the mock's own arithmetic. What it checks is the options it is handed, the DOM move being
  undone before the queue is told, the instance following the list in and out of the DOM (it is behind a
  `v-if`, so `onMounted` would work once and then stop after a clear), and every Alt+↑/↓ decision.
- **Playwright** (`tests/e2e/app/queue.spec.ts`) — the real browser half: the panel appearing and
  emptying, its two layouts and the phone toggle, real drags, the keyboard journey, and scrolling the
  loaded row into view. The one that quietly guards the storage design is **"survives a navigation,
  because the new order is persisted"** — it drags a row and then does a full page load, so it exercises
  the actual `pagehide` flush and rehydration rather than a mocked timer.
- **Signing out is split across both, because neither can answer it alone.** The half only a browser knows
  is that the persistent layout survives the visit at all, so `tests/e2e/guest/logout.spec.ts` queues a
  track, signs out, and asserts the bar, the panel and the toggle are gone. The half a browser cannot
  cheaply prove is the one about **not writing**: that the departing reader's stored copy is
  byte-for-byte what it was, and that a write still on the timer is dropped rather than landing after the
  logout. Those are `usePlayerQueue.test.ts` → *when the session ends*. The end-to-end spec does close
  the loop on the SERVER's copy, by wiping localStorage while signed out so the queue that comes back can
  have come from nowhere else.

  That spec lives in the **guest** project with an account of its own, and it is the only one whose parked
  session is never read — it signs out, which would kill any session it borrowed.

**Two traps found writing these**, both worth knowing beyond this file:

- **`vi.spyOn` returns the spy that is *already* installed**, and nothing in this repo restores spies
  between tests — so a second `setItem` spy arrives pre-loaded with the previous spec's writes and the key
  split looks broken when it is not. The file's `beforeEach` calls `vi.restoreAllMocks()`.
- **A pending flush is test state.** `resetPlayerQueueForTests()` cancels the timer and clears the dirty
  set, because a write left scheduled by one spec fires during the next one and drops the previous queue
  into that spec's storage.

The end-to-end cost of the queue being **user-scoped server state** is documented in
[`testing.md`](testing.md) → *Traps*: a fresh browser context is no longer a fresh player, so a spec that
writes user-scoped state needs its own account, `mode: "default"`, an out-of-band reset, and a stopped
sync.

## Known edges, and what is deliberately absent

- **A refused write is not silent.** The exception is swallowed — a full or disabled storage, or a dead
  network, must never take the player down — but the FACT is not. Two warnings, because the remedies
  differ: a browser that refuses is full or locked down and the queue will not survive this tab, while a
  server that refuses leaves the browser's copy intact and only costs the listener the trip to another
  device. `Utils/queueSaveWarning` latches each target until a write to it succeeds again, because the
  queue flushes on every track change and an unlatched warning would raise a toast every four minutes for
  as long as the tab is open. A **warning** rather than an error, deliberately: the music is playing and
  the queue on screen is right; only its survival is at risk. And nothing is said about the server while
  the tab is CLOSING — a toast raised into a page being torn down is one nobody can read.
- **The panel skips the rows nobody is looking at**, which is what makes bulk enqueue survivable: a big
  genre really can put thousands of rows in the queue in one click. Measured at 2,000 rows,
  `content-visibility: auto` on the row cut main-thread blocking during load from **810ms to 302ms**, the
  longest single task from **331ms to 151ms**, and first paint from **528ms to 268ms**.

  **Not windowing**, and the difference is what stays working. A virtual list renders a slice and fakes
  the rest, which breaks three things this panel already does correctly: SortableJS drags rows that must
  exist to be dragged, Alt+↑/↓ moves focus between rows that must exist to be focused, and
  `scrollIntoView` finds a row that must exist to be found. Skipped content is still in the DOM and still
  focusable — the browser renders it the moment focus, find-in-page or a scroll makes it relevant — so all
  three keep working with no code at all.

  **`contain-intrinsic-size` is the half that can go wrong quietly.** It replaces the CONTENT box, so the
  row's own padding is added on top: seeded with the measured 54px row height the list comes out a fifth
  too tall (65.8px a row) and every scrollbar in the panel lies. The figure is 42px, and the end-to-end
  guard asserts a rendered row and the total scroll height agree — a timing threshold would only be a
  flake waiting for a busy machine.
- **The shuffle walk is in-memory only**, so a reload restarts the pass, and an edit that renumbers rows
  does too. Both are argued above — they are the two things about shuffle a listener could actually
  notice, and persisting the walk is the known fix.
- **Nothing here is music-only.** `PlayerStatePayload` asks `QueuePayload` for any track type when it
  restores a queue (the filter is the caller's, defaulting to music for the four subject pages), and the
  play beacon never looks at the type at all. Audiobook chapters are queued, restored and counted through
  the same path.
