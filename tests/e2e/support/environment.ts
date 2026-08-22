/******************************************************************************
 * Standing the real app up for an end-to-end run.
 *
 * Everything here exists because the committed `.env` points at the REMOTE dev box
 * (its own Postgres, its own SMTP, secure cookies). A local run must override that
 * without editing the file — Laravel's dotenv is immutable, so a real environment
 * variable always wins, which is what `serverEnv` below relies on.
 *****************************************************************************/
import { execFileSync } from "node:child_process";
import { copyFileSync, existsSync, mkdirSync, renameSync, rmSync, writeFileSync } from "node:fs";
import { createConnection } from "node:net";
import path from "node:path";
import { DatabaseSync } from "node:sqlite";
import { setTimeout } from "node:timers/promises";
import { fileURLToPath } from "node:url";

/** Repo root — this module sits three levels down, in tests/e2e/support/. */
export const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../..");

/** Port the E2E app listens on. Deliberately not 8000, so a hand-started `artisan serve` survives. */
export const PORT = 8100;

/** Base URL every spec navigates against. */
export const BASE_URL = `http://127.0.0.1:${PORT}`;

/** The throwaway database. Wiped and re-seeded at the start of every run. */
const DATABASE = path.join(repoRoot, "storage", "e2e.sqlite");

/**
 * The throwaway media area, rebuilt at the start of every run.
 *
 * Rows alone are not enough: the player specs stream through the real route, and a 404
 * there proves nothing about playback. `seedMediaFiles` fills this with a copy of the
 * committed one-second mp3 at every path E2ESeeder claims.
 *
 * COVER art is deliberately still missing (the mp3 carries none, and no folder image is
 * written), because several specs depend on a cover request 404-ing to exercise
 * CoverImage's placeholder fallback. Audio real, artwork absent — on purpose.
 */
const MEDIA_ROOT = path.join(repoRoot, "storage", "e2e-media");

/**
 * The one-second synthetic mp3 the PHP suite already uses. One second is a FEATURE
 * here, not a compromise: the single most valuable thing a browser can prove about
 * this player is that the queue advances by itself when a track ends, and a track
 * that ends in a second makes that assertion fast and deterministic.
 */
const AUDIO_FIXTURE = path.join(repoRoot, "tests", "Fixtures", "audio", "tagged.mp3");

/**
 * Where a stale `public/hot` is parked for the duration of a run.
 *
 * A fixed path rather than a temp name on purpose: if a run is killed halfway, the
 * next one finds this file and puts it back (see `restoreHotFile`), so a crash can
 * never cost the developer their dev-server marker.
 */
const HOT = path.join(repoRoot, "public", "hot");
const HOT_BACKUP = path.join(repoRoot, "public", "hot.e2e-backup");

/**
 * The environment the app server runs with.
 *
 * A throwaway sqlite file and file-driven sessions: sessions must NOT go to the
 * database, because several workers logging in at once would contend for sqlite's
 * write lock and surface as random "database is locked" failures.
 */
