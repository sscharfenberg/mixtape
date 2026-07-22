/******************************************************************************
 * Music browse data — the Inertia prop shapes MusicController sends to the
 * Music page's widgets. Each widget receives BOTH its latest and a random set
 * (WidgetModes) so the header toggle can switch between them client-side.
 *****************************************************************************/

/** The active side of a widget's latest / random toggle. */
export type WidgetMode = "latest" | "random";

/** The latest (default) and random variants of one widget's entries. */
export interface WidgetModes<T> {
    latest: T[];
    random: T[];
}

/** A music album — id, title, album-artist (nullable), release year (nullable). */
export interface AlbumEntry {
    id: string;
    name: string;
    artist: string | null;
    year: number | null;
}

/** A song — id, title, performing artist (nullable). */
export interface SongEntry {
    id: string;
    name: string;
    artist: string | null;
}

/** A taxonomy row — an artist or a genre (id + name). */
export interface TaxonomyEntry {
    id: string;
    name: string;
}
