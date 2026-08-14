<?php

declare(strict_types=1);

namespace App\Services\Shares;

use App\Enums\CollectionType;
use App\Enums\ShareSubject;
use App\Enums\TrackType;
use App\Models\Share;
use App\Services\Music\QueuePayload;
use Illuminate\Database\Query\Builder;

/**
 * WHAT ONE SHARE LINK MAY PLAY — the single definition of a share's grant, used by the
 * guest page and by both media routes (docs/sharing.md → "The grant").
 *
 * ONE QUERY, TWO USES, AND THAT IS THE WHOLE POINT. The page a guest sees is drawn from
 * {@see tracks()} and the stream route admits a track through {@see contains()}, and both
 * resolve through {@see query()}. Written twice they would drift, and the drift has a
 * shape: a guest gets an album tile or a queue row for a track the stream then refuses,
 * which appears as a player that silently stops on one song out of ninety.
 *
 * IT DOES NOT INVENT THE MAPPING. `ShareSubject::grant()` hands back the `PlaylistSubject`
 * whose `column()` every detail controller already narrows its `queueTracks` prop by, so a
 * share plays exactly the tracks "play this" plays. The artist case is why that matters:
 * `tracks.artist_id` is NOT `collections.album_artist_id`, so a mapping restated here
 * would eventually grant a set the artist page never showed (docs/sharing.md → "the artist
 * trap").
 *
 * A PLAYLIST IS THE ONE SUBJECT THAT MAPPING CANNOT ANSWER, and everything
 * special about playlist sharing follows from it: a playlist's tracks are rows of
 * `playlist_tracks`, in the reader's own `position` order, so `grant()` is null and
 * {@see query()} joins the pivot instead. Two consequences worth knowing before touching
 * anything here. Its ORDER has to be the pivot's, so {@see tracks()} takes a branch of its
 * own — `QueuePayload::fromQuery` would impose album-then-disc-then-track and silently
 * rewrite a hand-made list. And an entry can repeat, deliberately (the same song twice in
 * one playlist is a thing people do), so the join can hand back a track more than once —
 * which is what the playlist's own page already shows its owner.
 *
 * IT IS RESOLVED FRESH ON EVERY REQUEST, which is the whole of "a shared playlist stays up
 * to date", which is a requirement rather than an accident. Nothing is copied at mint time: the row
 * holds a `playlist_id` and this class asks the pivot each time, so an owner adding, moving
 * or removing an entry changes what the link plays on the guest's next reload. No live
 * push, and none wanted — a reload is the contract.
 *
 * A SHARE WITH NO SUBJECT THIS APP CAN RESOLVE ANSWERS "NOTHING", rather than throwing.
 * Every column the table permits now has a case, so this is reachable only through a row
 * the table's CHECK would reject (all four FKs null) — the test connection is sqlite, which
 * has no CHECK, and the honest answer to a link the app cannot serve is a 404 from the
 * caller rather than a 500 from here. Hence {@see subject()} is nullable and the readers
 * below degrade to "no tracks, contains nothing"; SharePageController turns that into the
 * 404.
 */
final class ShareGrant
{
    /** Injected as the row itself, because everything here is a question about that row. */
    public function __construct(private readonly Share $share) {}

    /** Read as a sentence at the call sites: `ShareGrant::for($share)->tracks()`. */
    public static function for(Share $share): self
    {
        return new self($share);
    }

