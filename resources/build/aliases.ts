import path from "node:path";
import { fileURLToPath } from "node:url";

/**
 * The import aliases (`Utils/formatting`, `Components/…`, …), in one place.
 *
 * They live here rather than inline in `vite.config.ts` because there are now two
 * bundler configs that must agree on them: the app build and `vitest.config.ts`.
 * A test importing `Utils/formatting` has to resolve it exactly as the app does,
 * and a copy-pasted second list is the kind of thing that silently drifts the day
 * an alias is added — tests then fail to resolve an import the app builds fine.
 *
 * Note these must stay in step with `paths` in `tsconfig.json`, which is what
 * TypeScript and ESLint's import resolver read; TS cannot consume this map.
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/** Repo root — this module sits two levels down, in `resources/build/`. */
const root = path.resolve(__dirname, "../..");

/** Alias → absolute path, for a Vite `resolve.alias` block. */
export const aliases: Record<string, string> = {
    "~": path.resolve(root, "node_modules"),
    "@": path.resolve(root, "resources/app"),
    Assets: path.resolve(root, "resources/app/assets"),
    Components: path.resolve(root, "resources/app/components"),
    Composables: path.resolve(root, "resources/app/composables"),
    Utils: path.resolve(root, "resources/app/utils"),
    Abstracts: path.resolve(root, "resources/app/styles/abstracts"),
    Types: path.resolve(root, "resources/app/types"),
    // Test-only support code. Never imported by the app, so it never reaches a bundle.
    Testing: path.resolve(root, "resources/app/testing")
};
