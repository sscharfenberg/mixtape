/**
 * Type-safe translation keys.
 *
 * The German catalog (resources/app/lang/de.json) is the single source of truth
 * for the message schema — it is the default locale and the most complete. By
 * augmenting vue-i18n's `DefineLocaleMessage` with its shape, every `t('…')` /
 * `$t('…')` call is checked against the real key tree at build time (vue-tsc),
 * so a typo or a missing key fails `npm run build` instead of silently rendering
 * the raw key. Keep de.json and en.json structurally in sync.
 */
import type deMessages from "@/lang/de.json";

type MessageSchema = typeof deMessages;

declare module "vue-i18n" {
    // The empty body is the whole point: DefineLocaleMessage inherits the catalog
    // shape from MessageSchema, which is what makes t()/$t() keys type-safe.
    // eslint-disable-next-line @typescript-eslint/no-empty-object-type
    export interface DefineLocaleMessage extends MessageSchema {}
}