    /**
     * Which kind of thing this link is about, read back off whichever of the four subject
     * FKs the row has set — the reverse of the mapping the mint route wrote it with
     * (`ShareSubject::foreignKey()`).
     *
     * Null only for a row with none of the four set, which the table's CHECK forbids — see
     * the class note for why that is a null rather than an exception.
     */
    public function subject(): ?ShareSubject
    {
        return match (true) {
            $this->share->track_id !== null => ShareSubject::Song,
            // THE ONE FK THAT DOES NOT NAME ITS KIND. `collections` holds albums and
            // audiobooks, and both store their id in this column — by design, since a share of
            // either grants the same thing (that collection's tracks). So the kind has to be
            // read off the ROW, and it decides the words the guest page uses about it, not
            // what it grants.
            $this->share->collection_id !== null => $this->share->collection?->type === CollectionType::Audiobook
                ? ShareSubject::Audiobook
                : ShareSubject::Album,
            $this->share->artist_id !== null => ShareSubject::Artist,
            $this->share->playlist_id !== null => ShareSubject::Playlist,
            default => null,
        };
    }

    /** The id of the thing shared — the value in the FK {@see subject()} identified. */
    public function subjectId(): ?string
    {
        $subject = $this->subject();

        return $subject === null ? null : $this->share->{$subject->foreignKey()};
    }

    /**
     * A query over `tracks` narrowed to everything this link grants, and nothing else.
     *
     * EVERY NARROWING LIVES HERE, the track-type filter included, so that a caller reaching
     * for the grant cannot get a wider set by forgetting a second call — which is the same
     * argument as "one query, two uses" above, applied one level down. The page's cover fan
     * is the caller that made it worth stating: it adds columns of its own, and it must not
     * thereby be picking artwork off tracks the link does not grant.
     *
     * Fresh on every call, because callers do add to it and `QueuePayload::selectFrom` calls
     * `select()` rather than `addSelect()`.
     *
     * A grant with no resolvable subject narrows to `whereRaw('1 = 0')` rather than to
     * everything — the failure mode of getting that backwards is handing a stranger the
     * whole library, so it is spelled out rather than left to a caller's guard.
     *
     * A PLAYLIST IS A JOIN RATHER THAN A `where`, and it is the only subject that is: its
     * tracks are pivot rows, so the narrowing is on `playlist_tracks.playlist_id`. The join
     * also means a track can come back TWICE, which is correct — an entry may repeat, and the
     * playlist's own page shows it repeated too.
     */
    public function query(): Builder
    {
        $subject = $this->subject();

        if ($subject === null) {
            return QueuePayload::query()->whereRaw('1 = 0');
        }

        $type = $this->trackType();

        return $this->narrow($subject)
            ->when($type !== null, fn (Builder $query) => $query->where('tracks.type', $type->value));
    }

    /**
     * The subject's own narrowing, before any type filter — a column comparison for the three
     * library kinds, a pivot join for a playlist.
     *
     * Split out of {@see query()} so that "which rows" and "which kinds of track" stay two
     * separate decisions: the type filter is applied to whatever comes back from here, and a
     * caller reading `query()` can see both halves without either being buried in a `match`.
     */
    private function narrow(ShareSubject $subject): Builder
    {
        $grant = $subject->grant();

        if ($grant !== null) {
            return QueuePayload::query()->where($grant->column(), $this->subjectId());
        }

        // The pivot side is selected as well as joined, so the callers that need the reader's
        // own order (tracks(), and the card's sleeve pick) can sort on `position` — the join
        // is the only place that column is reachable from.
        return QueuePayload::query()
            ->join('playlist_tracks', 'playlist_tracks.track_id', '=', 'tracks.id')
            ->where('playlist_tracks.playlist_id', $this->subjectId());
    }

