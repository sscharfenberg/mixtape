# Sharing

Play one song, album, audiobook, artist or playlist **without an account** — "here's a song I found",
sent to family and friends. This is the headline use case of an internet-facing instance
([`app-rewrite.md`](app-rewrite.md) → _Authentication & access model_).

**Designed 2026-08-10; MINTING built 2026-08-11; the `/s/` GUEST SPACE built the same day.** This
file is the plan, written before the code the way [`now-playing.md`](now-playing.md) was, and kept
afterwards as the record of why the shape is what it is.

**A share link now works end to end.** A signed-in reader presses **share** in a song / album /
artist hero, the server mints (or re-hands) a link, and whoever it is sent to opens it with no
account and plays what it names — verified in a signed-out browser
(`tests/e2e/guest/share.spec.ts`). **The owner's half landed on 2026-08-12**: `/dashboard/shared`
lists what they have sent and revokes any of it, which is what the mint modal had been promising
since the feature shipped.

Read alongside:

- [`player.md`](player.md) — the `<audio>` element and the stream route the share space reuses.
- [`play-queue.md`](play-queue.md) — the queue a guest gets, and the per-track URL overrides that let
  a share feed the player without the player learning anything about shares.

## Status

| Piece                                    | State                                                      |
| ---------------------------------------- | ---------------------------------------------------------- |
| `shares` table + model                   | ✅ built 2026-08-11                                        |
| Minting (`POST /shares`) + the modal     | ✅ built 2026-08-11                                        |
| The `/s/{share}` route space             | ✅ built 2026-08-11 — four routes, not three (below)       |
| Guest page (`Share/SharePage.vue`)       | ✅ built 2026-08-11                                        |
| … as a PLAYER: queue pre-loaded, Now Playing block, own listing dropped | ✅ 2026-08-12 — below |
| Link previews (Open Graph) for `/s/` and invites | ✅ 2026-08-12 — below, and it needed a robots.txt change |
| "My shares" dashboard subpage + revoking | ✅ built 2026-08-12 — `/dashboard/shared`, below           |
| Pruning expired rows                     | ✅ built 2026-08-12 — weekly systemd timer, below          |
| Playlist shares                          | ⬜ deferred by the owner — the FK exists, the enum case does not |
| Audiobook shares                         | ⬜ blocked — the Audiobooks area is still a placeholder     |
| Genre shares                             | ❌ never — see *Known edges*                               |

## What a share is

**A row whose id is the capability.** `GET /s/{uuid}` finds the row, the row names one subject, and
the recipient may listen to that subject and to nothing else until `valid_until` passes.

Four properties, each decided rather than inherited:

- **Listening only.** No mp3, no zip. "Listen to this" and "here is the file" are different acts, and
  only the first one is being asked for.
- **Seven days**, from minting. Not a per-link choice.
- **Revocable early**, by deleting the row.
- **It does not chain.** A recipient cannot mint a further share, because the `/s/` space contains no
  mint route at all.

### Why a row and not a signed URL

The plan said Laravel **signed / temporary URLs** until 2026-08-10. It could not survive its own next
requirement — that share links be revocable — and the reasons generalise:

- **A signature is an assertion, not a record.** There is nothing to revoke. The only lever is
  rotating `APP_KEY`, which voids every session, every encrypted column, and every outstanding
  verification and password-reset link. Anything short of that means keeping a list of dead
  links — which is a table, arrived at the long way round.
- **A row makes the expiry editable.** A signed URL bakes the expiry into the string, so "give them
  another week" means minting and sending a different link.
- **The HMAC then guards nothing.** Once an unguessable id resolves to a row that has to be read
  anyway, the signature is a second mechanism doing the first one's job.

## The table

```
shares
  id             uuid       primary                        -- the URL secret (see below)
  user_id        uuid       FK users        cascade        -- who minted it
  track_id       uuid       FK tracks       cascade  null
  collection_id  uuid       FK collections  cascade  null  -- album OR audiobook
  artist_id      uuid       FK artists      cascade  null
  playlist_id    uuid       FK playlists    cascade  null
  note           string     null                           -- who it was for; the Invite precedent
  valid_until    timestamp  NOT NULL
  timestamps

  CHECK  exactly one of the four subject FKs is non-null
  INDEX  (user_id, valid_until)                            -- the "My shares" list
```

