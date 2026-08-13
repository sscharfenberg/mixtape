import { defineConfig, devices } from "@playwright/test";
import { BASE_URL, PORT, serverEnv } from "./tests/e2e/support/environment.ts";

/*
 * Playwright — the end-to-end layer (https://playwright.dev/docs/test-configuration).
 *
 * This exists to answer the questions the Vitest suite structurally cannot. happy-dom
 * has no layout, no real navigation and no CSP, so anything that depends on the browser
 * actually being a browser lands here: that a page really boots with its assets, that
 * the server-driven DataTable's state survives a reload, that auth genuinely gates a
 * route, and — once the player is built — that audio plays under the production CSP.
 *
 * It drives the REAL app: Laravel over a throwaway sqlite, seeded fresh each run. See
 * tests/e2e/support/environment.ts for why the environment is overridden rather than
 * configured, and for the `public/hot` trap that silently blanks every asset.
 */
export default defineConfig({
    testDir: "./tests/e2e",

    /* Sets up the DB, the built assets, and the public/hot guard before anything runs. */
    globalSetup: "./tests/e2e/support/globalSetup.ts",
    globalTeardown: "./tests/e2e/support/globalTeardown.ts",

    /*
     * Parallel across files, but modestly. The whole run shares ONE app server and ONE
     * sqlite file, so this is not free concurrency: reads are fine, and sessions are kept
     * out of the database precisely so logins do not contend for its write lock.
     */
    fullyParallel: true,
    workers: process.env.CI ? 1 : 3,

    /* A test that only passes sometimes is worse than no test — never let one land green. */
    forbidOnly: Boolean(process.env.CI),
    retries: 0,

    reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : [["list"], ["html", { open: "never" }]],

    use: {
        baseURL: BASE_URL,
        /* Evidence for a failure, and nothing kept for a pass — traces are large. */
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
        video: "off",
        /* The app is German by default; asserting on catalog strings assumes it. */
        locale: "de-DE",
        timezoneId: "UTC"
    },

    projects: [
        /* Signs in once and saves the session for every project that needs one. */
        { name: "setup", testMatch: /support\/auth\.setup\.ts/u },

        /*
         * Guest specs run with NO stored session. Separated by directory rather than by
         * clearing cookies per test, so a stray storageState can never make an
         * auth-gate test pass by accident.
         */
        {
            name: "guest",
            testMatch: /guest\/.*\.spec\.ts/u,
            use: { ...devices["Desktop Chrome"], storageState: undefined }
        },
        {
            name: "app",
            testMatch: /app\/.*\.spec\.ts/u,
            dependencies: ["setup"],
            use: { ...devices["Desktop Chrome"], storageState: "tests/e2e/.auth/user.json" }
        }
    ],

    /*
     * `--no-reload` so the server does not restart mid-run when a file is touched.
     * Reused when already up, which makes a re-run of a single spec fast; the port is
     * deliberately not 8000, so a hand-started `artisan serve` is never clobbered.
     */
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORT} --no-reload`,
        // THE HEALTH ROUTE, not a page: readiness here means "PHP is answering", and a
        // page means whatever that page happens to need. `/login` was the probe until a
        // shared prop started reading the library on every render — which the app is
        // entitled to do, and which deadlocked the suite, since global setup migrates only
        // AFTER the server is up. `/up` is Laravel's own health endpoint (bootstrap/app.php)
        // and touches nothing.
        url: `${BASE_URL}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
        stdout: "ignore",
        stderr: "pipe",
        env: serverEnv
    }
});
