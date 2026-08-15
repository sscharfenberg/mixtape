import { expect } from "@playwright/test";
import type { Page, Request, Response } from "@playwright/test";
import { SEED_USER } from "./environment";

/**
 * Fill in and submit the login form.
 *
 * Fields are addressed by their `id` rather than their label. That is not laziness about
 * accessible selectors — `getByLabel(/Passwort/)` is genuinely AMBIGUOUS here, matching
 * both the password input and the "Passwort anzeigen" reveal button beside it, and a
 * strict-mode violation in a shared helper would break every spec at once. The ids are a
 * real contract in the markup (FormRow's `for-id` wires the label to them).
 *
 * @param page     the page to drive
 * @param user     credentials; defaults to the seeded account
 * @param expectTo where a successful login should land — pass null to skip the wait when
 *                 the caller is testing a FAILING login
 */
export const signIn = async (
    page: Page,
    user: { name: string; password: string } = SEED_USER,
    expectTo: RegExp | null = /\/dashboard/u
): Promise<void> => {
    await page.goto("/login");
    await page.locator("#name").fill(user.name);
    await page.locator("#password").fill(user.password);
    await page.getByRole("button", { name: /^Anmelden$/u }).click();

    if (expectTo) await page.waitForURL(expectTo);
};

/**
 * Every row's value in the column under `header`, in render order.
 *
 * Addressed by HEADER rather than by position, which matters more than it looks: the
 * tables do not share a column order. The songs listing leads with the title, but the
 * albums listing and a genre's songs tab both lead with a cover-art cell — so a
 * `td:first-child` helper silently returns a column of empty strings there, and every
 * assertion built on it compares nothing to nothing.
 *
 * Throws with the available headers when there is no match, because the alternative is a
 * confusing empty array.
 */
export const columnValues = async (page: Page, header: string | RegExp): Promise<string[]> => {
    const headers = await page.locator("thead th").allInnerTexts();

    /*
     * Compare only the FIRST LINE. A sortable header also carries a visually-hidden
     * announcement of the current sort state, so the cell's text is really
     * "Album\nNach Album aufsteigend sortiert" — and an exact match on "Album" finds
     * nothing, in a way that reads as "the column is missing".
     */
    const labels = headers.map(text => text.split("\n")[0].trim());
    const index = labels.findIndex(label => (typeof header === "string" ? label === header : header.test(label)));

    if (index < 0) throw new Error(`No column header matching ${String(header)}. Found: ${labels.join(" | ")}`);

    return page.locator(`tbody tr td:nth-child(${index + 1})`).allInnerTexts();
};

/**
 * Lower-case and strip diacritics, mirroring how the server matches a search.
 *
 * The app searches against stored accent-folded `name_fold` columns, so "Uber" legitimately
 * returns a row that RENDERS "Über". Comparing the raw strings would report that correct
 * behaviour as a failure — and only for the seeds that happen to contain an accent, which
 * is the worst kind of intermittent.
 */
export const fold = (value: string): string =>
    value
        .toLowerCase()
        .normalize("NFD")
        .replace(/\p{Diacritic}/gu, "");

/** Parse a rendered `m:ss` / `h:mm:ss` clock back into seconds, for ordering assertions. */
export const clockToSeconds = (clock: string): number =>
    clock
        .trim()
        .split(":")
        .map(Number)
        .reduce((total, part) => total * 60 + part, 0);

/**
 * A DETAIL page's own heading — what the page is about, not the wordmark in the app header.
 *
 * The first `<h2>` inside `<main>`, which is the page title because the wordmark is the
 * document's only `<h1>` (browse.spec pins exactly that). It covers all three shapes a detail
 * page comes in: the <Headline> the Music pages put their titles in, and the hero title the
 * playlist and Now Playing pages carry.
 *
 * `:has(.hero-section)` IS THE PART THAT MAKES IT USABLE, and it is not decoration. Every
 * caller navigates from a LISTING and then reads this — and a listing has an `<h2>` of its
 * own, the "Songs" / "Alben" headline. Matching `main h2` alone therefore resolved against
 * the page being left behind: `waitForURL` returns when Inertia updates the address, which is
 * before the component has swapped, so the helper answered "Songs" where a song's title was
 * expected. Requiring the hero — which only the destination has — is what restores the wait a
 * narrower, destination-only selector would give for free. Broadening this without it costs
 * eleven failing specs, all of them looking like the player had loaded the wrong track.
 *
 * The corollary: this is for detail pages only. A listing's own headline is not reachable
 * through it, and nothing needs it to be.
 */
