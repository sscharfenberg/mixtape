/******************************************************************************
 * Share-link props, shared between the "My shares" page and the row it draws.
 *
 * Mirrors what Dashboard\SharesController's row mapper puts on the wire — nothing is
 * pre-formatted there, so the raw instant arrives here and the page renders it against the
 * viewer's own locale and timezone.
 *
 * IT CARRIES NO "expired" FLAG, and that is deliberate: the server sends TWO lists (`shares`
 * and `expiredShares`), so which list a row arrived in already says whether the link still
 * works. A flag beside that would be a second answer to the same question, and the two can
 * only ever disagree — a dead row in the live list is a copy button that pastes a 404.
 *****************************************************************************/

/** One share link, as Dashboard\SharesController shapes it. */
export type ShareRow = {
    /** The share's UUID — its identity, and the id the DELETE names. */
    id: string;
    /** Which kind of thing it grants: `App\Enums\ShareSubject`, and nothing else. */
    kind: "song" | "album" | "artist" | "playlist" | "audiobook";
    /** The subject's name, as data — printed, never translated. */
    name: string;
    /** The link itself, ABSOLUTE — it is copied into a chat window, not into an <a href>. */
    url: string;
    /** ISO-8601 instant, formatted on the page since the server knows neither locale nor timezone. */
    validUntil: string;
};