export const serverEnv: Record<string, string> = {
    APP_ENV: "local",
    APP_DEBUG: "true",
    APP_URL: BASE_URL,
    APP_LOCALE: "de",
    DB_CONNECTION: "sqlite",
    DB_DATABASE: DATABASE,
    /*
     * SURVIVING THREE WORKERS ON ONE SQLITE FILE. Keeping sessions out of the database
     * (below) stops the worst of the contention, but any spec that WRITES through the app —
     * creating a playlist, syncing a queue, logging a play — can still meet a concurrent
     * read, and sqlite's default is to fail that write immediately with "database is
     * locked". It surfaces as a 500 on a page that is entirely correct, on a different spec
     * each run, which is the worst kind of intermittent — and it costs a full-suite run to
     * diagnose.
     *
     * `busy_timeout` makes a blocked writer WAIT rather than throw; WAL lets readers carry
     * on while it holds the lock, so the wait is rarely needed at all. `synchronous=off` is
     * safe here and nowhere else: this database is deleted and re-seeded at the start of
     * every run, so durability across a crash buys nothing and costs an fsync per write.
     *
     * config/database.php reads these three from the environment for this reason.
     */
    DB_BUSY_TIMEOUT: "5000",
    DB_JOURNAL_MODE: "wal",
    DB_SYNCHRONOUS: "off",
    SESSION_DRIVER: "file",
    SESSION_SECURE_COOKIE: "false",
    CACHE_STORE: "file",
    QUEUE_CONNECTION: "sync",
    MAIL_MAILER: "log",
    /*
     * The generated media area, NOT the committed `.env`'s `/var/media/music` — which is
     * the live server's path and does not exist on a developer's machine, so every stream
     * request would 404 and the player specs would be testing a broken route.
     *
     * `MIXTAPE_STREAM_INTERNAL_PREFIX` is pinned EMPTY rather than merely left out, and
     * that is a lesson rather than a preference: left out, the app fell back to whatever
     * the committed `.env` happened to say, so the whole player suite passed only because
     * that file had no such key yet. The moment one was added the run would have streamed
     * through a hand-off with no nginx to catch it. A real environment variable beats
     * dotenv, so this makes the run independent of the developer's `.env` — and empty is
     * the honest value, because there is no nginx in front of `artisan serve`.
     */
    MIXTAPE_MUSIC_PATH: MEDIA_ROOT,
    // The SAME root as music, which is right rather than lazy: a stored path is
    // area-relative and E2ESeeder writes `/audiobooks/NNN.mp3`, so the two areas resolve
    // into sibling directories under one throwaway tree. It was empty until the audiobooks
    // area existed, and empty means "no such area" — a chapter's stream would 404 and the
    // resume spec would look like the bookmark was never written.
    MIXTAPE_AUDIOBOOKS_PATH: MEDIA_ROOT,
    MIXTAPE_STREAM_INTERNAL_PREFIX: ""
};

/** Run an artisan command with the E2E overrides applied, returning its stdout. */
export const artisan = (...args: string[]): string =>
    execFileSync("php", ["artisan", ...args], {
        cwd: repoRoot,
        env: { ...process.env, ...serverEnv },
        encoding: "utf8",
        stdio: ["ignore", "pipe", "pipe"]
    });

/** True when something is accepting connections on `port`. */
const isListening = (port: number): Promise<boolean> =>
    new Promise(resolve => {
        const socket = createConnection({ port, host: "127.0.0.1" })
            .on("connect", () => {
                socket.end();
                resolve(true);
            })
            .on("error", () => resolve(false));
        socket.setTimeout(500, () => {
            socket.destroy();
            resolve(false);
        });
    });

/**
 * Put a previously stashed `public/hot` back. Called at the START of a run as well as
 * at the end, so a killed run self-heals on the next one.
 */
export const restoreHotFile = (): void => {
    if (existsSync(HOT_BACKUP)) renameSync(HOT_BACKUP, HOT);
};

/**
 * Deal with `public/hot`, which decides where the app loads its assets from.
 *
 * The file is written by `npm run dev` and is NOT removed when that server stops, so
 * it very often outlives it. While it exists, `@vite` points every asset at the URL it
 * names and ignores the built manifest entirely — so a stale one means a run against a
 * page with no CSS and no JavaScript, which presents as every selector timing out.
 *
 * A LIVE dev server is left alone: it serves assets perfectly well, and stealing the
 * marker out from under a developer's running `npm run dev` would be worse than the
 * problem. Only a stale marker is stashed, and it is put back in teardown.
 */
export const stashStaleHotFile = async (): Promise<void> => {
    restoreHotFile();
    if (!existsSync(HOT)) return;

    const origin = (await import("node:fs")).readFileSync(HOT, "utf8").trim();
    const port = Number(new URL(origin).port || 80);
    if (await isListening(port)) return;

    renameSync(HOT, HOT_BACKUP);
};

