<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the cross-kind search can find (docs/search.md → "The kinds").
 *
 * THE DECLARATION ORDER IS THE GROUP ORDER, and it is a decision rather than an accident:
 * artists → albums → playlists → songs → genres. Containers before contents, because one
 * artist row leads to everything by them; a genre last, because a genre is a shelf rather
 * than a thing somebody was looking for. `LibrarySearch` walks `cases()` to build its groups,
 * so `?kinds=` can narrow the answer but cannot reorder it — a reader scanning a dropdown is
 * aiming at a shape they have learnt, and a shape that reshuffles per query is one they
 * cannot learn.
 *
 * THERE IS NO CROSS-KIND SCORE and there is deliberately no way to add one here. Nothing
 * honestly compares "an artist called Black Sabbath" with "a song called Black"; collapsing
 * empty groups already floats the answer for a specific query, which is the useful half of
 * what a global ranking would buy.
 *
 * THE AUDIOBOOK CASE SHOWS WHAT A KIND COSTS: one entry
 * here, one registry line, and a class beside the others — no edit to how matching, counting
 * or ordering work, which is the whole reason the kinds are a registry rather than a
 * hard-coded union. And no `Genre` share-style omission either: unlike
 * {@see ShareSubject}, a genre IS searchable — it is a fine thing to look for and a poor
 * thing to send.
 */
enum SearchKind: string
{
    case Artist = 'artist';
    case Album = 'album';
    case Playlist = 'playlist';
    case Song = 'song';
    case Genre = 'genre';
    // Last, so adding it left every existing group where it was — the declaration order IS
    // the group order, and a book is the thing a reader is least often looking for in a
    // library that is mostly music.
    case Audiobook = 'audiobook';
}
