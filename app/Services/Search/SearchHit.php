<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * One row in a search group: something to name it, one line of context, and somewhere to go.
 *
 * THE SECOND LINE IS NOT DECORATION. Searching "black" returns three rows all called
 * something with Black in it, and a dropdown of near-identical strings is a dropdown a reader
 * cannot choose from — so every kind says one more thing about its row: an artist's album
 * count, an album's and a song's artist, a genre's and a playlist's size.
 *
 * IT TRAVELS AS A NUMBER *OR* A NAME, never as a rendered phrase, because this app's rule is
 * that the server sends raw values and the client formats them (CLAUDE.md → the formatting
 * note). "12 Alben" composed here would be German in a page the reader is viewing in English,
 * and a count formatted here would miss the thousands separator the reader's locale uses. So
 * `count` is pluralised client-side against the group's own kind and `text` is printed as it
 * stands. Which of the two a kind uses is fixed per kind, so the client never has to guess.
 */
final readonly class SearchHit
{
    /**
     * @param  string  $id  the row's UUID — what makes it a stable `:key` in a list that repaints per keystroke
     * @param  string  $name  the matched name, raw, as it is stored
     * @param  string  $href  the row's own page, decided server-side like every other link in this app
     * @param  int|null  $count  a number the client pluralises for this kind (albums / songs / tracks)
     * @param  string|null  $text  a name to print as it stands (an artist), or null when there is none to give
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $href,
        public ?int $count = null,
        public ?string $text = null,
    ) {}

    /** @return array{id: string, name: string, href: string, count: int|null, text: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'href' => $this->href,
            'count' => $this->count,
            'text' => $this->text,
        ];
    }
}