/**
 * Rebuild the bundle, since with no `public/hot` the app serves from the manifest.
 *
 * ALWAYS, not only when the manifest is missing — which is what this did first, and it was
 * wrong in the most confusing way available: after any frontend change the suite would run
 * against the PREVIOUS bundle, so a brand-new component simply was not on the page and every
 * selector for it timed out. Nothing about that failure points at a stale build.
 *
 * `build-only` skips the lint and type-check `npm run build` chains, which the developer
 * (and CI) run separately, so the cost is a second or so per run — cheap against a whole
 * class of failures that look like a broken feature.
 */
export const buildAssets = (): void => {
    execFileSync("npm", ["run", "build-only"], { cwd: repoRoot, stdio: "inherit" });
};

/**
 * Reset the database to a freshly seeded state.
 *
 * `config:clear` first, and it is load-bearing: a cached config file beats real
 * environment variables, so a stale `bootstrap/cache/config.php` would silently point
 * this whole run at the REMOTE Postgres — which fails as a connection error from a
 * command that plainly asked for sqlite.
 */
export const resetDatabase = (): void => {
    mkdirSync(path.dirname(DATABASE), { recursive: true });
    writeFileSync(DATABASE, "");
    /*
     * The WAL sidecars go with it. Truncating the main file alone leaves a write-ahead log
     * and shared-memory index describing a database that no longer exists, and sqlite's
     * first act on opening the fresh file is to replay them — so a run could start against
     * the tail of the previous one's writes, or simply refuse to open. `force`, because they
     * are legitimately absent after a clean shutdown.
     */
    rmSync(`${DATABASE}-wal`, { force: true });
    rmSync(`${DATABASE}-shm`, { force: true });

    artisan("config:clear");
    artisan("migrate:fresh", "--force");
    /*
     * E2ESeeder, NOT the default `--seed`, and that is now doubly true: DatabaseSeeder no
     * longer calls LibrarySeeder at all (it exists for developing without an mp3 collection,
     * and every dev box has one), so the default would leave a run with an account and an
     * empty library.
     *
     * It was never the right seeder anyway. LibrarySeeder is deliberately random (factories,
     * random_int, inRandomOrder) and re-rolled on every run — good for a developer wanting a
     * plausible library, wrong for a browser test, which then cannot name a song and meets
     * thin edge cases unpredictably. E2ESeeder is a fixed fixture: same ids, names, durations
     * and timestamps every time.
     */
    artisan("db:seed", "--class=Database\\Seeders\\E2ESeeder", "--force");
};

/**
 * Put a playable file at every path the seeded library claims.
 *
 * Copies rather than symlinks, and the whole directory is rebuilt from scratch, so a
 * half-finished previous run cannot leave a truncated file behind that decodes as a
 * damaged track. 67 copies of an 18 kB fixture is about a megabyte — cheap enough to do
 * unconditionally, which is what keeps it in step with the seeder.
 *
 * The paths are E2ESeeder's `/music/%03d.mp3`, resolved the way Track::absolutePath does
 * (area root + the stored, area-relative path). They are derived from the same rule
 * rather than read out of the database, so this needs no connection and runs before the
 * app server is up.
 */
export const seedMediaFiles = (): void => {
    rmSync(MEDIA_ROOT, { recursive: true, force: true });
    mkdirSync(path.join(MEDIA_ROOT, "music"), { recursive: true });
    mkdirSync(path.join(MEDIA_ROOT, "audiobooks"), { recursive: true });

    // 67 music tracks, per E2ESeeder's docblock — the count that puts a listing past its
    // 50-row page size. One spare file costs nothing; one missing file is a silent 404.
    for (let position = 1; position <= 70; position += 1) {
        copyFileSync(AUDIO_FIXTURE, path.join(MEDIA_ROOT, "music", `${String(position).padStart(3, "0")}.mp3`));
    }

    // 11 audiobook chapters across the fixture's two books. Real audio matters more here
    // than for a song: the resume spec asserts a bookmark, and a bookmark is only written
    // once playback actually reports a position.
    for (let chapter = 1; chapter <= 15; chapter += 1) {
        copyFileSync(AUDIO_FIXTURE, path.join(MEDIA_ROOT, "audiobooks", `${String(chapter).padStart(3, "0")}.mp3`));
    }
};

