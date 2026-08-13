/******************************************************************************
 * Listening counts — what kinds of thing they can describe.
 *
 * A MODULE OF ITS OWN because `<script setup>` may export types but not VALUES, and the list has
 * to be a value: a spec can loop over an array, and it cannot loop over a union. That difference
 * is why this file exists at all — `playlist` was added to the union when the playlist page
 * started showing these tiles, PlayCountFacts' spec went on naming four subjects by hand, and the
 * two `music.plays.playlist.*` sentences were never written. Nothing failed, because a missing
 * translation is a console warning and the raw key on screen, until the owner opened a playlist
 * (2026-08-13). Driven off the array, a subject with no copy fails the spec instead.
 *****************************************************************************/

/**
 * Every kind of thing a play count can be about — one per page that shows the tiles.
 *
 * The order is the union's old order and carries no meaning. Adding a case here means adding
 * `music.plays.<case>.ownTip` / `.othersTip` to BOTH catalogs; PlayCountFacts.test.ts checks it.
 */
export const PLAY_COUNT_SUBJECTS = ["song", "artist", "genre", "album", "playlist"] as const;

/** What kind of thing the counts describe — decides only which sentences are shown. */
export type PlayCountSubject = (typeof PLAY_COUNT_SUBJECTS)[number];
