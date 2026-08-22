<script setup lang="ts">
/******************************************************************************
 * ExportPresetsPage
 * The reader's .m3u export presets, at /dashboard/export-presets (route `dashboard.presets`,
 * behind auth, rendered by Dashboard\ExportPresetsController) — the only place a preset is
 * made, edited, made default or deleted.
 *
 * WHAT A PRESET IS: one device. Exporting a playlist asks three questions — which .m3u
 * flavour, which encoding, what to put in front of every path — and all three are answered by
 * the machine that will PLAY the file rather than by the playlist or by this server. A car head
 * unit wants a simple list in Windows-1252 with no prefix; a Mac wants an extended list in
 * UTF-8 under /Volumes. So the three travel together under a name, and the export dialog picks
 * one instead of asking a reader to reconstruct it from memory every time.
 *
 * ONE LIST, NOT TWO. Unlike the share links this page sits beside, presets have no second
 * state to separate: nothing here expires, so every row is live and the only distinction is
 * which one the dialog opens on — which is a marker on a row, not a list of its own.
 *
 * THE EMPTY STATE IS A REAL AND COMMON STATE, and it says what happens meanwhile rather than
 * only that there is nothing here: without presets the export dialog falls back to the
 * server's configured prefix, which is a working arrangement and not a broken one.
 *
 * DELETING IS CONFIRMED, CHANGING THE DEFAULT IS NOT. The asymmetry is the consequence: one
 * throws away values the reader typed, the other moves a marker they can move straight back.
 *****************************************************************************/
import { Head, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { ExportPreset } from "Types/exportPresets";
import ExportPresetDeleteModal from "./ExportPresetDeleteModal.vue";
import ExportPresetRow from "./ExportPresetRow.vue";

defineProps<{
    /**
     * The reader's presets, default first then by name — the order the export modal's picker
     * draws them in, so the row marked here is the one that dialog opens on. Empty is normal.
     */
    presets: ExportPreset[];
}>();

const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "dashboard.page.title", icon: "user-settings", href: "/dashboard" },
    { labelKey: "dashboard.presets.title", icon: "file_export" }
]);

/** The row a reader has asked to delete, or null while the dialog is shut. */
const removing = ref<ExportPreset | null>(null);

/**
 * Move the default marker to this preset.
 *
 * NO CONFIRMATION, unlike deleting: the act is reversible in one press, and a dialog in front
 * of it would cost more attention than the change is worth. `preserveScroll` because the reader
 * is looking at the row they just pressed and the list must not jump under them.
 */
function makeDefault(preset: ExportPreset): void {
    router.patch(`/dashboard/export-presets/${preset.id}/default`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('dashboard.presets.title')" />
    <!-- Outside the Container like every other page heading — the glowing border has to reach
         the window edge so its seam hides off-screen (see Container). -->
    <headline glow align="left">
        <icon name="file_export" :size="3" />
        {{ t("dashboard.presets.title") }}
    </headline>
    <container>
        <p class="presets__intro">{{ t("dashboard.presets.intro") }}</p>

        <!-- A LIST, semantically: a screen reader gets "list, N items" before the rows, which is
             the one thing a bare stack of <div>s would say worse. Labelled by the page's own
             heading so it is told apart when read out of context. -->
        <ul v-if="presets.length" class="presets" :aria-label="t('dashboard.presets.title')">
            <export-preset-row
                v-for="preset in presets"
                :key="preset.id"
                :preset="preset"
                @remove="removing = $event"
                @make-default="makeDefault"
            />
        </ul>

        <!-- Says what happens meanwhile, not just that the list is empty — see the banner. -->
        <p v-else class="presets__empty">{{ t("dashboard.presets.empty") }}</p>

        <!-- NO `prefetch`: it leads to a form, and a prefetch landing after the reader has
             navigated there is applied to the page they are now on, re-keying it — which
             discards what has been typed and saves what the server sent (CLAUDE.md → the
             prefetch rule). LabelledLink leaves it opt-in for exactly this reason. -->
        <labelled-link href="/dashboard/export-presets/create" icon="playlist_add">
            {{ t("dashboard.presets.create") }}
        </labelled-link>
    </container>

    <export-preset-delete-modal
        v-if="removing"
        :id="removing.id"
        :name="removing.name"
        @close="removing = null"
    />
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* Air above and below the page's one block. Reaching Container's root from here works because
   Vue puts this page's scope id on it, and it is the narrowest way to say "on this page":
   Container is shared by every page in the app and holds no inset of its own.

   `margin-block`, NOT the `3rem 0` shorthand — Container centres itself with
   `margin-inline: auto`, and the shorthand would reset that and drop the whole page body
   against the left edge. */
.container {
    margin-block: map.get(s.$c-presets, "block-margin");
}

.presets__intro {
    margin: 0 0 map.get(s.$c-card, "gap");
}

/* A column of rows at every width — the list is read down, and the first row is the one the
   export dialog opens on, which a fluid column count would scramble. */
.presets {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0 0 map.get(s.$c-card, "gap");
    gap: map.get(s.$c-presets, "gap");

    list-style: none;
}

.presets__empty {
    margin: 0 0 map.get(s.$c-card, "gap");
}
</style>