/**
 * Clear the login rate limiter before a run.
 *
 * Fortify throttles login at `Limit::perMinute(5)` keyed on `username|ip`
 * (FortifyServiceProvider), and the limiter counts through the CACHE — not the database —
 * so wiping the database does NOT reset it. A single run signs in about four times (the
 * stored-session setup, plus the guest specs that exercise login and logout for real), so
 * two runs inside the same minute sail past five and the app starts answering 429.
 *
 * That failure is thoroughly misleading: the login page simply never navigates and no
 * error text appears, so it reads as "the login form broke" rather than "the app is
 * correctly throttling you". Clearing here makes every run start from zero attempts.
 *
 * The budget is real, though — keep the number of genuine logins per run below five, or
 * raise the limit for this environment.
 */
export const resetRateLimiter = (): void => {
    artisan("cache:clear");
};

/** The seeded account every authenticated spec signs in as. Login is by NAME, not email. */
export const SEED_USER = { name: "Ashaltiriak", password: "passwort" } as const;

/**
 * One account per spec file that leaves a PLAY QUEUE behind (E2ESeeder creates them).
 *
 * The queue is server state (`player_states`), which breaks the assumption a browser test
 * naturally makes: a fresh browser context is NOT a fresh player, because a queue follows the
 * USER. With one shared account, a spec in one worker restores a queue another worker just
 * left — and it fails two files away from the cause.
 *
 * Playwright never splits a FILE across workers, so a file with its own account has the
 * account to itself. What tests inside one file still owe each other is a reset, which is
 * `clearServerQueue` in actions.ts.
 */
