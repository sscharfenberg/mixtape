/******************************************************************************
 * ogImage
 * Renders `public/og/mixtape.png` — the 1200×630 card a link to this app shows when it is
 * pasted somewhere that has no cover of its own to display: an invite, and every page a
 * crawler is redirected to (App\Services\Meta\SocialCards).
 *
 * IT IS A SCREENSHOT, NOT A DRAWING, and that is the whole reason it can be trusted. The card
 * has to look like MixTape, and the things that make it look like MixTape are already in this
 * repo as the app's own assets: `logo.svg`, the Google Sans woff2 files, and the retro palette
 * in the SCSS token tree. Rasterising an SVG through a library means restating each of those —
 * the woff2 in particular cannot be handed to librsvg at all, so the wordmark would silently
 * come out in whatever fallback the machine had. Chromium is already a dev dependency for the
 * E2E suite, it eats woff2, and it lays text out exactly as the site does.
 *
 * The font and the logo are INLINED as data URIs rather than served, so this needs no dev
 * server and no network: the page is a string, and `file://` origin rules never come into it.
 *
 * NOT PART OF `npm run build`. The output is committed, because it changes roughly never and
 * a build should not depend on a browser. Run it by hand (`npm run og`) after touching the
 * logo, the wordmark or the palette. SocialCards checks the file exists before pointing
 * `og:image` at it, so a checkout that has never run this simply gets a text-only card rather
 * than a broken image.
 *****************************************************************************/
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

/** Where the card lands. `public/` so it is served as a plain static file, with no PHP in the way. */
const OUTPUT = resolve(root, "public/og/mixtape.png");

/**
 * The card's pixel size.
 *
 * 1200×630 is the ratio every platform crops to (1.91:1) at the resolution they upscale from,
 * so anything smaller is blurry on a retina screen and anything larger is discarded bandwidth.
 */
const WIDTH = 1200;
const HEIGHT = 630;

/** Read a file as a data URI, so the page needs no server and no network. */
async function dataUri(path: string, mime: string): Promise<string> {
    return `data:${mime};base64,${(await readFile(resolve(root, path))).toString("base64")}`;
}

/**
 * The card itself.
 *
 * THE COLOURS ARE THE PALETTE'S, copied deliberately rather than imported: this file cannot
 * `@use` SCSS, and a Sass compile step to read three hex values would be more machinery than
 * the values are worth. They are the dark side of `$retro` c1 / c2 / c4 and the deep ground
 * the app's dark theme uses — see `styles/abstracts/colors/_global-color-tokens.scss`, which
 * is where a change to them belongs first.
 *
 * The tape reel glyph is the app's own logo, at the size it can carry: it is the mark people
 * recognise in the header, and a card is looked at for about a second.
 */
function markup(logo: string, font: string): string {
    return `<!doctype html>
<meta charset="utf-8" />
<style>
  @font-face {
    font-family: "Google Sans";
    font-weight: 700;
    src: url("${font}") format("woff2");
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    width: ${WIDTH}px;
    height: ${HEIGHT}px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 44px;
    /* The dark theme's ground, with the neon blue bled up from the bottom the way the
       glowing headline sits on the page. */
    background:
      radial-gradient(120% 90% at 50% 118%, #00a1ef44 0%, #00a1ef00 62%),
      linear-gradient(170deg, #02233e 0%, #032d50 100%);
    color: #f4f7fb;
    font-family: "Google Sans", sans-serif;
    font-weight: 700;
  }
  img { width: 320px; }
  h1 {
    font-size: 92px;
    letter-spacing: -0.02em;
    /* The wordmark's own two-tone: the app draws "Mix" plain and "Tape" in the neon. */
    color: #f4f7fb;
  }
  h1 span { color: #00a1ef; }
  p {
    font-size: 32px;
    font-weight: 400;
    color: #9fb6cc;
    letter-spacing: 0.06em;
  }
</style>
<body>
  <img src="${logo}" alt="" />
  <h1>Mix<span>Tape</span></h1>
  <p>Eine private Musiksammlung</p>
</body>`;
}

/** Render the card and write it out. */
async function main(): Promise<void> {
    const logo = await dataUri("resources/app/components/Landmarks/Header/logo.svg", "image/svg+xml");
    const font = await dataUri("resources/app/assets/fonts/google-sans-v69-latin_latin-ext-700.woff2", "font/woff2");

    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: WIDTH, height: HEIGHT }, deviceScaleFactor: 1 });

    await page.setContent(markup(logo, font), { waitUntil: "load" });
    // The face is a data URI, so there is nothing to fetch — but layout still settles a frame
    // later, and a screenshot taken before it catches the fallback metrics.
    await page.evaluate(() => document.fonts.ready);

    await mkdir(dirname(OUTPUT), { recursive: true });
    await writeFile(OUTPUT, await page.screenshot({ type: "png" }));
    await browser.close();

    console.log(`og image written: ${OUTPUT}`);
}

await main();