export const pageHeading = (page: Page) => page.locator("main:has(.hero-section) h2").first();

/** Wait until the DataTable has settled on the given page number. */
export const expectOnTablePage = async (page: Page, pageNumber: number): Promise<void> => {
    await expect(page.locator(".dt-pagination__current")).toHaveText(String(pageNumber));
};

/**
 * Count the DOCUMENT requests a block of interactions causes.
 *
 * Used to prove a tab change costs no round trip. Counting `framenavigated` does not work
 * for this: that event also fires for same-document history updates, which is exactly
 * what `history.replaceState` does — so it reports a "navigation" for the very case the
 * test is trying to show is free.
 */
export const countDocumentRequests = async (page: Page, block: () => Promise<void>): Promise<number> => {
    let requests = 0;
    const listener = (request: Request): void => {
        if (request.resourceType() === "document") requests += 1;
    };

    page.on("request", listener);
    await block();
    page.off("request", listener);

    return requests;
};

/**
 * Open the play-queue panel, whatever width the test is running at.
 *
 * EVERY SPEC THAT TOUCHES THE PANEL NEEDS THIS. The panel is an overlay toggled from the header
 * at every width — never a permanent column, see PlayQueue's banner — so "there is a queue" and
 * "the queue is on screen" are two different facts, even at desktop width. This asserts the
 * second.
 *
 * Idempotent: a panel that is already open is left alone, so a helper chain can call it
 * without tracking who called it first.
 */
export const openQueuePanel = async (page: Page): Promise<void> => {
    const panel = page.locator(".play-queue");

    if (!(await panel.isVisible())) {
        // The toggle only exists while the queue holds something, which is the caller's job.
        await page.locator(".play-queue-toggle").click();
    }

    await expect(panel).toBeVisible();

    /*
     * …AND HAS FINISHED ARRIVING. The panel wipes in with `clip-path` from its trailing edge, so
     * for the length of that transition the LEFT of it — where every row's drag grip lives — is
     * clipped away, and a clipped region does not take a pointer event. Playwright's visibility
     * check knows nothing about `clip-path`, so a drag started here pressed on whatever was
     * behind the panel and moved nothing: two reorder tests failed on it, intermittently, which
     * is exactly how it reads as a broken drag rather than as a race.
     *
     * Waiting on the computed value rather than a duration, so the pause is as short as the
     * animation actually is. `none` is the reduced-motion answer, where there is no wipe at all.
     */
    await page.waitForFunction(() => {
        const clip = getComputedStyle(document.querySelector(".play-queue")!).clipPath;

        return clip === "none" || clip === "inset(0px)";
    });
};

/**
 * Put the play-queue panel away after an enqueue, cancelling its peek.
 *
 * Adding to the queue reveals the panel for three seconds and then hides it again, so "there
 * is a queue" and "the panel is shut" stopped being the same instant — and a pending
 * auto-close is a timer that will move the layout under whatever the test does next. TWO WAYS
 * THAT BIT, both on one test, and they are why the dismissal lives in the enqueue helper below
 * rather than at the call sites that happened to notice:
 *
 *   - THE PEEK COVERS THE MENU IT CAME FROM. The panel overlays the trailing edge of the page,
 *     which on a detail page is where the hero's action menu sits, so a second enqueue's click
 *     has nowhere to land: Playwright waits out the three seconds and then clicks. Seven
 *     enqueues in a row took twenty-four seconds.
 *   - THE AUTO-CLOSE RACES THE NEXT ACTION. On CI that same click landed just AFTER the peek
 *     had closed itself — so instead of shutting the panel it opened it, and a test asserting
 *     the panel was shut watched it stay visible for the full timeout. It failed there and
 *     nowhere else.
 *
 * IT WAITS FOR THE PEEK BEFORE DISMISSING IT, which is not belt-and-braces: `isVisible()` is a
 * snapshot, and a check made in the instant between the queue growing and the panel appearing
 * reports "already shut" — leaving the peek to open a moment later, behind the test's back, in
 * exactly the state this is meant to prevent. Waiting cannot hang, because a grown queue always
 * peeks (and a panel that was already open is visible anyway).
 *
 * Escape rather than waiting the peek out: it is instant, and it also CANCELS the pending
 * auto-close, so nothing left over from an enqueue can shut a panel the test later opens.
 *
 * Module-local on purpose. Every spec reaches it through `enqueueFromHero`, so the rule stays
 * one rule — "an enqueue leaves the panel shut; open it if you need it" — rather than something
 * each call site can forget.
 */
