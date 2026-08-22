/******************************************************************************
 * The reader's .m3u export presets — a named device and the three options that make a
 * playlist file play on it.
 *
 * Mirrors what App\Services\Playlists\ExportPresetRows puts on the wire, and it is ONE shape
 * for two consumers: the presets page lists them, and the playlist page hands the same rows to
 * the export modal's picker. Nothing is pre-formatted there — `format` and `encoding` arrive as
 * the stored values, and the client turns them into the words the export modal already has.
 *****************************************************************************/

/** The .m3u flavour: `simple` is a bare list of paths, `extended` adds `#EXTINF` metadata. */
export type ExportFormat = "simple" | "extended";

/** The file's text encoding. Windows-1252 exists for one real device — see the export modal. */
export type ExportEncoding = "UTF-8" | "Windows-1252";

/** One preset, as ExportPresetRows shapes it. */
export type ExportPreset = {
    /** UUID — the key for :key, the route parameter for edit/delete, and the picker's option value. */
    id: string;
    /** What the reader called the device: "MacBook", "Auto", "Handy". Unique per owner. */
    name: string;
    /** The .m3u flavour this device understands. */
    format: ExportFormat;
    /** The encoding this device renders without mojibake. */
    encoding: ExportEncoding;
    /**
     * What goes in front of every path, from this device's point of view.
     *
     * An EMPTY STRING is a real and ordinary value — the car case, where the playlist sits
     * beside the music and the paths are relative — never null. The row renders it in words
     * rather than as a blank cell.
     */
    pathPrefix: string;
    /**
     * Whether the export modal opens on this one. Exactly one of a reader's presets carries it
     * while they have any at all: the first they create takes it, and deleting the holder
     * passes it on.
     */
    isDefault: boolean;
};
