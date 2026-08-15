import { describe, expect, it } from "vitest";
import de from "./de.json";
import en from "./en.json";

/*
 * The two client catalogs, held to the same shape.
 *
 * NOTHING ELSE CHECKS THIS. `resources/types/i18n.d.ts` derives `MessageSchema` from `de.json`
 * alone, which is what makes German keys type-safe — and English ones invisible. A key that is
 * dropped from `en.json` type-checks clean, passes every other suite, and reaches a reader of the
 * English UI as a raw dot-path on the page.
 *
 * Asserted on the FLATTENED paths rather than by walking the objects, so the failure message
 * names the key that is missing instead of the branch it is missing from.
 */

/** Every leaf path in a catalog, dot-joined, sorted. */
const paths = (node: unknown, prefix = ""): string[] => {
    if (typeof node !== "object" || node === null) return [prefix];

    return Object.entries(node as Record<string, unknown>)
        .flatMap(([key, value]) => paths(value, prefix === "" ? key : `${prefix}.${key}`))
        .sort();
};

/** Every leaf path mapped to its plural-branch count, which vue-i18n selects between. */
const branches = (node: unknown, prefix = ""): Record<string, number> => {
    if (typeof node === "string") return { [prefix]: node.split("|").length };
    if (typeof node !== "object" || node === null) return {};

    return Object.entries(node as Record<string, unknown>).reduce<Record<string, number>>(
        (all, [key, value]) => ({ ...all, ...branches(value, prefix === "" ? key : `${prefix}.${key}`) }),
        {}
    );
};

/** Every `{placeholder}` a string interpolates, sorted, so two catalogs can be compared. */
const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/gu)].map(match => match[1]).sort();

/** Leaf path → string, for the per-key comparisons below. */
const strings = (node: unknown, prefix = ""): Record<string, string> => {
    if (typeof node === "string") return { [prefix]: node };
    if (typeof node !== "object" || node === null) return {};

    return Object.entries(node as Record<string, unknown>).reduce<Record<string, string>>(
        (all, [key, value]) => ({ ...all, ...strings(value, prefix === "" ? key : `${prefix}.${key}`) }),
        {}
    );
};

describe("the client catalogs", () => {
    it("offer exactly the same keys in both languages", () => {
        expect(paths(en)).toStrictEqual(paths(de));
    });

    it("give every key the same number of plural branches", () => {
        /*
         * A branch-count mismatch is the quietest way this can break: vue-i18n selects a branch by
         * count, so a German key with two and an English key with one renders the whole
         * "1 track | {n} tracks" string verbatim, pipe and all, rather than failing.
         */
        expect(branches(en)).toStrictEqual(branches(de));
    });

    it("interpolate the same placeholders on both sides of every key", () => {
        /*
         * PER PLURAL BRANCH, not per whole string: a placeholder present in one branch and absent
         * from the other still renders a literal `{n}` for whichever count selects the bad branch,
         * and comparing the joined string would miss it.
         */
        const deStrings = strings(de);
        const enStrings = strings(en);

        const mismatched = Object.keys(deStrings).filter(key => {
            const deBranches = deStrings[key].split("|");
            const enBranches = (enStrings[key] ?? "").split("|");

            return deBranches.some(
                (branch, index) => placeholders(branch).join() !== placeholders(enBranches[index] ?? "").join()
            );
        });

        expect(mismatched).toStrictEqual([]);
    });
});
