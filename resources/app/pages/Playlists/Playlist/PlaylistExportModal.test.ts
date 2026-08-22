import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { mountApp, translate } from "Testing/mount";
import type { ExportPreset } from "Types/exportPresets";
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

/**
 * Two presets, the way the server sends them: default first, and the second one exercising
 * every field at once — the car case, whose EMPTY prefix is a real value rather than an
 * absence.
 */
const PRESETS: ExportPreset[] = [
    {
        id: "preset-mac",
        name: "MacBook",
        format: "simple",
        encoding: "UTF-8",
        pathPrefix: "/Volumes/media/music",
        isDefault: true
    },
    {
        id: "preset-car",
        name: "Auto",
        format: "extended",
        encoding: "Windows-1252",
        pathPrefix: "",
        isDefault: false
    }
];

/** Mount the modal and capture whatever it navigates to. */
const modal = (fallbackPrefix = "/Volumes/media/music", tracks = SAFE, presets: ExportPreset[] = []) => {
    const assign = vi.fn();
    Object.defineProperty(window, "location", {
        configurable: true,
        value: { assign } as unknown as Location
    });

    const wrapper = mountApp(PlaylistExportModal, {
        props: { endpoint: "/playlists/playlist-1/export", count: 1, fallbackPrefix, presets, tracks }
    });

    return { wrapper, assign };
};

/**
 * Pick a preset from the picker, the way a click does.
 *
 * The Select is a button plus an ARIA listbox rather than a native <select>, so the option is
 * found by its label and clicked. Teleported like everything else here, hence `document`.
 */
const pickPreset = async (label: string): Promise<void> => {
    document.querySelector<HTMLButtonElement>(".form-select__button")!.click();
    await nextTick();

    const option = [...document.querySelectorAll<HTMLElement>('[role="option"]')].find(
        entry => entry.textContent?.trim() === label
    );

    option!.click();
    await nextTick();
};

/** What the picker's trigger currently reads. */
const pickerLabel = (): string =>
    document.querySelector(".form-select__button")?.textContent?.trim() ?? "";

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

    describe("the preset picker", () => {
        /*
         * WHAT THE PICKER IS FOR: all three answers belong to the DEVICE, not to the playlist,
         * so a preset seeds all three at once. What cannot be tested on the server is exactly
         * this — which values a mounted dialog starts with, and whether the picker goes on
         * claiming a preset after the reader has edited away from it.
         */

        it("is not drawn at all for a reader who keeps none", () => {
            // The dialog is then what it was before presets existed, seeded from config.
            modal("/Volumes/media/music", SAFE, []);

            expect(document.querySelector(".form-select__button")).toBeNull();
        });

        it("names the picker on the trigger itself, not on the box around it", () => {
            // A Select is a button plus a listbox, so a `for`/`id` pair would leave the row's
            // label pointing at a <div> and the button unnamed — and the button's own text is a
            // preset NAME once one is picked, so without this it announces itself as "MacBook".
            modal("/config/prefix", SAFE, PRESETS);

            expect(document.querySelector(".form-select__button")!.getAttribute("aria-label")).toBe(
                translate("playlists.export.presetLabel")
            );
        });

        it("opens on the default preset rather than on the configured prefix", () => {
            const { assign } = modal("/config/prefix", SAFE, PRESETS);

            submit();

            expect(submitted(assign).searchParams.get("prefix")).toBe("/Volumes/media/music");
            expect(pickerLabel()).toContain("MacBook");
        });

        it("fills all three fields from the preset the reader picks", async () => {
            const { assign } = modal("/config/prefix", SAFE, PRESETS);

            await pickPreset("Auto");
            submit();

            const url = submitted(assign);
            expect(url.searchParams.get("format")).toBe("extended");
            expect(url.searchParams.get("encoding")).toBe("Windows-1252");
            // The car preset's EMPTY prefix is a value, not an absence — picking it must clear
            // the field rather than leave the previous preset's path standing.
            expect(url.searchParams.get("prefix")).toBe("");
        });

        it("stops claiming a preset once a field is edited away from it", async () => {
            // A dialog reading "Auto" while showing UTF-8 is worse than one claiming nothing.
            modal("/config/prefix", SAFE, PRESETS);

            await pickPreset("Auto");
            expect(pickerLabel()).toContain("Auto");

            typePrefix("/somewhere/else");
            await nextTick();

            expect(pickerLabel()).not.toContain("Auto");
            expect(pickerLabel()).toContain(translate("playlists.export.presetPlaceholder"));
        });

        it("claims it again when the fields are put back", async () => {
            // Derived rather than watched, so it reads honestly in both directions.
            modal("/config/prefix", SAFE, PRESETS);

            typePrefix("/somewhere/else");
            await nextTick();
            expect(pickerLabel()).not.toContain("MacBook");

            typePrefix("/Volumes/media/music");
            await nextTick();

            expect(pickerLabel()).toContain("MacBook");
        });

        it("still exports what the reader edited, preset or not", async () => {
            // A preset SEEDS, it does not lock: "the MacBook one but extended, this once".
            const { assign } = modal("/config/prefix", SAFE, PRESETS);

            await choose("format", 1);
            submit();

            const url = submitted(assign);
            expect(url.searchParams.get("format")).toBe("extended");
            expect(url.searchParams.get("prefix")).toBe("/Volumes/media/music");
        });
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
