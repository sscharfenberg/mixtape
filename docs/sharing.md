# Share links

Play one song, album, audiobook, artist or playlist **without an account** — "here's a song I found",
sent to family and friends. This is the headline use case of an internet-facing instance
([`architecture.md`](architecture.md) → *Access model*).

Read alongside:

- [`player.md`](player.md) — the `<audio>` element and the stream route the share space reuses.
- [`play-queue.md`](play-queue.md) — the queue a guest gets, and the per-track URL overrides that let a
  share feed the player without the player learning anything about shares.

## What a share is

**A row whose id is the capability.** `GET /s/{uuid}` finds the row, the row names one subject, and the
recipient may listen to that subject and to nothing else until `valid_until` passes.

Four properties, each decided rather than inherited:

- **Listening only.** No mp3, no zip. "Listen to this" and "here is the file" are different acts, and only
  the first one is being asked for.
- **Seven days**, from minting. Not a per-link choice: one rule is easier to reason about, and a link that
  never dies is the one that eventually leaks.
- **Revocable early**, by deleting the row.
- **It does not chain.** A recipient cannot mint a further share, because the `/s/` space contains no mint
  route at all.

### Why a row and not a signed URL

Laravel's signed / temporary URLs are the obvious mechanism and they cannot meet the revocation
requirement. The reasons generalise:

- **A signature is an assertion, not a record.** There is nothing to revoke. The only lever is rotating
  `APP_KEY`, which voids every session, every encrypted column, and every outstanding verification and
  password-reset link. Anything short of that means keeping a list of dead links — which is a table,
  arrived at the long way round.
- **A row makes the expiry editable.** A signed URL bakes the expiry into the string, so "give them another
  week" means minting and sending a different link.
- **The HMAC then guards nothing.** Once an unguessable id resolves to a row that has to be read anyway,
  the signature is a second mechanism doing the first one's job.

## The table

```
shares
  id             uuid       primary                        -- the URL secret (see below)
  user_id        uuid       FK users        cascade        -- who minted it
  track_id       uuid       FK tracks       cascade  null
  collection_id  uuid       FK collections  cascade  null  -- album OR audiobook
  artist_id      uuid       FK artists      cascade  null
  playlist_id    uuid       FK playlists    cascade  null
  note           string     null                           -- who it was for
  valid_until    timestamp  NOT NULL
  timestamps

  CHECK  exactly one of the four subject FKs is non-null
  INDEX  (user_id, valid_until)                            -- the owner's list
```

**The primary key is the secret.** `HasUuids` emits UUIDv7 on Laravel 13 — 48 bits of millisecond
timestamp and 74 bits of randomness. 74 bits is far beyond brute force over a rate-limited HTTP endpoint,
so the capability is sound; the cost is that the id publicly encodes when it was minted, which for "here's a
song" discloses nothing worth having. Using the PK directly buys route-model binding and `whereUuid` for
free. The one reason to add a separate token column would be wanting to *re-roll* a link while keeping the
row's note and history.

**Stored unhashed, unlike an invite code.** An invite is sent once and never shown again, so hashing costs
nothing. A share is re-copied from the owner's list weeks after minting, and a digest cannot be
re-displayed. The stakes differ too: a leaked invite creates an account, a leaked share plays a song.

**Four real FKs rather than a polymorphic `shareable_type` / `shareable_id` pair.** The point is `cascade`:
when a rescan drops a track or an album, its shares go with it. A morph column has no referential
integrity, so it would leave rows pointing at nothing — which surfaces as a share link resolving to a page
it cannot build. Hand-written CHECK constraints are already this schema's idiom.

`user_id` cascades too: delete the account, and the links it handed out stop working.

## The grant: the same query "play this" already means

**A share must grant exactly the set of tracks that the app already considers to *be* that subject.** This
is the part most likely to be got subtly wrong, and the app solves it once:

| subject | the tracks it grants |
| --- | --- |
| song | `tracks.id` |
| album | `tracks.collection_id` |
| audiobook | `tracks.collection_id` |
| artist | `tracks.artist_id` |
| playlist | `playlist_tracks`, in its order |

[`App\Enums\PlaylistSubject`](../app/Enums/PlaylistSubject.php) exists so the browser can say "artist X"
instead of sending a track list, and its `column()` is deliberately the same narrowing each detail
controller applies to build its `queueTracks` prop — so "add this artist" and "play this artist" cannot
come to mean different songs. `ShareSubject` delegates to it, because a share is a third phrasing of the
same question.

