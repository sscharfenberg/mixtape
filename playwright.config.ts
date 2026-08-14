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
    /*
     * TWO, not three, and it is FREE — which is the only reason to prefer it. Measured over six
     * interleaved full runs: three at each setting, alternating so machine drift
     * could not favour either. Two workers averaged 5.6 minutes against three workers' 5.5, i.e.
     * no cost at all, because this suite waits on a SERIAL app server rather than on CPU (see the
     * timeout note below). The one failure in those six runs was at three.
     *
     * That single failure proves nothing on its own and is not the argument. The argument is that
     * a third worker buys no time and can only add contention to the one thing that is already the
     * bottleneck, so there is nothing to weigh against it.
     */
    workers: process.env.CI ? 1 : 2,

    /* A test that only passes sometimes is worse than no test — never let one land green. */
    forbidOnly: Boolean(process.env.CI),
    retries: 0,

    /*
     * ROOM FOR THE SERVER TO STALL, which it does, and which was costing one or two red runs a
     * day — a different test every time, all green in isolation.
     *
     * WHAT IS ACTUALLY HAPPENING. `artisan serve` is PHP's built-in server: strictly SERIAL, one
     * connection at a time, for all three workers. Polling `/up` — Laravel's health route, which
     * touches nothing — every 200ms through a whole run puts the median at 20ms and the p99 at
     * 1.2s, with a worst case of 3.8s. A trace of a real failure showed the same thing from the
     * browser's side: 5.6s and 9.0s of `wait` (time to first byte) on a static font and a 404,
     * arriving in batches as the queue drained. The app was never wrong; it was waiting.
     *
     * Against that, Playwright's DEFAULT 5s assertion budget leaves about a second of headroom.
     * Anything that costs the machine a moment — another suite, a build, a busy laptop — tips a
     * correct app into a red run. 15s is four times the worst stall measured.
     *
     * IT HIDES NOTHING, which is why it is this rather than `retries`. An assertion resolves the
     * instant it is true, so a green run costs exactly the same; a genuinely broken app still
     * fails, ten seconds later. Retries would have made a flaky test land green, which the line
     * above rejects on principle.
     *
     * The per-TEST timeout goes up with it, or a spec making several slow waits would hit the
     * 30s cap while every individual assertion was still inside its own.
     *
     * WHAT WAS TRIED AND REJECTED: `PHP_CLI_SERVER_WORKERS`, the obvious answer to a serial
     * server. Measured on the real suite it was WORSE — 6 failures against 1, and the worst stall
     * up from 3.8s to 10.2s. Four PHP processes contend for one sqlite file, and a blocked writer
     * waits out `DB_BUSY_TIMEOUT` (5s, see serverEnv). The median improved and the tail is what
     * fails tests. Do not put it back without measuring the tail.
     */
    timeout: 60_000,
    expect: { timeout: 15_000 },

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
