import { beforeEach, describe, expect, it } from "vitest";
import { setupI18n } from "@/i18n";
import de from "@/lang/de.json";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetToastsForTests, useToast } from "Composables/useToast";
import { announcePlaybackFailure, forgetPlaybackFailure } from "Utils/playbackError";

/*
 * The message a listener gets when a track will not play.
 *
 * Two things are worth pinning here and neither is the wording. The first is the
 * CLASSIFICATION: a cancelled fetch is this app moving on and must stay silent, while a
 * dropped connection and an unreadable file are different pieces of advice. The second is
 * SAYING IT ONCE — one failed load reports itself twice (the element's `error` event, then
 * the rejected `play()` promise), so without the memory in this module every dead track
 * would stack two identical toasts.
 *
 * The real German catalog is used rather than a stub, for the reason Testing/mount gives:
 * a renamed or dropped key should fail a test rather than render as its own name.
 */


/** What is on screen right now, as plain strings. */
const messages = (): string[] => useToast().activeToasts.value.map(toast => toast.message);

/** A MediaError with the code a browser would set. happy-dom has no media stack to produce one. */
const mediaError = (code: number): MediaError => ({ code, message: "" }) as MediaError;

/** The failing track — only the name reaches the message. */
const track = { id: "a", name: "Paranoid Android" } as QueueTrack;

describe("announcePlaybackFailure", () => {
    beforeEach(() => {
        // The composable translates through the i18n SINGLETON (it runs inside a media
        // event handler, where useI18n() is not available), so the singleton has to exist.
        setupI18n({ legacy: false, locale: "de", messages: { de } });
        resetToastsForTests();
        forgetPlaybackFailure();
    });

    it("names the track and says the file is unavailable", () => {
        // MEDIA_ERR_SRC_NOT_SUPPORTED — what a 404 from the stream route becomes, which is
        // the case the whole feature exists for: a file gone between library scans.
        announcePlaybackFailure(mediaError(4), track);

        expect(messages()).toHaveLength(1);
        expect(messages()[0]).toContain("Paranoid Android");
        expect(messages()[0]).toContain("nicht verfügbar");
        expect(useToast().activeToasts.value[0].type).toBe("error");
    });

    it("says the connection was interrupted when that is what happened", () => {
        // The advice differs: this one is worth trying again, a missing file is not.
        announcePlaybackFailure(mediaError(2), track);

        expect(messages()[0]).toContain("Verbindung");
    });

    it("stays silent when the load was aborted, because that is this app's own doing", () => {
        // MEDIA_ERR_ABORTED is what pressing next produces — the element re-points and the
        // download in flight is cancelled. A toast for that would fire on ordinary use.
        announcePlaybackFailure(mediaError(1), track);

        expect(messages()).toHaveLength(0);
    });

    it("reports one failure once, however many times it is reported", () => {
        // The `error` event and the rejected play() promise both carry the SAME MediaError.
        const failure = mediaError(4);

        announcePlaybackFailure(failure, track);
        announcePlaybackFailure(failure, track);

        expect(messages()).toHaveLength(1);
    });

    it("reports a second, genuinely different failure", () => {
        // A new load that fails brings a new MediaError, and the listener has not been told
        // about that one.
        announcePlaybackFailure(mediaError(4), track);
        announcePlaybackFailure(mediaError(4), track);

        expect(messages()).toHaveLength(2);
    });

    it("speaks again after the listener asks for playback again", () => {
        // The element keeps its one MediaError while the dead source is loaded, so pressing
        // play would otherwise be met with the silence this module exists to remove.
        const failure = mediaError(4);
        announcePlaybackFailure(failure, track);

        forgetPlaybackFailure();
        announcePlaybackFailure(failure, track);

        expect(messages()).toHaveLength(2);
    });

    it("still speaks when the engine left no MediaError behind", () => {
        // Older engines fire `error` with nothing on the element. An unknown failure is
        // still a failure, and it is reported as the unreadable-file case.
        announcePlaybackFailure(null, track);

        expect(messages()[0]).toContain("nicht verfügbar");
    });

    it("says nothing when there is no track to name", () => {
        // Nothing loaded means nothing the listener asked for; the player has still stopped.
        announcePlaybackFailure(mediaError(4), null);

        expect(messages()).toHaveLength(0);
    });
});