**The primary key is the secret.** `HasUuids` emits **UUIDv7** on Laravel 13 — 48 bits of millisecond
timestamp and 74 bits of randomness. 74 bits is far beyond brute force over a rate-limited HTTP
endpoint, so the capability is sound; the cost is that the id publicly encodes when it was minted,
which for "here's a song" discloses nothing worth having. Using the PK directly buys route-model
binding and `whereUuid` for free. The one reason to add a separate token column would be wanting to
*re-roll* a link while keeping the row's note and history — not a need today.

**Stored unhashed, unlike `Invite`.** An invite code is sent once and never shown again, so hashing
costs nothing. A share is re-copied from the owner's list weeks after minting, and a digest cannot be
re-displayed. The stakes differ too: a leaked invite creates an account, a leaked share plays a song.

**Four real FKs rather than a polymorphic `shareable_type`/`shareable_id` pair.** The point is
`cascade`: when a rescan drops a track or an album, its shares go with it. A morph column has no
referential integrity, so it would leave rows pointing at nothing — which surfaces as a share link
that resolves to a page it cannot build. Hand-written CHECK constraints are already this schema's
idiom (`collections_owner_type_ck`, `tracks_type_taxonomy_ck`).

`user_id` cascades too: delete the account, and the links it handed out stop working.

## The grant: the same query "play this" already means

**A share must grant exactly the set of tracks that the app already considers to *be* that subject.**
This is the part most likely to be got subtly wrong, and the app has already solved it once.

[`App\Enums\PlaylistSubject`](../app/Enums/PlaylistSubject.php) exists so the browser can say "artist
X" instead of sending a track list, and its `column()` is documented as *deliberately* the same
narrowing each detail controller applies to build its `queueTracks` prop — so that "add this artist"
and "play this artist" can never come to mean different songs. **A share is a third phrasing of the
same question and must reuse that mapping**, whether by extending the enum or by a `ShareSubject`
that delegates to it:

| subject       | the tracks it grants               |
| ------------- | ---------------------------------- |
| song          | `tracks.id`                        |
| album         | `tracks.collection_id`             |
| audiobook     | `tracks.collection_id`             |
| artist        | `tracks.artist_id`                 |
| playlist      | `playlist_tracks`, in its order    |

**The artist trap, concretely.** `ArtistController` builds its page from two different queries: the
albums grid reads `collections.album_artist_id` (`:168`), the playable queue reads `tracks.artist_id`
(`:91`, `:228`). Those overlap but neither contains the other — a compilation holds an artist's track
without being their album, and an album credited to them can hold a guest track credited to someone
else. Enforce one and render the other, and a guest gets an album tile holding a track that 404s: a
bug that only appears on the one record in the collection with a featured guest.

So the rule for the whole `/s/` space: **the guest page is built from the same query the stream guard
enforces.** The share page for an artist will therefore look slightly different from the signed-in
artist page — it shows precisely what it can play, which is the correct difference.

**An artist share is an expanding grant.** Unlike an album, whose track list is fixed when the link is
minted, an artist share also serves whatever a later rescan adds. Within seven days that is
immaterial, and it is arguably the point.

**A playlist is the one subject with no column**, for the reason `PlaylistSubject` omits it: a
playlist's order *is* its content, so no id over `tracks` names it. It resolves through
`playlist_tracks` instead — two shapes of grant resolution, not one.

## The URL space

Its own space, `/s/{share}/…`, rather than widening the gate on `/music/*`:

```
GET  /s/{share}                          the guest page
GET  /s/{share}/cover                    the SUBJECT's artwork      (added while building)
GET  /s/{share}/tracks/{track}/stream    audio bytes
GET  /s/{share}/tracks/{track}/cover     cover art, per track
```

**The fourth route was not in the sketch.** The hero of an album share is not its first track's
picture: `CoverService` keeps two orders on purpose — a track prefers its own embedded image, an
album prefers the directory's `folder.jpg` — because rips exist where every file carries a different
inline picture, and there "the embedded cover" makes a record's artwork depend on which track happens
to sort first. Drawing the hero through a per-track route would have imported exactly that bug into
the one page a listener sees before deciding whether to press play. It 404s for an artist share,
which nothing points an `<img>` at: MixTape stores no artist images, so that hero fans a few of the
artist's own sleeves instead (`CoverSleeves`, the same object the playlist hero wears), drawn from
**the grant** rather than from their discography — a sleeve off an album the link cannot play would
be a picture of something the page has no rows for.

