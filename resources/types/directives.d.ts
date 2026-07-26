/**
 * Type-safe global directives.
 *
 * Directives registered on the app instance (main.ts) are invisible to vue-tsc:
 * a template using one would fail to resolve `vTooltip` and break
 * `npm run type-check`. Augmenting Vue's `GlobalDirectives` registers them for
 * the template compiler, so `v-tooltip="…"` is checked against the directive's
 * real value type instead of being an unknown name. Add one entry per global
 * directive.
 */
import type { vTooltip } from "@/directives/vTooltip";

declare module "vue" {
    export interface GlobalDirectives {
        vTooltip: typeof vTooltip;
    }
}
