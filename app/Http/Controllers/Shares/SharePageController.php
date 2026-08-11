<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Enums\ShareSubject;
use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Services\Media\CoverService;
use App\Services\Music\FannedCovers;
use App\Services\Shares\ShareGrant;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * WHAT SOMEBODY WITH A LINK AND NO ACCOUNT SEES (`GET /s/{share}`, route `shares.show`) —
 * the whole point of the sharing feature (docs/sharing.md).
 *
 * THE ONLY UNAUTHENTICATED PAGE IN THE APP THAT SHOWS THE LIBRARY, which is why it lives in
 * a URL space of its own rather than behind a widened gate on `/music`. Containment is
 * structural: every media URL this page hands out is built from the share's grant, so it
 * cannot NAME a track the link does not cover — and `routes/web.php` goes on saying that
 * everything under `/music` sits behind `auth`, which stays true by reading it.
 *
 * IT IS NOT THE DETAIL PAGE WITH THINGS HIDDEN. A song page carries the site menu, the
 * ActionPanel, add-to-playlist, play counts and a download button, all of which are either
 * meaningless without an account or actively wrong. What a guest gets instead is: what this
 * is, how long the link lives, and a way to play it.
 *
 * AN EXPIRED SHARE IS STILL RENDERED, kindly — it says the link has expired and to ask
 * whoever sent it for a new one, and it names the thing so that asking is possible. That
 * discloses that a share once existed, which is not a leak: the only person who can reach
 * this URL is someone who was given it. A REVOKED share is a different answer, and is not
 * this controller's to give — revoking deletes the row, so binding 404s before this runs,
 * indistinguishable from a typo. Both behaviours follow from revoke being a DELETE.
 *
 * WHAT IT DELIBERATELY DOES NOT SEND: who minted it. That reads as a friendly touch —
 * "Anna sent you this" — and it would publish a login identifier on a page reachable by
 * anyone holding a forwarded link, since this app logs in by `users.name`. Half a credential
 * pair is too much to pay for a nicety, and the recipient already knows who sent it.
 */
class SharePageController extends Controller
{
    /** Injected so the "has this any artwork at all?" policy stays the one CoverService owns. */
    public function __construct(private readonly CoverService $covers) {}

    /**
     * Render one share. `{share}` resolves through implicit binding on the UUID, so a
     * revoked or mistyped link is a 404 before this runs.
     *
     * NO FormRequest, and that is a decision rather than an omission of the repo's rule
     * (CLAUDE.md → FORM REQUESTS): this endpoint validates no input and guards no subject.
     * Expiry — the one thing that could be mistaken for a permission here — is CONTENT on
     * this route: the page's job when a link has died is to say so. The routes that answer
     * with bytes do treat it as a permission, and they each have a request class.
     */
    public function __invoke(Share $share): Response
    {
        $grant = ShareGrant::for($share);
        $subject = $grant->subject();

        // A share this app has no subject case for — today, only a hand-written
        // `playlist_id` row, since playlist sharing is deferred and `ShareSubject` has no
        // case to mint one with. There is no page to build, so it is a 404 rather than an
        // empty one: the link names something this instance does not serve.
        abort_if($subject === null, HttpResponse::HTTP_NOT_FOUND);

        $live = $share->isLive();

        return Inertia::render('Share/SharePage', [
            'share' => [
                'kind' => $subject->value,
                // Raw, like every instant this app sends: the page formats it in the
                // reader's own locale and timezone, and a guest's locale is resolved from
                // their browser (ConfigureLocale needs no user).
                'validUntil' => $share->valid_until->toIso8601String(),
                'expired' => ! $live,
            ],
            'subject' => $this->subject($share, $subject, $grant, $live),
            // Nothing to play once the week is up, and the emptiness is what the page draws
            // its "this link has expired" state from having no rows for. Also an economy
            // that matters: an artist share can be a few hundred entries.
            'tracks' => $live ? $grant->tracks() : [],
        ]);
    }

