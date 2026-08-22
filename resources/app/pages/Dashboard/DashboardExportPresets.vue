<script setup lang="ts">
/******************************************************************************
 * DashboardExportPresets
 * The dashboard's "export presets" section — a heading and one link, to the page where the
 * reader's .m3u export presets are made and managed.
 *
 * IT IS A SIGNPOST, NOT A LIST, the same design DashboardShares follows: a reader may hold one
 * preset or a dozen, and a section of unknown length in the middle of a settings page pushes
 * everything below it out of reach. The dashboard says the thing exists and where it is;
 * `/dashboard/export-presets` says what is in it.
 *
 * IT IS DRAWN FOR EVERYONE, WHICH IS WHERE IT PARTS COMPANY WITH THE SHARES SECTION ABOVE IT.
 * That one appears only for a reader who has shared something, because a share link is a thing
 * they MADE and a section explaining a feature nobody has used is a section everybody scrolls
 * past. A preset is a SETTING, and the dashboard is where settings live and is exhaustive — so
 * this is also the one place a reader who has never heard of presets can meet them. Gate it and
 * the only remaining way in is the export dialog they are trying to stop retyping into.
 *
 * The link WARMS on hover: `/dashboard/export-presets` is a page a reader only reads, so it is
 * exactly the case LabelledLink's `prefetch` was left opt-in for (CLAUDE.md → the prefetch
 * rule). The link to the FORM, one page further on, deliberately does not.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";

withDefaults(
    defineProps<{
        /** Which edge the headline's glowing-border tab hugs — the dashboard alternates them. */
        align?: "left" | "right";
    }>(),
    { align: "left" }
);

const { t } = useI18n();
</script>

<template>
    <headline :size="3" anchor-id="presetsSection" glow :align="align">
        <icon name="file_export" />
        {{ t("dashboard.presets.headline") }}
    </headline>

    <!-- A `div.form` rather than a <Form>: the section submits nothing, and the class is what
         gives it the same column, inset and rhythm as the settings sections around it — a link
         floating at page width beside them would read as a stray paragraph. -->
    <div class="form">
        <p>{{ t("dashboard.presets.summary") }}</p>
        <labelled-link href="/dashboard/export-presets" icon="file_export" prefetch>
            {{ t("dashboard.presets.link") }}
        </labelled-link>
    </div>
</template>