**Every sub-route resolves its subject through the share row**, so containment is structural rather
than conditional: a share of one album cannot address a track outside it, because the route has no
way to *name* one. The alternative — marking the session as holding a share and teaching the existing
routes to accept "authenticated **or** entitled" — reuses more code, but turns the auth boundary from
a line you read into logic you reason about, on a box that is deliberately reachable from the
internet. With the split, `routes/web.php` keeps saying that everything under `/music` sits behind
`auth`, full stop, and that remains verifiable by reading it.

The controllers stay thin: both media routes resolve the track, check membership of the grant, and
hand off to the same [`InternalRedirect`](../app/Services/Media/InternalRedirect.php) and
[`CoverService`](../app/Services/Media/CoverService.php) the signed-in routes use, so nginx's
`X-Accel-Redirect` hand-off and HTTP Range behave identically ([`player.md`](player.md)). One
difference worth noting: the share stream controller must **not** carry `SongStreamController`'s
music-only `TrackType` guard, since an audiobook share streams audiobook tracks — which is easier to
get right in a separate controller than in a shared one.

**Validation and authorization live in FormRequests** under `app/Http/Requests/Shares/`, per
[`../CLAUDE.md`](../CLAUDE.md). `authorize()` is where "this share is live" and "this track is in its
grant" belong; the routed models are already resolved, so it reads `$this->route('share')`.

**The page route has no request class, and that is the rule being followed rather than broken**: it
validates no input and guards no subject. Expiry — the one thing that could be mistaken for a
permission there — is *content* on that route, since the page's job when a link has died is to say
so. The three routes that answer with bytes treat it as a permission and each have one
(`AuthorizesShareTrack` for the two that name a track, `ShareCoverRequest` for the one that does
not).

**One class owns the grant**: [`App\Services\Shares\ShareGrant`](../app/Services/Shares/ShareGrant.php).
The guest page is drawn from `tracks()` and the media routes admit a track through `contains()`, and
both resolve through the same `query()` — including the track-type filter, so a caller cannot widen
the set by forgetting a second call. Written twice they would drift, and the drift has a shape: a
guest gets a row for a track the stream then refuses, which appears as a player that stops silently
on one song out of ninety. `tests/Feature/Shares/ShowShareTest.php` asserts the page's rows and the
guard's answers against each other for exactly the artist case above.

### What an expired link answers, and what a revoked one does

They differ, and the difference follows from revoke being a DELETE:

- **Expired** — the row is still there, so the page can say so kindly: *this link has expired, ask
  whoever sent it for a new one*. That discloses that a share once existed, which is not a leak: the
  only person who can reach the URL is someone who was given it.
- **Revoked** — the row is gone, so it is an ordinary 404, indistinguishable from a typo. Slightly
  blunt for the recipient, and correct: the owner's decision was that this should stop existing.

The media sub-routes answer **404 either way**, per the repo's rule that a 403 which confirms a row
exists is itself a disclosure. `failedAuthorization()` overrides to `abort(404)`.

## What the guest gets

A dedicated `pages/Share/SharePage.vue` on a trimmed layout — **not** the real detail page, which
carries the site menu, `ActionPanel`, add-to-playlist and the download button, all of them either
meaningless or actively wrong without an account. Language still works: `ConfigureLocale` falls back
session → browser when there is no user.

**The player needs no changes at all.** `usePlayerQueue`'s `PersistedTrack` already carries optional
`coverUrl`, `href` and `streamUrl` overrides, and the `streamUrl` one is documented in the source as
being for *"a signed share link, say"* — the seam was cut for this feature before it was designed.
The share page populates the three per track and everything downstream works unchanged.

Two consequences of that reuse to get right:

- **`href` must be overridden, not defaulted.** It resolves to `/music/songs/{id}`, which for a guest
  is a bounce to the login page. It should point back at the share.
- **The stored queue is user-scoped, and a guest's `userId` is `null`.** That is fine for an actual
  guest. The awkward case is the *owner* opening their own share link while signed in: the queue is
  keyed to them, so the share's tracks — carrying `/s/…` URLs that die in seven days — would overwrite
  their real queue and leave dead entries behind it. Worth deciding before building; not persisting
  the queue at all on a share page is the cheap answer.

### What the guest page turned out to be (built 2026-08-11)

