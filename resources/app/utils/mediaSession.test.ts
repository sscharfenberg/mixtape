import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { bindActionHandlers, publishMetadata, publishPlaybackState, publishPositionState } from "Utils/mediaSession";

/*
 * What this app SAYS to the OS — testable at all only because the module was pulled out of
 * usePlayerAudio and made stateless. While it lived in there, every guard below was
 * unreachable: the publishers read module state and the handlers were wired inside
 * `attach()`, so nothing could exercise them without a whole player.
 *
 * The API IS THE THING UNDER TEST, so stubbing `navigator.mediaSession` is the point rather
 * than a compromise — the assertions are about what gets handed to it. That is a different
 * matter from mocking something only a browser has (layout, a decoder), which would only
 * assert the mock.
 *
 * happy-dom implements no Media Session at all, which conveniently makes the
 * "absent entirely" case the default state.
 */

/** A track with just enough shape to appear in metadata. */
const track = (): QueueTrack => ({
    id: "a",
    name: "Airbag",
    artist: "Radiohead",
    album: "OK Computer",
    coverUrl: "/covers/a.jpg",
    duration: 100,
    href: "/music/songs/a",
    streamUrl: "/music/songs/a/stream"
});

/** A stand-in for the real API, recording what the player asks of it. */
type Stub = {
    metadata: unknown;
    playbackState: string;
    setPositionState: ReturnType<typeof vi.fn>;
    setActionHandler: ReturnType<typeof vi.fn>;
};

/**
 * Install a stub Media Session, plus the `MediaMetadata` constructor it needs.
 *
 * `MediaMetadata` is absent in happy-dom too, and `publishMetadata` calls `new` on it —
 * so without this the metadata test would throw rather than assert.
 */
const install = (overrides: Partial<Stub> = {}): Stub => {
    const stub: Stub = {
        metadata: undefined,
        playbackState: "none",
        setPositionState: vi.fn(),
        setActionHandler: vi.fn(),
        ...overrides
    };

    Object.defineProperty(navigator, "mediaSession", { configurable: true, value: stub, writable: true });
    vi.stubGlobal(
        "MediaMetadata",
        class {
            constructor(public init: unknown) {}
        }
    );

    return stub;
};