**The artist trap, concretely.** `ArtistController` builds its page from two different queries: the albums
grid reads `collections.album_artist_id`, the playable queue reads `tracks.artist_id`. Those overlap but
neither contains the other — a compilation holds an artist's track without being their album, and an album
credited to them can hold a guest track credited to someone else. Enforce one and render the other, and a
guest gets an album tile holding a track that 404s: a bug that only appears on the one record in the
collection with a featured guest.

So the rule for the whole `/s/` space: **the guest page is built from the same query the stream guard
enforces.** A share page for an artist therefore looks slightly different from the signed-in artist page —
it shows precisely what it can play, which is the correct difference.

**One class owns the grant**: [`App\Services\Shares\ShareGrant`](../app/Services/Shares/ShareGrant.php).
The guest page is drawn from `tracks()` and both media routes admit a track through `contains()`, and both
resolve through the same `query()` — including the track-type filter, so a caller cannot widen the set by
forgetting a second call. Written twice they drift, and the drift has a shape: a guest gets a row for a
track the stream then refuses, which appears as a player stopping silently on one song out of ninety.
`tests/Feature/Shares/ShowShareTest.php` asserts the page's rows and the guard's answers against each
other for exactly the artist case above.

**Anything new under `/s/` asks `ShareGrant` rather than re-deriving the set.**

**An artist share is an expanding grant.** Unlike an album, whose track list is fixed when the link is
minted, an artist share also serves whatever a later rescan adds. Within seven days that is immaterial, and
it is arguably the point.

### A playlist is the exception to all of that

A playlist's **order is its content**, so no id over `tracks` names it: `ShareSubject::grant()` answers
`null` for one and `ShareGrant::narrow()` joins `playlist_tracks` instead. Two shapes of grant resolution,
not one. Four consequences:

- **`ShareGrant::entries()` must sort on `playlist_tracks.position`**, then on the pivot's own id
  (position is non-unique). `QueuePayload::fromQuery` imposes album-then-disc-then-track — right for "play
  this artist", and for a hand-made list it is a **silent rewrite**: nothing looks broken, the guest simply
  hears somebody's mix in an order they never chose. Pinned twice — a PHPUnit fixture whose two candidate
  orders disagree, and an end-to-end spec that reads the owner's list and the guest's queue and compares
  them.
- **No type filter**, unlike an artist share: a reader may deliberately mix audiobook chapters into a
  playlist, and filtering would drop entries they added themselves. The playlist's own page says the same
  thing about itself, and the two have to agree.
- **Nothing is snapshotted.** The row holds a `playlist_id` and the grant resolves the pivot on every
  request, so a guest who reloads gets the playlist as it is now, and a track the owner removed stops
  streaming at once because the media routes ask the same grant. No live push, and none wanted.
- **It is the only subject with an owner**, which is why only its owner may share it (below).

## The URL space

Its own space, `/s/{share}/…`, rather than widening the gate on `/music/*`:

```
GET  /s/{share}                          the guest page
GET  /s/{share}/cover                    the SUBJECT's artwork
GET  /s/{share}/tracks/{track}/stream    audio bytes
GET  /s/{share}/tracks/{track}/cover     cover art, per track
```

**Every sub-route resolves its subject through the share row**, so containment is structural rather than
conditional: a share of one album cannot address a track outside it, because the route has no way to *name*
one. The alternative — marking the session as holding a share and teaching the existing routes to accept
"authenticated **or** entitled" — reuses more code, but turns the auth boundary from a line you read into
logic you reason about, on a box that is deliberately reachable from the internet. With the split,
`routes/web.php` keeps saying that everything under `/music` sits behind `auth`, full stop, and that stays
verifiable by reading it.

**The subject cover is its own route, and that is not redundancy.** `CoverService` keeps two orders on
purpose — a track prefers its own embedded image, a collection prefers the directory's `folder.jpg` —
because rips exist where every file carries a different inline picture, and there "the embedded cover"
makes a record's artwork depend on which track happens to sort first. Drawing the hero through a per-track
route would import exactly that bug into the one page a listener sees before deciding whether to press
play.

It 404s for an artist or a playlist share, which nothing points an `<img>` at: MixTape stores no artist
images and a playlist is not a record, so those heroes fan a few of the subject's own sleeves instead
(`CoverSleeves`) — drawn from **the grant** rather than from the discography, because a sleeve off an album
the link cannot play would be a picture of something the page has no rows for.

