import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
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

/** A playlist whose paths are all plain ASCII — nothing for the encoding check to say. */
const SAFE = [
    { name: "Airbag", path: "Radiohead/OK Computer/01 Airbag.mp3" },
    { name: "Bones", path: "Radiohead/The Bends/05 Bones.mp3" }
];

/** Mount the modal and capture whatever it navigates to. */
const modal = (defaultPrefix = "/Volumes/media/music", tracks = SAFE) => {
    const assign = vi.fn();
    Object.defineProperty(window, "location", {
        configurable: true,
        value: { assign } as unknown as Location
    });

    const wrapper = mountApp(PlaylistExportModal, {
        props: { playlistId: "playlist-1", defaultPrefix, tracks }
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

/**
 * Choose the nth radio of a group, the way a click does.
 *
 * Awaits a tick, because anything reading the DOM afterwards is reading a RE-RENDER: the click
 * only sets the ref, and Vue flushes on the next tick. The tests that read the submitted URL
 * do not need it — that value is computed in the handler — which is exactly why this bit at
 * the ones that read rendered text and not at the ones that came before them.
 */
const choose = async (name: string, index: number): Promise<void> => {
    document.querySelectorAll<HTMLInputElement>(`input[name="${name}"]`)[index].click();
    await nextTick();
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

    it("sends the format and encoding the reader chose", async () => {
        const { assign } = modal();

        await choose("format", 1);
        await choose("encoding", 1);
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

    describe("the Windows-1252 warning", () => {
        /*
         * The reason it exists: a path Windows-1252 cannot carry comes out with "?" where the
         * character was, and on a PATH line that is a DEAD line — "?" is not a legal filename
         * character, so the player looks for a file that cannot exist. Nothing about the
         * download says so, and the reader finds out in the car.
         *
         * Measured against the real collection, the failure CLUSTERS — 27 tracks for one band,
         * 23 for another — so it is none of most playlists and all of a few.
         */
        const RISKY = [
            { name: "Airbag", path: "Radiohead/OK Computer/01 Airbag.mp3" },
            { name: "Świt", path: "Mgła/Exercises in Futility/01 Świt.mp3" },
            { name: "渡頭", path: "Bloody Tyrant/01 渡頭.mp3" }
        ];

        /** The warning's text, or "" when it is not shown. */
        const warning = (): string => document.querySelector(".form-legend .warning")?.textContent?.trim() ?? "";

        it("says nothing while UTF-8 is selected, whatever the paths hold", () => {
            // UTF-8 carries everything, so there is no warning to give — this is a warning
            // about a CHOICE, not about the playlist.
            modal("/x", RISKY);

            expect(warning()).toBe("");
        });

        it("appears the moment Windows-1252 is chosen", async () => {
            modal("/x", RISKY);

            await choose("encoding", 1);

            expect(warning()).not.toBe("");
        });

        it("names the tracks that will be missing, and not the ones that will not", async () => {
            modal("/x", RISKY);

            await choose("encoding", 1);

            expect(warning()).toContain("Świt");
            expect(warning()).toContain("渡頭");
            // Plain ASCII survives, so naming it would send the reader after a file that works.
            expect(warning()).not.toContain("Airbag");
        });

        it("lists the offending characters, which is the actionable half", async () => {
            // "ł" says which record and what to rename; "this will not play" leaves them
            // guessing. De-duplicated, so one character repeated across ten paths is said once.
            modal("/x", RISKY);

            await choose("encoding", 1);

            expect(warning()).toContain("ł");
            expect(warning()).toContain("Ś");
        });

        it("stays silent when every path survives", async () => {
            modal("/x", SAFE);

            await choose("encoding", 1);

            expect(warning()).toBe("");
        });

        it("goes away again when the reader switches back to UTF-8", async () => {
            modal("/x", RISKY);

            await choose("encoding", 1);
            expect(warning()).not.toBe("");

            await choose("encoding", 0);
            expect(warning()).toBe("");
        });

        it("caps the names it lists rather than filling the modal", async () => {
            // One band's record is 27 dead lines; listing all of them would push the download
            // button off the panel.
            const many = Array.from({ length: 9 }, (_, index) => ({
                name: `Track ${index}`,
                path: `Mgła/album/0${index} Świt.mp3`
            }));
            modal("/x", many);

            await choose("encoding", 1);

            expect(warning()).toContain("Track 3");
            expect(warning()).not.toContain("Track 8");
            expect(warning()).toContain("5");
        });
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