    /**
     * Which kinds of track the subject's own page would have played — the second half of
     * "the grant is what play means", and per subject rather than app-wide.
     *
     * ARTIST IS MUSIC-ONLY, matching QueuePayload's default and so the artist page's own
     * `queueTracks`. Today the `tracks` CHECK makes that redundant — its audiobook arm
     * requires `artist_id IS NULL`, so nothing but music can carry one — and it is written
     * anyway, because "an artist share plays what the artist page plays" is the rule, and a
     * rule that holds only because of a constraint in a different table is one a later
     * migration can quietly repeal.
     *
     * THE OTHER THREE ARE UNFILTERED, which is not an inconsistency but the same rule read
     * the other way: a song share names ONE row and grants that row whatever kind it is, and
     * an album share grants its collection's tracks, where `collections` already
     * discriminates album from audiobook. Both are therefore as narrow as their subject
     * already, and a type filter on them is precisely what would break when audiobook shares
     * switch on (docs/sharing.md → Known edges).
     *
     * A PLAYLIST IS UNFILTERED FOR A THIRD REASON, and it is the strongest of them: a reader
     * may deliberately mix music with audiobook chapters in one — that is what the unified
     * `tracks` table is for — so filtering here would silently drop entries they put in
     * themselves. PlaylistController says the same thing about its own page, and it has to be
     * the same answer: a shared playlist plays what the playlist plays.
     */
    private function trackType(): ?TrackType
    {
        return match ($this->subject()) {
            ShareSubject::Artist => TrackType::Music,
            default => null,
        };
    }

    /**
     * Whether this link may play that track — the question both media routes ask, and the
     * only permission in the `/s/` space.
     *
     * An EXISTS against the grant rather than a comparison of ids, because three of the
     * four subject kinds name a set rather than a row. Cheap: every column it can narrow
     * on is a foreign key with an index behind it.
     */
    public function contains(string $trackId): bool
    {
        return $this->query()->where('tracks.id', $trackId)->exists();
    }

    /**
     * What the link is called — the name of the row {@see subject} identified.
     *
     * Here rather than in the page controller because two readers now want it and they must
     * agree: the hero prints it, and the social card puts it in a title that appears in a chat
     * window before anybody has opened the link. Read off the share's own relations, so which
     * row is described can never disagree with which row was granted.
     *
     * Null for a subject this app cannot resolve, which the callers already handle: the page
     * 404s on it before rendering.
     */
    public function subjectName(): ?string
    {
        return match ($this->subject()) {
            ShareSubject::Song => $this->share->track->name,
            ShareSubject::Album, ShareSubject::Audiobook => $this->share->collection->name,
            ShareSubject::Artist => $this->share->artist->name,
            ShareSubject::Playlist => $this->share->playlist->name,
            default => null,
        };
    }

    /**
     * One track of this grant that carries artwork — the sleeve a social preview borrows.
     *
     * IT EXISTS FOR THE SOCIAL CARD, which needs a single picture where the page fans three
     * (ShareArtwork). The two kinds that have no one picture of their own borrow one here, and
     * WHICH one is a different question for each:
     *
     *   - AN ARTIST lends their most recent granted record, because a band's newest
     *     record is the one a recipient is most likely to recognise. Undated records sort last
     *     rather than first — `collections.year` is null for plenty of rips, and "no year" is
     *     not "the newest".
     *   - A PLAYLIST lends its FIRST ENTRY, because a playlist has an order and
     *     its opening track is the one thing about it a maker actually chose. Sorting it by
     *     year would pick a record out of the middle of somebody's sequence.
     *
     * EITHER WAY IT IS STABLE, which is the requirement the whole method exists to meet: the
     * page's fan is deliberately re-shuffled on every visit, while this string is what a chat
     * window caches against the URL — a preview that changed on each paste looks like a fault
     * in whatever is showing it.
     *
     * DRAWN FROM THE GRANT, like the fan, which is the artist trap in miniature: a sleeve off
     * an album this link cannot play would be a picture of something the page has no rows for.
     *
     * Null when nothing granted carries a cover at all.
     */
    public function cardTrackId(): ?string
    {
        $query = $this->query()
            ->where('tracks.cover', true)
            ->select(['tracks.id']);

        if ($this->subject() === ShareSubject::Playlist) {
            // `position` then the pivot's own id, the tie-break the playlist page uses too:
            // position is deliberately non-unique, so two entries sharing one would otherwise
            // be free to swap places between two crawls of the same link.
            return $query->orderBy('playlist_tracks.position')->orderBy('playlist_tracks.id')->first()?->id;
        }

        return $query
            ->leftJoin('collections', 'collections.id', '=', 'tracks.collection_id')
            ->orderByRaw('coalesce(collections.year, 0) desc')
            ->orderBy('collections.name')
            ->first()
            ?->id;
    }

