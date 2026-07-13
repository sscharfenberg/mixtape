export {};

/**
 * Props shared with every Inertia page by `HandleInertiaRequests::share()`.
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
                    id: number;
                    name: string;
                    email: string;
                } | null;
            };
            // Backend feature flags gating guest-only links. Placeholder
            // values until Fortify supplies real ones (see HandleInertiaRequests).
            features: {
                registration: boolean;
                resetPasswords: boolean;
                emailVerification: boolean;
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
    }
}
