export {};

/**
 * Props shared with every Inertia page by `HandleInertiaRequests::share()`.
 * Extend this as more shared data is added on the server side.
 */
declare module "@inertiajs/core" {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
        };
    }
}
