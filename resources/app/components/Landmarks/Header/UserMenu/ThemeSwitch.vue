<script setup lang="ts">
/******************************************************************************
 * ThemeSwitch
 * a three-way light / dark / system colour-scheme picker, ported from
 * cantrip.me (with its ThemeSwitchItem consolidated inline). It drives the
 * <meta name="color-scheme"> tag — which is what CSS light-dark() and the
 * `theme-dark` mixin key off — and persists the choice in localStorage.
 * "light dark" means "follow the OS".
 *
 * THE CONTROL ITSELF IS NOW SHARED (Components/UI/OptionBubbles), which is why this
 * file has no styles left. The bubbles-with-a-sliding-pill pattern started here and
 * was generalised when the player's settings needed it twice; keeping a second copy
 * meant two sets of tokens and two pill implementations for one look. What stayed
 * behind is the only part that was ever about colour schemes: the meta tag, the
 * persistence, and the three values.
 *
 * The pill also moved from `:has(input:nth-of-type(n):checked)` to arithmetic on the
 * option count — same three stops, but 100%/3 rather than the 33% / 66% this file
 * used to approximate.
 *****************************************************************************/
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { BubbleOption } from "Components/UI/OptionBubbles.vue";
import OptionBubbles from "Components/UI/OptionBubbles.vue";

const { t } = useI18n();

/** The <meta name="color-scheme"> tag that controls the browser's colour scheme. */
const colorScheme = document.querySelector("meta[name='color-scheme']");
if (!colorScheme) {
    throw new Error("Meta tag with name='color-scheme' not found");
}

/** Applies a colour scheme immediately by updating the meta tag's content. */
function updateMeta(val: string): void {
    colorScheme!.setAttribute("content", val);
}

/**
 * The active theme — seeded from localStorage, then the meta tag, then "follow the OS".
 *
 * A REF, NOT A COMPUTED WITH A SETTER, which it was until 2026-08-06 and which was a bug
 * waiting for a witness. That getter read `localStorage` and an attribute, neither of
 * which Vue tracks, so it never re-evaluated: the old markup got away with it because the
 * pill was drawn by `:has(input:nth-of-type(n):checked)` — the browser's own radio state,
 * which changes whether or not Vue notices. Moving the pill onto component state (see the
 * banner) made the staleness visible immediately: the scheme changed and the pill stayed
 * put. Owning the value here is what makes both true at once.
 */
const theme = ref<string>(localStorage.getItem("theme") || colorScheme.getAttribute("content") || "light dark");

/**
 * Apply a chosen scheme: the ref (so the control redraws), the tag (so the page changes)
 * and storage (so it outlives the tab).
 *
 * Bound to the group's update event rather than through `v-model`, and not a watcher on the
 * ref either, because both would skip the case where the scheme chosen is the one already
 * active: assigning an unchanged ref notifies nothing, and then "the reader explicitly chose
 * to follow the OS" is indistinguishable in storage from "following the OS is merely the
 * default they never touched".
 */
function setTheme(value: string): void {
    theme.value = value;
    updateMeta(value);
    localStorage.setItem("theme", value);
}

/**
 * Selectable options — `"light dark"` delegates to the OS preference. Labels are translated
 * (and re-render on a locale switch).
 *
 * Each carries THREE strings, because they are read by different people at different moments.
 * `label` is the option's name, which assistive tech announces ("Dunkel, Optionsfeld, 1 von
 * 3"). `hint` is what someone hovering an unlabelled glyph wants: the action it performs.
 * `selectedHint` is what that same hover should say once the option is already in force,
 * where offering the action again would read as though the click had not registered — so it
 * states the mode instead. The system option spends a clause on what "system" even means in
 * both, since it is the one choice whose result is not visible in the switch itself.
 */
const options = computed<BubbleOption[]>(() => [
    {
        value: "dark",
        label: t("header.theme.dark"),
        hint: t("header.theme.hint.dark"),
        selectedHint: t("header.theme.current.dark"),
        icon: "dark"
    },
    {
        value: "light",
        label: t("header.theme.light"),
        hint: t("header.theme.hint.light"),
        selectedHint: t("header.theme.current.light"),
        icon: "light"
    },
    {
        value: "light dark",
        label: t("header.theme.system"),
        hint: t("header.theme.hint.system"),
        selectedHint: t("header.theme.current.system"),
        icon: "system"
    }
]);

/** Re-apply the persisted theme on mount, in case the server-rendered default differs. */
onMounted(() => {
    if (colorScheme.getAttribute("content") !== theme.value) updateMeta(theme.value);
});
</script>

<template>
    <option-bubbles
        :model-value="theme"
        :options="options"
        name="theme"
        :label="t('header.theme.label')"
        @update:model-value="setTheme"
    />
</template>