The cheap answer was taken, and three more things were settled by building it:

- **The "trimmed layout" is `ShareLayout`, and it is FullLayout minus two things** (three since
  2026-08-12 — the queue panel joined them, see the next section). No
  breadcrumb — a trail says where you are in the app, and a share link is not *in* the app, so the
  trail would render as a lone home chip pointing at a login form. And **no persistence**: it calls
  `beginEphemeralQueue()` instead of `hydrate()`, so nothing a guest queues is written to
  `localStorage` or synced. The flag sits on `commit()`, the one choke point both writers are behind.
  Leaving the space (`endEphemeralQueue`) re-arms the restore rather than clearing the queue, because
  a Vue layout swap may mount the incoming layout *first* and `clear()` persists.
  The **header is kept unchanged**, because it trims itself: `SiteMenu` renders nothing without a
  user, so a guest gets the wordmark, the language and theme switches, and a login link — while the
  owner arriving at their own link keeps their way back into the app.
- **A song share draws no track list.** The hero has just named the track, its artist, its album and
  its playing time; a one-row list under that reads as a rendering fault. Albums and artists get the
  list, which is the whole content of the page.
- **A fact that is true of every row is a fact about the subject, so the row chips are conditional.**
  Twelve rows reading "Radiohead · OK Computer" under a hero saying exactly that is noise — the same
  reason the artist page's songs table has no artist column. `ShareTracks` works it out from the data
  rather than from the subject kind (`varies()`), so a **compilation** shared as an album still shows
  its performers, and an artist share still shows which record each track is from.

*(Both of those bullets describe `ShareTracks`, which no longer exists — see the next section. They
are kept because the second one's rule is still true and still worth having if a listing ever comes
back.)*

**What the page deliberately does NOT show: who minted it.** It reads as a friendly touch — "Anna
sent you this" — and it would publish a login identifier on a page reachable by anyone holding a
forwarded link, since this app logs in by `users.name`. Half a credential pair is too much to pay for
a nicety, and the recipient already knows who sent it.

### The page became a player (2026-08-12)

The owner's call, and it removed more than it added: **the link's tracks go into the queue as the
page opens**, and everything below the hero is `Components/Player/NowPlayingSection` — the
visualiser, the previous / next cards, and the queue itself. `ShareTracks` is gone, and so is its
whole token group.

**The listing only ever existed because the queue was empty.** Its rows were the way to get tracks
into the player, one press at a time. Fill the queue on arrival and the same twelve tracks are drawn
twice on one page, in the same order, one copy live and one inert — so the inert copy went. What a
guest sees instead is what the app's own `/now-playing` shows, under a hero about the *link's
subject* rather than about the loaded track. Only the block's three rows come across; the Now Playing
hero does not, because this page already has one.

Four things worth knowing:

- **Loading is not playing.** Nothing starts on its own — a browser would refuse it, and a page that
  made noise on arrival would be the wrong thing even if it could. The reader gets a queue, a player
  bar, and three rows describing what pressing play would do.
- **It stands down for a player that is already running**, which is the guard the whole thing turns
  on. `beginEphemeralQueue()` deliberately leaves the queue alone so that a reader who was listening
  keeps listening — and that reader is usually the **owner**, opening a link they minted. Replacing
  their queue unasked would cut their music off mid-track. A *paused* queue is fair game: nothing
  under `/s/` is written down, so theirs is restored from storage the moment they leave the space.
- **`enqueue` had to go with it.** `SubjectActions`' second verb would append a second copy of a
  queue that already holds exactly those tracks. What is left is one page-local button, "play it
  all", which restates the queue from the top — the same thing a hero's play means everywhere else,
  where the bar below is what means *resume*.
- **A guest can now remove and reorder rows**, which the old listing would not let them do. The
  owner's call, and the cost is small: nothing is persisted, so the link is one reload away from
  whole.

The mount guard asks **`tracks.length`, not `share.expired`**, because a live link whose grant
resolves to nothing sends none either — and `playNow([])` would *empty* a queue rather than fill it.

**And the sliding panel is gone from this space entirely** — `ShareLayout` mounts no `PlayQueue`.
With the queue on the page there is nothing for a second, sliding copy of it to add, and the panel
stays what it is: a signed-in reader's affordance for a library they can build a queue out of.

