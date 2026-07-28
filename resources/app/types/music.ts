/******************************************************************************
 * Music data — the Inertia prop shapes the Music controllers send: the browse
 * widgets on the Music page (MusicController) and one song's detail page
 * (SongController). Each widget receives BOTH its latest and a random set
 * (WidgetModes) so the header toggle can switch between them client-side.
 *
 * Every value here is RAW — seconds, bytes, Hz, ISO-8601 instants — because
 * sizes, rates, durations and dates all read differently per language; the pages
 * format them against the active locale via Utils/formatting.ts.
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

/** Channel layout as the scanner stored it — the raw values of PHP's `App\Enums\Channel`. */
export type Channel = "stereo" | "joint_stereo" | "dual_mono" | "mono";

/**
 * One song with every stored fact about its file — the song detail page's prop
 * (SongController). Distinct from SongEntry (a widget's id/title/artist) and from
 * the listing's page-local SongRow: this is the full record, and the only shape
 * that carries the technical stream fields and the file's own metadata.
 */
export interface SongDetail {
    /** The track's UUID — the id in the page's own URL. */
    id: string;
    /** Track title, straight from the file's tags. */
    name: string;
    /** Performing artist's name, or null for a file whose tags carried none. */
    artist: string | null;
    /** Album (collection) name, or null when the song isn't filed under one. */
    album: string | null;
    /** Genre name, or null when untagged. */
    genre: string | null;
    /** The album's release year, or null when unknown. */
    year: number | null;
    /** Composer tag, or null — set on classical rips far more often than on pop. */
    composer: string | null;
    /** Publisher / label tag, or null. */
    publisher: string | null;
    /** Playing time in seconds, or null when the file carried no duration. */
    duration: number | null;
    /** Track number within its disc, or null when the file carried no track tag. */
    track: number | null;
    /** How many tracks share this song's disc — the "/8" in "2/8"; null without an album. */
    trackTotal: number | null;
    /** Disc number in a multi-disc set, or null when untagged. */
    disc: number | null;
    /** How many discs the album has; 1 (or 0, when untagged) means the disc row is hidden. */
    discTotal: number | null;
    /** Compact codec label, e.g. "MPEG1 L3". */
    codec: string | null;
    /** Channel layout, or null when the stream didn't say. */
    channel: Channel | null;
    /** Sample rate in Hz (e.g. 44100). */
    sampleRate: number | null;
    /** Bit rate in BITS per second (e.g. 320000) — displayed as kbit/s. */
    bitRate: number | null;
    /** Whether the file is variable-bit-rate, in which case `bitRate` is the average. */
    vbr: boolean;
    /** Whether the mp3 carries embedded cover art (an APIC frame). */
    cover: boolean;
    /** File size in bytes. */
    sizeBytes: number | null;
    /**
     * The file's mtime as an ISO-8601 instant (with offset), or null. A string, not
     * a number: JSON has no date type, and an offset-carrying ISO string is
     * self-describing and parses straight into `new Date()`, where a unix integer
     * would drop the offset.
     */
    modifiedAt: string | null;
    /** When the scan first inserted the row, ISO-8601 — the real "date added". */
    addedAt: string | null;
    /** Path relative to the library root — i.e. the path on the Samba share. */
    path: string;
}

/** A taxonomy row — an artist or a genre (id + name). */
export interface TaxonomyEntry {
    id: string;
    name: string;
}

/**
 * Collection totals for the stats widget (music only). Raw numbers — the widget
 * formats `sizeBytes` (→ GB) and `playtimeSeconds` (→ months/days/…) for display.
 */
export interface CollectionStats {
    /** Number of music files (music-type tracks). */
    songs: number;
    /** Combined size of all music files, in bytes. */
    sizeBytes: number;
    /** Combined duration of all music files, in seconds. */
    playtimeSeconds: number;
    /** Number of music albums. */
    albums: number;
    /** Number of artists with at least one track. */
    artists: number;
    /** Number of genres with at least one track. */
    genres: number;
}