    /**
     * The facts the hero prints, per kind of subject.
     *
     * THE THREE KINDS ARE DESCRIBED BY ONE SHAPE, with nulls where a kind has no such fact,
     * so the page renders one hero rather than three. That is the same trade the Facts list
     * on the song page makes: a flat description with holes in it reads better than a pile
     * of conditionals, and the component drops the empty tiles.
     *
     * `songs` and `duration` are the grant's own totals rather than the subject's, which is
     * the rule the whole space follows — an artist's page counts their music, and so does
     * their share, because both ask ShareGrant. For an album share the two happen to
     * coincide; for an artist share they must, or the hero would promise more than the link
     * plays.
     *
     * @return array<string, mixed>
     */
    private function subject(Share $share, ShareSubject $subject, ShareGrant $grant, bool $live): array
    {
        $totals = $grant->totals();

        return [
            ...$this->identity($share, $subject),
            'songs' => $totals['songs'],
            'duration' => $totals['duration'],

            // The hero's <img>, decided here so the page can draw its placeholder rather
            // than point an <img> at a 404 — the same call every detail page makes. Null
            // for an expired share as well as an artless one, because the cover route
            // refuses a dead link (ShareCoverRequest) and a broken image is a poor way to
            // learn that.
            'coverUrl' => $live && $this->hasCover($share, $subject)
                ? route('shares.cover', $share, absolute: false)
                : null,

            // An artist has no artwork in this app — MixTape stores no artist images — so
            // their hero fans a few of their own sleeves instead, exactly as the artist page
            // does. Empty for the other two kinds, which have a cover of their own.
            'sleeves' => $live && $subject === ShareSubject::Artist ? $this->sleeves($share, $grant) : [],
        ];
    }

    /**
     * What the subject IS: its name, and the two credits that place it.
     *
     * Read off the share's own relations rather than re-queried, so which row is described
     * can never disagree with which row was granted.
     *
     * @return array{name: string, artist: string|null, album: string|null, year: int|null}
     */
    private function identity(Share $share, ShareSubject $subject): array
    {
        return match ($subject) {
            ShareSubject::Song => [
                'name' => $share->track->name,
                // The performing artist, which on a compilation is not who the record is
                // credited to — the song page draws the same distinction.
                'artist' => $share->track->artist?->name,
                'album' => $share->track->collection?->name,
                'year' => $share->track->collection?->year,
            ],
            ShareSubject::Album => [
                'name' => $share->collection->name,
                // The ALBUM artist here, off the collection: it is the record that was
                // shared, so the credit that identifies it is the record's.
                'artist' => $share->collection->albumArtist?->name,
                'album' => null,
                'year' => $share->collection->year,
            ],
            ShareSubject::Artist => [
                'name' => $share->artist->name,
                'artist' => null,
                'album' => null,
                'year' => null,
            ],
        };
    }

    /**
     * Whether this subject can show artwork at all — asked of CoverService so the answer is
     * the one the app gives everywhere else, including its inversion: an ALBUM prefers the
     * directory's folder image over any embedded picture, a SONG prefers its own. Getting
     * that backwards would make a compilation's hero depend on which track sorts first.
     */
    private function hasCover(Share $share, ShareSubject $subject): bool
    {
        return match ($subject) {
            ShareSubject::Song => $this->covers->exists($share->track),
            ShareSubject::Album => $this->covers->existsForAlbum($share->collection),
            ShareSubject::Artist => false,
        };
    }

    /**
     * Up to three of the artist's covers for the hero's fanned sleeves, drawn from THE
     * GRANT rather than from their discography.
     *
     * That difference is deliberate and is the artist trap in miniature: the artist page
     * fans covers off `collections.album_artist_id`, which is not the set
     * `tracks.artist_id` grants. A sleeve from an album this link cannot play would be a
     * picture of something the page then has no rows for.
     *
     * Keyed by album so the fan is three different records, falling back to the track's own
     * id for a loose file belonging to none — the same key rule the playlist hero uses. The
     * URLs are per-track cover routes inside this share's space, which is what the sleeves
     * on a playlist are too.
     *
     * @return array<int, string>
     */
    private function sleeves(Share $share, ShareGrant $grant): array
    {
        $rows = $grant->query()
            ->where('tracks.cover', true)
            ->select(['tracks.id', 'tracks.collection_id'])
            ->get();

        return FannedCovers::pick($rows->map(fn (object $row): array => [
            $row->collection_id ?? $row->id,
            route('shares.tracks.cover', [$share, $row->id], absolute: false),
        ]));
    }
}
