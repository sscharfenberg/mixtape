import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import PlayQueue from "./PlayQueue.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The panel is a thin view over usePlayerQueue (whose own spec covers the operations), so
 * what is left to prove here is the part the composable cannot: what gets DRAWN.
 *
 * Chiefly that an empty queue renders nothing at all. FullLayout keys its grid column off
 * the same `isEmpty`, so a panel that rendered an empty shell would leave a 280px hole
 * beside every page — and that is invisible in a unit test of the composable, which would
 * happily report a queue of length zero either way.
 */

/** A queue track with just enough shape to be identifiable in the DOM. */
const track = (id: string, name: string, artist: string | null = "Radiohead"): QueueTrack => ({
    id,
    name,
    artist,
    album: "The Bends",
    coverUrl: null,
    duration: 120,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

/**
 * Fill the queue, then mount the panel over it.
 *
 * Attached to the document because two of these tests are about FOCUS, and an element
 * outside the document cannot hold any — `focus()` on a detached node is a no-op and
 * `document.activeElement` stays on <body>, so the assertion would pass or fail for
 * the wrong reason.
 */
const panel = async (tracks: QueueTrack[]) => {
    if (tracks.length) usePlayerQueue().enqueue(tracks);
    const wrapper = mountApp(PlayQueue, { attachTo: document.body });
    await nextTick();

    return wrapper;
};

/** Press a key on one of a row's controls. */
const press = (target: Element, key: string, altKey = true): void => {
    target.dispatchEvent(new KeyboardEvent("keydown", { key, altKey, bubbles: true, cancelable: true }));
};

describe("PlayQueue", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        window.localStorage.clear();
    });

    it("renders nothing at all while the queue is empty", async () => {
        const wrapper = await panel([]);

        // Not an empty panel: FullLayout gives the column its 280px off the same
        // condition, so a shell here would indent every page for a queue that is not there.
        expect(wrapper.find("aside").exists()).toBe(false);
        expect(wrapper.html()).toBe("<!--v-if-->");
    });

    it("lists the queue in play order", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);

        expect(wrapper.findAll(".play-queue__name").map(node => node.text())).toStrictEqual(["Airbag", "Bones"]);
    });

    it("marks the loaded track, and only it", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);
        usePlayerQueue().jumpTo(1);
        await nextTick();

        const current = wrapper.findAll(".play-queue__row--current");
        expect(current).toHaveLength(1);
        expect(current[0].text()).toContain("Bones");
        expect(current[0].attributes("aria-current")).toBe("true");
    });

    it("scrolls the newly loaded row into view, with a row of margin published for it", async () => {
        /*
         * WIRING ONLY, and deliberately so. Whether the row ends up a row's height clear of
         * the edge is geometry, and happy-dom has no layout to measure — that half is a
         * Playwright spec. What can be proved here is the part that would break silently:
         * that moving the pointer scrolls THE ROW THAT IS NOW PLAYING rather than a stale
         * one, and that the margin the CSS reads is published before the scroll is asked for.
         */
        const scrollIntoView = vi.fn();
        vi.spyOn(Element.prototype, "scrollIntoView").mockImplementation(scrollIntoView);

        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones"), track("c", "Creep")]);
        expect(scrollIntoView).not.toHaveBeenCalled();

        usePlayerQueue().jumpTo(2);
        await nextTick();

        expect(scrollIntoView).toHaveBeenCalledTimes(1);
        // `nearest` is what makes an already-visible row stay put; the margin does the rest.
        expect(scrollIntoView).toHaveBeenCalledWith({ block: "nearest", inline: "nearest" });
        expect(scrollIntoView.mock.instances[0]).toBe(wrapper.findAll(".play-queue__row")[2].element);
        expect(wrapper.find<HTMLElement>(".play-queue__list").element.style.getPropertyValue("--queue-row-height")).
            toMatch(/^\d+px$/u);
    });

    it("loads the track whose row is clicked", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);

        await wrapper.findAll(".play-queue__load")[1].trigger("click");

        expect(usePlayerQueue().current.value?.name).toBe("Bones");
    });

    it("drops the row whose remove button is pressed", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);

        await wrapper.findAll(".play-queue__remove")[0].trigger("click");

        expect(usePlayerQueue().tracks.value.map(entry => entry.name)).toStrictEqual(["Bones"]);
    });

    it("empties the queue from the menu, and disappears with it", async () => {
        // Clearing sits behind the popover rather than on a bare trash icon in the
        // header — it is destructive, and one stray click in a 280px strip is too
        // cheap a way to lose the queue. The dialog's contents are in the DOM whether
        // it is open or not, so the test clicks the entry directly. Still matched by the
        // `--caution` variant rather than by position: clearing is the only entry left
        // now that repeat has moved to the player's settings popover, and the next verb
        // to arrive ("save queue as playlist") would otherwise silently be clicked here.
        const wrapper = await panel([track("a", "Airbag")]);

        await wrapper.find(".popover-list-item--caution").trigger("click");
        await nextTick();

        expect(wrapper.find("aside").exists()).toBe(false);
    });

    it("summarises the queue's length and running time", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);

        // 2 x 120s. The count and the clock share a line because a 280px panel has no
        // room for either beside the title.
        expect(wrapper.find(".play-queue__summary").text()).toContain("4:00");
    });

    it("gives the title no link, so aiming at it cannot navigate away", async () => {
        // It WAS a <Link> to the song's page. In a panel where every other pixel plays
        // the track, the title is the one spot a listener aims at — and it was the one
        // spot that left the page. Now it is plain text under the row's play overlay.
        // The hit area itself is layout, so that half is a Playwright spec; what this
        // guards is the regression that would re-introduce an anchor here.
        const wrapper = await panel([track("a", "Airbag")]);
        const name = wrapper.find(".play-queue__name");

        expect(name.element.tagName).toBe("SPAN");
        expect(name.attributes("href")).toBeUndefined();
        expect(wrapper.find(".play-queue__meta a").exists()).toBe(false);
    });

    it("leaves out the artist line for a track whose file carried no artist", async () => {
        const wrapper = await panel([track("a", "Airbag", null)]);

        expect(wrapper.find(".play-queue__artist").exists()).toBe(false);
    });

    it("gives every row a grip holding the cover and the drag glyph", async () => {
        // The grip is the cover WITH the glyph under it, not the glyph alone: 16px of
        // dots is too small a thing to aim a drag at, on a phone especially. The cost is
        // that the cover no longer plays the track — it belongs to the grip now.
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);
        const grip = wrapper.findAll(".play-queue__grip");

        expect(grip).toHaveLength(2);
        // `__box` rather than the <img>: these tracks carry no artwork, so CoverImage draws
        // its placeholder — the box is what it renders either way, and it is the 24px half
        // of the grip that makes the strip worth grabbing.
        expect(grip[0].find(".cover-image__box").exists()).toBe(true);
        expect(iconNames(grip[0])).toContain("drag");
        expect(grip[0].attributes("aria-label")).toBe(translate("player.queue.move").replace("{name}", "Airbag"));
        // Where the keyboard alternative is advertised: the shortcut moves this row, so
        // it is named on the control that moves it (the handler itself sits on the <li>).
        expect(grip[0].attributes("aria-keyshortcuts")).toBe("Alt+ArrowUp Alt+ArrowDown");
    });

    it("keeps the play overlay a button of its own, with nothing inside it", async () => {
        // The regression this guards: putting anything back INSIDE the overlay button.
        // It is stretched across the whole row, so a child of it would be a child of the
        // hit area — which is how the cover ended up unable to be the drag grip.
        const wrapper = await panel([track("a", "Airbag")]);
        const load = wrapper.find(".play-queue__load");

        expect(load.element.tagName).toBe("BUTTON");
        expect(load.element.childElementCount).toBe(0);
        expect(load.attributes("aria-label")).toBe(translate("player.queue.load").replace("{name}", "Airbag"));
    });

    it("moves a row with Alt+↑/↓, and keeps focus on the control that moved it", async () => {
        /*
         * The keyboard companion to the drag, end to end through the real panel — the
         * module's own spec covers the decision, but not this half, which is what makes it
         * usable twice: the v-for key carries the index, so every row in the moved range
         * is a NEW element and the node holding focus is gone after the re-render. Without
         * putting focus back, the second Alt+↓ goes to <body> and nothing happens.
         */
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones"), track("c", "Creep")]);
        const gripOf = (row: number) => wrapper.findAll(".play-queue__grip")[row].element as HTMLElement;

        gripOf(0).focus();
        press(gripOf(0), "ArrowDown");
        await nextTick();

        expect(usePlayerQueue().tracks.value.map(entry => entry.name)).toStrictEqual(["Bones", "Airbag", "Creep"]);
        expect(document.activeElement).toBe(gripOf(1));

        // …and again, from where it left off — the walk down the queue.
        press(document.activeElement!, "ArrowDown");
        await nextTick();

        expect(usePlayerQueue().tracks.value.map(entry => entry.name)).toStrictEqual(["Bones", "Creep", "Airbag"]);
        expect(document.activeElement).toBe(gripOf(2));
    });

    it("returns focus to the same KIND of control, not always the grip", async () => {
        // Alt+↑/↓ works from any of the row's three controls, so focus has to come back
        // to the one that was held — landing on the grip after pressing it from the remove
        // button would quietly move the reader's place in the row.
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);
        const remove = wrapper.findAll(".play-queue__remove")[1].element as HTMLElement;

        remove.focus();
        press(remove, "ArrowUp");
        await nextTick();

        expect(usePlayerQueue().tracks.value.map(entry => entry.name)).toStrictEqual(["Bones", "Airbag"]);
        expect(document.activeElement).toBe(wrapper.findAll(".play-queue__remove")[0].element);
    });

    it("labels the panel for assistive tech", async () => {
        const wrapper = await panel([track("a", "Airbag")]);

        expect(wrapper.find("aside").attributes("aria-label")).toBe(translate("player.queue.label"));
    });
});
