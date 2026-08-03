import { buildAssets, resetDatabase, resetRateLimiter, seedMediaFiles, stashStaleHotFile } from "./environment";

/**
 * Prepare the machine for a run: assets, the `public/hot` guard, a freshly seeded
 * database, and a playable file behind every row in it.
 *
 * Order matters. The hot-file guard comes first because it decides whether the app will
 * serve assets from the manifest at all, and the manifest check is what makes that
 * possible. The database is reset last so the app server — which Playwright starts after
 * this — never sees a half-migrated schema.
 */
export default async function globalSetup(): Promise<void> {
    await stashStaleHotFile();
    buildAssets();
    // Before the database, because the two together are the fixture: rows whose files are
    // missing would 404 the stream route and the player specs would prove nothing.
    seedMediaFiles();
    resetDatabase();
    // Must come after the migration: the limiter lives in the cache, not the database, so
    // a fresh schema does not clear it. See resetRateLimiter for why that matters.
    resetRateLimiter();
}
