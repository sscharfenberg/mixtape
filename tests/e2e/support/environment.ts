/******************************************************************************
 * Standing the real app up for an end-to-end run.
 *
 * Everything here exists because the committed `.env` points at the REMOTE dev box
 * (its own Postgres, its own SMTP, secure cookies). A local run must override that
 * without editing the file — Laravel's dotenv is immutable, so a real environment
 * variable always wins, which is what `serverEnv` below relies on.
 *****************************************************************************/
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, renameSync, writeFileSync } from "node:fs";
import { createConnection } from "node:net";
import path from "node:path";
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
    SESSION_DRIVER: "file",
    SESSION_SECURE_COOKIE: "false",
    CACHE_STORE: "file",
    QUEUE_CONNECTION: "sync",
    MAIL_MAILER: "log"
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
 * Make sure built assets exist, since with no `public/hot` the app serves from the
 * manifest. Only builds when it has to — a build is slow, and the common case is that
 * the developer already has one.
 */
export const ensureBuiltAssets = (): void => {
    if (existsSync(path.join(repoRoot, "public", "build", "manifest.json"))) return;

    execFileSync("npm", ["run", "build"], { cwd: repoRoot, stdio: "inherit" });
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

    artisan("config:clear");
    artisan("migrate:fresh", "--force");
    /*
     * E2ESeeder, NOT the default `--seed`. DatabaseSeeder runs LibrarySeeder, which is
     * deliberately random (factories, random_int, inRandomOrder) and re-rolled on every
     * run — good for a developer wanting a plausible library, wrong for a browser test,
     * which then cannot name a song and meets thin edge cases unpredictably. E2ESeeder is
     * a fixed fixture: same ids, names, durations and timestamps every time.
     */
    artisan("db:seed", "--class=Database\\Seeders\\E2ESeeder", "--force");
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
