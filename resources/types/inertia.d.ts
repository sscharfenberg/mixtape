import type { BreadcrumbItem } from "Composables/useBreadcrumbs";
import type { QueueTrack } from "Composables/usePlayerQueue";

/**
 * Props shared with every Inertia page by `HandleInertiaRequests::share()`,
 * plus the layout props a page publishes for itself.
 * Extend this as more shared data is added on the server side.
 */
declare module "@inertiajs/core" {
    export interface InertiaConfig {
        // The app name is not shared here — the frontend reads it from
        // import.meta.env.VITE_APP_NAME (mirrored from Laravel's APP_NAME).
        // Add shared props as server-side shared data grows.
        sharedPageProps: {
            auth: {
                // `null` until a user is logged in (prep for Fortify).
                user: {
                    // A UUID string, not an integer: User uses HasUuids (see the
                    // create_users_table migration). Typed `number` until the play
                    // queue became the first thing to actually read it.
                    id: string;
                    name: string;
                    email: string;
                } | null;
            };
            // The session's CSRF token. Inertia's own visits carry it themselves;
            // this is for the one place that talks to the server WITHOUT a visit —
            // the play queue's sync PUT (see usePlayerQueue).
            csrfToken: string;
            // The queue this user left behind, from `player_states`. Present only on a
            // FULL page load (HandleInertiaRequests skips it for client-side visits,
            // where the persistent layout already holds a live queue), and `null` both
            // for a guest and for a user who has never synced one — which the client
            // reads as "keep whatever localStorage has".
            playerState: {
                tracks: QueueTrack[];
                currentIndex: number;
                repeat: boolean;
                shuffle: boolean;
                // The CLIENT's clock at its last change — what settles which copy is newer.
                updatedAt: number;
                // How far into the loaded track this queue had got, in milliseconds.
                positionMs: number;
            } | null;
            // Player settings the client honours but the server owns
            // (config/mixtape.php → the player). `positionHeartbeat` is in seconds of
            // PLAYBACK, and 0 turns the heartbeat off.
            player: {
                positionHeartbeat: number;
            };
            // Active locale (resolved server-side by ConfigureLocale) and the
            // supported set, used to seed vue-i18n and render the language
            // switcher (see i18n.ts, main.ts, LanguageSwitch.vue).
            locale: string;
            supportedLocales: string[];
            // Backend feature flags gating guest-only links. Placeholder
            // values until Fortify supplies real ones (see HandleInertiaRequests).
            features: {
                registration: boolean;
                resetPasswords: boolean;
                emailVerification: boolean;
                twoFactorAuthentication: boolean;
            };
            // Session flash, bridged into the toast (see ToastContainer.vue).
            // Always shared (a closure in HandleInertiaRequests); fields are
            // null when nothing was flashed. `type` is a raw string, cast to a
            // ToastType in the component; `nonce` is fresh whenever a message
            // exists so the toast watcher fires for every flash.
            flash: {
                message: string | null;
                type: string | null;
                duration: number | null;
                nonce: string | null;
            };
        };
        // Props a PAGE publishes to its layout, rather than anything the server
        // sends. Inertia spreads these onto FullLayout and — crucially — resets
        // them inside `swapComponent`, so the outgoing page's values survive
        // until the incoming one is actually on screen (see useBreadcrumbs).
        layoutProps: {
            // The trail declared by the current page, read by FullLayout and
            // handed to the single <Breadcrumb>. Absent on a page that declares
            // none, which is how the trail hides itself.
            breadcrumbs: BreadcrumbItem[];
        };
    }
}
