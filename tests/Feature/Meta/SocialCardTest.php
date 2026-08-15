<?php

namespace Tests\Feature\Meta;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Invite;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Share;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * THE OPEN GRAPH CARD — what a link to this app looks like pasted into a chat window
 * (App\Services\Meta\SocialCards, docs/sharing.md → "What a pasted link looks like").
 *
 * PHPUNIT IS THE ONLY LAYER THAT CAN SEE THIS AT ALL, and that is not a preference: the tags
 * are `<meta>` elements in the HTML Laravel sends, put there precisely because the crawlers
 * that read them do not run JavaScript. Vitest mounts a Vue component and never sees the
 * document `<head>`; Playwright drives a browser that has already run the app, so it could
 * read them but would prove nothing the response body does not say more cheaply.
 *
 * WHAT IS WORTH PINNING is not the tag syntax but the three decisions behind it:
 *
 *   - THE THREE AREAS GET THREE DIFFERENT CARDS, and everything under `auth` collapses into
 *     the generic one — which is correct rather than unfinished, a crawler having no session.
 *   - AN EXPIRED LINK ADVERTISES NOTHING. No image (both cover routes refuse a dead share, so
 *     it would 404 on fetch) and a description that says so.
 *   - THE INVITE CARD NAMES NOBODY. Not the inviter, not the note, and its `og:url` does not
 *     carry the one-time code. Each of those is a small leak that would be easy to add for
 *     friendliness, so each is asserted rather than left to the docblock.
 */
class SocialCardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fetch a page as a crawler speaking `$language`.
     *
     * THE HEADER HAS TO BE STATED, and finding out why cost a while: Symfony's test client
     * sends `Accept-Language: en-us,en;q=0.5` of its OWN accord when a request names none, and
     * `ConfigureLocale` quite correctly honours it. So every response-level assertion about
     * copy in this suite is silently English unless it says otherwise — including one written
     * against the app's German default, which then fails looking like a translation bug.
     * Setting `config('app.locale')` does not help: the middleware's browser arm never reaches
     * the fallback.
     *
     * `de` here is not a fixture convenience — it is the app default, and therefore what the
     * card renders in for the crawlers that send no preference at all, which is most of them.
     */
    private function visit(string $url, string $language = 'de'): TestResponse
    {
        return $this->withHeaders(['Accept-Language' => $language])->get($url);
    }

    /**
     * An invite, and the plaintext code that opens it.
     *
     * There is no factory — the table stores only a sha256 of the code — so this mirrors
     * RegisterTest's helper rather than inventing a second shape.
     *
     * @return array{0: string, 1: Invite}
     */
    private function invite(string $note = 'Oma'): array
    {
        $code = Str::random(40);

        return [$code, Invite::create([
            'token' => Invite::hashCode($code),
            'note' => $note,
            'valid_until' => now()->addDays(7),
        ])];
    }

    /** Whether the response's `<head>` carries this tag with exactly this content. */
    private function assertTag(TestResponse $response, string $property, string $content): void
    {
        $attribute = str_starts_with($property, 'og:') ? 'property' : 'name';

        // `false` so the needle is not escaped a second time — the haystack is raw HTML, and
        // the content is compared as Blade wrote it.
        $response->assertSee('<meta '.$attribute.'="'.$property.'" content="'.e($content).'" />', false);
    }

    public function test_a_share_link_says_what_was_sent_and_how_long_it_runs(): void
    {
        $album = Collection::factory()->create(['name' => 'OK Computer']);
        Track::factory()->count(3)->create(['collection_id' => $album->id, 'duration' => 260]);
        $share = Share::factory()->ofAlbum($album)->create();

        $response = $this->visit("/s/{$share->id}");

        $this->assertTag($response, 'og:title', 'MixTape-Link: OK Computer');
        // Kind, count, runtime — 3 × 260s is 13 minutes, ROUNDED, because a preview is a
        // glance and nobody is deciding on the seconds.
        $this->assertTag($response, 'og:description', 'Album · 3 Titel · 13 Minuten');
        $this->assertTag($response, 'og:url', $share->url());
    }

    public function test_a_shared_album_offers_its_own_cover_as_the_preview_image(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->create(['collection_id' => $album->id, 'cover' => true]);
        $share = Share::factory()->ofAlbum($album)->create();

        $response = $this->visit("/s/{$share->id}");

        // ABSOLUTE, unlike the hero's URL on the page: a crawler has only the string, with no
        // document to resolve it against. And inside `/s/`, because it is fetched with no
        // session — a `/music/…` cover would unfurl as a broken image.
        $this->assertTag($response, 'og:image', route('shares.cover', $share));
    }

    public function test_a_shared_artist_borrows_the_sleeve_of_their_newest_record(): void
    {
        $artist = Artist::factory()->create();
        $old = Collection::factory()->create(['name' => 'First', 'year' => 1994]);
        $new = Collection::factory()->create(['name' => 'Latest', 'year' => 2004]);

        Track::factory()->create(['artist_id' => $artist->id, 'collection_id' => $old->id, 'cover' => true]);
        $newest = Track::factory()->create([
            'artist_id' => $artist->id,
            'collection_id' => $new->id,
            'cover' => true,
        ]);

        $share = Share::factory()->ofArtist($artist)->create();
        $response = $this->visit("/s/{$share->id}");

        // The PAGE fans three sleeves at random, on purpose. The CARD cannot: a preview that
        // changed every time the link was pasted looks like a fault in the chat window showing
        // it, so it picks one and picks the same one — the most recent record.
        $this->assertTag($response, 'og:image', route('shares.tracks.cover', [$share, $newest->id]));
    }

    public function test_a_shared_playlist_names_itself_and_borrows_its_opening_sleeve(): void
    {
        /*
         * A PLAYLIST SHARE, and the card is the easiest place to miss a subject kind: the
         * description is built from `social.share.kind.<subject>`, so a subject with no label
         * unfurls the raw KEY into somebody's chat window — a failure no page test can see,
         * because the page never reads that catalog.
         *
         * The sleeve is the FIRST ENTRY, not the newest record: a playlist has an order, and its
         * opening track is the one thing about it its maker actually chose. The fixture puts the
         * newer album second so the two rules disagree.
         */
        $playlist = Playlist::factory()->create(['name' => 'Freitagabend']);
        $opener = Track::factory()->create([
            'cover' => true,
            'duration' => 300,
            'collection_id' => Collection::factory()->create(['year' => 1994]),
        ]);
        $newer = Track::factory()->create([
            'cover' => true,
            'duration' => 300,
            'collection_id' => Collection::factory()->create(['year' => 2019]),
        ]);

        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $opener->id, 'position' => 1]);
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $newer->id, 'position' => 2]);

        $share = Share::factory()->ofPlaylist($playlist)->create();
        $response = $this->visit("/s/{$share->id}");

        $this->assertTag($response, 'og:title', 'MixTape-Link: Freitagabend');
        $this->assertTag($response, 'og:description', 'Wiedergabeliste · 2 Titel · 10 Minuten');
        $this->assertTag($response, 'og:image', route('shares.tracks.cover', [$share, $opener->id]));
    }

    public function test_an_expired_link_advertises_no_music_and_no_picture(): void
    {
        $album = Collection::factory()->create(['name' => 'OK Computer']);
        Track::factory()->create(['collection_id' => $album->id, 'cover' => true]);
        $share = Share::factory()->create([
            'collection_id' => $album->id,
            'valid_until' => now()->subDay(),
        ]);

        $response = $this->visit("/s/{$share->id}");

        // It keeps the NAME — the page keeps it too, so that asking for a new link is
        // possible — and says the link is dead instead of describing music that will not play.
        $this->assertTag($response, 'og:title', 'MixTape-Link: OK Computer');
        $this->assertTag($response, 'og:description', 'Dieser Link ist abgelaufen.');
        // Both cover routes refuse a dead share, so an image here would 404 on fetch, and a
        // broken frame is a worse way to learn this than the sentence above.
        $response->assertDontSee('og:image', false);
    }

    public function test_an_invite_link_says_nothing_about_the_invitation(): void
    {
        [$code] = $this->invite('Oma');

        $response = $this->visit('/register?code='.$code);

        $this->assertTag($response, 'og:title', 'Einladung zu MixTape');
        $this->assertTag($response, 'og:description', 'Du wurdest zu einer privaten Musiksammlung eingeladen.');
        // The note is a private reminder of who the invite was for, in the minter's own
        // words — it is for their own list, not for a chat window. The card is the same
        // for every invite there has ever been, which is the whole of its privacy model.
        $response->assertDontSee('Oma', false);
    }

    public function test_an_invite_card_does_not_carry_the_one_time_code_as_its_canonical_url(): void
    {
        [$code] = $this->invite();

        $response = $this->visit('/register?code='.$code);

        // The platform unfurling it already holds the full link, so this discloses no secret
        // — but a canonical URL is a string that gets copied onward, and a one-time token has
        // no business travelling in one.
        $this->assertTag($response, 'og:url', route('register'));
        $response->assertDontSee('<meta property="og:url" content="'.e(route('register', ['code' => $code])).'"', false);
    }

    public function test_every_other_page_gets_the_generic_card(): void
    {
        $response = $this->visit('/login');

        // Not a fallback for work not yet done: a crawler has no session, so every URL behind
        // `auth` answers it with a redirect to exactly this page. There is nothing else it
        // could truthfully say.
        $this->assertTag($response, 'og:title', config('app.name'));
        $this->assertTag($response, 'og:description', 'Eine private Musiksammlung.');
    }

    public function test_a_crawler_that_states_a_language_is_answered_in_it(): void
    {
        $album = Collection::factory()->create(['name' => 'OK Computer']);
        Track::factory()->create(['collection_id' => $album->id, 'duration' => 260]);
        $share = Share::factory()->ofAlbum($album)->create();

        $response = $this->visit("/s/{$share->id}", 'en');

        // The card gets no special locale handling and needs none: it renders in whatever
        // ConfigureLocale resolved, which is the app default for the crawlers that state
        // nothing and the stated language for the ones that do. Worth pinning because the
        // English path is otherwise only reached by accident (see `visit`).
        $this->assertTag($response, 'og:title', 'MixTape share: OK Computer');
        $this->assertTag($response, 'og:description', 'Album · 1 track · 4 minutes');
    }

    public function test_a_square_cover_asks_for_the_small_twitter_card(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->create(['collection_id' => $album->id, 'cover' => true]);
        $share = Share::factory()->ofAlbum($album)->create();

        // `summary_large_image` crops to roughly 2:1, and the image here is almost always a
        // record sleeve — the top and bottom of the artwork would simply be gone.
        $this->assertTag($this->visit("/s/{$share->id}"), 'twitter:card', 'summary');
    }
}