The controllers stay thin: both media routes resolve the track, check membership of the grant, and hand off
to the same [`InternalRedirect`](../app/Services/Media/InternalRedirect.php) and
[`CoverService`](../app/Services/Media/CoverService.php) the signed-in routes use, so nginx's
`X-Accel-Redirect` hand-off and HTTP Range behave identically. One difference worth noting: the share
stream controller must **not** carry `SongStreamController`'s music-only `TrackType` guard, since an
audiobook share streams audiobook tracks — which is easier to get right in a separate controller than in a
shared one.

**Validation and authorization live in FormRequests** under `app/Http/Requests/Shares/`, per
[`../CLAUDE.md`](../CLAUDE.md). `authorize()` is where "this share is live" and "this track is in its
grant" belong; the routed models are already resolved, so it reads `$this->route('share')`.

**The page route has no request class, and that is the rule being followed rather than broken**: it
validates no input and guards no subject. Expiry — the one thing that could be mistaken for a permission
there — is *content* on that route, since the page's job when a link has died is to say so. The three
routes that answer with bytes treat it as a permission and each have one (`AuthorizesShareTrack` for the
two that name a track, `ShareCoverRequest` for the one that does not).

### What an expired link answers, and what a revoked one does

They differ, and the difference follows from revoke being a DELETE:

- **Expired** — the row is still there, so the page can say so kindly: *this link has expired, ask whoever
  sent it for a new one*. That discloses that a share once existed, which is not a leak: the only person
  who can reach the URL is someone who was given it.
- **Revoked** — the row is gone, so it is an ordinary 404, indistinguishable from a typo. Slightly blunt
  for the recipient, and correct: the owner's decision was that this should stop existing.

The media sub-routes answer **404 either way**, per the repo's rule that a 403 which confirms a row exists
is itself a disclosure. `failedAuthorization()` overrides to `abort(404)`.

## What the guest gets

A dedicated `pages/Share/SharePage.vue` on `ShareLayout` — **not** the real detail page, which carries the
site menu, `ActionPanel`, add-to-playlist and the download button, all of them either meaningless or
actively wrong without an account. Language still works: `ConfigureLocale` falls back session → browser
when there is no user.

**`ShareLayout` is `FullLayout` minus three things.** No breadcrumb — a trail says where you are in the
app, and a share link is not *in* the app, so the trail would render as a lone home chip pointing at a
login form. No **persistence** (below). And no sliding queue panel, because the queue is on the page.

The **header is kept unchanged**, because it trims itself: `SiteMenu` renders nothing without a user, so a
guest gets the wordmark, the language and theme switches, and a login link — while the owner arriving at
their own link keeps their way back into the app.

**What the page deliberately does not show: who minted it.** It reads as a friendly touch — "Anna sent you
this" — and it would publish a login identifier on a page reachable by anyone holding a forwarded link,
since this app logs in by `users.name`. Half a credential pair is too much to pay for a nicety, and the
recipient already knows who sent it.

### The page is a player

**The link's tracks go into the queue as the page opens**, and everything below the hero is
`Components/Player/NowPlayingSection` — the visualiser, the previous / next cards, and the queue itself.

**A separate track listing only makes sense while the queue is empty.** Its rows are the way to get tracks
into the player, one press at a time. Fill the queue on arrival and the same twelve tracks would be drawn
twice on one page, in the same order, one copy live and one inert. So what a guest sees is what the app's
own `/now-playing` shows, under a hero about the *link's subject* rather than about the loaded track. Only
the block's three rows come across; the Now Playing hero does not, because this page already has one.

Four things worth knowing:

- **Loading is not playing.** Nothing starts on its own — a browser would refuse it, and a page that made
  noise on arrival would be the wrong thing even if it could. The reader gets a queue, a player bar, and
  three rows describing what pressing play would do.
- **It stands down for a player that is already running**, which is the guard the whole thing turns on.
  `beginEphemeralQueue()` deliberately leaves the queue alone so that a reader who was listening keeps
  listening — and that reader is usually the **owner**, opening a link they minted. Replacing their queue
  unasked would cut their music off mid-track. A *paused* queue is fair game: nothing under `/s/` is
  written down, so theirs is restored from storage the moment they leave the space.
- **There is no "enqueue" verb here.** A second verb would append a second copy of a queue that already
  holds exactly those tracks. What is left is one page-local button, "play it all", which restates the
  queue from the top — the same thing a hero's play means everywhere else, where the bar below is what
  means *resume*.
