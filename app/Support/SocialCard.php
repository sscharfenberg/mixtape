<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a link to this app looks like when it is pasted into a chat window — the Open Graph
 * card, as a value rather than as a pile of `<meta>` tags built in a template.
 *
 * IT IS RENDERED BY THE SERVER, WHICH IS THE WHOLE CONSTRAINT. The crawlers that read these
 * tags do not run JavaScript, so nothing an Inertia page knows can reach them: the card has to
 * be in the HTML that leaves Laravel. That is why this is a Support value and not a prop, and
 * why the only three things it can describe are the three URL spaces a stranger can actually
 * fetch (App\Services\Meta\SocialCards).
 *
 * A readonly object rather than an array, so a missing key is a type error at the point that
 * forgot it rather than an empty `content=""` attribute nobody notices in a preview.
 */
final readonly class SocialCard
{
    /**
     * @param  string  $title  the bold line — the only part every platform is guaranteed to show
     * @param  string  $description  the grey line under it; empty renders no tag at all
     * @param  string  $url  the canonical address, ABSOLUTE, and never carrying a secret (see the invite card)
     * @param  string|null  $image  an ABSOLUTE, publicly fetchable image, or null for a text-only card
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $url,
        public ?string $image = null,
    ) {}
}
