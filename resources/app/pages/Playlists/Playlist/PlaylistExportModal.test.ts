import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountApp, translate } from "Testing/mount";
import PlaylistExportModal from "./PlaylistExportModal.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The export form's three choices, and the URL they add up to.
 *
 * THE URL IS THE WHOLE CONTRACT of this component. It performs no request — submitting hands
 * a location to the browser, which does the download natively — so what it produces is one
 * string, and a wrong parameter name or a lost default is a file the reader cannot use on the
 * device they made it for. Nothing else here is worth a test: the bytes behind that URL are
 * pinned in tests/Feature/Playlists/ExportPlaylistTest.php, and the modal's own furniture
 * belongs to Modal.
 *
 * `window.location.assign` is stubbed rather than spied through, because happy-dom implements
 * it as a real navigation and would tear the document down mid-test.
 *
 * EVERYTHING IS QUERIED OFF `document`, not off the wrapper. Modal TELEPORTS to <body>, so
 * `wrapper.find()` reaches straight past it — and, worse, can match something with the same
 * selector back in the host page. Which also means the inputs are driven with native events
 * rather than VTU helpers, since those need a wrapper to hang off.
 */

/** Mount the modal and capture whatever it navigates to. */
const modal = (defaultPrefix = "/Volumes/media/music") => {
    const assign = vi.fn();
    Object.defineProperty(window, "location", {
        configurable: true,
        value: { assign } as unknown as Location
    });

    const wrapper = mountApp(PlaylistExportModal, {
        props: { playlistId: "playlist-1", defaultPrefix }
    });

    return { wrapper, assign };
};

/** The URL the form navigated to, parsed. */
const submitted = (assign: ReturnType<typeof vi.fn>): URL => new URL(assign.mock.calls[0][0], "http://localhost");

/** Submit the teleported form. */
const submit = (): void => {
    document
        .querySelector("#playlist-export-form")!
        .dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
};

/** Choose the nth radio of a group, the way a click does. */
const choose = (name: string, index: number): void => {
    (document.querySelectorAll<HTMLInputElement>(`input[name="${name}"]`)[index]).click();
};

/** Type into the prefix field — `input`, which is the event v-model listens for. */
const typePrefix = (value: string): void => {
    const field = document.querySelector<HTMLInputElement>("#export-prefix")!;
    field.value = value;
    field.dispatchEvent(new Event("input", { bubbles: true }));
};

describe("PlaylistExportModal", () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it("downloads a simple UTF-8 file with the configured prefix by default", () => {
        // The three defaults, and the one that comes from the server: a reader who opens the
        // modal and presses download immediately gets what most devices want.
        const { assign } = modal("/Volumes/media/music");

        submit();

        const url = submitted(assign);
        expect(url.pathname).toBe("/playlists/playlist-1/export");
        expect(url.searchParams.get("format")).toBe("simple");
        expect(url.searchParams.get("encoding")).toBe("UTF-8");
        expect(url.searchParams.get("prefix")).toBe("/Volumes/media/music");
    });

    it("sends the format and encoding the reader chose", () => {
        const { assign } = modal();

        choose("format", 1);
        choose("encoding", 1);
        submit();

        const url = submitted(assign);
        expect(url.searchParams.get("format")).toBe("extended");
        expect(url.searchParams.get("encoding")).toBe("Windows-1252");
    });

    it("sends an edited prefix, spaces and all", () => {
        // `URLSearchParams` rather than concatenation: a space or a plus in a mount point has
        // to reach the server as itself.
        const { assign } = modal();

        typePrefix("/Volumes/My Media/music");
        submit();

        expect(submitted(assign).searchParams.get("prefix")).toBe("/Volumes/My Media/music");
    });

    it("sends an empty prefix when the field is cleared", () => {
        // Which the server reads as "relative paths", for a playlist sitting beside its files.
        const { assign } = modal();

        typePrefix("");
        submit();

        expect(submitted(assign).searchParams.get("prefix")).toBe("");
    });

    it("closes itself once the download is handed over", () => {
        // There is nothing to await, so nothing to keep the modal open for.
        const { wrapper, assign } = modal();

        submit();

        expect(assign).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("names the two .m3u flavours and the two encodings", () => {
        // The labels do the explaining — an encoding is not something to leave a reader
        // guessing at — so they are worth pinning against the catalogue.
        modal();
        const labels = [...document.querySelectorAll(".radio-group__item")].map(node =>
            (node.textContent ?? "").trim()
        );

        expect(labels).toStrictEqual([
            translate("playlists.export.formatSimple"),
            translate("playlists.export.formatExtended"),
            translate("playlists.export.encodingUtf8"),
            translate("playlists.export.encodingWindows")
        ]);
    });
});
