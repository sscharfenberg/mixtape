import { describe, expect, it, vi } from "vitest";
import { mountApp, translate } from "Testing/mount";
import DownloadButton from "./DownloadButton.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The hero's download control.
 *
 * Three claims, and each of them is the kind a browser test would be slow to make and
 * `assertInertia` cannot make at all:
 *
 *   - it is an ANCHOR carrying the URL. That is the whole design decision (see the
 *     component banner): a <button> with a click handler would lose middle-click, "save
 *     link as" and a download that outlives the page, and an Inertia <Link> would fetch
 *     the mp3 over XHR and try to read a page out of it. A refactor that "tidied" this
 *     into a Button would break all of that silently, since the thing still looks right.
 *   - the label says WHICH file arrives — an mp3 or a zip — in the language being read.
 *     Two keys rather than one with the kind interpolated, checked against the real
 *     catalog (Testing/mount imports it).
 *   - it wears the no-halo modifier, which is what keeps the neon pool off the hero panel.
 */

/** Mount the control for a subject. */
const button = (subject: "song" | "album", locale: "de" | "en" = "de") =>
    mountApp(DownloadButton, { props: { subject, href: `/music/${subject}s/x/download` }, locale });

describe("DownloadButton", () => {
    it("is a plain link to the file, not a scripted button", () => {
        const anchor = button("song").find("a");

        expect(anchor.attributes("href")).toBe("/music/songs/x/download");
        // A hint only — the server's Content-Disposition carries the real filename, which
        // it knows and this component does not.
        expect(anchor.attributes("download")).toBeDefined();
    });

    it("says which file the reader is about to get", () => {
        expect(button("song").text()).toContain(translate("music.download.song"));
        expect(button("album").text()).toContain(translate("music.download.album"));
        expect(button("album", "en").text()).toContain(translate("music.download.album", "en"));
    });

    it("drops the halo, because it sits on the hero's own panel", () => {
        expect(button("song").find("a").classes()).toContain("btn--no-halo");
    });
});
