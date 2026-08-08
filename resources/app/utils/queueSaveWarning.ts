/******************************************************************************
 * queueSaveWarning
 * Saying that the queue could not be SAVED — without saying it every four minutes,
 * and without touching what is playing.
 *
 * A MODULE OF FUNCTIONS beside mediaSession, playbackError and playBeacon: one-way
 * output with a latch, no reactive state, nothing that knows what a queue is.
 *
 * THE FAILURES IT REPORTS WERE DELIBERATELY SILENT, and staying silent was the
 * wrong half of a right decision. A full storage or a refused sync must never take
 * the player down with it — that part stands, and playback is untouched — but the
 * consequence is real and invisible: the queue on screen is no longer the queue
 * that will come back. A listener who drags twenty tracks into order and finds them
 * gone tomorrow deserves to have been told today.
 *
 * A WARNING, NOT AN ERROR, and the distinction is the whole tone of it. The music
 * is still playing and the queue on screen is still correct; what is at risk is
 * only its survival. The failed-stream toast is the error, because that one stops
 * the music.
 *
 * SAID ONCE PER FAILURE, NOT ONCE PER WRITE. The queue flushes on every track
 * change, so a full storage would otherwise raise a toast every four minutes for as
 * long as the tab is open. Each target latches until a write to it SUCCEEDS again,
 * which is what makes a recovery — a tab closed elsewhere, a network that came
 * back — speak up if it fails a second time.
 *****************************************************************************/
import type { Composer } from "vue-i18n";
import { getI18n } from "@/i18n";
import { useToast } from "Composables/useToast";

/** Where a save was refused. Two places, two remedies, two sentences. */
export type QueueSaveTarget = "browser" | "server";

/**
 * Translate through the i18n singleton — the same reach useTwoFactorAuth documents: this
 * runs inside a storage write and a fetch handler, neither of which is component setup.
 */
const translate = (key: string): string => (getI18n().global as unknown as Composer).t(key);

/** Targets already complained about, cleared when one of them works again. */
const announced = new Set<QueueSaveTarget>();

/**
 * Warn that the queue could not be saved, unless that has already been said.
 *
 * The two messages differ because the remedies do. A browser that refuses is full or
 * locked down and the queue will not survive this tab; a server that refuses still leaves
 * the browser's own copy, so the queue survives here and simply will not follow the
 * listener to another device.
 */
export function announceQueueSaveFailure(target: QueueSaveTarget): void {
    if (announced.has(target)) return;

    announced.add(target);
    useToast().addToast(translate(`player.queue.saveFailed.${target}`), "warning");
}

/**
 * Note that a save to `target` worked, so the next failure is worth mentioning again.
 *
 * Called on every SUCCESSFUL write rather than only after a failure: it costs a set
 * lookup, and it is what keeps the latch honest across the hours a tab stays open.
 */
export function noteQueueSaveSucceeded(target: QueueSaveTarget): void {
    announced.delete(target);
}

/** Drop the latches — tests only, and the app's other singletons are drained the same way. */
export function resetQueueSaveWarningsForTests(): void {
    announced.clear();
}
