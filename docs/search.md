# Search

Find anything in the collection from anywhere in the app — a song, an album, an artist, a genre, one
of your playlists — without first working out which listing it lives in. One of the headline v2
features ([`app-rewrite.md`](app-rewrite.md) → _New and improved features_, "improved search /
filtering").

**Designed 2026-08-13, nothing built yet.** This file is the plan, written before the code the way
[`sharing.md`](sharing.md) and [`now-playing.md`](now-playing.md) were, and kept afterwards as the
record of why the shape is what it is. Every number in it was measured against the live dev database
(12,074 tracks) rather than estimated; where a figure still needs measuring on the box, it says so.

Read alongside:

- [`components/DataTable/README.md`](../resources/app/components/DataTable/README.md) — the listing
  surface whose own search this feature hands off to, and never replaces.
- [`data-model.md`](data-model.md) → _(c) substring search_ — why `name_fold` columns exist at all.

## Status

| Piece                                              | State                                             |
| -------------------------------------------------- | ------------------------------------------------- |
| `name_fold` columns + `pg_trgm` GIN indexes        | ✅ built 2026-07-28 — the whole engine rests on it |
| Per-listing search (`?search=` through the URL)    | ✅ built with the DataTable                        |
| `GET /search` — the cross-kind endpoint            | ⬜ planned, below                                  |
| The result surface (one component, mounted twice)  | ⬜ planned                                         |
| Header trigger + overlay                           | ⬜ planned — surface (b)                            |
| Music page: "Deine Musik" + scope chips            | ⬜ planned — surface (c)                            |
| `playlists` fold columns                           | ⬜ planned — the one migration this needs           |
| Audiobooks as a kind                               | ⬜ blocked — the Audiobooks area is a placeholder    |
| Per-widget search boxes                            | ❌ never — see _Why the widgets get no boxes_       |
| An "advanced search" page with filters             | ❌ never — the listings already are that            |

## What is wrong with today

Search exists and works: every listing has it, server-driven through the URL, and it is wider than it
looks — the Songs listing matches `tracks.name`, `artists.name`, `collections.name` **and**
`genres.name`. What it is not is reachable. A reader who wants "that Sabbath record" has to decide
between Songs, Albums and Artists, navigate there, and only then type. The knowledge needed to start
searching is the knowledge they were searching for.

## Three surfaces, one engine

The owner asked for three placements (2026-08-13). They are **one feature with three mountings**, not
three features:

| surface                    | trigger                             | searches                        | results appear                     |
| -------------------------- | ----------------------------------- | ------------------------------- | ---------------------------------- |
| **(b)** header             | the `search` icon, or `/` / `⌘K`    | every kind                      | an overlay under the header        |
| **(c)** Music page         | an input in the wide widget         | every kind, narrowed by chips   | inline, in place of the stat tiles |
| **(a)** per-type, deep-linked | the group's "see all" link       | that listing's own wide search   | the listing, at `?search=…`        |

So (a) is not a search box at all in the end — it is the hand-off that (b) and (c) both need anyway.
That is the whole of the owner's option (a), spent once instead of four times.

### Why the widgets get no boxes of their own

The first sketch put a search field in each of the four browse widgets (Albums, Artists, Genres,
Songs), which is what v1 did. Combined with (c) on the same page that is **five inputs, four of them
a subset of the fifth** — and the four would each show a truncated list with no honest way to say
"and 72 more". The owner's call: one input on the Music page with **scope chips** (_alles / Alben /
Künstler / Genres / Songs / Wiedergabelisten_), which is the same capability in one field. The browse
widgets keep doing what they are for — latest, random, most-played — which is browsing, not looking.

## What a match is

**A row matches on ITS OWN name.** A song matches its title; an artist matches their name. A song is
*not* returned because its artist matched — the artist is, as an artist.

This is the decision the whole feature turns on, and the numbers are why. Searching `"black"`:

| group   | own name only                                            | also matching artist / album / genre |
| ------- | -------------------------------------------------------- | ------------------------------------ |
| Songs   | **77**                                                   | **1,238** — 10% of the whole library |
| Albums  | 9                                                        |                                      |
| Artists | 3 — Black Sabbath, Beast In Black, Godspeed You! Black Emperor |                                 |
| Genres  | 10                                                       |                                      |

