import { default as vitePluginVue } from "@vitejs/plugin-vue";
import { defineConfig } from "vitest/config";
import { aliases } from "./resources/build/aliases.ts";

/*
 * Vitest — the frontend unit layer (https://vitest.dev/config/).
 *
 * Deliberately a SEPARATE config rather than a `test:` key on vite.config.ts: that
 * one is a factory that pulls in laravel-vite-plugin, the image optimizer and the
 * devtools plugin, none of which a unit run should pay for or need. The one thing
 * the two configs must agree on — the import aliases — is imported from a shared
 * module instead of copied (resources/build/aliases.ts).
 */
export default defineConfig({
    /*
     * Only the Vue SFC compiler — none of the app build's other plugins. Note there is
     * deliberately no sass setup: `test.css` stays at its default of false, so an SFC's
     * <style lang="scss"> block is stubbed out rather than compiled. Tests assert markup,
     * classes and behaviour, never computed styling (that is Playwright's job), and
     * compiling the whole Abstracts token tree per file would dominate the run time.
     */
    plugins: [vitePluginVue()],
    resolve: {
        alias: aliases
    },
    test: {
        /*
         * Tests sit beside the code they cover (CLAUDE.md → Pages/Inertia: page-local
         * components, composables and tests live next to the page file), so there is no
         * tests/ tree to point at — glob the app source instead.
         */
        include: ["resources/app/**/*.{test,spec}.ts"],

        /*
         * happy-dom over jsdom: markedly faster to boot per file, and it already carries
         * the APIs this app's components reach for (localStorage, matchMedia, Selection,
         * history). What it does NOT have is layout — no real box metrics, no
         * IntersectionObserver behaviour — which is exactly why useStickyNav and the
         * tooltip's anchor positioning are left to Playwright rather than faked here.
         */
        environment: "happy-dom",

        /* Polyfills for the handful of APIs happy-dom omits — see the file for each one. */
        setupFiles: ["resources/app/testing/setup.ts"],

        /*
         * No injected globals — test files import { describe, it, expect } explicitly.
         * That keeps `types` in tsconfig.json free of "vitest/globals": the colocated
         * *.test.ts files are already matched by its recursive `resources` include, so
         * they are type-checked by `npm run type-check` like any other source file.
         */
        globals: false,

        /*
         * Pin the timezone. formatDateTime() renders an instant in the VIEWER's zone by
         * design, so its expected output otherwise depends on the machine running the
         * suite (this one is Europe/Berlin — two hours off, and a different offset in
         * winter). Vitest applies this to each worker before it loads a test file.
         */
        env: {
            TZ: "UTC"
        }
    }
});
