import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { specStorageState } from "../support/environment";

/*
 * Export presets, from the dashboard to the export dialog they exist for.
 *
 * The server side is pinned by tests/Feature/Dashboard/ExportPresets — what validates, what is
 * stored, who may see whose, and the succession when the default is deleted — and the list's own
 * rendering by ExportPresetsPage.test.ts, including the empty state and the words an empty prefix
 * is printed as. Neither is repeated here. What only a browser can answer:
 *
 *   - THE WHOLE JOURNEY AS ONE MOVE. The point of the feature is not that a row exists, it is
 *     that a preset made on one page changes what a DIALOG on another page opens with. Both
 *     halves are proven separately; that they meet is not, and could not be — the dialog reads a
 *     prop the presets page never sees.
 *   - THE PICKER IS A REAL CONTROL. Select is a button plus an ARIA listbox in the browser's top
 *     layer, opened by the Popover API and positioned by CSS anchors. happy-dom renders the
 *     markup, but whether a click on an option in a teleported panel inside a MODAL actually
 *     reaches the handler is an engine question.
 *   - THE DEFAULT MARKER MOVING WITHOUT A RELOAD. The press is an Inertia PATCH with
 *     `preserveScroll`, so what a reader sees is one row's marker going out and another's coming
 *     in, in place.
 *   - THE DOWNLOAD ITSELF. The dialog hands a URL to the browser and gets out of the way, so
 *     whether a file actually arrives — and whether it is one .m3u or the .zip that "export all"
 *     promises — is a question only an engine with a download manager can answer.
 *
 * THE TWO EXPORT JOURNEYS FROM THE LISTING ARE HERE rather than in playlists.spec.ts, and that
 * is a deliberate placement: they need playlists of their own, and that file's drag test computes
 * pointer coordinates from its own rows — extra rows above them are what its banner records
 * breaking it. This file already builds a playlist for the dialog, and owns its account.
 *
 * EVERY TEST CREATES WHAT IT NEEDS, the rule playlists.spec.ts records twice over:
 * `reuseExistingServer` keeps data alive between runs, so every name below carries a
 * per-invocation stamp, and no test opens a row another test made.
 *
 * ITS OWN ACCOUNT AND `mode: "serial"`, both halves. The account keeps its presets out of every
 * other spec's export dialog; serial keeps its own tests from racing each other on one session,
 * which is what eats a flash (Laravel writes the session whole and does not lock it). Either
 * alone leaves the other failure in place.
 */

test.describe.configure({ mode: "serial" });
test.use({ storageState: specStorageState("presets") });

/** Per-invocation stamp, so a kept database cannot collide with an earlier run. */
const STAMP = Date.now().toString(36);

/** A preset name nothing else can have made. */
const named = (label: string): string => `${label}-${STAMP}`;

/** The row for one preset, found by the device name it carries. */
const row = (page: Page, name: string) => page.locator(".presets__row").filter({ hasText: name });

/**
 * The INERT marker on the row that holds the default.
 *
 * Addressed as an element rather than by its text, and that is not fussiness: the marker reads
 * "Standard" and the button offering it on every other row reads "Als Standard verwenden", so a
 * text assertion is true of both and would pass on a page where the flag had not moved at all.
 * The holder renders a <span>, everyone else a <button> — which is the actual distinction.
 */
const marker = (page: Page, name: string) => row(page, name).locator("span.presets__marker");

/** …and the button that offers it, on every row that does not hold it. */
const offer = (page: Page, name: string) => row(page, name).locator("button.presets__marker--button");

/**
 * Fill in the preset form and save.
 *
 * The two radio groups are addressed by their VALUE rather than by their label text: the labels
 * are shared with the export dialog and read as prose ("einfache .m3u"), while the values are the
 * contract the server validates against.
 */
const fillForm = async (page: Page, fields: { name: string; format: string; encoding: string; prefix: string }) => {
    await page.locator("#name").fill(fields.name);
    // THE LABEL, NOT THE INPUT. RadioButton clips its <input> so the styled control can stand in
    // its place, and Playwright refuses to click something with no box — the trap shortcuts.spec
    // and player.spec both record. The `for` attribute is the same `${name}_${value}` pair the
    // component builds, which is why the value is still what addresses the option.
    await page.locator(`label[for="format_${fields.format}"]`).click();
    await page.locator(`label[for="encoding_${fields.encoding}"]`).click();
    await page.locator("#path_prefix").fill(fields.prefix);
    await page.getByRole("button", { name: /voreinstellung anlegen|änderungen speichern/iu }).click();
};

