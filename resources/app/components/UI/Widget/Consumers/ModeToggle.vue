<script setup lang="ts">
/******************************************************************************
 * ModeToggle
 * The latest / random switch shown in a music widget's header. For now an
 * UNSTYLED pair of native radios (to be restyled into a real toggle switch);
 * two-way binds the active mode via v-model. `name` groups the two radios, so
 * every toggle on the page must be given a unique one.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import type { WidgetMode } from "Types/music";

const { t } = useI18n();

defineProps<{
    /** Unique radio-group name — distinct per widget so the page's toggles don't collide. */
    name: string;
}>();

const mode = defineModel<WidgetMode>({ required: true });
</script>

<template>
    <span class="mode-toggle">
        <label>
            <input v-model="mode" type="radio" :name="name" value="latest" />
            {{ t("music.mode.latest") }}
        </label>
        <label>
            <input v-model="mode" type="radio" :name="name" value="random" />
            {{ t("music.mode.random") }}
        </label>
    </span>
</template>

<style scoped lang="scss">
// Unstyled for now — only nudge the toggle to the trailing edge of the widget
// title and give the two options some breathing room; the radios keep their
// native look until we design the real switch.
.mode-toggle {
    display: inline-flex;

    margin-inline-start: auto;
    gap: 1ch;

    font-size: 0.75em;
    font-weight: 400;
}
</style>
