<script setup lang="ts">
/******************************************************************************
 * ArtistsWidget
 * The Music page's "Artists" card — four artists, toggled between latest
 * (most recently added, by newest track) and a random pick via the header
 * WidgetModeToggle. Both sets arrive as Inertia props (see MusicController).
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import type { TaxonomyEntry, WidgetMode, WidgetModes } from "Types/music";
import WidgetList from "./WidgetList.vue";

const props = defineProps<WidgetModes<TaxonomyEntry>>();

const { t } = useI18n();
const mode = ref<WidgetMode>("latest");

/** Active-mode artists — a plain name list (no secondary line). */
const items = computed(() => (mode.value === "random" ? props.random : props.latest));
</script>

<template>
    <widget>
        <template #title>
            {{ t("music.widgets.artists") }}
            <widget-mode-toggle v-model="mode" name="artists-mode" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/artists" class="btn btn-default">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
