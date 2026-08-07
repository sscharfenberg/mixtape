import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetInertia, routerCalls } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import WidgetFooter from "./WidgetFooter.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The refresh button, and the request it makes. Two things here are easy to write
 * plausibly and wrong:
 *
 *   - the reload must be PARTIAL — `only: [<the widget's prop>]`. Drop that option and the
 *     button still works: the page reloads, the card updates, and a reader notices nothing
 *     except that pressing "reshuffle" on one card silently re-ran every other widget's
 *     query and reshuffled them too. Nothing fails; the whole page just churns.
 *   - the in-flight state has to travel BOTH ways. Local `refreshing` disables the button
 *     and spins the glyph, the emitted event is what raises the parent's skeleton, and a
 *     path that sets one without the other leaves either a dead button or a card stuck on
 *     placeholders. `onStart`/`onFinish` are captured from the recorded call and fired by
 *     hand, since there is no network here.
 *
 * The button is wrapped in the Tooltip component rather than carrying `v-tooltip` itself,
 * and that is deliberate: a disabled control emits no mouse events, so the hint would
 * vanish exactly when the reader is most likely to ask what the spinning glyph means.
 */

/** Mount the strip. */
const footer = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) =>
    mountApp(WidgetFooter, { props, slots });

/** The options bag of the most recent reload the button asked for. (`.at()` is out — lib: ES2020.) */
const lastReload = (): Record<string, unknown> => routerCalls[routerCalls.length - 1].options!;

describe("WidgetFooter", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("renders nothing but the slot when the widget has no refreshable prop", () => {
        const wrapper = footer({}, { default: "<a href='/music/albums'>Alle Alben</a>" });

        expect(wrapper.find(".widget__refresh").exists()).toBe(false);
        expect(wrapper.find("a").text()).toBe("Alle Alben");
    });

    it("reloads ONLY its own prop, so pressing one card does not re-roll every other", async () => {
        const wrapper = footer({ refresh: "albums" });

        await wrapper.find(".widget__refresh").trigger("click");

        expect(routerCalls).toHaveLength(1);
        expect(routerCalls[0].method).toBe("reload");
        expect(lastReload().only).toStrictEqual(["albums"]);
    });

    it("disables itself and spins its glyph while the reload is in flight", async () => {
        const wrapper = footer({ refresh: "artists" });
        const button = wrapper.find(".widget__refresh");

        await button.trigger("click");
        expect(button.attributes("disabled")).toBeUndefined();

        (lastReload().onStart as () => void)();
        await nextTick();
        expect(button.attributes("disabled")).toBeDefined();
        expect(wrapper.findComponent({ name: "Icon" }).props("rotate")).toBe(true);

        (lastReload().onFinish as () => void)();
        await nextTick();
        expect(button.attributes("disabled")).toBeUndefined();
        expect(wrapper.findComponent({ name: "Icon" }).props("rotate")).toBe(false);
    });

    it("tells the parent when to raise its skeleton, and when to drop it again", async () => {
        const wrapper = footer({ refresh: "genres" });

        await wrapper.find(".widget__refresh").trigger("click");
        (lastReload().onStart as () => void)();
        (lastReload().onFinish as () => void)();

        expect(wrapper.emitted("refreshing")).toStrictEqual([[true], [false]]);
    });

    it("names the icon-only button, and shows the refresh glyph", () => {
        const wrapper = footer({ refresh: "songs" });

        expect(wrapper.find(".widget__refresh").attributes("aria-label")).toBe(translate("music.refresh"));
        expect(iconNames(wrapper)).toStrictEqual(["refresh"]);
    });

    it("hangs the hint off an enabled wrapper, because a disabled button emits no hover", () => {
        // The Tooltip component wraps the button; `v-tooltip` on the button itself would go
        // silent the moment the refresh disables it.
        const wrapper = footer({ refresh: "songs" });
        const tip = wrapper.find(".tooltip");

        expect(tip.exists()).toBe(true);
        expect(tip.find(".widget__refresh").exists()).toBe(true);
    });
});
