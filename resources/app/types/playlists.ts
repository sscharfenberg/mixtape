/******************************************************************************
 * Playlist props shared between the Playlists pages.
 *
 * Mirrors what PlaylistsController's row mapper puts on the wire — nothing is
 * pre-formatted there, so the raw shapes (an ISO-8601 instant, a plain count)
 * arrive here and the page renders them against the viewer's locale.
 *****************************************************************************/

/** One row of the playlists listing, as PlaylistsController shapes it. */
export type PlaylistEntry = {
    /** UUID — the key for :key and, once the detail page lands, its route parameter. */
    id: string;
    /** The playlist's name, unique per owner. */
    name: string;
    /** The owner's blurb, or null when they left it empty (the server stores "" as null). */
    description: string | null;
    /** How many entries the playlist holds. 0 is the normal state right after creating one. */
    tracks: number;
    /** Total playing time in raw seconds, or null for an empty playlist (SUM over no rows). */
    duration: number | null;
    /** ISO-8601 instant, formatted on the page — the server knows neither the locale nor the timezone. */
    createdAt: string | null;
    /**
     * ISO-8601 instant of the last change, or null when nothing has happened since it was
     * created. The server answers "was it changed at all", because that is a fact about the
     * data; the page only decides how the date reads.
     */
    updatedAt: string | null;
};
