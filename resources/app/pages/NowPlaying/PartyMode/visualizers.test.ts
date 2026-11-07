import { describe, expect, it } from "vitest";
import de from "@/lang/de.json";
import en from "@/lang/en.json";
import { VISUALIZERS, pickRandom } from "./visualizers";

/*
 * The registry and random mode's pick. What would go wrong unseen:
 *
 *   - RANDOM PICKING THE PICTURE ALREADY ON SCREEN — a "change" that changes nothing, which over a
 *     long party reads as random mode being broken. And a pick that never reaches some entry means
 *     a visualiser nobody sees.
 *   - A VISUALISER WITHOUT A NAME in one language, which the picker would show as a raw key.
 *   - TWO ENTRIES SHARING AN ID, which the picker and the stored choice could not tell apart.
 */

describe("pickRandom", () => {
    it("never picks the visualiser already on screen", () => {
        for (let current = 0; current < 7; current++) {
            for (let roll = 0; roll < 1; roll += 0.01) {
                expect(pickRandom(7, current, () => roll)).not.toBe(current);
            }
        }
    });

    it("reaches every other visualiser", () => {
        const reached = new Set<number>();
        for (let roll = 0; roll < 1; roll += 0.01) reached.add(pickRandom(7, 3, () => roll));

        expect([...reached].sort()).toEqual([0, 1, 2, 4, 5, 6]);
    });

    it("picks from all of them when nothing is showing yet", () => {
        const reached = new Set<number>();
        for (let roll = 0; roll < 1; roll += 0.01) reached.add(pickRandom(7, -1, () => roll));

        expect(reached.size).toBe(7);
    });

    it("has nowhere else to go with a single visualiser", () => {
        expect(pickRandom(1, 0)).toBe(0);
    });
});

describe("the registry", () => {
    it("gives every visualiser a unique id", () => {
        const ids = VISUALIZERS.map(entry => entry.id);

        expect(new Set(ids).size).toBe(ids.length);
    });

    it("names every visualiser in both languages", () => {
        for (const catalog of [de, en]) {
            const names = catalog.nowPlaying.party.visualizers as Record<string, string>;
            for (const { id } of VISUALIZERS) expect(names[id], id).toBeTruthy();
        }
    });
});