The way its absence propagates is the part worth keeping. The header's toggle and the `Q` shortcut
both have to disappear with it, and **neither of them asks whether it is in the share space** —
`PlayQueue` registers itself when it mounts (`notePlayQueuePanel`) and both read that flag. Any
rule they applied themselves ("am I in ShareLayout?", "is there a user?") would be a second copy of
the layout's decision, and the drift has a shape: a round button in the header that opens nothing.
A plain boolean survives a layout swap in both directions — Vue mounts the incoming layout before
unmounting the outgoing one, but only one side ever writes each value — and
`tests/e2e/app/share.spec.ts` walks the owner's round trip (app → link → app) to keep that true,
which is a journey the guest project cannot make.

## What a pasted link looks like (2026-08-12)

A share link is *sent* — into WhatsApp, Signal, a Slack channel — and a bare URL there said
nothing about what was on the other end. `App\Services\Meta\SocialCards` fills that in: the card
names the subject, says what kind of thing it is, how many tracks and how long they run, and
carries the artwork.

**THE TAGS ARE ONLY HALF OF IT, and the other half is `public/robots.txt`.** That file said
`Disallow: /` for everybody, on the entirely reasonable argument that nothing here is meant to be
indexed — and the crawlers that draw link previews honour it. Slack, X, Discord, Facebook and
WhatsApp simply would not have looked; Telegram, Signal and iMessage ignore robots.txt and were
the only places a preview appeared. So the file now names those agents and allows them `/s/` and
`/register`, each group still carrying `Disallow: /` so that is the whole of what they may fetch.
Every indexer — Google, Bing, GPTBot, ClaudeBot — matches the `*` group above and stays shut out
of everything. **If a preview ever stops appearing on one platform, this is the first file to
read**, not the tags.

**Three areas, and there can only be three.** A crawler has no session, so every URL under `auth`
answers it with a redirect to the login form — a per-album card would be written for a fetch that
never happens. What is left is the share space, the invite link, and one default that every other
URL collapses into. The default is the correct answer for a page nobody outside can see, not a
placeholder for work not yet done.

**Decisions worth keeping:**

- **The card is server-rendered, so it lives in Blade.** Unfurl crawlers do not run JavaScript;
  nothing an Inertia page knows can reach them. A view composer on `app` supplies it, rather than
  each controller passing one — a per-controller card is something every new public page has to
  remember, and forgetting is silent.
- **`SocialCards` reads the route rather than being told**, so the areas are a list in one place
  and adding one is a `match` arm.
- **An artist share borrows the sleeve of their most recent granted record** (`ShareGrant::latestCoveredTrackId`).
  The page fans three at random on purpose; a card cannot, because a preview that changed on each
  paste looks like a fault in the chat window showing it. Drawn from the *grant*, like the fan —
  the artist trap in miniature.
- **`og:image` must be absolute and publicly fetchable**, which means it can only ever be a `/s/`
  cover route: `/music/…` covers sit behind `auth` and would unfurl as a broken frame.
- **An expired link says so and offers no picture.** Both cover routes refuse a dead share, so an
  image would 404 on fetch. It keeps the name, as the page does, so asking for a new one is
  possible.