- **A guest can remove and reorder rows.** The cost is small: nothing is persisted, so the link is one
  reload away from whole.

The mount guard asks **`tracks.length`, not `share.expired`**, because a live link whose grant resolves to
nothing sends none either — and `playNow([])` would *empty* a queue rather than fill it.

**Not persisting is the answer to a real problem.** The stored queue is user-scoped, and a guest's `userId`
is `null`, which is fine for an actual guest. The awkward case is the **owner** opening their own share
link while signed in: the queue is keyed to them, so the share's tracks — carrying `/s/…` URLs that die in
seven days — would overwrite their real queue and leave dead entries behind it. `ShareLayout` therefore
calls `beginEphemeralQueue()` instead of `hydrate()`, and the flag sits on `commit()`, the one choke point
both writers are behind. Leaving the space (`endEphemeralQueue`) **re-arms the restore rather than
clearing the queue**, because a Vue layout swap may mount the incoming layout *first* and `clear()`
persists.

**The player itself needs no changes at all.** `usePlayerQueue`'s `PersistedTrack` carries optional
`coverUrl`, `href` and `streamUrl` overrides, and the share page populates all three per track.
Two consequences of that reuse:

- **`href` must be overridden, not defaulted.** It resolves to `/music/songs/{id}`, which for a guest is a
  bounce to the login page. It points back at the share.
- **A URL with a query string or a foreign origin survives the storage trim** (`isDerivable()` compares the
  parsed path), which is what stops a share URL coming back rebuilt and refused.

**The absence of the queue panel propagates by a flag, not by a rule.** The header's toggle and the `Q`
shortcut both have to disappear with the panel, and **neither of them asks whether it is in the share
space** — `PlayQueue` registers itself when it mounts (`notePlayQueuePanel`) and both read that flag. Any
rule they applied themselves ("am I in ShareLayout?", "is there a user?") would be a second copy of the
layout's decision, and the drift has a shape: a round button in the header that opens nothing. A plain
boolean survives a layout swap in both directions — Vue mounts the incoming layout before unmounting the
outgoing one, but only one side ever writes each value — and `tests/e2e/app/share.spec.ts` walks the
owner's round trip (app → link → app) to keep that true, which is a journey the guest project cannot make.

### The hero explains itself

The hero would otherwise be **measurably empty** at desktop width: five chips and one button, so the text
column runs out ~123px before the 240px cover square does. Three things fill it, and only one is really
about the space:

- **A sentence in the `#description` slot** — the one `HeroSection` carries for a playlist's blurb.
  *"Somebody sent you this ⟨Album⟩ as a listening link. You can play it right here — no account, no signing
  in."* It is missing regardless of layout: this is the only page in the app a stranger reaches, and the
  header above it looks like an app they have never signed into.
  - **One sentence per KIND, in the catalog** (`share.intro.{song,album,audiobook,artist,playlist}`),
    because German agrees both the determiner and the pronoun with the noun — *diesen* Song … ihn, *dieses*
    Album … es, *diese* Wiedergabeliste … sie. A single template with the noun swapped in is wrong in most
    cases.
  - **The noun is an element, so `<i18n-t>` assembles it**: it wears a chip and the app's own glyph for
    that kind of thing, which a `t()` interpolation cannot carry. Every kind's sentence must be written to
    that pattern — one written with markup inside the message instead (`…<strong>{name}</strong>…`) makes
    vue-i18n warn on every render. The chip reads the FACT TILE's fill and corners (`c./s.$c-facts`) rather
    than minting a colour, because it stands among those tiles on the same panel.
- **A genre tile**, after the year — the fact that means most to a recipient who does not know the band.
  Derived by `DominantGenre`, the same answer the library's own pages give, and **unlinked**: a genre page
  lives under `/music`, so a guest following it would land on the login form. A playlist gets none;
  `DominantGenre` ranks by artist and by album, and "mostly Doom" is not something a hand-made mix is.
- **`growMetadata` on `HeroSection`**, letting the chips fill their row. The default stays `flex-grow: 0` —
  a few chips stretched across a Music hero read as a table nobody asked for — and this hero is the case
  where the argument runs the other way.

## What a pasted link looks like

A share link is *sent* — into WhatsApp, Signal, a Slack channel — and a bare URL there says nothing about
what is on the other end. `App\Services\Meta\SocialCards` fills that in: the card names the subject, says
what kind of thing it is, how many tracks and how long they run, and carries the artwork.