export const SPEC_USERS = {
    queue: "spec-queue",
    player: "spec-player",
    /*
     * Its own account rather than sharing `player`, because the rule above is per FILE, not per
     * feature — two files about the player are still two files. Sharing one fails exactly as
     * advertised: the spec in another worker leaves a track behind, and this one counts eleven
     * rows where it queued ten.
     */
    nowPlaying: "spec-now-playing",
    shortcuts: "spec-shortcuts",
    widgets: "spec-widgets",
    // Playlists are PRIVATE PER OWNER, so this account also owns the fixture the spec
    // reads — a playlist seeded for anyone else would 404 for it.
    playlistDetail: "spec-playlist-detail",
    /*
     * The only account here that owns nothing and leaves everything: its spec CREATES
     * playlists, which is state no other spec should have to see.
     *
     * It is not about the queue, unlike the four above. Adding rows to the shared account's
     * playlist listing broke `playlists.spec.ts`'s DRAG test — that one scrolls three of its
     * own rows into view and computes pointer coordinates from them, and every extra row
     * above pushes those coordinates somewhere the drag does not land. The failure appears in a
     * file this one never touches, which is the exact shape of problem an account per spec
     * exists to prevent.
     */
    addToPlaylist: "spec-add-to-playlist",
    /*
     * IT IS HERE FOR THE SESSION, not for the rows — which is a different reason from every
     * entry above it, and the hardest of the three to find. Inertia carries validation errors
     * and flash messages in the SESSION, and Laravel does not lock sessions: it reads the
     * whole payload at the start of a request and writes the whole payload at the end. Two
     * concurrent requests sharing one cookie therefore lose one of the two writes, and what
     * gets lost is whichever flash was written first.
     *
     * This spec writes through the app and then asserts what the flash said, which is what
     * makes it the one that can see this at all — as "will not submit a nameless playlist"
     * finding no error message, and "edits a playlist's metadata" finding no toast, roughly one
     * full run in five between them, each pointing at the feature rather than at the harness.
     * Measured: 10/10 green on one worker, 2-in-14 red on three, 42/42 green on three once each
     * worker had a session of its own.
     *
     * AN ACCOUNT ALONE IS NOT ENOUGH, which is why the spec is also `mode: "serial"` — with
     * `fullyParallel`, its own tests would race each other on this one session just as
     * happily. The two together are the fix.
     */
    playlists: "spec-playlists",
    /*
     * The export-presets spec's account, here for the SESSION reason above and for the rows it
     * writes — both, and either alone would have earned it one.
     *
     * Its tests create, rename, re-default and delete presets, which are user-scoped rows that
     * would otherwise appear in another spec's export dialog as options it never made. And every
     * one of those writes lands a flash it then asserts, which is exactly the write two workers
     * on one cookie lose.
     *
     * `mode: "serial"` in the spec itself is the other half, for the reason `playlists` records:
     * an account of its own does nothing about its own tests racing each other.
     */
    presets: "spec-presets",
    /*
     * The search spec's account, and it is here for the FIRST reason on this list rather than the
     * later ones: its central assertion is that typing a song title does not drive the player, so
     * it has to have something playing — which means it leaves a queue behind.
     *
     * Nothing it searches for is user-scoped, so the fixture it reads is the shared library and
     * this account owns no rows of its own. The playlist half of the feature — the one kind that
     * IS per-owner — is pinned in tests/Feature/Search, where a stranger's playlist can be created
     * and asserted absent in three lines.
     */
    search: "spec-search",
    /*
     * Audiobooks own their spec account for the queue reason above AND one of their own: the
     * area's resume feature is per (reader, book), so a bookmark left by another spec would be
     * a chapter this one never played.
     */
    audiobooks: "spec-audiobooks",
    /*
     * The bulk-actions spec, and the only entry here on TWO of the three counts at once: it
     * plays what it ticks (a queue) and it adds what it ticks to a playlist of its own making
     * (rows on the playlist listing). Either alone would have earned it an account; both
     * together make sharing one indefensible.
     */
    selection: "spec-selection",
    /*
     * The queue reason again, with a twist that makes this the only entry whose parked session
     * is never read: its spec SIGNS OUT, which kills the session it is using, so a stored
     * `storageState` would be dead for anything else that touched it. It therefore lives in the
     * `guest` project and signs in for real — signing out being the thing under test.
     *
     * It is still listed here rather than as a loose string, because the rule it obeys is this
     * one: it queues a track in order to watch the queue be abandoned, and a queue follows the
     * USER. The session auth.setup mints for it is one unused login a run, on a throttle bucket
     * of its own, which is cheaper than a second register of account names to keep in step.
     */
    logout: "spec-logout"
} as const;

/** Where a spec account's signed-in session is parked by the setup project. */
export const specStorageState = (spec: keyof typeof SPEC_USERS): string =>
    path.join(repoRoot, `tests/e2e/.auth/${SPEC_USERS[spec]}.json`);

/**
 * Forget a spec account's audiobook bookmarks, straight in the database.
 *
 * WHY A RESET IS NEEDED AT ALL, and it is not the queue's reason: a bookmark is per (reader,
 * book) and OUTLIVES the queue by design, so it survives `clearServerQueue` and every page
 * load after it. Two tests that both play the same chapter then look wrong in a way that reads
 * as the feature failing — the second one writes nothing, because the bookmark is already
 * exactly where it would put it, and a test waiting for that write waits forever.
 *
 * No settle loop, unlike the queue's: nothing flushes a bookmark from a closing tab, so there
 * is no in-flight write to outrace.
 */
export const clearBookmarks = (spec: keyof typeof SPEC_USERS): void => {
    const database = new DatabaseSync(path.join(repoRoot, "storage/e2e.sqlite"));
    database.exec("PRAGMA busy_timeout = 2000");

    try {
        database
            .prepare("DELETE FROM audiobook_bookmarks WHERE user_id IN (SELECT id FROM users WHERE name = ?)")
            .run(SPEC_USERS[spec]);
    } finally {
        database.close();
    }
};

