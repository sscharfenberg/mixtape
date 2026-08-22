import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { ExportPreset } from "Types/exportPresets";
import ExportPresetsPage from "./ExportPresetsPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The reader's export presets, at /dashboard/export-presets.
 *
 * WHAT IS TESTED HERE IS WHAT PHP CANNOT SEE, per the repo's layer rule. The ORDER of the rows,
 * which preset carries the default flag and who may see whose are the server's decisions and
 * are pinned by `assertInertia` in tests/Feature/Dashboard/ExportPresets/, so none of that is
 * repeated. What belongs to this page:
 *
 *   - AN EMPTY PREFIX RENDERED IN WORDS. '' is a real choice — the USB stick where the playlist
 *     sits beside the music — and the server sends it as an empty string, so whether the reader
 *     sees "relative Pfade" or a blank cell is decided here. A blank reads as a preset somebody
 *     forgot to finish.
 *   - THE VALUES IN THE EXPORT DIALOG'S OWN WORDS, read from `playlists.export.*` rather than
 *     from a second set of keys. Two catalogues for one pair of options is how a reader ends up
 *     wondering whether "einfache .m3u" here and there mean the same thing.
 *   - THE DEFAULT MARKER BEING INERT ON THE ROW THAT HOLDS IT and a button on every other. A
 *     control that does nothing is worse than a label, and this is the one distinction the list
 *     turns on.
 *   - THE ACCESSIBLE NAME OF EACH CONTROL carrying its device. A column of identical "delete"
 *     labels tells a screen-reader user which row they are on only by counting.
 *   - THE EMPTY STATE saying what happens meanwhile, which is a working arrangement rather than
 *     a broken one.
 */

/**
 * A catalog line with its one placeholder filled.
 *
 * `translate` returns the raw string — it resolves keys, it does not interpolate — so the
 * substitution is done here rather than the assertion being loosened to a `toContain` on the
 * device name alone. That would pass on a label that had lost its wording entirely.
 */
const named = (key: string, name: string): string => translate(key).replace("{name}", name);

/** A preset; tests override only what they are about. */
const preset = (overrides: Partial<ExportPreset> = {}): ExportPreset => ({
    id: "preset-mac",
    name: "MacBook",
    format: "simple",
    encoding: "UTF-8",
    pathPrefix: "/Volumes/media/music",
    isDefault: true,
    ...overrides
});

/** Mount the page over a list of presets, optionally in English. */
const page = (presets: ExportPreset[] = [preset()], locale: "de" | "en" = "de") =>
    mountApp(ExportPresetsPage, { props: { presets }, locale });

describe("ExportPresetsPage", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { auth: { user: { id: "u1", name: "Ash", email: "a@b.c" } }, hasExportPresets: true } });
    });

    it("prints an empty prefix in words rather than as a blank", () => {
        const wrapper = page([preset({ name: "Auto", pathPrefix: "" })]);

        expect(wrapper.text()).toContain(translate("dashboard.presets.relativePaths"));
    });

    it("prints a real prefix as itself", () => {
        const wrapper = page([preset({ pathPrefix: "/storage/emulated/0/Music" })]);

        expect(wrapper.text()).toContain("/storage/emulated/0/Music");
        expect(wrapper.text()).not.toContain(translate("dashboard.presets.relativePaths"));
    });

    it("describes format and encoding in the export dialog's own words", () => {
        const wrapper = page([preset({ format: "extended", encoding: "Windows-1252" })]);

        expect(wrapper.text()).toContain(translate("playlists.export.formatExtended"));
        expect(wrapper.text()).toContain(translate("playlists.export.encodingWindows"));
    });

    it("renders those words in the reader's own language", () => {
        // The server sends the stored values ("simple", "UTF-8"); which words they become is
        // this page's decision, and the one thing a German assertion alone would not catch.
        const wrapper = page([preset()], "en");

        expect(wrapper.text()).toContain(translate("playlists.export.formatSimple", "en"));
        expect(wrapper.text()).not.toContain(translate("playlists.export.formatSimple", "de"));
    });

    it("marks the default row without offering to set it again", () => {
        const wrapper = page([preset({ isDefault: true })]);

        expect(wrapper.text()).toContain(translate("dashboard.presets.defaultMarker"));
        expect(wrapper.find("button.presets__marker--button").exists()).toBe(false);
    });

    it("offers the marker as a button on every other row", () => {
        const wrapper = page([preset({ isDefault: true }), preset({ id: "preset-car", name: "Auto", isDefault: false })]);

        const buttons = wrapper.findAll("button.presets__marker--button");

        expect(buttons).toHaveLength(1);
        expect(buttons[0].attributes("aria-label")).toBe(
            named("dashboard.presets.setDefaultLabel", "Auto")
        );
    });

    it("names the device in each control's accessible name", () => {
        const wrapper = page([preset({ name: "Auto" })]);

        const labels = wrapper.findAll("[aria-label]").map(node => node.attributes("aria-label"));

        expect(labels).toContain(named("dashboard.presets.editLabel", "Auto"));
        expect(labels).toContain(named("dashboard.presets.deleteLabel", "Auto"));
    });

    it("says what happens meanwhile when there are no presets", () => {
        // Not merely "nothing here": without presets the export dialog falls back to the
        // server's configured prefix, which works.
        const wrapper = page([]);

        expect(wrapper.text()).toContain(translate("dashboard.presets.empty"));
        expect(wrapper.find(".presets").exists()).toBe(false);
    });

    it("keeps the way to the create form on an empty list", () => {
        // The empty state is the one a reader most needs the button from.
        const wrapper = page([]);

        expect(wrapper.html()).toContain("/dashboard/export-presets/create");
    });
});