**THE TAGS ARE ONLY HALF OF IT, and the other half is `public/robots.txt`.** A blanket `Disallow: /` is the
entirely reasonable default here, since nothing in this app is meant to be indexed — **and the crawlers
that draw link previews honour it.** Slack, X, Discord, Facebook and WhatsApp simply do not look; Telegram,
Signal and iMessage ignore robots.txt and are the only places a preview appears. So the file names those
agents and allows them `/s/` and `/register`, each group still carrying `Disallow: /` so that is the whole
of what they may fetch. Every indexer — Google, Bing, GPTBot, ClaudeBot — matches the `*` group above and
stays shut out of everything. **If a preview ever stops appearing on one platform, this is the first file
to read**, not the tags.

**Three areas, and there can only be three.** A crawler has no session, so every URL behind `auth` answers
it with a redirect to the login form — a per-album card would be written for a fetch that never happens.
What is left is the share space, the invite link, and one default that every other URL collapses into. The
default is the correct answer for a page nobody outside can see, not a placeholder.

Decisions worth keeping:

- **The card is server-rendered, so it lives in Blade.** Unfurl crawlers do not run JavaScript; nothing an
  Inertia page knows can reach them. A view composer on `app` supplies it, rather than each controller
  passing one — a per-controller card is something every new public page has to remember, and forgetting is
  silent.
- **`SocialCards` reads the route rather than being told**, so the areas are a list in one place and adding
  one is a `match` arm.
- **The two kinds that fan sleeves lend the card ONE of them** (`ShareGrant::cardTrackId`, per kind): an
  artist lends their most recent granted record, a playlist lends its **first entry** — a playlist has an
  order, and its opening track is the one thing about it its maker actually chose. The page fans three at
  random on purpose; a card cannot, because a preview that changed on each paste looks like a fault in the
  chat window showing it. Drawn from the *grant*, like the fan.
- **`og:image` must be absolute and publicly fetchable**, which means it can only ever be a `/s/` cover
  route: `/music/…` covers sit behind `auth` and would unfurl as a broken frame.
- **An expired link says so and offers no picture.** Both cover routes refuse a dead share, so an image
  would 404 on fetch. It keeps the name, as the page does, so asking for a new one is possible.
- **The invite card names nobody.** Not the inviter (this app logs in by `users.name`, so that is half a
  credential pair), not the note (a private reminder of who it was for). Its `og:url` is the bare
  `/register`, without the one-time code — the platform already holds the full link, but a canonical URL is
  a string that gets copied onward. A `GET` does not consume an invite, so an unfurl cannot burn one.
- **`twitter:card` is `summary`, not `summary_large_image`.** The image is almost always a record sleeve,
  and the large card crops to roughly 2:1 — the top and bottom of the artwork simply gone.
- **The server formats the runtime here**, which is the one place it may: the app's rule is that
  controllers send raw seconds and `Utils/formatting.ts` renders them, and it holds because there is always
  a client on the other end. A crawler is not one. Minutes, rounded — a preview is a glance.

**`ShareArtwork` owns "which picture stands for a share"** — the hero, the fan and the preview — because
there are two readers wanting different answers to the same question, and the shared half (does this
subject have artwork, and at which route) would otherwise be copied into both.

**The generic fallback image is generated, not drawn** (`npm run og` → `resources/build/ogImage.ts` →
`public/og/mixtape.png`). It is a Playwright screenshot of a page built from the app's own `logo.svg`, its
woff2 and the retro palette — because the alternative, rasterising an SVG through a library, cannot use a
woff2 at all and would silently render the wordmark in whatever fallback the machine had. It is **not** part
of `npm run build`: the output is committed, and a build should not need a browser. `SocialCards` checks the
file exists before pointing at it, so a checkout that never ran the script gets a text-only card rather
than a broken image.

> **One trap worth knowing beyond this feature**: Symfony's test client sends
> `Accept-Language: en-us,en;q=0.5` of its own accord when a request names none, and `ConfigureLocale`
> correctly honours it. **Every response-level assertion about copy is silently English unless the header
> is pinned** — including one written against this app's German default, which then fails looking like a
> translation bug. Setting `config('app.locale')` does not help: the middleware's browser arm never reaches
> the fallback. `SocialCardTest::visit()` pins it.

## Minting

**Minting is a row of buttons on the existing detail pages** — `SubjectActions`, holding play / enqueue /
share as primary buttons under the hero's tinted `ActionPanel`. The split is what each row is about: the
panel is what a reader does with their own library (file it in a playlist, keep a copy), the row is what
they do with the music.

