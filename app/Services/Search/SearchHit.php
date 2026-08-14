<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * One row in a search group: something to name it, the facts that tell it apart, and somewhere
 * to go.
 *
 * THE FACTS ARE NOT DECORATION. Searching "black" returns several rows all called something with
 * Black in it, and a dropdown of near-identical strings is a dropdown a reader cannot choose
 * from — so every kind says two more things about its row, drawn as icon pips underneath the
 * name:
 *
 *   artist   → how many albums, how long their tracks run
 *   album    → who it is by, how many tracks
 *   playlist → how many tracks, how long it runs
 *   song     → who it is by, how long it runs
 *   genre    → how many artists, how many songs
 *
 * THEY TRAVEL RAW, AS A BAG, and both halves of that are deliberate. Raw because this app's rule
 * is that the server sends numbers and the client formats them: seconds become a clock and a
 * count picks up its locale's thousands separator, and "12 Alben" composed here would be German
 * on a page being read in English. A bag rather than a fixed pair of fields because the five
 * kinds do not agree on WHICH two facts they carry — and the ORDER is the client's, which owns
 * the icon and the label for each key anyway (SearchResults), so nothing here has to be kept in
 * step with a layout decision.
 *
 * A NULL FACT IS A MISSING FACT, not a zero, and the client drops its pip: a file that credits
 * no artist and an artist credited on albums but performing no tracks of their own are both real,
 * and "0:00" reads as a broken row rather than as an absence.
 */
final readonly class SearchHit
{
    /**
     * @param  string  $id  the row's UUID — what makes it a stable `:key` in a list that repaints per keystroke
     * @param  string  $name  the matched name, raw, as it is stored
     * @param  string  $href  the row's own page, decided server-side like every other link in this app
     * @param  array<string, int|float|string|null>  $facts  raw values keyed by fact name — see the class note
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $href,
        public array $facts = [],
    ) {}

    /** @return array{id: string, name: string, href: string, facts: array<string, int|float|string|null>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'href' => $this->href,
            'facts' => $this->facts,
        ];
    }
}
