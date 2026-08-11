# Sharing

Play one song, album, audiobook, artist or playlist **without an account** — "here's a song I found",
sent to family and friends. This is the headline use case of an internet-facing instance
([`app-rewrite.md`](app-rewrite.md) → _Authentication & access model_).

**Designed 2026-08-10; MINTING built 2026-08-11.** This file is the plan, written before the code the
way [`now-playing.md`](now-playing.md) was, and kept afterwards as the record of why the shape is
what it is.

What exists today is the row and the button that creates it: a signed-in reader presses **share** in
a song / album / artist hero, the server mints (or re-hands) a link, and a modal explains what
handing it out means and copies it on click. **Nothing serves `/s/{share}` yet**, so a minted link is
a real, revocable capability with no page behind it — and "revoke it from your dashboard", which the
modal promises, is likewise still to come. Both are the next two rows of the table below.

Read alongside:

- [`player.md`](player.md) — the `<audio>` element and the stream route the share space reuses.
- [`play-queue.md`](play-queue.md) — the queue a guest gets, and the per-track URL overrides that let
  a share feed the player without the player learning anything about shares.

## Status

| Piece                                    | State                                                      |
| ---------------------------------------- | ---------------------------------------------------------- |
| `shares` table + model                   | ✅ built 2026-08-11                                        |
| Minting (`POST /shares`) + the modal     | ✅ built 2026-08-11                                        |
| The `/s/{share}` route space             | ⬜ planned — **next**                                      |
| Guest page (`Share/SharePage.vue`)       | ⬜ planned — **next**                                      |
| "My shares" dashboard subpage            | ⬜ planned — the modal already promises it                 |
| Pruning expired rows                     | ⬜ planned                                                 |
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
GET  /s/{share}/tracks/{track}/stream    audio bytes
GET  /s/{share}/tracks/{track}/cover     cover art
```

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

**"My shares"** is a Dashboard subpage (`/dashboard/shares`) rather than a block on the dashboard
itself: it is a list that grows and will want paging, and `DashboardPage.vue`'s existing sections are
fixed-size forms. Per the page conventions it lives at `pages/Dashboard/Shares/`. It lists what was
shared, the note, when the link dies, and a revoke button — and it shows **expired** rows too, which
is the point of pruning them lazily (below).

## Rate limiting

The `/s/` space is the only unauthenticated surface in the app that reaches the disk, which makes it
the most abuse-exposed thing on the box. Guests have no user id, so every bucket keys on **IP**.

Per [`../CLAUDE.md`](../CLAUDE.md) → _Rate limiting_, each route needs its **own** named bucket
(`throttle:60,1,share-stream` and so on) or they share one counter per caller and the tightest
ceiling refuses traffic that never touched it — `tests/Feature/RateLimitBucketsTest.php` fails the
suite for a bare numeric throttle. The stream route needs a ceiling that survives ordinary listening:
one request per track, plus more when a listener seeks, since Range requests re-enter it.

## Pruning

Expired rows linger deliberately, so the owner sees a dead link in the list and re-mints in one click
rather than wondering where it went — a week is short for "listen to this", and a friend who opens it
on day nine is best served by that. Something has to sweep them eventually: Laravel's `Prunable` on a
schedule, keeping rows some way past `valid_until`. A share that is revoked skips all of this and is
deleted outright.

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
- **Playwright** takes the journeys no other layer can see. Two of them, and only the first exists:
  minting from a hero and **copying out of the field** (happy-dom has no clipboard, so nothing below
  this layer can prove a click really copies — `tests/e2e/app/share.spec.ts`); and, once `/s/` is
  built, a **signed-out** browser opening a share link and playing audio under the shipped CSP. The
  guest case makes that second one of the few E2E tests that belongs in `tests/e2e/guest/`.

What shipped: `tests/Feature/Shares/CreateShareTest.php`, `resources/app/composables/useShareLink.test.ts`,
`resources/app/components/Music/ShareModal.test.ts`, `resources/app/components/Music/SubjectActions.test.ts`,
and `tests/e2e/app/share.spec.ts`.

## Known edges, and what is deliberately absent

- **Audiobook shares are blocked on a page to share.** `AudiobooksController` still renders a
  placeholder. The schema covers them for free — one `collection_id` FK, since `collections` already
  discriminates album from audiobook — so this switches on with the Audiobooks area and needs no
  migration.
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