Any signed-in user may mint one for any library subject — everyone can see the whole library, so there is
nothing to protect — **except a playlist, which only its owner may share.**

**That check is a validation RULE, not an `authorize()`.** The mint request narrows the `exists` rule:
`Rule::exists('playlists', 'id')->where('user_id', $this->user()->id)`. It is the same *kind* of question
the two type narrowings beside it already answer — "could the page that sent this have shown it to you?" —
and it lands in the same place, because a stranger's playlist id then fails validation exactly as a made-up
UUID does. A 403 would confirm that the id names somebody's real playlist; a **422 on `id` says nothing at
all.** `CreateShareTest` pins both directions.

**`POST /shares` answers JSON, not a redirect.** The reader is not navigating; they want a string to look
at. An Inertia visit would re-render a page carrying a hero, a table and a queue to deliver one URL — and on
a page with an open form that swap is the documented way to lose what was typed
([`architecture.md`](architecture.md) → the prefetch rule). So it is a plain `fetch` with the CSRF token
sent by hand, exactly like `useDeleteAccount` and the queue's own sync.

**A second press hands back the first link**, and that matters more than it looks. If re-sharing minted a
new row, the owner's list would become a list of *presses* rather than of things shared — and if it instead
*extended* the existing one, a link re-sent every few days would never expire and the seven-day rule would
be a rule about nothing. So the reader's own live link for that subject is returned unchanged, expiry
included. Scoped to the reader, so two users sharing one album get a link each and neither can revoke the
other's.

