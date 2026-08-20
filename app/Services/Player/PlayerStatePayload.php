<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\PlayerState;
use App\Models\User;
use App\Services\Music\QueuePayload;

/**
 * The server's half of the play queue: what `player_states` stores, and what the client
 * gets back on a page load (data-model.md → "the play queue").
 *
 * NOT UNDER `Services\Music`, though it borrows from there today. The player is one player:
 * when audiobooks become queueable this same row carries them, and a service that had to be
 * imported from a music namespace to restore a book would be describing the wrong shape of
 * the app. The queue being music-only is a fact about the QUEUE right now, not about where
 * player state belongs.
 *
 * IT REUSES QueuePayload FOR THE MAPPING, and asks it for ANY track type. A restored queue and a queue built by pressing
 * "play this artist" have to arrive in exactly the same shape — the client's `QueueTrack`,
 * eight fields — and one mapping is what guarantees that. Two things differ. The ORDER:
 * QueuePayload sorts a subject into album-then-disc-then-track, while a restored queue must
 * come back in the order the listener built, so the rows are re-sorted here into the stored
 * sequence. And the TYPE: that mapping defaults to music, which is right for the four
 * subject pages that built the queue and wrong for restoring one — so this passes
 * `only: null`. A chapter the listener queued comes back as a chapter rather than as a
 * silent gap.
 *
 * THE ROW HOLDS IDS, NOT TRACKS, and that asymmetry is deliberate. Storing what the client
 * holds would mean the title in the database going stale the moment a file is re-tagged,
 * and a queue of 12,000 tracks would be megabytes of denormalised JSON per user; ids are 36
 * bytes each and the tracks are one join away. What comes BACK is the full shape, because
 * the client has no REST API to look ids up with — that is the same reason the queue holds
 * whole tracks in the browser (see usePlayerQueue's own note).
 *
 * A TRACK THE LIBRARY NO LONGER HAS IS SKIPPED, never faked: the scan legitimately drops
 * rows when files disappear, and a queue that came back with holes in it would either break
 * the player or lie about what it holds. The pointer is moved to match, so "resume where I
 * left off" survives the track before it being deleted.
 */
final class PlayerStatePayload
{
    /**
     * Shape of the stored blob.
     *
     * Versioned for the same reason the browser's copy is: this is a JSON blob nothing
     * validates on write beyond the controller, so a future shape change needs a way to
     * refuse the old one rather than misread it. A refused row is simply overwritten by the
     * next flush, which is why there is no migration path.
     */
    private const VERSION = 1;

    /**
     * The queue to hand a page load, or null when there is nothing to restore.
     *
     * NULL FOR AN EMPTY STORED QUEUE AS WELL AS FOR NO ROW AT ALL, and the client cannot tell
     * the two apart — which is the point. A browser holding a good local queue must not have
     * it wiped by the first page load after signing in on a second device, and a stored queue
     * that is empty carries nothing worth having, so both answer "there is nothing here" and
     * the local copy survives.
     *
     * The consequence, stated where somebody reading this will need it: A QUEUE IS REPLACED
     * ACROSS DEVICES, NEVER EMPTIED. Clearing on one device does not clear the other; the
     * other keeps its own until something is queued over it. See UpdatePlayerStateRequest for
     * why that trade is the right way round.
     *
     * @return array{tracks: list<array<string, mixed>>, currentIndex: int, repeat: bool, shuffle: bool, updatedAt: int, positionMs: int}|null
     */
    public static function forUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $stored = PlayerState::query()->whereKey($user->id)->value('queue');

        if (! is_array($stored) || ($stored['version'] ?? null) !== self::VERSION) {
            return null;
        }

        /** @var list<string> $ids */
        $ids = array_values(array_filter($stored['tracks'] ?? [], 'is_string'));

        if ($ids === []) {
            return null;
        }

        // Keyed by id so the stored ORDER can be replayed over the result — the query
        // itself comes back in QueuePayload's subject order, which is the wrong one here.
        // ANY TYPE, not just music: these are ids the listener queued, and the queue is the
        // player's rather than the music section's. Filtering here would drop an audiobook
        // chapter out of a restored queue without a word — see QueuePayload's own note.
        $byId = collect(QueuePayload::fromQuery(QueuePayload::query()->whereIn('tracks.id', $ids), only: null))
            ->keyBy('id');

        $tracks = [];
        foreach ($ids as $id) {
            if ($byId->has($id)) {
                $tracks[] = $byId->get($id);
            }
        }

        if ($tracks === []) {
            return null;
        }

        $surviving = $byId->keys()->flip()->all();
        $storedIndex = (int) ($stored['currentIndex'] ?? 0);