The 1,238 is almost entirely "Black Metal" *as a genre* dragging in every track filed under it. In a
grouped dropdown that is not a longer answer, it is a worse one: "Back in Black" would be one row
among 1,238, and the artist the reader probably meant would be somewhere below the fold. Meanwhile
the narrow reading loses nothing — **Black Sabbath is still one press away, as the artist row**,
which is a better answer than two hundred of their songs.

### …and the wide search is still there

It moves to the hand-off. **"Alle 77 in Songs anzeigen"** links to `/music/songs?search=black`, and
that listing does the wide match across four columns, sorted, paginated, and deep-linkable. So both
behaviours exist, each where it fits: narrow while you are typing, wide once you have committed to a
kind. Nothing about the listings changes.

## Ranking

### Across kinds: fixed groups, no cross-kind score

Groups in a fixed order — **Artists → Albums → Playlists → Songs → Genres** — with empty groups
collapsing. Containers before contents, because one artist row leads to everything by them; a genre
last, because a genre is a shelf rather than a thing somebody was looking for.

There is deliberately **no relevance ranking between kinds**. There is no honest common scale between
"an artist called Black Sabbath" and "a song called Black", and a reader scanning a dropdown is
looking for a kind anyway — a shape that is the same every time is what lets them stop reading and
start aiming. Collapsing already floats the answer for a specific query: `"karma police"` leaves
Artists, Albums and Playlists empty, so Songs is the first thing on screen. A "best match" row
hoisted above the groups was considered and dropped for that reason.

### Within a kind: four tiers, then a total tie-break

Measured on `"black"` in songs:

| tier                            | matches |
| ------------------------------- | ------- |
| exact — the folded query equals the folded name | 2 |
| starts with (`black%`)          | 40      |
| word start (`% black%`)         | 37      |
| anywhere else                   | 0 here  |
| **then name A→Z**               | the tie-break |

A `CASE` expression in `ORDER BY` over the already-filtered rows, so no extra index and nothing
precomputed — 77 rows is nothing to sort.

**The tie-break has to be TOTAL.** With `LIMIT 5` over a partial order, two identical queries can
return different rows — the same trap `DominantGenre` documents about ties, in a place where a reader
would see it as results flickering while they type.

### Not now: similarity, and popularity

- **No `similarity()` ordering.** `pg_trgm` earns its keep here as the *index*; as a sort key it
  produces an order nobody can explain when asked why row 3 beat row 4, and it needs a threshold
  tuned per collection. The four tiers cover the real cases.
- **No typo tolerance** for the same reason. If it is ever wanted, the `%` operator over the existing
  GIN index is the cheap way in — and it belongs behind "no results", not in front of them.
- **Most-played first, later.** `plays` is an event table (`user_id`, `track_id`, `played_at`) with
  **10 rows** in the dev database today, so ordering by it would order nothing. Once history has
  volume it is the natural upgrade inside the Songs group, as a subquery over the *matched* ids (77
  for "black"), never over the library.

## The kinds

A **registry**, not a hard-coded four-way union, because the list is known to grow (audiobooks) and
each entry is the same five facts:

| kind      | table         | matched            | links to                | scope        |
| --------- | ------------- | ------------------ | ----------------------- | ------------ |
| artist    | `artists`     | `name`             | `/music/artists/{id}`   | everybody's  |
| album     | `collections` | `name`             | `/music/albums/{id}`    | everybody's, `type = album` |
| playlist  | `playlists`   | `name`, `description` | `/playlists/{id}`    | **the reader's own** |
| song      | `tracks`      | `name`             | `/music/songs/{id}`     | everybody's, `type = music` |
| genre     | `genres`      | `name`             | `/music/genres/{id}`    | everybody's  |
| audiobook | `collections` | `name`             | — (no page yet)         | `type = audiobook`, **later** |