**The modal says three things, in this order:** when the link dies (formatted from the raw instant in the
reader's locale), that it can be revoked early from the dashboard, and that whoever holds it can listen
without an account. The last one is the honest note — forwarding is not preventable, so a reader should read
it once, *before* pasting the link into a chat window, which is the whole reason this is a dialog rather
than a line of text beside the button. The link sits in a readonly field that **copies on click** (and on
focus, so a keyboard reaches it).

**Sharing does not chain** — not because a check forbids it, but because there is no mint route inside
`/s/`. Worth stating plainly, though: **the link itself can always be forwarded.** A bearer capability
travels, and no design short of per-recipient accounts changes that. The seven-day expiry is what bounds
the spread, not a permission.

## The owner's list, and revoking

**`/dashboard/shared`** — a Dashboard subpage rather than a block on the dashboard itself, because it is a
list of unknown length where that page's other sections are fixed-size forms. Per the page conventions it
lives at `pages/Dashboard/Shares/`.

**Revoking is `DELETE /shares/{share}`, and deleting the row is the whole mechanism.** Every route under
`/s/{share}` resolves it by implicit binding, so one statement closes the page, both cover routes and the
stream — for the holder and for anybody they forwarded it to — and closes them with the same 404 a typo
gets. That is the payoff of *Why a row and not a signed URL*, collected: there is no cache to purge and no
revocation list to append to.

- **Only the minter may.** This is the first place in the whole feature where ownership means anything:
  minting needs no ownership check (every account sees the whole library) and the `/s/` space needs none
  (holding the id *is* the permission). A stranger's id answers **404 rather than 403** — on an instance
  shared between family and friends, "you may not revoke that" confirms the link is real and somebody
  else's.
- **The row shows the kind as a pip, not as "(Album)" in running text.** A parenthesis is punctuation to
  parse; a chip is a label to skip past on the way to the name. The validity wears the same pip at the
  other end of the row, and the revoke button carries a fill at rest — unlike every other per-row control
  in the app, which stay transparent until aimed at. This is the only control on its row, and the only one
  in the app whose consequence lands in somebody else's hands.
- **Both ways in are gated on a `shares` boolean shared prop** — the dashboard's own section and the
  user-menu entry below "dashboard". An account that has never pressed share never meets the feature. A
  boolean rather than the list, because the list would otherwise ride on every page load in the app to
  decide the visibility of one menu item.
- **What it deliberately does not show is the note.** That is a private reminder of who a link was for, and
  the list is read at a glance — the subject and the clock are what a revoke decision turns on.

### Two lists, and re-activating a dead link

The page sends **two props, `shares` and `expiredShares`** — live links under the page's heading, dead ones
under a right-aligned *"your expired links"* (`share_off` — the app's share glyph, crossed out). A single
mixed list with its dead rows marked carries too much: what a reader wants at a glance is *what am I
sharing right now*, and that answer would be a count they had to make themselves by discounting rows.
Splitting it also puts every copy button in the half where copying makes sense.

- **Two props, not one plus a flag**, and `ShareRow` carries no `expired` at all: the list a row arrives in
  *is* that answer, and a second one beside it could only ever disagree — a dead row in the live half is a
  copy button that pastes a 404.
- **Opposite sort orders, one query.** Live runs soonest-to-die first ("which of these runs out next");
  dead runs most-recently-dead first ("where has the link I sent on Monday gone?"). One query ordered
  ascending, partitioned in PHP, the dead half reversed.
- **One row component** (`ShareLinkRow.vue`) rather than the same fifty lines of markup twice, holding
  `useClipboard` — a flag per row is what a per-row "copied" tick actually needs.

**The word "expired" on a dead row is a button**, and that is what makes the thirty-day grace period worth
having. `PATCH /shares/{share}/renew` puts a dead row back to `now() + LIFETIME_DAYS`, so for the three
weeks after a link dies its minter can simply switch it back on — *the same URL*, already sitting in
somebody's chat window, working again. Without it, a dead row can only be revoked or replaced by a second
link, which means the recipient has to be sent a new address.

- **The remedy hangs off the word, not off a fourth control.** The pip a reader is already looking at
  becomes a `<button>` — same chip, same place — rather than the row growing another icon whose meaning has
  to be worked out. It opens a dialog rather than acting: reviving a link is not a stray-click act, and
  "the URL you already sent starts working again" does not fit on a chip.
- **A LIVE link cannot be renewed, and that refusal is the load-bearing half.** Minting deliberately
  re-hands an existing link rather than granting a fresh week, precisely so the seven-day rule cannot be
  reset on demand; a renew that accepted a live row would be that extension by another name. A dead row is
  different in kind — reviving one is a decision taken *after* the link stopped working, and it is bounded
  by the sweep. So the guard is "mine, and finished", and both refusals answer **404**: a stranger's link
  must not be confirmed, and for a live row there genuinely is no such action (`RenewShareRequest`).
- **Seven days from now, not seven added to a remainder** — there is no remainder; the row was finished.
- **It moves the sweep with it.** Pruning measures thirty days past `valid_until`, so a renewed row's
  window starts over from its new expiry. `RenewShareTest` runs the real `model:prune` to say so.
- **A reader who already minted a replacement then holds two live links to the same subject.** Not a bug to
  guard: both were deliberate acts, `store` hands back whichever it finds first, and revoking one leaves
  the other working.

## Rate limiting

The `/s/` space is the only unauthenticated surface in the app that reaches the disk, which makes it the
most abuse-exposed thing on the box. Guests have no user id, so every bucket keys on **IP**.

Per [`../CLAUDE.md`](../CLAUDE.md) → *Rate limiting*, each route needs its **own** named bucket or they
share one counter per caller and the tightest ceiling refuses traffic that never touched it —
`tests/Feature/RateLimitBucketsTest.php` fails the suite for a bare numeric throttle.

Per minute per IP:

| route | bucket | ceiling | why that number |
| --- | --- | --- | --- |
| the guest page | `share-page` | 60 | a page opened once and then sat on; high enough for a household behind one NAT |
| the subject's cover | `share-cover` | 60 | one request per page load |
| a track's audio | `share-stream` | 120 | one per track *plus* every seek, since a Range request re-enters the route |
| a track's cover | `share-track-cover` | 240 | **the highest ceiling in the app**: one page load fires one per row, and an artist share lists everything it grants. Lazy-loaded, so a long list only pays for what is scrolled into view — but a reader scrolling a 200-track share must not find the artwork stops half way down |

## Pruning

Expired rows linger deliberately, so the owner sees a dead link in the list and re-activates it in one
click rather than wondering where it went — a week is short for "listen to this", and a friend who opens it
on day nine is best served by that. A share that is *revoked* skips all of this and is deleted outright.

`Share` is `MassPrunable`, swept by `php artisan model:prune --model="App\Models\Share"` on a **weekly
systemd timer** (`files/mixtape-share-prune.{service,timer}`, installed per
[`self-hosting/03-production-deploy.md`](self-hosting/03-production-deploy.md)).

- **The grace period is `Share::PRUNE_AFTER_DAYS` = 30**, measured past `valid_until` rather than past
  minting. Long enough that "where did the link I sent Oma go?" has an answer on screen; short enough that
  the list stays a list of what a reader is doing rather than an archive of everything they have ever sent.
  Sweeping at expiry would delete exactly the rows most likely to be asked about — and it is the window
  that makes re-activating possible at all.
- **A systemd timer rather than Laravel's scheduler**, which is this box's rule rather than a preference
  about pruning: a home server sleeps through its scheduled hour, and `Persistent=true` runs the missed job
  after the next boot where a skipped `dailyAt()` is simply lost.
- **Weekly, not daily**, because the window is 30 days wide: running it seven times a week deletes the same
  set the first run already took, six times over for nothing.
- **`MassPrunable`, not `Prunable`** — one statement, no model hydrated, no per-row `deleting` event.
  Correct rather than a shortcut: a share owns nothing outside its row. There is no file to unlink and no
  cache to clear, which is the same reason revoking is a bare `delete()`.
- **The list keeps showing expired links until they are swept**, which is why a dead row keeps its revoke
  button (and loses only its copy button): a reader who wants a dead link gone now does not have to wait a
  month for the timer.

## Tests, and which layer answers what

- **PHPUnit** answers nearly everything, because nearly all of it is a server decision: that a live share
  streams its own tracks and 404s on any other, that the four-FK CHECK holds, that an expired share stops
  working, that a revoked one is gone, that a non-owner cannot share a playlist, that **neither download
  route has a counterpart under `/s/`**, that no play row is written for a guest, and — the artist trap —
  that the page's props and the stream guard describe the same set.
- **Vitest** has little to do on the guest side, which is the sign the design is right: the share page's
  queue entries are ordinary `PersistedTrack`s with overrides, and the override behaviour is already
  covered. It owns the two things about MINTING that PHP cannot see — the modal formatting the expiry in the
  reader's locale, and the failure branches of `useShareLink` (a mint that fails must leave `link` null, or
  the modal opens on an empty field somebody then copies). It also owns **what the page does to the QUEUE
  on arrival**: filling an empty one, standing down for a player already running, and not emptying anything
  for a dead link. All three are decisions about module state made in `onMounted`, cheap to stage here and
  awkward to stage in a browser.
- **Playwright** takes the journeys no other layer can see, and there are two: minting from a hero and
  **copying out of the field** (happy-dom has no clipboard, so nothing below this layer can prove a click
  really copies), and a **signed-out** browser opening a link, playing audio, and never being redirected to
  the login form. The second is one of the few specs that belongs in `tests/e2e/guest/` — the project runs
  with no stored session, so a leaked login could not make it pass by accident.

  It needs a link it cannot mint (minting is behind `auth`), so `E2ESeeder` seeds two with fixed ids —
  `LIVE_SHARE`, an album, and `EXPIRED_SHARE`, a song — spelled out as constants because a spec cannot call
  PHP for one.

## Known edges, and what is deliberately absent

- **No genre shares, ever.** `PlaylistSubject` has a `Genre` case and the mapping would be free, but
  "listen to this genre" is a different kind of act from "listen to this" — a share hands over one thing
  somebody chose to send, and a genre is a shelf. `ShareSubject` therefore has no genre case, and the genre
  hero renders no share button: the absence is stated in both halves rather than left to a rule that would
  422 a button nobody should have seen.
- **`ShareGrant::subject()` has to read `collections.type`**, because `collection_id` is the only one of
  the four FKs that does not name its own kind — and a share that cannot tell an album from a book calls
  every shared book an album to every guest who opens it, in German with the wrong article. **Anything that
  lists shares must eager-load that column**: an eager load of `collection:id,name` leaves the type
  unreadable and every book in the list says "Album".
- **Adding a subject kind does not fail any `match` that has a `default`**, which is the most instructive
  trap this feature carries. `ShareArtwork::hero()` and `ShareCoverController` list the kinds that *have* a
  single picture and let the rest fall to a `default` meaning "no single picture" — correct for an artist
  and a playlist, wrong for an audiobook, which has a `folder.jpg` exactly as a record does. The symptom is
  a placeholder glyph on the page and no image at all in a chat window's preview. **A `default` that means
  "the kinds with no cover" is a list of kinds written as an absence** — be suspicious of every one of them
  whenever the enum grows. Likewise `SharePage.test.ts`'s per-kind loop must actually iterate every case.
- **No per-link expiry, and no never-expiring link.** One rule is easier to reason about, and a link that
  never dies is the one that eventually leaks.
- **No view counts, no "who opened it".** Guest listens are not recorded at all (`plays.user_id` stays
  `NOT NULL`), so most-played keeps meaning the household's own listening.
- **Forwarding is not preventable**, as above.