        return [
            'tracks' => $tracks,
            'currentIndex' => self::survivingIndex($ids, $surviving, $storedIndex),
            'repeat' => (bool) ($stored['repeat'] ?? false),
            'shuffle' => (bool) ($stored['shuffle'] ?? false),
            // The CLIENT'S clock, stored verbatim and handed straight back: the browser
            // compares it with its own copy's stamp to decide which is newer, and a value
            // this server had rewritten would be comparing two different clocks.
            'updatedAt' => (int) ($stored['updatedAt'] ?? 0),
            // How far into the loaded track the listener had got. Whether it is worth
            // resuming at all — too early in the track, too near its end — is the client's
            // rule, applied where the element and the duration both are.
            //
            // ZERO WHEN THE TRACK IT WAS MEASURED ON HAS GONE, because the pointer above then
            // names whatever moved up into the gap and these seconds are a stranger's. The row
            // stores the position against an INDEX and cannot name its track, so this is the
            // only place that knows the pointer moved for that reason rather than because the
            // listener moved it.
            'positionMs' => self::survivingPosition($ids, $surviving, $storedIndex, (int) ($stored['positionMs'] ?? 0)),
        ];
    }

    /**
     * Replace this user's stored queue.
     *
     * An upsert rather than a read-then-write: the queue is read and written WHOLESALE (the
     * reason it is one JSON blob and not a table), and two devices racing is settled by
     * last-write-wins, which is what the plan chose for this scale.
     *
     * @param  list<string>  $ids  track ids in play order, already validated by the caller
     * @param  int  $updatedAt  the CLIENT's clock in milliseconds — see the note in `forUser`
     * @param  int  $positionMs  how far into the loaded track, in milliseconds
     */
    public static function store(User $user, array $ids, int $currentIndex, bool $repeat, bool $shuffle, int $updatedAt, int $positionMs): void
    {
        /*
         * A WRITE OLDER THAN THE STORED ONE IS IGNORED, which is the same rule the browser
         * applies to what this hands back — the newest stamp wins in both directions.
         *
         * Without it, closing a stale tab rolls the server back: that tab flushes on its way
         * out, and its queue is whatever it was holding when it was abandoned. Two tabs open,
         * fifty tracks queued in the second, close the first — and the fifty are gone. The
         * E2E suite found it as a test inheriting a queue from the test before it, which is
         * the same event with the tabs a second apart instead of an hour.
         *
         * The cost is the one the stamp already carries: a device whose clock runs behind
         * can have a legitimate write refused. Both ends of this comparison are wall clocks,
         * and data-model.md accepted that trade for a family's worth of listening.
         */
        $stored = PlayerState::query()->whereKey($user->id)->value('queue');

        if (is_array($stored) && (int) ($stored['updatedAt'] ?? 0) > $updatedAt) {
            return;
        }

        PlayerState::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['queue' => [
                'version' => self::VERSION,
                'tracks' => $ids,
                'currentIndex' => $currentIndex,
                'repeat' => $repeat,
                'shuffle' => $shuffle,
                'updatedAt' => $updatedAt,
                'positionMs' => $positionMs,
            ]],
        );
    }

    /**
     * The stored position, or zero if the track it belongs to is no longer in the library.
     *
     * Separate from {@link survivingIndex} rather than folded into it, because the two answer
     * opposite halves of the same event: the pointer FOLLOWS a deletion (whatever moved up is
     * the next thing the listener would have heard), while the position does not travel with
     * it — resuming a song nobody chose at a minute mark measured on a song that has gone is
     * the one outcome the client cannot detect for itself, since what it receives looks
     * exactly like an ordinary resume.
     *
     * @param  list<string>  $ids  the stored order, including ids the library no longer has
     * @param  array<string, int>  $surviving  id => anything, for the ids that resolved
     */
    private static function survivingPosition(array $ids, array $surviving, int $storedIndex, int $positionMs): int
    {
        // -1 is "nothing loaded" (see below), and a position measured on nothing is nothing.
        if ($storedIndex < 0 || ! isset($ids[$storedIndex])) {
            return 0;
        }

        return array_key_exists($ids[$storedIndex], $surviving) ? $positionMs : 0;
    }

    /**
     * Where the pointer lands once missing tracks are dropped.
     *
     * Counting survivors BEFORE the stored index rather than searching for the loaded id,
     * because both cases fall out of the same sum: if the loaded track is still there, the
     * count is exactly its new position; if it is gone, the pointer lands on whatever moved
     * up into its place, which is the next thing the listener would have heard anyway.
     *
     * -1 IS CARRIED THROUGH RATHER THAN COUNTED. It is the client's "nothing loaded" (see
     * UpdatePlayerStateRequest), and the loop below cannot express it: with a stored index of
     * -1 the loop never runs and the sum is 0, so "nothing was playing" comes back as "track
     * one is". On the next device that silently promotes the player bar over the footer and
     * starts a queue the listener had deliberately left idle.
     *
     * @param  list<string>  $ids  the stored order, including ids the library no longer has
     * @param  array<string, int>  $surviving  id => anything, for the ids that resolved
     */
    private static function survivingIndex(array $ids, array $surviving, int $storedIndex): int
    {
        if ($storedIndex < 0) {
            return -1;
        }

        $index = 0;

        for ($position = 0; $position < $storedIndex && $position < count($ids); $position++) {
            if (array_key_exists($ids[$position], $surviving)) {
                $index++;
            }
        }

        return $index;
    }
}