- **The invite card names nobody.** Not the inviter (this app logs in by `users.name`, so that is
  half a credential pair), not the note (the owner's private reminder of who it was for). Its
  `og:url` is the bare `/register`, without the one-time code — the platform already holds the
  full link, but a canonical URL is a string that gets copied onward. A `GET` does not consume an
  invite, so an unfurl cannot burn one.
- **`twitter:card` is `summary`, not `summary_large_image`.** The image is almost always a record
  sleeve, and the large card crops to roughly 2:1 — the top and bottom of the artwork simply gone.
- **The server formats the runtime here**, which is the one place it may: the rule is that
  controllers send raw seconds and `Utils/formatting.ts` renders them, and it holds because there
  is always a client on the other end. A crawler is not one. Minutes, rounded — a preview is a
  glance.

**`ShareArtwork` now owns "which picture stands for a share"** — the hero, the fan and the
preview — because there were two readers wanting different answers to the same question, and the
shared half (does this subject have artwork, and at which route) would otherwise have been copied
into the new one. `SharePageController` lost its two private methods to it.

**The generic image is generated, not drawn** (`npm run og` → `resources/build/ogImage.ts` →
`public/og/mixtape.png`). It is a Playwright screenshot of a page built from the app's own
`logo.svg`, its Google Sans woff2 and the retro palette — because the alternative, rasterising an
SVG through a library, cannot use a woff2 at all and would silently render the wordmark in
whatever fallback the machine had. Not part of `npm run build`: the output is committed, and a
build should not need a browser. `SocialCards` checks the file exists before pointing at it, so a
checkout that never ran the script gets a text-only card rather than a broken image.

**One trap, found the hard way and worth knowing beyond this feature**: Symfony's test client
sends `Accept-Language: en-us,en;q=0.5` of its own accord when a request names none, and
`ConfigureLocale` correctly honours it. **Every response-level assertion about copy is silently
English unless the header is pinned** — including one written against this app's German default,
which then fails looking like a translation bug. Setting `config('app.locale')` does not help: the
middleware's browser arm never reaches the fallback. `SocialCardTest::visit()` pins it.

## Minting, and "My shares"

**Minting** is a button on the existing detail pages. Any signed-in user may mint one for any
library subject — everyone can see the whole library, so there is nothing to protect — **except a
playlist, which only its owner may share.** That check belongs in the mint request's `authorize()`,
answering 404; it is unwritten because playlist shares are deferred, and `ShareSubject` having no
`Playlist` case is what makes its absence safe rather than an omission.

### What minting turned out to be (built 2026-08-11)

Three departures from the sketch above, each decided while building:

- **It is a row of BUTTONS, not a control in the `ActionPanel`.** The plan assumed the tinted panel
  the download button lives in. In the event the four Music heroes also gave up their popover menu
  (`SubjectMenu`) and their title, so play / enqueue / share became one row of primary buttons under
  that panel — `SubjectActions`. The split is what each row is about: the panel is what a reader does
  with their own library (file it in a playlist, keep a copy), the row is what they do with the music.
- **`POST /shares` answers JSON, not a redirect.** The reader is not navigating; they want a string
  to look at. An Inertia visit would re-render a page carrying a hero, a table and a queue to deliver
  one URL — and on a page with an open form that swap is the documented way to lose what was typed
  ([`../CLAUDE.md`](../CLAUDE.md) → prefetch). So it is a plain `fetch` with the CSRF token sent by
  hand, exactly like `useDeleteAccount` and the queue's own sync.
- **A second press hands back the first link.** Not in the plan, and it matters more than it looks:
  if re-sharing minted a new row, "My shares" would become a list of presses rather than of things
  shared — and if it instead *extended* the existing one, a link re-sent every few days would never
  expire and the seven-day rule would be a rule about nothing. So the reader's own live link for that
  subject is returned unchanged, expiry included. Scoped to the reader, so two users sharing one
  album get a link each and neither can revoke the other's.

The modal says three things, in this order: **when the link dies** (formatted from the raw instant in
the reader's locale), **that it can be revoked early from the dashboard**, and **that whoever holds it
can listen without an account**. The last one is the honest note — forwarding is not preventable, so
a reader should read it once, before pasting the link into a chat window, which is the whole reason
this is a dialog rather than a line of text beside the button. The link sits in a readonly field that
**copies on click** (and on focus, so a keyboard reaches it).

**Sharing does not chain** — not because a check forbids it, but because there is no mint route inside
`/s/`. Worth stating plainly, though: **the link itself can always be forwarded.** A bearer capability
travels, and no design short of per-recipient accounts changes that. The seven-day expiry is what
bounds the spread, not a permission.

**"My shares"** is a Dashboard subpage rather than a block on the dashboard itself: it is a list of
unknown length, and `DashboardPage.vue`'s existing sections are fixed-size forms. Per the page
conventions it lives at `pages/Dashboard/Shares/`.

### What revoking turned out to be (built 2026-08-12)

The URL is **`/dashboard/shared`**, not `/dashboard/shares` as sketched above — the page is about
what the reader has *shared*, and the noun reads better in a path a person may type.

**Revoking is `DELETE /shares/{share}`, and deleting the row is the whole mechanism.** Every route
under `/s/{share}` resolves it by implicit binding, so one statement closes the page, both cover
routes and the stream — for the holder and for anybody they forwarded it to — and closes them with
the same 404 a typo gets. That is the payoff of *Why a row and not a signed URL* above, collected:
there was no cache to purge and no revocation list to append to.

- **Only the minter may**, which is the first place in the whole feature where ownership means
  anything: minting needs no ownership check (every account sees the whole library) and the `/s/`
  space needs none (holding the id *is* the permission). A stranger's id answers **404 rather than
  403** — on an instance shared between family and friends, "you may not revoke that" confirms the
  link is real and somebody else's.
- **Expired rows are listed and revocable.** They are still things the reader made, and a list that
  quietly shrank would read as links going missing. It is also how a reader tidies up until pruning
  exists (below).
- **The row shows the kind as a pip, not as "(Album)" in running text** (the owner's call). A
  parenthesis is punctuation to parse; a chip is a label to skip past on the way to the name. The
  validity wears the same pip at the other end of the row, and the revoke button carries a fill at
  rest — unlike every other per-row control in the app, which stay transparent until aimed at. This
  is the only control on its row, and the only one in the app whose consequence lands in somebody
  else's hands.
- **Both ways in are gated on a `shares` boolean shared prop** — the dashboard's own section and the
  user-menu entry below "dashboard". An account that has never pressed share never meets the feature.
  A boolean rather than the list, because the list would otherwise ride on every page load in the app
  to decide the visibility of one menu item.
- **What it deliberately does not show is the note.** That is the owner's private reminder of who a
  link was for, and the list is read at a glance — the subject and the clock are what a revoke
  decision turns on.

## Rate limiting

The `/s/` space is the only unauthenticated surface in the app that reaches the disk, which makes it
the most abuse-exposed thing on the box. Guests have no user id, so every bucket keys on **IP**.

Per [`../CLAUDE.md`](../CLAUDE.md) → _Rate limiting_, each route needs its **own** named bucket or
they share one counter per caller and the tightest ceiling refuses traffic that never touched it —
`tests/Feature/RateLimitBucketsTest.php` fails the suite for a bare numeric throttle.

As built, per minute per IP:

| route                    | bucket              | ceiling | why that number                                                  |
| ------------------------ | ------------------- | ------- | ---------------------------------------------------------------- |
| the guest page           | `share-page`        | 60      | a page opened once and then sat on; high enough for a household behind one NAT |
| the subject's cover      | `share-cover`       | 60      | one request per page load                                        |
| a track's audio          | `share-stream`      | 120     | one per track *plus* every seek, since a Range request re-enters the route |
| a track's cover          | `share-track-cover` | 240     | **the highest ceiling in the app**: one page load fires one per row — an artist share lists everything it grants. Lazy-loaded (`CoverImage`), so a long list only pays for what is scrolled into view, but a reader scrolling a 200-track share must not find the artwork stops half way down |

## Pruning

Expired rows linger deliberately, so the owner sees a dead link in the list and re-mints in one click
rather than wondering where it went — a week is short for "listen to this", and a friend who opens it
on day nine is best served by that. A share that is revoked skips all of this and is deleted outright.

**Built 2026-08-12.** `Share` is `MassPrunable`, swept by
`php artisan model:prune --model="App\Models\Share"` on a **weekly systemd timer**
(`files/mixtape-share-prune.{service,timer}`, installed per
[`self-hosting/03-production-deploy.md`](self-hosting/03-production-deploy.md)).

- **The grace period is `Share::PRUNE_AFTER_DAYS` = 30**, measured past `valid_until` rather than
  past minting. Long enough that "where did the link I sent Oma go?" has an answer on screen; short
  enough that `/dashboard/shared` stays a list of what a reader is doing rather than an archive of
  everything they have ever sent. Sweeping at expiry would delete exactly the rows most likely to be
  asked about.
- **A systemd timer rather than Laravel's scheduler**, which is this box's rule and not a preference
  about pruning: a home server sleeps through its scheduled hour, and `Persistent=true` runs the
  missed job after the next boot where a skipped `dailyAt()` is simply lost. The nightly library scan
  is scheduled the same way and argues it at length.
- **Weekly, not daily**, because the window is 30 days wide: running it seven times a week deletes
  the same set the first run already took, six times over for nothing.
- **`MassPrunable`, not `Prunable`** — one statement, no model hydrated, no per-row `deleting` event.
  Correct rather than a shortcut: a share owns nothing outside its row. There is no file to unlink
  and no cache to clear, which is the same reason revoking is a bare `delete()`.
- **The list keeps showing expired links until they are swept.** That is the point of the grace
  period, and it is why the row keeps its revoke button (and loses only its copy button) once dead:
  a reader who wants a dead link gone now does not have to wait a month for the timer.

## Tests, and which layer answers what

The usual three ([`testing.md`](testing.md)), and the split here is unusually clean:

- **PHPUnit** answers nearly everything, because nearly all of it is a server decision: that a live
  share streams its own tracks and 404s on any other, that the four-FK CHECK holds, that an expired
  share stops working, that a revoked one is gone, that a non-owner cannot share a playlist, that
  **neither download route has a counterpart under `/s/`**, that no play row is written for a guest,
  and — the artist trap — that the page's props and the stream guard describe the same set.
- **Vitest** has little to do on the guest side, which is the sign the design is right: the share
  page's queue entries are ordinary `PersistedTrack`s with overrides, and the override behaviour is
  already covered. It does own the two things about MINTING that PHP cannot see — the modal
  formatting the expiry in the reader's locale, and the failure branches of `useShareLink` (a mint
  that fails must leave `link` null, or the modal opens on an empty field somebody then copies).
  **Since 2026-08-12 it also owns what the page does to the QUEUE on arrival** — filling an empty
  one, standing down for a player already running, and not emptying anything for a dead link. All
  three are decisions about module state made in `onMounted`, cheap to stage here and awkward to
  stage in a browser.
- **Playwright** takes the journeys no other layer can see, and there are two: minting from a hero
  and **copying out of the field** (happy-dom has no clipboard, so nothing below this layer can prove
  a click really copies), and a **signed-out** browser opening a link, playing audio, and never being
  redirected to the login form. The second is one of the few specs that belongs in
  `tests/e2e/guest/` — the project runs with no stored session, so a leaked login could not make it
  pass by accident.

  It needs a link it cannot mint (minting is behind `auth`), so **`E2ESeeder` seeds two with fixed
  ids** — `LIVE_SHARE`, an album, and `EXPIRED_SHARE`, a song — spelled out as constants because a
  spec cannot call PHP for one.

What shipped: `tests/Feature/Shares/CreateShareTest.php`, `tests/Feature/Shares/ShowShareTest.php`,
`tests/Feature/Shares/ShareMediaTest.php`, `resources/app/composables/useShareLink.test.ts`,
`resources/app/components/Music/ShareModal.test.ts`, `resources/app/components/Music/SubjectActions.test.ts`,
`resources/app/pages/Share/SharePage.test.ts`, the ephemeral-queue block in
`resources/app/composables/usePlayerQueue.test.ts`, and the two specs `tests/e2e/app/share.spec.ts` +
`tests/e2e/guest/share.spec.ts`. (`ShareTracks.test.ts` went with its component on 2026-08-12.)

## Known edges, and what is deliberately absent

- **Audiobook shares are blocked on a page to share.** `AudiobooksController` still renders a
  placeholder. The schema covers them for free — one `collection_id` FK, since `collections` already
  discriminates album from audiobook — so this switches on with the Audiobooks area and needs no
  migration. **The serving half is already built for them**: the share stream carries no music-only
  guard, and `ShareGrant` filters by track type only for an *artist* subject (where it matches what
  "play this artist" means). `ShareMediaTest` streams an audiobook chapter through a collection share
  to keep that true.
- **No genre shares**, confirmed by the owner on 2026-08-11. `PlaylistSubject` has a `Genre` case and
  the mapping would be free, but "listen to this genre" is a different kind of act from "listen to
  this" — a share hands over one thing somebody chose to send, and a genre is a shelf. `ShareSubject`
  therefore has no genre case, and the genre hero renders no share button: the absence is stated in
  both halves rather than left to a rule that would 422 a button nobody should have seen.
- **No playlist shares yet**, deferred the same day. `shares.playlist_id` exists — the column was
  created with the others so the four-way CHECK was written once, rather than dropping and re-adding
  it on a live table later — but there is no enum case and so no way to mint one. It is the one
  subject that would need an ownership check, since a playlist belongs to one user where the library
  belongs to everybody.
- **No per-link expiry, and no never-expiring link.** `app-rewrite.md` offered both until 2026-08-10.
  One rule is easier to reason about, and a link that never dies is the one that eventually leaks.
- **No view counts, no "who opened it".** Guest listens are not recorded at all (`plays.user_id`
  stays `NOT NULL`), so most-played keeps meaning the household's own listening.
- **Forwarding is not preventable**, as above.