Both `type` narrowings are the ones the rest of the app already applies: `tracks` and `collections`
are unified tables, and an audiobook chapter answering a music search is the same class of bug
`AuthorizesMusicTrack` and `StoreShareRequest` exist to prevent. **Audiobooks are one registry entry
plus a route when that area exists** (the owner's addendum, 2026-08-13) — the reason the registry is a
registry.

Every match runs through **`FoldedSearch`**, never through raw SQL folding. That is not a preference:
`artists.name` carries the nondeterministic `case_insensitive` ICU collation, and Postgres refuses
`LIKE` / `ILIKE` / regex on it — so a search written against the raw columns is a hard 500 on one
table and unrunnable on the sqlite test database. The `_fold` companions carry the default collation
and one code path. Anything added here asks that class rather than re-deriving it.

## The endpoint

**`GET /search?q=…&kinds=…`, answering JSON, behind `auth`.**

- **JSON rather than an Inertia partial reload**, which is the one architectural decision worth
  defending, since this app has no REST API by design. A typeahead firing `router.reload` would
  re-render the page component on every debounce and push history entries — and re-creating a page
  under a reader who is typing is precisely the documented data-loss trap (CLAUDE.md → the prefetch
  rule). Two JSON endpoints already exist for the same reason: minting a share, and syncing the
  player state.
- **A named throttle bucket** (`throttle:60,1,search`), per the repo rule — a numeric throttle with no
  prefix shares one counter per reader with every other unprefixed route.
- **`Cache-Control: private, no-store`.** Playlists make the response reader-specific, so it must
  never be cached anywhere: two accounts typing the same query get different totals.
- **Shape**: one group per kind, each with what the header needs to be honest.

```
{ "groups": [
    { "kind": "artist", "total": 3, "rows": [ { "id": "…", "name": "Black Sabbath", "meta": "12 Alben", "href": "/music/artists/…" } ] },
    { "kind": "song",   "total": 77, "rows": [ … 5 … ], "seeAll": "/music/songs?search=black" }
] }
```

- **`LIMIT 5` per kind, plus the real `total`**, so a group header can say "77" and "see all" is
  offered only when there is more to see. Five is what fits an overlay without scrolling on a phone.
- **`kinds=` is the chip filter** — the same endpoint, fewer groups. Not a second route: the chips are
  a narrowing of one question, and two endpoints would be two ranking rules to keep in step.
- **`meta` is one line of context per row**, computed per kind (an artist's album count, a song's
  artist, a playlist's track count) — a row saying only "Black" three times is a row a reader cannot
  choose from.

### On the client

- **Debounce ~200ms, minimum 3 characters, and abort the in-flight request.** The abort is the one
  that matters: without it a slow response for `"bla"` can land after `"black"` and paint stale rows,
  which reads as the search being wrong rather than late. `AbortController`, keyed on the query.
- **One composable, one result component, mounted twice.** The header overlay and the Music page
  block differ in where they sit and whether chips are shown — nothing else. Two implementations
  would be two sets of keyboard handling.

## Performance, measured

Against the live dev database over the SSH tunnel from the Mac (so these include a round trip the
server does not pay):

| query                                        | measured |
| -------------------------------------------- | -------- |
| four narrow kinds, `LIMIT 5` each            | **48.5 ms total** |
| a two-character query (`%bl%`)               | 5.0 ms   |

Two honest readings of that. The four-kind cost is dominated by the tunnel, so the figure to quote is
one measured **on the box** — that measurement is a step of building this, not an assumption to carry
into it. And the two-character query being fast means **the three-character minimum is about result
quality, not speed**: at 12k rows a `%bl%` scan is cheap, but it matches half the library, and a
trigram index cannot help a pattern shorter than a trigram. Both facts are worth keeping written down
because the obvious guess is the other way round.

## Playlists are the awkward one

Two things fall out of including them, and both were found by reading the schema rather than by
guessing:

1. **They have no fold columns.** The 2026-07-28 migration covered `tracks`, `collections`,
   `artists`, `authors`, `narrators`, `genres` — not `playlists`. `playlists.name` and
   `.description` carry the *default* collation, so a plain `like` would technically run, and that is
   the trap: it would be the one search in the app that is case-insensitive but accent-**sensitive**,
   with no trigram index behind it. **This feature therefore needs one migration**: `name_fold` and
   `description_fold` on `playlists`, backfilled, with `HasFoldedName` generalised to fold a second
   column (`description` is `text`, and it is nullable, so the fold has to allow null).
2. **They are user-scoped** — the only kind where the reader's identity changes the result set. That
   is what makes the response uncacheable across users, and it means the E2E fixture needs a playlist
   per account rather than one shared row.

There is deliberately **no "see all" for playlists**: the playlists listing is a hand-ordered list
(`li.playlist`), not a DataTable, so it has no `?search=` to link to. The group shows its five and
says nothing more — and if a household ever holds enough playlists for that to bite, the listing
growing a search is the fix, not the dropdown growing a page.

## The Music page: "Deine Musik"

`StatsWidget` — the wide widget spanning two grid columns — becomes the page's search hub: the
heading changes from _Statistik_ to **_Deine Musik_**, the search input sits at the top of its body,
and the stat tiles stay below it. The tiles are the right neighbours for it: they describe what there
is to search, and a reader who came to browse still gets them first.

While a query is active the results take the place of the tiles rather than pushing them down — the
widget is a fixed thing in a grid, and a block that grows by 300px shoves the four browse widgets off
the fold. Clearing the field puts the tiles back.

## The header

The trigger belongs in `HeaderNavigation`, beside the site menu, the user menu and the queue toggle —
one more icon button in a row that is already the app's "everything else" cluster. The `search` glyph
is already in the sprite; no new icon.

- **Click the icon → an overlay opens under the header** with the field focused. Not an input that
  lives permanently in the header: at phone width the header is already logo + title + three
  buttons, and a field wedged in there is what makes a header wrap.
- **`/` focuses it and `⌘K` opens it**, both verified free: `usePlayerShortcuts`' keymap holds space,
  the arrows, `k j l n p m s r q` and nothing else, and it **already stands down inside text entry
  and for any modifier combo** — so typing "space" or "q" in the search field cannot pause playback
  or open the queue panel. That check is written down here because the failure would be silent and
  bizarre: a reader typing a title while their music seeks around under them.
- **Escape closes it; ↑/↓ walk the rows; Enter opens the focused row.** The rows are real links, so
  the keyboard is a convenience rather than the only way in.
- **Never in the guest space.** `ShareLayout` mounts no search — a share grants one subject, and a
  library search on that page would be an invitation to a login form.

## Tests, and which layer answers what

The usual three ([`testing.md`](testing.md)):

- **PHPUnit** owns the engine, because nearly all of it is a server decision: that a song matches its
  own title and *not* its artist's name (the feature's central rule, and the one a later "improvement"
  is most likely to break), that the four tiers order as specified, that the tie-break is total, that
  a `type`-narrowed kind never returns an audiobook, that playlists are scoped to the caller and a
  stranger's playlist never appears, that `kinds=` narrows without changing order, and that the
  totals are the real counts rather than the length of the truncated list.
- **Vitest** owns the client's own behaviour: the debounce, the three-character floor, that a
  superseded response is discarded (mock two overlapping fetches and assert the older one paints
  nothing), the chips narrowing the request, the empty state, and the keyboard walk.
- **Playwright** owns the journeys and the one thing neither other layer can see: that typing in the
  overlay does not drive the player. Also `/` and `⌘K`, opening a result, and the hand-off landing on
  a listing whose own search is filled in.

## Known edges, and what is deliberately absent

- **No advanced-search page with filters** (the owner's option (d)), because the listings already are
  one: search, sort, paginate, per-column, all in the URL. What (d) would have added over "see all in
  Songs" is cross-kind results in a full page, which is the overlay's job.
- **No search history and no suggestions.** Both need storage per reader, and a household instance
  where three people share a library is exactly where a "recent searches" list is a small privacy
  surprise.
- **No searching inside file paths or tags** beyond the names — the DataTable listings expose those
  columns, and a path fragment is not what somebody types into a header field.
- **No results for what a reader cannot reach.** Genres and albums are everybody's, playlists are
  their own; there is no admin scope and no cross-user visibility to get wrong.
- **The `meta` line costs a query per kind** (an album count, a track count). Measured on the box
  before it ships: if it is material, it is the first thing to drop — a row can live without its
  second line, and cannot live with a slow field.
