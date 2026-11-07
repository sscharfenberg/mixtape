/******************************************************************************
 * trackFacts
 * The chips under a track's title wherever a queue track is shown as a card: artist, album,
 * genre and runtime, in the hero's order, with every fact the file has no answer for dropped.
 *
 * ONE DEFINITION FOR EVERY CARD THAT SHOWS THEM — the neighbour pair on Now Playing and the three
 * entries in party mode's track box. The two layouts differ on purpose (a page card against a
 * caption over a fullscreen picture), but WHAT a card says about a track must not: a second copy
 * of this list is how one card ends up showing a genre the other one lost.
 *****************************************************************************/
import type { QueueTrack } from "Composables/usePlayerQueue";
import { formatClock } from "Utils/formatting";

/** One chip: which fact it is (also its `v-for` key), the glyph beside it, and the text. */
export type TrackFact = {
    /** Which fact this is — stable across renders, so it doubles as the list key. */
    key: "artist" | "album" | "genre" | "duration";
    /** The sprite glyph drawn before the value. */
    icon: string;
    /** The fact itself, already formatted for display. */
    value: string;
};

/**
 * A track's facts in display order, keeping only the ones it has.
 *
 * The genre is handed in rather than read off the track because the stored queue has no room for
 * it (docs/now-playing.md → *The track's own facts*); null drops the chip, which is the same answer
 * for "untagged" and "not fetched yet". An unknown runtime is dropped rather than shown as `0:00`.
 */
export function trackFacts(track: QueueTrack, genre: string | null): TrackFact[] {
    const facts: { key: TrackFact["key"]; icon: string; value: string | null }[] = [
        { key: "artist", icon: "artist", value: track.artist },
        { key: "album", icon: "album", value: track.album },
        { key: "genre", icon: "genre", value: genre },
        { key: "duration", icon: "duration", value: formatClock(track.duration) }
    ];

    return facts.filter((fact): fact is TrackFact => Boolean(fact.value));
}