    /**
     * The granted tracks as play-queue entries, with every URL pointing back into this
     * share's own space.
     *
     * THE PLAYER NEEDS NO CHANGE FOR ANY OF THIS. `QueueTrack` already carries `streamUrl`,
     * `coverUrl` and `href` per track — the seam was cut for exactly this, before the
     * feature was designed — so a guest's queue is an ordinary queue whose entries happen
     * to name `/s/…` routes.
     *
     * All three are overridden rather than defaulted, and `href` is the one that would be
     * missed: it resolves to `/music/songs/{id}`, which for a guest is a bounce to the
     * login form. It points back at the share page instead, so a queue row is still a real
     * link to somewhere the reader may go.
     *
     * `coverUrl` stays null where QueuePayload put a null — that is the `tracks.cover`
     * column saying the file carries no picture, and pointing an <img> at a route that
     * would 404 is worse than the placeholder the panel draws.
     *
     * @return list<array<string, mixed>> entries in the shape `QueueTrack` expects
     */
    public function tracks(): array
    {
        $page = route('shares.show', $this->share, absolute: false);

        return array_map(fn (array $entry): array => [
            ...$entry,
            'href' => $page,
            'streamUrl' => route('shares.tracks.stream', [$this->share, $entry['id']], absolute: false),
            'coverUrl' => $entry['coverUrl'] === null
                ? null
                : route('shares.tracks.cover', [$this->share, $entry['id']], absolute: false),
        ], $this->entries());
    }

    /**
     * The granted tracks as queue entries, in the order the link should play them.
     *
     * TWO ORDERS, AND THE SPLIT IS THE POINT. Three of the four subjects want playing order —
     * album, then disc, then track — which is what a listener pressing "play this artist"
     * expects and what `QueuePayload::fromQuery` imposes. A PLAYLIST wants the reader's own
     * sequence, so it goes the long way round `selectFrom`: `fromQuery` would sort a hand-made
     * list into record order and nothing would look broken, which is the worst kind of wrong —
     * the guest would simply hear somebody else's playlist in an order they never chose. The
     * playlist's own page makes exactly this split for exactly this reason (PlaylistController).
     *
     * `only: null` on both, because what a link may play is already decided by the grant: an
     * artist share is narrowed to music inside {@see query()}, and a playlist may deliberately
     * hold audiobook chapters that a type filter here would drop.
     *
     * @return list<array<string, mixed>> entries in the shape `QueueTrack` expects
     */
    private function entries(): array
    {
        if ($this->subject() !== ShareSubject::Playlist) {
            return QueuePayload::fromQuery($this->query(), only: null);
        }

        // `position` then the pivot's own id — position is deliberately non-unique, so without
        // the second key two entries sharing one could swap places between two loads.
        return QueuePayload::selectFrom($this->query(), only: null)
            ->orderBy('playlist_tracks.position')
            ->orderBy('playlist_tracks.id')
            ->get()
            ->map(fn (object $row): array => QueuePayload::entry($row))
            ->all();
    }

    /**
     * How many tracks the link grants and how long they play — the two numbers the guest
     * hero prints, as one aggregate rather than as a count over the payload.
     *
     * Duration is null when nothing granted carries one, which the page reads as a tile to
     * leave out; a sum of 0 would print "0 seconds" beside a real track count.
     *
     * @return array{songs: int, duration: float|null}
     */
    public function totals(): array
    {
        $row = $this->query()
            ->selectRaw('count(*) as songs, sum(tracks.duration) as duration')
            ->first();

        return [
            'songs' => (int) ($row->songs ?? 0),
            'duration' => $row?->duration === null ? null : (float) $row->duration,
        ];
    }
}
