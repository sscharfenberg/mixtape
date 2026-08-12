<?php

declare(strict_types=1);

namespace App\Services\Shares;

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
 * A SHARE WITH NO SUBJECT THIS APP CAN RESOLVE ANSWERS "NOTHING", rather than throwing.
 * `shares.playlist_id` exists in the schema with no mint path (the owner deferred playlist
 * sharing), so a row naming one can only have been written by hand — and the honest answer
 * to a link the app cannot serve is a 404 from the caller, not a 500 from here. Hence
 * {@see subject()} is nullable and the two readers below degrade to "no tracks, contains
 * nothing"; SharePageController turns that into the 404.
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
     * Null for a share this app has no subject case for, which today means exactly one
     * thing: a hand-written `playlist_id` row. See the class note for why that is a null
     * rather than an exception.
     */
    public function subject(): ?ShareSubject
    {
        return match (true) {
            $this->share->track_id !== null => ShareSubject::Song,
            $this->share->collection_id !== null => ShareSubject::Album,
            $this->share->artist_id !== null => ShareSubject::Artist,
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
     */
    public function query(): Builder
    {
        $subject = $this->subject();

        if ($subject === null) {
            return QueuePayload::query()->whereRaw('1 = 0');
        }

        $type = $this->trackType();

        return QueuePayload::query()
            ->where($subject->grant()->column(), $this->subjectId())
            ->when($type !== null, fn (Builder $query) => $query->where('tracks.type', $type->value));
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
     * THE OTHER TWO ARE UNFILTERED, which is not an inconsistency but the same rule read
     * the other way: a song share names ONE row and grants that row whatever kind it is, and
     * an album share grants its collection's tracks, where `collections` already
     * discriminates album from audiobook. Both are therefore as narrow as their subject
     * already, and a type filter on them is precisely what would break when audiobook shares
     * switch on (docs/sharing.md → Known edges).
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
            ShareSubject::Album => $this->share->collection->name,
            ShareSubject::Artist => $this->share->artist->name,
            default => null,
        };
    }

    /**
     * One track of this grant that carries artwork, from the artist's most recent record.
     *
     * IT EXISTS FOR THE SOCIAL CARD, which needs a single picture where the page fans three
     * (ShareArtwork). An artist has no image of their own in this app, so the card borrows a
     * sleeve — and "the latest" is the owner's rule: a band's newest record is the one a
     * recipient is most likely to recognise, and it is stable, where the page's fan is
     * deliberately re-shuffled on every visit. A preview that changed each time it was pasted
     * would look like a bug in whatever chat window is showing it.
     *
     * DRAWN FROM THE GRANT, like the fan, which is the artist trap in miniature: a sleeve off
     * an album this link cannot play would be a picture of something the page has no rows for.
     *
     * Undated records sort last rather than first — `collections.year` is null for plenty of
     * rips, and "no year" is not "the newest". Null when nothing granted carries a cover at all.
     */
    public function latestCoveredTrackId(): ?string
    {
        $row = $this->query()
            ->where('tracks.cover', true)
            ->leftJoin('collections', 'collections.id', '=', 'tracks.collection_id')
            ->orderByRaw('coalesce(collections.year, 0) desc')
            ->orderBy('collections.name')
            ->select(['tracks.id'])
            ->first();

        return $row?->id;
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
        ], QueuePayload::fromQuery($this->query(), only: null));
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