const dismissQueuePeek = async (page: Page): Promise<void> => {
    const panel = page.locator(".play-queue");

    await expect(panel).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(panel).toBeHidden();
};

/**
 * Enqueue the subject of the Music detail page currently open, from its hero.
 *
 * SHARED BY FIVE SPECS, and copied into each of them until the peek arrived: the queue, the
 * player and the shortcuts all begin by putting a song in the queue, and all three then had to
 * learn the same thing about what an enqueue leaves on screen. One copy now, so the next change
 * to that starting state is made once.
 *
 * IT IS HERE BECAUSE THE GESTURE MOVES. How a hero offers "enqueue" — a visible button, an entry
 * in a popover — is a design question that gets revisited, and one helper is one place to follow
 * it. Today it presses a visible button in SubjectActions; the playlist page wears a popover menu
 * instead, which is why this helper is about the MUSIC heroes and says so.
 *
 * IT LEAVES THE PANEL SHUT — see `dismissQueuePeek` for what that saves. Pass `keepPeek` only
 * to watch the peek itself.
 *
 * @param page     the page to drive; its hero must be the subject to queue
 * @param options  `keepPeek` leaves the peek on screen, for the one test that asserts it
 */
export const enqueueFromHero = async (page: Page, options: { keepPeek?: boolean } = {}): Promise<void> => {
    // WAITED FOR, because enqueuing from the hero is asynchronous where the very first version
    // of this button was not: it asks the server for the subject's tracks (an optional Inertia
    // prop), so the queue grows a round trip after the click. Without this a caller reads the
    // queue — or the transport's disabled states — before the tracks have landed.
    const before = await page.locator(".play-queue__row").count();

    await page.locator(".subject-actions__enqueue").click();
    await expect(page.locator(".play-queue__row")).toHaveCount(before + 1);

    if (!options.keepPeek) await dismissQueuePeek(page);
};

/**
 * End a queue spec without letting its last breath reach the next one.
 *
 * THE HOLE `clearServerQueue` CANNOT CLOSE. A tab flushes its queue as it goes, with
 * `keepalive` precisely so the request outlives the page — so it is fired while Playwright
 * tears the context down, and it can land AFTER the next test has reset the account. That
 * test then finds a queue nobody in it built and fails on a count one too high, which is
 * exactly as confusing as it sounds. Worse, it is a race: it fails on some runs and not
 * others, and never in the file it belongs to.
 *
 * THE PAGE IS CLOSED WITHOUT RUNNING UNLOAD HANDLERS, so the flush never happens at all.
 * `runBeforeUnload: true` is the tempting opposite — make the flush happen "here, awaited,
 * inside the test that owns it" — and Playwright does not do that: it runs the handlers but, per
 * its own docs, "will NOT wait for the page to close", where the default "does not run any unload
 * handlers and waits for the page to be closed". So the flush fires at some unknowable moment
 * after this returns.
 *
 * THAT IS ALSO WHY THE SERVER'S STALE-STAMP GUARD DID NOT CATCH IT, which is the part worth
 * remembering: `flushQueueWrites` stamps `updatedAt` with `Date.now()` AT FLUSH TIME, not
 * when the queue changed. A flush that fires after the next test's reset therefore carries a
 * NEWER stamp than the reset did, so PlayerStatePayload::store accepts it — the one write the
 * whole "last write wins" rule cannot refuse. Measured on CI: the reordering spec queues three,
 * and the next test in the file counts five where it had queued two.
 *
 * The route abort is only about a request already in flight when this runs —
 * one of those carries a stamp from BEFORE the reset, so the server would refuse it anyway.
 */
export const stopQueueSync = async (page: Page): Promise<void> => {
    await page.route("**/player/state", route => route.abort());
    await page.close();
};