/**
 * The playlist this file exports from.
 *
 * IT HAS TO MAKE ITS OWN. Playlists are private per owner and the seeder gives none to this
 * spec's account, so `/playlists` is empty for it — an export dialog reached through somebody
 * else's playlist would 404 on the way. Empty is fine: the dialog is unconditional, and what is
 * under test is what the FIELDS open with rather than what the file contains.
 */
const PLAYLIST = named("Exportliste");

/** Create that playlist, unless an earlier test in this serial file already did. */
const ensurePlaylist = async (page: Page): Promise<void> => {
    await page.goto("/playlists");

    if ((await page.locator("li.playlist", { hasText: PLAYLIST }).count()) > 0) return;

    await page.goto("/playlists/create");
    await page.locator("#name").fill(PLAYLIST);
    await page.getByRole("button", { name: /wiedergabeliste anlegen/iu }).click();
    await page.waitForURL(/\/playlists$/u);
};

/** Open the export dialog on the first playlist this account can see. */
const openExportDialog = async (page: Page): Promise<void> => {
    await ensurePlaylist(page);

    await page.locator("li.playlist", { hasText: PLAYLIST }).locator("a.playlist__link").click();
    await page.waitForURL(/\/playlists\/[0-9a-f-]{36}$/u);
    await page.getByRole("button", { name: /playlist-datei exportieren/iu }).click();
    await expect(page.locator("#playlist-export-form")).toBeVisible();
};