describe("mediaSession", () => {
    beforeEach(() => {
        // Start from "the API does not exist", which is also a real browser (desktop Firefox).
        Reflect.deleteProperty(navigator, "mediaSession");
    });

    afterEach(() => {
        Reflect.deleteProperty(navigator, "mediaSession");
        vi.unstubAllGlobals();
    });

    describe("when the API is missing entirely", () => {
        it("says nothing, rather than throwing", () => {
            // Desktop Firefox and older WebViews. A missing lock-screen title is the only cost.
            expect(() => publishMetadata(track())).not.toThrow();
            expect(() => publishPlaybackState(true)).not.toThrow();
            expect(() => publishPositionState(100, 10, 1)).not.toThrow();
            expect(bindActionHandlers({} as never)).toStrictEqual([]);
        });
    });

    describe("naming what is playing", () => {
        it("passes the track through, with artwork when it has a cover", () => {
            const stub = install();

            publishMetadata(track());

            expect((stub.metadata as { init: Record<string, unknown> }).init).toStrictEqual({
                title: "Airbag",
                artist: "Radiohead",
                album: "OK Computer",
                artwork: [{ src: "/covers/a.jpg", type: "image/jpeg" }]
            });
        });

        it("clears the metadata when nothing is loaded", () => {
            const stub = install({ metadata: "stale" });

            publishMetadata(null);

            // Not a nicety: leftover metadata means a lock screen still offering a track
            // the queue no longer holds.
            expect(stub.metadata).toBeNull();
        });
    });

    describe("the cursor", () => {
        it("clamps the position into the duration before handing it over", () => {
            const stub = install();

            publishPositionState(100, 250, 1);

            // `setPositionState` THROWS on a position past the duration, and the two numbers
            // reach here from different places — the database's measurement and the element's
            // real playhead — so a file whose tags disagree with its bytes must not break it.
            expect(stub.setPositionState).toHaveBeenCalledWith({ duration: 100, position: 100, playbackRate: 1 });
        });

        it("says nothing about a duration it cannot use", () => {
            const stub = install();

            publishPositionState(Number.POSITIVE_INFINITY, 5, 1);
            publishPositionState(0, 5, 1);

            // A VBR MP3 with no Xing header reports Infinity until fully downloaded.
            expect(stub.setPositionState).not.toHaveBeenCalled();
        });

        it("survives an implementation that throws anyway", () => {
            const stub = install({
                setPositionState: vi.fn(() => {
                    throw new TypeError("nope");
                })
            });

            expect(() => publishPositionState(100, 10, 1)).not.toThrow();
            expect(stub.setPositionState).toHaveBeenCalled();
        });

        it("skips a browser with Media Session but no position support", () => {
            install({ setPositionState: undefined as never });

            expect(() => publishPositionState(100, 10, 1)).not.toThrow();
        });
    });

    describe("the OS transport controls", () => {
        it("wires every action and hands back the undo for each", () => {
            const stub = install();
            const handlers = {
                play: vi.fn(),
                pause: vi.fn(),
                previous: vi.fn(),
                next: vi.fn(),
                seekBy: vi.fn(),
                seekTo: vi.fn()
            };

            const undo = bindActionHandlers(handlers);

            const wired = stub.setActionHandler.mock.calls.map(([action]) => action);
            expect(wired).toStrictEqual([
                "play",
                "pause",
                "stop",
                "previoustrack",
                "nexttrack",
                "seekbackward",
                "seekforward",
                "seekto"
            ]);
            expect(undo).toHaveLength(8);

            // Teardown is RETURNED rather than applied here, so the player can push it onto
            // the same list that undoes its own listeners — one place that clears everything.
            stub.setActionHandler.mockClear();
            for (const off of undo) off();
            expect(stub.setActionHandler.mock.calls.every(([, handler]) => handler === null)).toBe(true);
        });

        it("treats stop as a pause, and seeks by a fixed step in each direction", () => {
            const stub = install();
            const handlers = {
                play: vi.fn(),
                pause: vi.fn(),
                previous: vi.fn(),
                next: vi.fn(),
                seekBy: vi.fn(),
                seekTo: vi.fn()
            };
            bindActionHandlers(handlers);

            const call = (action: string) =>
                stub.setActionHandler.mock.calls.find(([name]) => name === action)?.[1] as (payload?: unknown) => void;

            call("stop")();
            expect(handlers.pause).toHaveBeenCalledTimes(1);

            call("seekbackward")();
            call("seekforward")();
            expect(handlers.seekBy.mock.calls).toStrictEqual([[-10], [10]]);

            // `seekto` carries a payload, and a call without a usable one must be ignored.
            call("seekto")({ seekTime: 42 });
            call("seekto")({});
            expect(handlers.seekTo.mock.calls).toStrictEqual([[42]]);
        });

        it("keeps wiring the rest when one action is unknown to the browser", () => {
            const stub = install({
                setActionHandler: vi.fn((action: string) => {
                    if (action === "seekto") throw new TypeError("unsupported");
                })
            });

            const undo = bindActionHandlers({
                play: vi.fn(),
                pause: vi.fn(),
                previous: vi.fn(),
                next: vi.fn(),
                seekBy: vi.fn(),
                seekTo: vi.fn()
            });

            // Seven wired, the eighth refused — and no undo registered for the one that failed,
            // or teardown would call `setActionHandler` for an action that never took.
            expect(undo).toHaveLength(7);
            expect(stub.setActionHandler).toHaveBeenCalledTimes(8);
        });
    });

    describe("the play state", () => {
        it("mirrors the intent both ways", () => {
            const stub = install();

            publishPlaybackState(true);
            expect(stub.playbackState).toBe("playing");

            publishPlaybackState(false);
            expect(stub.playbackState).toBe("paused");
        });
    });
});
