<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\Share;
use App\Services\Shares\ShareArtwork;
use App\Services\Shares\ShareGrant;
use App\Support\SocialCard;
use Illuminate\Http\Request;

/**
 * WHICH PREVIEW CARD A URL GETS — one class, one `match`, one arm per area.
 *
 * THREE AREAS, AND THERE CAN ONLY BE THREE, which is the fact that shapes this file. A crawler
 * has no session, so every URL under `auth` answers it with a redirect to the login form: a
 * per-album or per-playlist card would be written for a fetch that never reaches the page. The
 * only addresses a stranger can actually retrieve are the share space, the invite link, and
 * the handful of guest pages in front of the app — hence a share card, an invite card, and one
 * default that every other URL in the app collapses into. That default is not a fallback for
 * work not yet done; it is the correct answer for a page nobody outside can see.
 *
 * IT READS THE ROUTE RATHER THAN BEING TOLD, and that is deliberate. The alternative — each
 * controller setting its own card — means every new public page has to remember to, and the
 * failure is silent: a link that unfurls as the generic card looks fine until somebody notices
 * it says nothing. Here the areas are a list in one place, and adding one is an arm.
 *
 * THE SHARE ARM RE-ASKS THE GRANT for its totals, which the page controller has also just
 * asked. Two identical aggregates per share page load, both a `count`/`sum` over an indexed
 * foreign key — paid deliberately, to keep the alternative away: threading the card through
 * `Inertia::render` would put a presentation concern into every controller's signature to save
 * a query that does not show up in a profile.
 *
 * THE LOCALE IS WHATEVER `ConfigureLocale` RESOLVED, which for a crawler is the app default —
 * they rarely send `Accept-Language`. That is the right answer rather than a compromise: the
 * card is read by whoever the link was sent to, and this instance's people share a language.
 */
final class SocialCards
{
    /** Injected so "which picture stands for a share" stays the one class that owns it. */
    public function __construct(private readonly ShareArtwork $artwork) {}

    /**
     * The card for this request.
     *
     * Keyed on the ROUTE NAME rather than the path, so a URL scheme can change without
     * silently dropping a card — a renamed path with the same route name keeps working, and a
     * renamed route fails loudly in the tests that name it.
     */
    public function for(Request $request): SocialCard
    {
        $share = $request->route('share');

        return match ($request->route()?->getName()) {
            'shares.show' => $share instanceof Share ? $this->share($share) : $this->site(),
            'register' => $this->invite(),
            default => $this->site(),
        };
    }

    /**
     * A share link: what was sent, how much of it there is, and its artwork.
     *
     * THE TITLE NAMES THE SUBJECT, because that is the one line every platform shows and the
     * only question a recipient has before deciding to tap — "Anna sent me something" is
     * already known from the conversation the link is sitting in. What it does NOT name is who
     * minted it, for the same reason the page does not: this app logs in by `users.name`, so a
     * friendly "Anna shared this" publishes half a credential pair to anyone holding a
     * forwarded link, and to the platform that unfurled it.
     *
     * AN EXPIRED LINK SAYS SO IN THE CARD, rather than advertising music that will not play
     * when it is opened. It keeps the name — the page keeps it too, so that asking for a new
     * link is possible — and drops the image, both cover routes refusing a dead share.
     */
    private function share(Share $share): SocialCard
    {
        $grant = ShareGrant::for($share);
        $name = $grant->subjectName();

        if ($name === null) {
            return $this->site();
        }

        $totals = $grant->totals();

        return new SocialCard(
            title: __('social.share.title', ['name' => $name]),
            description: $share->isLive()
                ? $this->summary($share, $totals['songs'], (int) ($totals['duration'] ?? 0))
                : __('social.share.expired'),
            url: $share->url(),
            image: $this->artwork->preview($share),
        );
    }

    /**
     * The grey line under a live share's title: what kind of thing it is, how many tracks, and
     * how long they run.
     *
     * THE SERVER FORMATS THE RUNTIME HERE, which is the one place in this app it may: the rule
     * is that controllers send raw seconds and `Utils/formatting.ts` renders them, and the
     * rule holds because there is always a client on the other end to do it. A crawler is not
     * one. Minutes only, and rounded — a preview is a glance, and "53 Minuten" is what a
     * reader is deciding on, not "52:47".
     */
    private function summary(Share $share, int $songs, int $seconds): string
    {
        $kind = __('social.share.kind.'.ShareGrant::for($share)->subject()?->value);
        $songLine = trans_choice('social.share.songs', $songs, ['count' => $songs]);

        if ($seconds < 60) {
            return $kind.' · '.$songLine;
        }

        $minutes = (int) round($seconds / 60);
        $runtime = $minutes < 60
            ? trans_choice('social.share.minutes', $minutes, ['count' => $minutes])
            : __('social.share.hours', ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60]);

        return $kind.' · '.$songLine.' · '.$runtime;
    }

    /**
     * An invite link, and deliberately the same card for every one of them.
     *
     * NOTHING ABOUT THE INVITATION IS IN IT — not who sent it, not the note they wrote, not
     * how long it lasts. The note is private (it is the owner's reminder of who the invite was
     * for, in their own words), and naming the inviter would publish a login identifier, which
     * is the same trade the share card refuses above. What is left is true of every invite and
     * gives away nothing: somebody was invited to a music collection.
     *
     * `og:url` is the BARE register URL, without the code. The platform unfurling it already
     * has the full link, so this changes no secret — but a canonical URL is a string that gets
     * copied onward, and a one-time token has no business travelling in one.
     */
    private function invite(): SocialCard
    {
        return new SocialCard(
            title: __('social.invite.title'),
            description: __('social.invite.description'),
            url: route('register'),
            image: $this->brandImage(),
        );
    }

    /** Everything else — which, a crawler having no session, is every page it will ever be shown. */
    private function site(): SocialCard
    {
        return new SocialCard(
            title: config('app.name', 'MixTape'),
            description: __('social.site.description'),
            url: route('home'),
            image: $this->brandImage(),
        );
    }

    /**
     * The generic card image, or null until somebody generates one.
     *
     * A FILE CHECK RATHER THAN A CONSTANT, because the asset is built by a script
     * (`npm run og`) and is not something a fresh checkout necessarily has. Pointing `og:image`
     * at a 404 is worse than sending no image: several platforms show a broken frame where they
     * would otherwise have laid the card out as text.
     */
    private function brandImage(): ?string
    {
        return file_exists(public_path('og/mixtape.png')) ? asset('og/mixtape.png') : null;
    }
}
