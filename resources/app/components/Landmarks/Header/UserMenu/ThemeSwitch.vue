<script setup lang="ts">
/******************************************************************************
 * ThemeSwitch
 * a three-way light / dark / system colour-scheme picker, ported from
 * cantrip.me (with its ThemeSwitchItem consolidated inline). It drives the
 * <meta name="color-scheme"> tag — which is what CSS light-dark() and the
 * `theme-dark` mixin key off — and persists the choice in localStorage.
 * "light dark" means "follow the OS".
 *****************************************************************************/
import { computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

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
 * The active theme.
 * - **get**: localStorage → meta tag → `"light dark"` (OS default).
 * - **set**: updates the meta tag (instant switch) and localStorage (persistence).
 */
const theme = computed<string>({
    get() {
        return localStorage.getItem("theme") || colorScheme.getAttribute("content") || "light dark";
    },
    set(val) {
        updateMeta(val);
        localStorage.setItem("theme", val);
    }
});

/** Selectable options — `"light dark"` delegates to the OS preference. Labels are translated (and re-render on a locale switch). */
const options = computed(() => [
    { value: "dark", label: t("header.theme.dark"), icon: "dark" },
    { value: "light", label: t("header.theme.light"), icon: "light" },
    { value: "light dark", label: t("header.theme.system"), icon: "system" }
]);

/** Re-apply the persisted theme on mount, in case the server-rendered default differs. */
onMounted(() => {
    if (colorScheme.getAttribute("content") !== theme.value) updateMeta(theme.value);
});
</script>

<template>
    <div class="theme-switch__list" role="radiogroup" :aria-label="t('header.theme.label')">
        <template v-for="option in options" :key="option.value">
            <input
                :id="'theme' + option.value.replace(' ', '')"
                name="theme"
                type="radio"
                :value="option.value"
                :checked="theme === option.value"
                :aria-label="option.label"
                @change="theme = option.value"
            />
            <label :for="'theme' + option.value.replace(' ', '')" :aria-label="option.label" class="theme-switch__item">
                <icon :name="option.icon" />
            </label>
        </template>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

.theme-switch {
    &__list {
        display: flex;
        position: relative;
        justify-content: space-between;

        // the sliding "pill" behind the active option.
        &::before {
            display: block;
            position: absolute;
            top: 0;
            left: 0; // resting position — option 1 (dark); options 2/3 shift it right

            z-index: map.get(z.$index, "background");

            width: calc(100% / 3);
            height: 100%;

            background-color: map.get(c.$c-theme-switch, "background-selected");
            border-radius: map.get(s.$c-theme-switch, "radius");

            content: "";

            @media (prefers-reduced-motion: no-preference) {
                transition:
                    left ti.$c-theme-switch linear,
                    background-color ti.$c-theme-switch linear;
            }
        }

        // radios stay in the DOM but visually hidden — still focusable/tabbable for
        // keyboard + screen-reader users (unlike display:none). Native arrow keys move
        // focus AND selection within the group, which is what drives the theme change.
        input {
            position: absolute;

            overflow: hidden;

            width: 1px;
            height: 1px;
            padding: 0;
            border: 0;
            margin: -1px;
            clip-path: inset(50%);

            white-space: nowrap;
        }

        &:has(input:nth-of-type(2):checked)::before {
            left: 33%;
        }

        &:has(input:nth-of-type(3):checked)::before {
            left: 66%;
        }
    }

    &__item {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-grow: 1;

        padding: map.get(s.$c-theme-switch, "padding");
        gap: 4px;

        color: map.get(c.$c-theme-switch, "surface");

        line-height: 1;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-theme-switch linear;
        }

        // visible keyboard focus ring on the focused option's label
        // (:focus-visible → keyboard only, not mouse clicks).
        input:focus-visible + & {
            outline: 2px solid map.get(c.$c-theme-switch, "surface-selected");
            outline-offset: -2px;
        }

        input:checked + & {
            color: map.get(c.$c-theme-switch, "surface-selected");
        }
    }
}
</style>
