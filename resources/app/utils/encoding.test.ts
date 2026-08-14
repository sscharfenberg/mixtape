import { describe, expect, it } from "vitest";
import { isWindows1252, unencodableInWindows1252 } from "./encoding";

/*
 * Which characters a Windows-1252 .m3u can carry.
 *
 * WHY IT IS WORTH A TEST OF ITS OWN: the answer decides whether the export modal warns, and a
 * wrong answer is invisible in both directions — a false negative sends the reader to the car
 * with dead lines, a false positive nags about a file that would have played. Neither shows up
 * on screen.
 *
 * THE TABLE WAS CROSS-CHECKED AGAINST THE SERVER, once, over the whole real collection: PHP's
 * `mb_convert_encoding` round-trip and this function each flagged 89 of 12,074 paths, with
 * zero disagreements. That is the guarantee this file cannot give on its own —
 * what it pins instead is the boundaries, so a later "simplification" to a plain Latin-1 test
 * fails here rather than silently in the car.
 */

describe("unencodableInWindows1252", () => {
    describe("what survives", () => {
        it("passes plain ASCII", () => {
            expect(isWindows1252("Radiohead/OK Computer/01 Airbag.mp3")).toBe(true);
        });

        it("passes the Latin-1 upper half, which is most of Western Europe", () => {
            // Umlauts, accents, ñ, ç, and the Scandinavian and Icelandic letters all fit — this
            // is why the encoding is usable at all.
            expect(isWindows1252("Motörhead/Björk/Sigur Rós/Ágætis byrjun/Þeir/Café/Señor")).toBe(true);
        });

        it("passes the 27 characters Windows-1252 puts where Latin-1 keeps controls", () => {
            // The block a naive "is it Latin-1?" test gets wrong: a curly quote, an em dash, an
            // ellipsis and a euro sign all survive, and a Latin-1 check would flag every one.
            expect(unencodableInWindows1252("€‚ƒ„…†‡ˆ‰Š‹ŒŽ''\"\"•–—˜™š›œžŸ")).toStrictEqual([]);
        });
    });

    describe("what does not", () => {
        it("flags Polish, Czech and the other Latin-Extended letters", () => {
            // The real collection's biggest offender after CJK: 30 paths with ł alone.
            expect(unencodableInWindows1252("Mgła")).toStrictEqual(["ł"]);
            expect(unencodableInWindows1252("Dvořák")).toStrictEqual(["ř"]);
            expect(unencodableInWindows1252("Gjǫll")).toStrictEqual(["ǫ"]);
        });

        it("flags CJK, which is what most of the affected collection is", () => {
            expect(unencodableInWindows1252("暴君")).toStrictEqual(["暴", "君"]);
        });

        it("flags Greek, including the capitals a title can be made of", () => {
            // ΚΕΦΑΛΗΞΘ — an Aphex Twin record, nine tracks of it.
            expect(unencodableInWindows1252("ΚΕΦΑΛΗΞΘ")).toHaveLength(8);
        });

        it("flags the symbols that turn up in real album titles", () => {
            // F♯ A♯ ∞, and Undertale's ♫.
            expect(unencodableInWindows1252("F♯ A♯ ∞")).toStrictEqual(["♯", "∞"]);
            expect(unencodableInWindows1252("Uwa!! So Temperate♫")).toStrictEqual(["♫"]);
        });

        it("flags a DECOMPOSED accent even though the composed one is fine", () => {
            /*
             * The subtlest case in the collection, and the one worth keeping: macOS stores
             * filenames in NFD, so `Chèvre` can arrive as "e" plus a combining grave. The
             * composed è is in Windows-1252 and the pair is not — so the same word passes or
             * fails on its normal form alone.
             */
            expect(isWindows1252("Kung-Fu Chèvre")).toBe(true);
            expect(unencodableInWindows1252("Kung-Fu Chèvre")).toStrictEqual(["̀"]);
        });

        it("flags private-use characters, which look like nothing at all", () => {
            // Ten paths in the real collection carry these — a tagger swapping out characters a
            // filename may not hold. They are invisible in Finder, which is the point.
            expect(unencodableInWindows1252("Why")).toStrictEqual([""]);
        });

        it("flags an exotic space that reads as an ordinary one", () => {
            expect(unencodableInWindows1252("28,340 Dead")).toStrictEqual([" "]);
        });
    });

    describe("what it reports", () => {
        it("lists each offender once, however often it repeats", () => {
            // The warning prints these, and one character repeated over ten paths should be
            // said once.
            expect(unencodableInWindows1252("łłłał")).toStrictEqual(["ł"]);
        });

        it("keeps them in the order they first appear", () => {
            expect(unencodableInWindows1252("ałb暴cł")).toStrictEqual(["ł", "暴"]);
        });

        it("walks CODE POINTS, so an astral character is one offender and not two halves", () => {
            // Indexing a string splits anything outside the BMP into surrogates, which would be
            // reported as a pair of meaningless characters.
            const found = unencodableInWindows1252("track 🎵.mp3");

            expect(found).toStrictEqual(["🎵"]);
            expect(found[0]).toHaveLength(2);
        });

        it("says nothing about an empty string", () => {
            expect(unencodableInWindows1252("")).toStrictEqual([]);
            expect(isWindows1252("")).toBe(true);
        });
    });
});