/**
 * Wait out the page-to-page VIEW TRANSITION, without which raw pointer events go nowhere.
 *
 * main.ts opts every navigation into the View Transitions API, and while one is running the
 * browser paints `::view-transition-*` — a pseudo-element tree belonging to the ROOT — over the
 * whole page in the top layer. Hit testing lands on that, so `document.elementFromPoint` returns
 * the <html> element at every coordinate on the page, including one squarely inside a row, while
 * `getBoundingClientRect()` on that row reports exactly the rectangle you would expect.
 *
 * Locator actions ride this out on their own: `click()` and `hover()` retry until the element
 * really receives pointer events. Anything driven through `page.mouse` does NOT — it fires once,
 * into the snapshot, and nothing happens. So call this after a navigation and before any raw
 * pointer work: a drag by a grip, a click at a measured offset, a hover on a coordinate.
 *
 * It cost an hour twice before being understood, once as a SortableJS drag that silently refused
 * to start and once as a click on a row that plainly had a stretched link under it — both
 * presenting as broken features rather than as mis-timed input.
 *
 * Polled on the SYMPTOM rather than on `:active-view-transition`, because the symptom is the
 * precondition a caller actually needs: that a coordinate on this page hits something. (4, 4) is
 * inside the app header, which every page carries.
 */
export const settled = async (page: Page): Promise<void> => {
    await expect
        .poll(() => page.evaluate(() => document.elementFromPoint(4, 4)?.tagName ?? "NONE"))
        .not.toBe("HTML");
};

/**
 * A response to the WRITE, not to a live-validation request that happens to share its verb.
 *
 * EVERY PRECOGNITION FORM IN THIS APP VALIDATES AGAINST THE ROUTE IT SUBMITS TO, WITH THE SAME
 * METHOD — measured: `PUT /playlists/{id}` carrying `Precognition: true` and
 * `Precognition-Validate-Only: description`, fired by the `change` event that Playwright's
 * `fill()` dispatches. So a matcher on url + method alone resolves on the VALIDATION and reports
 * that the save landed when nothing has been saved. The symptom is never at the cause: a later
 * assertion reads a stale listing, several steps away.
 *
 * The real write is the one Inertia sends: `X-Inertia`, and no Precognition header.
 */
export const isWrite = (response: Response, method: "POST" | "PUT" | "PATCH" | "DELETE"): boolean =>
    response.request().method() === method && response.request().headers().precognition === undefined;


/** Everything a spec asks the `<audio>` element about. Field names are the element's own. */
export type AudioState = {
    paused: boolean;
    currentTime: number;
    playbackRate: number;
    volume: number;
    src: string;
    /** How far the browser has actually buffered, in seconds; 0 when nothing has loaded. */
    buffered: number;
};

/**
 * Read the real <audio> element rather than what the UI claims about it.
 *
 * ONE COPY, because three specs had their own and no two agreed: different field names for the
 * same property (`rate` / `time`), different subsets, and a `!` in one where another had a
 * cast. A spec that wants a field the local copy happened to omit then grows a fourth. The
 * element is the source of truth for every one of these, so there is nothing per-spec to vary.
 */
export const audioState = (page: Page): Promise<AudioState> =>
    page.evaluate(() => {
        const audio = document.querySelector("audio") as HTMLAudioElement;

        return {
            paused: audio.paused,
            currentTime: audio.currentTime,
            playbackRate: audio.playbackRate,
            volume: audio.volume,
            src: audio.getAttribute("src") ?? "",
            buffered: audio.buffered.length > 0 ? audio.buffered.end(audio.buffered.length - 1) : 0
        };
    });


/**
 * Wait until a value read off the page stops changing, then return it.
 *
 * THE RULE IS "TWO IDENTICAL READS IN A ROW", not "one read that looks right": an element
 * mid-transition has a perfectly plausible width, position or colour at every frame, so a
 * single sample can be taken at any point along the way and nothing about it looks wrong. The
 * assertion built on it then fails for a reason that has nothing to do with what it is
 * testing, and typically only in a full-file run where the machine is busy enough to change
 * the timing.
 *
 * Named `settledValue` rather than `settled`, deliberately: this file already exports
 * `settled`, which waits out a VIEW TRANSITION and answers a different question. Three specs
 * each had a private copy of this loop and one of them called it `settled`, shadowing the
 * import — so the same word meant two things depending on which file you were reading.
 *
 * @param read the value to sample; anything `===`-comparable, so JSON.stringify a box first
 */
export const settledValue = async <T>(read: () => Promise<T>): Promise<T> => {
    let previous: T | undefined;

    await expect
        .poll(async () => {
            const current = await read();
            const stable = current === previous;
            previous = current;

            return stable;
        })
        .toBe(true);

    return read();
};
