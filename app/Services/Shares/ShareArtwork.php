<?php

declare(strict_types=1);

namespace App\Services\Shares;

use App\Enums\ShareSubject;
use App\Models\Share;
use App\Services\Media\CoverService;
use App\Services\Music\FannedCovers;

/**
 * WHICH PICTURE STANDS FOR A SHARE — the one owner of that question, for both readers that
 * ask it (docs/sharing.md).
 *
 * IT EXISTS BECAUSE THERE ARE NOW TWO, and they want different answers to the same question.
 * The guest PAGE draws a hero: one cover for a song or an album, and for an artist a fan of up
 * to three sleeves, re-shuffled every visit, because MixTape stores no artist images. The
 * SOCIAL CARD (App\Services\Meta\SocialCards) needs exactly one image, and needs it to be the
 * same one every time — a preview that changed on each paste looks like a fault in whatever
 * chat window is showing it. Left in the controller, the shared half of that — "does this
 * subject have artwork at all", and which route serves it — would have been copied.
 *
 * EVERY URL IT HANDS BACK IS IN THE `/s/` SPACE, and that is not a style choice: the card's
 * image is fetched by a stranger's server with no session, and `/music/...` covers sit behind
 * `auth`. A card pointing there unfurls as a broken image at best.
 *
 * A DEAD LINK HAS NO PICTURE. Both cover routes refuse an expired share (ShareCoverRequest),
 * so anything offered for one would 404 on fetch — and a broken image is a poor way to learn
 * that a link has expired, where the page says so in words.
 */
final class ShareArtwork
{
    /** Injected so the "which image, in which order" policy stays CoverService's, not this file's. */
    public function __construct(private readonly CoverService $covers) {}

    /**
     * The page hero's image, or null when there is none to point an `<img>` at.
     *
     * Null for an ARTIST by design — they get {@see sleeves} instead — and null for a subject
     * whose files carry no artwork, which is what lets the page draw its placeholder rather
     * than a broken image.
     *
     * The two kinds are asked DIFFERENTLY on purpose, and getting it backwards is a real bug:
     * an album prefers the directory's folder image while a song prefers its own embedded
     * picture, because rips exist where every file carries a different inline cover — and
     * there, "the embedded cover" makes a record's artwork depend on which track sorts first.
     */
    public function hero(Share $share): ?string
    {
        if (! $share->isLive()) {
            return null;
        }

        $exists = match (ShareGrant::for($share)->subject()) {
            ShareSubject::Song => $this->covers->exists($share->track),
            ShareSubject::Album => $this->covers->existsForAlbum($share->collection),
            default => false,
        };

        return $exists ? route('shares.cover', $share, absolute: false) : null;
    }

    /**
     * Up to three of an artist's own covers for the hero's fan; empty for every other kind.
     *
     * Keyed by album so the fan is three different records, falling back to the track's own id
     * for a loose file belonging to none — the same key rule the playlist hero uses. Drawn from
     * THE GRANT rather than the artist's discography: the artist page fans covers off
     * `collections.album_artist_id`, which is not the set `tracks.artist_id` grants, and a
     * sleeve from an album this link cannot play would be a picture of something the page has
     * no rows for.
     *
     * @return array<int, string>
     */
    public function sleeves(Share $share): array
    {
        $grant = ShareGrant::for($share);

        if (! $share->isLive() || $grant->subject() !== ShareSubject::Artist) {
            return [];
        }

        $rows = $grant->query()
            ->where('tracks.cover', true)
            ->select(['tracks.id', 'tracks.collection_id'])
            ->get();

        return FannedCovers::pick($rows->map(fn (object $row): array => [
            $row->collection_id ?? $row->id,
            route('shares.tracks.cover', [$share, $row->id], absolute: false),
        ]));
    }

    /**
     * THE ONE picture that represents this link in a social preview, absolute, or null.
     *
     * ABSOLUTE, unlike the two above, and that is the whole difference in shape: those go into
     * an `<img src>` on a page that resolves them against its own document, while this goes
     * into an `og:image` read by a crawler that has only the string.
     *
     * A song or an album is its own cover. An ARTIST borrows the sleeve of their most recent
     * granted record ({@see ShareGrant::latestCoveredTrackId}) — stable, where the page's fan
     * is deliberately random, because this string is what a chat window caches against the URL.
     */
    public function preview(Share $share): ?string
    {
        $track = ShareGrant::for($share)->subject() === ShareSubject::Artist
            ? ShareGrant::for($share)->latestCoveredTrackId()
            : null;

        if ($track !== null) {
            return $share->isLive() ? route('shares.tracks.cover', [$share, $track]) : null;
        }

        // A relative hero means there IS one; the card needs the same picture at an absolute URL.
        return $this->hero($share) === null ? null : route('shares.cover', $share);
    }
}
