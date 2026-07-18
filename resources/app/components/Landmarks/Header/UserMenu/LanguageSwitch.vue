<script setup lang="ts">
/******************************************************************************
 * LanguageSwitch
 * The language picker inside the UserMenu, sitting alongside ThemeSwitch as the
 * app's second preference toggle. Renders one popover-list item per supported
 * locale (from the `supportedLocales` shared prop); the active one carries
 * `--selected`. Selecting a locale lazy-loads its catalog, flips vue-i18n
 * (reactive re-render) + <html lang>, then persists the choice server-side via a
 * fire-and-forget POST /lang/{locale} (guests → session, users → DB). There is
 * no Inertia visit / reload — the UI updates optimistically. Ported from
 * cantrip.me's LanguageMenu + LanguageMenuItem, folded into one component.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { getI18n, loadLocaleMessages, setI18nLanguage } from "@/i18n";

const page = usePage();
const { t, locale: currentLocale } = useI18n();

/** Supported locales shared from the server (App\Enums\Locale::values()). */
const supportedLocales = computed(() => page.props.supportedLocales);

/** Native-language names — proper nouns, rendered identically in every UI language. */
const endonyms: Record<string, string> = { de: "Deutsch", en: "English" };
const endonym = (loc: string): string => endonyms[loc] ?? loc;

/** Resolve a locale to its bundled flag SVG URL (Vite rewrites the path at build). */
const flagSrc = (loc: string): string => new URL(`../../../../assets/flags/${loc}.svg`, import.meta.url).href;

/** @emits close — dismiss the surrounding user-menu popover after a switch. */
const emit = defineEmits<{ close: [] }>();

/**
 * Switch to `loc`: load its catalog, activate it (reactive) + set <html lang>,
 * then persist to the server. No-ops if it is already the active locale.
 */
const onSelect = async (loc: string): Promise<void> => {
    if (currentLocale.value === loc) return;
    emit("close");

    const i18n = getI18n();
    await loadLocaleMessages(i18n, loc);
    setI18nLanguage(i18n, loc);

    await fetch(`/lang/${loc}`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json"
        }
    });
};
</script>

<template>
    <li v-for="loc in supportedLocales" :key="loc">
        <button
            type="button"
            class="popover-list-item"
            :class="{ 'popover-list-item--selected': currentLocale === loc }"
            :aria-current="currentLocale === loc ? 'true' : undefined"
            :aria-label="`${t('header.language.label')}: ${endonym(loc)}`"
            @click="onSelect(loc)"
        >
            <img class="flag" :src="flagSrc(loc)" alt="" />
            {{ endonym(loc) }}
        </button>
    </li>
</template>
