import type { DiscographyAlbum } from "Components/Music/Discography/Discography.vue";

/**
 * The Inertia prop shapes the Audiobooks controllers send.
 *
 * Every value is RAW — seconds, bytes, plain counts — because sizes and durations read
 * differently per language and the formatting belongs with the locale (Utils/formatting.ts).
 * The same split `types/music.ts` documents at length.
 */

/** The six numbers on the audiobook stats card, mirroring AudiobooksController::stats(). */
export interface AudiobookStats {
    /** How many books the library holds. */
    books: number;
    /** How many chapters across all of them. */
    chapters: number;
    /** Combined size of every chapter file, in BYTES. */
    sizeBytes: number;
    /** Combined playing time of every chapter, in SECONDS. */
    playtimeSeconds: number;
    /** Distinct authors credited on at least one chapter. */
    authors: number;
    /** Distinct narrators credited on at least one chapter. */
    narrators: number;
}

/**
 * One author or narrator, with the books they contributed to.
 *
 * The two are the same shape because they answer the same question one column apart, and the
 * page draws them with one component.
 */
export interface AudiobookCredit {
    id: string;
    name: string;
    /**
     * The books they appear on, already shaped as Discography tiles. An anthology appears
     * under every contributor, which is what the credit tabs are for.
     */
    books: DiscographyAlbum[];
    /** How many books that is — an anthology counts once however many chapters they wrote. */
    bookCount: number;
    /**
     * How long THEIR chapters run, in SECONDS — not the length of the books they appear on.
     * An author of three stories in an anthology is worth three stories of listening.
     */
    duration: number | null;
}
