/******************************************************************************
 * Music browse data — the Inertia prop shapes MusicController sends to the
 * Music page's widgets. Each widget receives BOTH its latest and a random set
 * (WidgetModes) so the header toggle can switch between them client-side.
 *****************************************************************************/

/**
 * The active segment of a widget's mode toggle. `popular` exists only on the
 * widgets that support it (songs by plays, artists/genres by total file
 * duration) — never on albums.
 */
export type WidgetMode = "latest" | "random" | "popular";

/**
 * The per-mode entry sets one widget receives. `latest` and `random` are always
 * present; `popular` is sent only for songs/artists/genres (albums omit it), so
 * it's optional.
 */
export interface WidgetModes<T> {
    latest: T[];
    random: T[];
    popular?: T[];
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
