import { describe, expect, it } from "vitest";
import { mountApp, translate } from "Testing/mount";
import CoverImage from "./CoverImage.vue";

/*
 * CoverImage exists to hold three decisions that used to be copy-pasted into every
 * listing: the size triple, the MISSING case, and the FAILED case.
 *
 * The failed case is the one that earns the component. `coverUrl` rests on scan-time
 * flags, so a file re-tagged or deleted since the last `app:update` is still advertised
 * and then 404s. And because Vue REUSES a component instance when a keyed list
 * re-renders, the failure flag has to reset when the src changes — otherwise a row that
 * once 404'd keeps showing the placeholder over a different album's perfectly good
 * artwork. That is the regression the last test here guards.
 */

/** Mount a cover with the given props. */
const cover = (props: Record<string, unknown>) => mountApp(CoverImage, { props: { title: "OK Computer", ...props } });

describe("CoverImage", () => {
    it("renders the artwork when there is a URL", () => {
        const wrapper = cover({ src: "/covers/ok-computer.jpg" });

        expect(wrapper.find("img").attributes("src")).toBe("/covers/ok-computer.jpg");
    });

    it("loads artwork lazily, so a hidden layout's copy is never fetched", () => {
        // Discography renders the row and card artwork together, one of them display:none.
        expect(cover({ src: "/covers/x.jpg" }).find("img").attributes("loading")).toBe("lazy");
    });

    it("names the picture for assistive tech by default", () => {
        expect(cover({ src: "/covers/x.jpg" }).find("img").attributes("alt")).toBe("OK Computer");
    });

    it("renders decorative artwork with an empty alt, so a row is not read twice", () => {
        expect(cover({ src: "/covers/x.jpg", decorative: true }).find("img").attributes("alt")).toBe("");
    });

    it.each([
        ["tiny", 1],
        ["small", 2],
        ["large", 3],
        ["xlarge", 5]
    ])("carries the %s size onto the image and its placeholder glyph", (size, iconStep) => {
        const withArt = cover({ src: "/covers/x.jpg", size });
        expect(withArt.find("img").classes()).toContain(`cover-image--${size}`);

        // The glyph size has to sit comfortably inside the cover size.
        const iconClasses = ["tiny", "small", "medium", "large", "xlarge", "max"];
        const withoutArt = cover({ src: null, size });
        expect(withoutArt.classes()).toContain(iconClasses[iconStep]);
    });

    it("defaults to the small size", () => {
        expect(cover({ src: "/covers/x.jpg" }).find("img").classes()).toContain("cover-image--small");
    });

    describe("when the file carries no artwork", () => {
        it("draws the placeholder glyph instead of pointing an img at a 404", () => {
            const wrapper = cover({ src: null });

            expect(wrapper.find("img").exists()).toBe(false);
            expect(wrapper.classes()).toContain("cover-image__placeholder");
        });

        it("labels the placeholder as an image when it is not decorative", () => {
            const wrapper = cover({ src: null });

            expect(wrapper.attributes("role")).toBe("img");
            expect(wrapper.attributes("aria-label")).toBe(translate("components.cover.empty"));
        });

        it("hides a decorative placeholder from assistive tech entirely", () => {
            const wrapper = cover({ src: null, decorative: true });

            expect(wrapper.attributes("aria-hidden")).toBe("true");
            expect(wrapper.attributes("role")).toBeUndefined();
            expect(wrapper.attributes("aria-label")).toBeUndefined();
        });
    });

    describe("when the advertised artwork 404s", () => {
        it("falls back to the placeholder", async () => {
            const wrapper = cover({ src: "/covers/stale.jpg" });

            await wrapper.find("img").trigger("error");

            expect(wrapper.find("img").exists()).toBe(false);
            expect(wrapper.classes()).toContain("cover-image__placeholder");
        });

        it("keeps the failure to itself, so one bad row does not blank its neighbours", async () => {
            const failing = cover({ src: "/covers/stale.jpg" });
            const healthy = cover({ src: "/covers/fine.jpg" });

            await failing.find("img").trigger("error");

            expect(healthy.find("img").exists()).toBe(true);
        });

        it("recovers when the instance is handed a different album's artwork", async () => {
            // Vue reuses the instance when a keyed list re-orders; without the reset the
            // placeholder would stick to whatever row now occupies this slot.
            const wrapper = cover({ src: "/covers/stale.jpg" });
            await wrapper.find("img").trigger("error");
            expect(wrapper.find("img").exists()).toBe(false);

            await wrapper.setProps({ src: "/covers/different.jpg" });

            expect(wrapper.find("img").attributes("src")).toBe("/covers/different.jpg");
        });
    });
});