test.describe("export presets", () => {
    test("creates a preset from the dashboard and lands back on the list", async ({ page }) => {
        const name = named("MacBook");

        await page.goto("/dashboard/export-presets");
        await page.getByRole("link", { name: /neue voreinstellung/iu }).click();
        await expect(page).toHaveURL(/\/dashboard\/export-presets\/create/u);

        await fillForm(page, {
            name,
            format: "extended",
            encoding: "UTF-8",
            prefix: "/Volumes/media/music"
        });

        await expect(page).toHaveURL(/\/dashboard\/export-presets$/u);
        await expect(row(page, name)).toBeVisible();
        // The first preset takes the default flag, which is what makes the export dialog open on
        // it a test later — a reader with one preset has no choice to make.
        await expect(marker(page, name)).toBeVisible();
    });

    test("an empty prefix survives the round trip and is shown in words", async ({ page }) => {
        // The car case, and the one value `ConvertEmptyStringsToNull` turns into null on the way
        // in: the field is cleared, so what comes back has to be '' rather than a rejected form
        // or the server's own prefix.
        const name = named("Auto");

        await page.goto("/dashboard/export-presets/create");
        await fillForm(page, { name, format: "simple", encoding: "Windows-1252", prefix: "" });

        await expect(page).toHaveURL(/\/dashboard\/export-presets$/u);
        await expect(row(page, name)).toContainText("relative Pfade");
    });

    test("moves the default marker in place, without a reload", async ({ page }) => {
        const auto = named("Auto");
        const mac = named("MacBook");

        await page.goto("/dashboard/export-presets");
        await expect(marker(page, mac)).toBeVisible();

        await offer(page, auto).click();

        // The marker moves both ways: it is not enough that the new row gains it, since two
        // defaults look exactly like one until the export dialog picks the wrong one.
        await expect(marker(page, auto)).toBeVisible();
        await expect(marker(page, mac)).toHaveCount(0);
        await expect(offer(page, mac)).toBeVisible();
    });

    test("the export dialog opens on the default preset and fills every field from it", async ({ page }) => {
        // THE JOURNEY THIS FEATURE IS FOR. `Auto` is the default by the test above: a simple
        // Windows-1252 list with no prefix at all.
        await openExportDialog(page);

        await expect(page.locator(".form-select__button")).toContainText(named("Auto"));
        await expect(page.locator('input[name="encoding"][value="Windows-1252"]')).toBeChecked();
        await expect(page.locator("#export-prefix")).toHaveValue("");
    });

    test("picking another preset in the dialog rewrites all three fields", async ({ page }) => {
        await openExportDialog(page);

        // A real click into the listbox, which is the half happy-dom cannot answer: the panel is
        // in the browser's top layer, inside a modal that is itself teleported.
        await page.locator(".form-select__button").click();
        await page.getByRole("option", { name: named("MacBook") }).click();

        await expect(page.locator('input[name="format"][value="extended"]')).toBeChecked();
        await expect(page.locator('input[name="encoding"][value="UTF-8"]')).toBeChecked();
        await expect(page.locator("#export-prefix")).toHaveValue("/Volumes/media/music");
    });

    test("editing a field stops the dialog claiming the preset", async ({ page }) => {
        // A dialog reading "MacBook" while showing a different path is worse than one claiming
        // nothing — and the picker is derived, so it has to let go the moment a field moves.
        await openExportDialog(page);

        await page.locator(".form-select__button").click();
        await page.getByRole("option", { name: named("MacBook") }).click();
        await expect(page.locator(".form-select__button")).toContainText(named("MacBook"));

        await page.locator("#export-prefix").fill("/somewhere/else");

        await expect(page.locator(".form-select__button")).not.toContainText(named("MacBook"));
    });

    test("deleting the default passes the marker to the survivor", async ({ page }) => {
        const auto = named("Auto");
        const mac = named("MacBook");

        await page.goto("/dashboard/export-presets");
        await row(page, auto).getByRole("button", { name: /löschen/iu }).click();

        // Confirmed, unlike moving the default: this throws away values the reader typed.
        await page.getByRole("button", { name: /endgültig löschen/iu }).click();

        await expect(row(page, auto)).toHaveCount(0);
        // The reader is never left holding presets and no default — an export dialog that had
        // quietly gone back to the server's prefix, with nothing on any page to say why.
        await expect(marker(page, mac)).toBeVisible();
    });

    test("exports one playlist from its row menu on the listing", async ({ page }) => {
        // The dialog the listing raises is the one a playlist's own page raises — what differs
        // is where it submits, and only a browser can prove the whole press-to-file journey.
        await ensurePlaylist(page);

        await page.locator("li.playlist", { hasText: PLAYLIST }).getByRole("button", { name: /menü|aktionen/iu }).click();
        await page.getByRole("button", { name: /playlist-datei exportieren/iu }).click();
        await expect(page.locator("#playlist-export-form")).toBeVisible();

        const download = page.waitForEvent("download");
        await page.getByRole("button", { name: /\.m3u herunterladen/iu }).click();

        expect((await download).suggestedFilename()).toMatch(/\.m3u$/u);
    });

    test("exports every playlist as one .zip", async ({ page }) => {
        /*
         * A ZIP RATHER THAN N DOWNLOADS, which is the constraint the feature is shaped by: a
         * page gets one navigation, and Chrome asks "allow multiple downloads?" after the first
         * — a refusal then loses every file after it, silently. One archive is one download.
         */
        await ensurePlaylist(page);

        // A second playlist, because "export all" is not offered for one — it would hand over an
        // archive holding the file the row menu hands over directly.
        await page.goto("/playlists/create");
        await page.locator("#name").fill(`${PLAYLIST}-zwei`);
        await page.getByRole("button", { name: /wiedergabeliste anlegen/iu }).click();
        await page.waitForURL(/\/playlists$/u);

        await page.getByRole("button", { name: /alle exportieren/iu }).click();
        await expect(page.locator("#playlist-export-form")).toBeVisible();

        const download = page.waitForEvent("download");
        await page.getByRole("button", { name: /zip herunterladen/iu }).click();

        expect((await download).suggestedFilename()).toBe("playlists.zip");
    });

    test("the user menu offers the way back once a preset exists", async ({ page }) => {
        // Gated on `hasExportPresets`, which this account now satisfies. The dashboard's own
        // section is ungated and is what a reader with none would meet instead.
        await page.goto("/dashboard");
        await page.getByRole("button", { name: /benutzermenü öffnen/iu }).click();

        await page.getByRole("link", { name: /export-voreinstellungen/iu }).click();

        await expect(page).toHaveURL(/\/dashboard\/export-presets$/u);
    });
});