/**
 * Drop a spec account's stored play queue, straight in the database.
 *
 * WHY EVERY QUEUE SPEC NEEDS IT: the queue is server state, so a fresh browser context is not
 * a fresh player — the next test signs in as the same account and its first page load restores
 * whatever the last test left. Specs that assert "nothing is queued yet" would inherit a queue
 * nobody in that test ever built.
 *
 * STRAIGHT TO SQLITE RATHER THAN THROUGH THE APP'S OWN PUT, which is worth an hour of anyone's
 * time to know: an out-of-band request has to carry a session cookie AND a CSRF token that
 * still matches it, and a stored session that authenticates by remember-me gets a fresh
 * session — and with it a token the parked `XSRF-TOKEN` does not match. The request is then
 * bounced through a redirect chain and answers 405, from a URL that has nothing to do with the
 * queue. None of that is a fact about the app worth reproducing in a fixture; a DELETE is one
 * statement and cannot be redirected.
 *
 * `busy_timeout` because the running app holds the same file open: a reset that collided
 * with a request mid-flight would otherwise throw SQLITE_BUSY rather than wait the
 * millisecond out.
 */
export const clearServerQueue = async (spec: keyof typeof SPEC_USERS): Promise<void> => {
    const database = new DatabaseSync(path.join(repoRoot, "storage/e2e.sqlite"));
    // BEFORE ANYTHING ELSE, including the prepares: the app holds the same file open, and
    // `prepare` takes a lock of its own — set after them, the timeout is not in force for
    // the statement most likely to collide, which surfaced as a bare "database is locked".
    database.exec("PRAGMA busy_timeout = 2000");

    const write = database.prepare(
        `INSERT INTO player_states (user_id, queue, updated_at)
         VALUES ((SELECT id FROM users WHERE name = ?), ?, datetime('now'))
         ON CONFLICT(user_id) DO UPDATE SET queue = excluded.queue, updated_at = excluded.updated_at`
    );
    const read = database.prepare(
        "SELECT queue FROM player_states WHERE user_id IN (SELECT id FROM users WHERE name = ?)"
    );

    /** How many tracks the row currently claims. */
    const queued = (): number => {
        const row = read.get(SPEC_USERS[spec]) as { queue: string } | undefined;

        return row ? (JSON.parse(row.queue).tracks as string[]).length : 0;
    };

    try {
        /*
         * WRITTEN, THEN WATCHED, and the watching is not paranoia. The previous test's tab
         * flushes its queue as it goes — with `keepalive`, precisely so the request outlives
         * the page — so it can still be in the air when this runs, and it lands a moment
         * later. `stopQueueSync` aborts most of them and the server refuses any whose stamp
         * is older than this one, but a request already past both is a queue nobody in the
         * next test ever built, and it fails as a row count two too high somewhere else
         * entirely.
         *
         * So: overwrite, then confirm it STAYS overwritten. Two clean reads 40ms apart is
         * enough for anything fired at the previous context's death, and costs the suite
         * under three seconds in total.
         *
         * IT REALLY IS TWO READS. Returning on the first clean one is a single 40ms window,
         * which is not what the paragraph above claims. The leak is closed at its source in
         * `stopQueueSync` (a dying tab does not flush at all); this is the belt to that pair
         * of braces, because the one write the server cannot refuse is one stamped after
         * this reset.
         */
        for (let attempt = 0; attempt < 6; attempt += 1) {
            write.run(SPEC_USERS[spec], JSON.stringify({
                version: 1,
                tracks: [],
                currentIndex: -1,
                repeat: false,
                shuffle: false,
                // NOW, so the server refuses anything the last test fired before it.
                updatedAt: Date.now(),
                positionMs: 0
            }));

            await setTimeout(40);
            if (queued() !== 0) continue; // something landed — overwrite it and look again

            await setTimeout(40);
            if (queued() === 0) return;
        }

        throw new Error(`Could not clear the play queue for ${SPEC_USERS[spec]}: something keeps writing to it`);
    } finally {
        database.close();
    }
};
