import { describe, expect, it } from "vitest";
import { mountApp, translate } from "Testing/mount";
import CoverImage from "./CoverImage.vue";

/*
 * CoverImage exists to hold three decisions that would otherwise be copy-pasted into every
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
        // `xsmall` shares tiny's glyph step: at 32px the next one up leaves four pixels of
        // margin and reads as a note crammed into a square.
        ["xsmall", 1],
        ["small", 2],
        ["large", 3],
        ["xlarge", 5]
    ])("carries the %s size onto the image and its placeholder glyph", (size, iconStep) => {
        const withArt = cover({ src: "/covers/x.jpg", size });
        expect(withArt.find("img").classes()).toContain(`cover-image--${size}`);

        // The glyph size has to sit comfortably inside the cover size — and it is the
        // GLYPH that carries it, not the box around it: the box is the cover's square,
        // the glyph is deliberately smaller and centred in it.
        const iconClasses = ["tiny", "small", "medium", "large", "xlarge", "max"];
        const withoutArt = cover({ src: null, size });
        expect(withoutArt.find(".cover-image__placeholder").classes()).toContain(iconClasses[iconStep]);
        expect(withoutArt.classes()).toContain(`cover-image__box--${size}`);
    });

    it("defaults to the small size", () => {
        expect(cover({ src: "/covers/x.jpg" }).find("img").classes()).toContain("cover-image--small");
    });

    describe("when the file carries no artwork", () => {
        it("draws the placeholder glyph instead of pointing an img at a 404", () => {
            const wrapper = cover({ src: null });

            expect(wrapper.find("img").exists()).toBe(false);
            expect(wrapper.find(".cover-image__placeholder").exists()).toBe(true);
        });

        it("gives the placeholder the same square the image would have had", () => {
            // Otherwise the glyph IS the box, and it is far smaller than the cover it
            // stands in for: a list mixing tagged and untagged files gets rows of two
            // heights, and a flex row of cover-then-text starts its text in a different
            // place per row.
            expect(cover({ src: null, size: "small" }).classes()).toContain("cover-image__box--small");
        });

        it("keeps the hero size out of the flow, since its container decides the square", () => {
            // `display: contents` at xlarge — HeroSection draws its own dashed square
            // around whatever is not an <img>, and a box here would be a second
            // declaration of the one square.
            expect(cover({ src: null, size: "xlarge" }).classes()).toContain("cover-image__box--xlarge");
        });

        it("labels the placeholder as an image when it is not decorative", () => {
            const glyph = cover({ src: null }).find(".cover-image__placeholder");

            expect(glyph.attributes("role")).toBe("img");
            expect(glyph.attributes("aria-label")).toBe(translate("components.cover.empty"));
        });

        it("hides a decorative placeholder from assistive tech entirely", () => {
            const glyph = cover({ src: null, decorative: true }).find(".cover-image__placeholder");

            expect(glyph.attributes("aria-hidden")).toBe("true");
            expect(glyph.attributes("role")).toBeUndefined();
            expect(glyph.attributes("aria-label")).toBeUndefined();
        });
    });

    describe("when the advertised artwork 404s", () => {
        it("falls back to the placeholder", async () => {
            const wrapper = cover({ src: "/covers/stale.jpg" });

            await wrapper.find("img").trigger("error");

            expect(wrapper.find("img").exists()).toBe(false);
            expect(wrapper.find(".cover-image__placeholder").exists()).toBe(true);
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
