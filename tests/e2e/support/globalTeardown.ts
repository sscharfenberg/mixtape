import { restoreHotFile } from "./environment";

/**
 * Put a stashed `public/hot` back, so the developer's next `npm run dev` still points at
 * their dev server. The sqlite file is deliberately LEFT behind: it is the state a
 * failure happened in, and being able to open it after a red run is worth more than a
 * tidy working directory. The next run truncates it anyway.
 */
export default async function globalTeardown(): Promise<void> {
    restoreHotFile();
}
