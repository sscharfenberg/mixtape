<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Enums\ShareSubject;
use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Services\Music\DominantGenre;
use App\Services\Shares\ShareArtwork;
use App\Services\Shares\ShareGrant;
use Illuminate\Database\Query\Builder;
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
    /** Injected so "which picture stands for this share" stays the one class that owns it. */
    public function __construct(private readonly ShareArtwork $artwork) {}

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

        // A share this app has no subject case for. Every column the table permits has one, so
        // this is reachable only through a row with no subject at all — which the table's CHECK
        // forbids on Postgres. There is no
        // page to build for it, so it is a 404 rather than an empty one: the link names
        // something this instance does not serve.
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
            'subject' => $this->subject($share, $subject, $grant),
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
    private function subject(Share $share, ShareSubject $subject, ShareGrant $grant): array
    {
        $totals = $grant->totals();

        return [
            ...$this->identity($share, $subject),
            'songs' => $totals['songs'],
            'duration' => $totals['duration'],

            // Both halves of the hero's artwork are ShareArtwork's, and the expiry check is
            // inside them: a dead link gets no picture, because both cover routes refuse one
            // and a broken image is a poor way to learn that. An ARTIST gets no single cover
            // at all — MixTape stores no artist images — and fans a few of their own sleeves
            // instead, exactly as the artist page does.
            'coverUrl' => $this->artwork->hero($share),
            'sleeves' => $this->artwork->sleeves($share),
        ];
    }

    /**
     * What the subject IS: its name, the two credits that place it, and what kind of music
     * it is.
     *
     * Read off the share's own relations rather than re-queried, so which row is described
     * can never disagree with which row was granted.
     *
     * @return array{name: string, artist: string|null, album: string|null, year: int|null, genre: string|null}
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
                // A TRACK carries its genre itself, so there is nothing to derive here.
                'genre' => $share->track->genre?->name,
            ],
            ShareSubject::Album => [
                'name' => $share->collection->name,
                // The ALBUM artist here, off the collection: it is the record that was
                // shared, so the credit that identifies it is the record's.
                'artist' => $share->collection->albumArtist?->name,
                'album' => null,
                'year' => $share->collection->year,
                'genre' => $this->dominantGenre(DominantGenre::albumWinners($share->collection_id)),
            ],
            /*
             * AN AUDIOBOOK IS NOT AN ALBUM WEARING A DIFFERENT WORD, which is why it gets an
             * arm of its own rather than joining the one above. Its credit is its AUTHORS —
             * plural, read through the chapters, since an anthology names six — where an
             * album's is one album-artist off the collection. And it has no genre at all: the
             * tracks CHECK forbids an audiobook one, so asking DominantGenre would be asking
             * a question the schema has already answered with null.
             */
            ShareSubject::Audiobook => [
                'name' => $share->collection->name,
                // Joined here rather than sent as a list, because this shape is four nullable
                // strings the guest page prints as they are; a list would be a fifth shape for
                // one kind. Names separated by commas read the same in both catalogues.
                'artist' => $share->collection->authors()->orderBy('authors.name')
                    ->pluck('authors.name')->implode(', ') ?: null,
                'album' => null,
                'year' => $share->collection->year,
                'genre' => null,
            ],
            ShareSubject::Artist => [
                'name' => $share->artist->name,
                'artist' => null,
                'album' => null,
                'year' => null,
                'genre' => $this->dominantGenre(DominantGenre::winners($share->artist_id)),
            ],
            // A PLAYLIST HAS NONE OF THE FOUR, and each absence is a fact rather than a gap:
            // it is a list of other people's records, so no single artist, album or year
            // describes it — and no genre either, since DominantGenre ranks by ARTIST and by
            // ALBUM, and "mostly Doom" is not a thing a hand-made mix is. What it has instead
            // is a track count and a playing time, which {@see subject()} adds for every kind.
            ShareSubject::Playlist => [
                'name' => $share->playlist->name,
                'artist' => null,
                'album' => null,
                'year' => null,
                'genre' => null,
            ],
        };
    }

    /**
     * The main genre of an album or an artist, by name.
     *
     * ASKED OF THE SAME SERVICE THE LIBRARY'S OWN PAGES ASK, which is the whole reason this
     * is one line rather than a query: genre is tagged per TRACK in this app, so neither an
     * album nor an artist has one of its own, and "mostly this" is a derived fact with a
     * tie-break rule (DominantGenre). A guest arriving at a share must not be told a
     * different genre than the album page shows its owner.
     *
     * It takes the QUERY rather than an id, because the two kinds are ranked over different
     * owner columns and each caller above already knows which of DominantGenre's two entry
     * points is theirs — narrowed to one subject, so this is one row or none.
     *
     * Null where nothing is tagged, which drops the tile rather than printing an empty chip.
     */
    private function dominantGenre(Builder $winners): ?string
    {
        return $winners->first()?->genre_name;
    }
}
