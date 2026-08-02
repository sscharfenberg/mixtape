import { buildAssets, resetDatabase, resetRateLimiter, stashStaleHotFile } from "./environment";

/**
 * Prepare the machine for a run: assets, the `public/hot` guard, and a freshly seeded
 * database.
 *
 * Order matters. The hot-file guard comes first because it decides whether the app will
 * serve assets from the manifest at all, and the manifest check is what makes that
 * possible. The database is reset last so the app server — which Playwright starts after
 * this — never sees a half-migrated schema.
 */
export default async function globalSetup(): Promise<void> {
    await stashStaleHotFile();
    buildAssets();
    resetDatabase();
    // Must come after the migration: the limiter lives in the cache, not the database, so
    // a fresh schema does not clear it. See resetRateLimiter for why that matters.
    resetRateLimiter();
}
