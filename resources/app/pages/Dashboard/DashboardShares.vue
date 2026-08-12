<script setup lang="ts">
/******************************************************************************
 * DashboardShares
 * The dashboard's "your shared content" section — a heading and one link, to the page that
 * lists the reader's share links and is the only place they can be revoked.
 *
 * IT IS A SIGNPOST, NOT A LIST, and that is the whole design: a reader may hold three links
 * or thirty, and a section of unknown length in the middle of a settings page would push
 * everything below it out of reach. So the dashboard says the thing exists and where it is;
 * `/dashboard/shared` says what is in it.
 *
 * IT RENDERS ONLY FOR A READER WHO HAS SHARED SOMETHING (the `shares` shared prop), which is
 * why it sits at the TOP rather than among the account settings: it is not a setting, and for
 * most readers most of the time it is not there at all. A section explaining a feature nobody
 * has used yet is a section everybody scrolls past.
 *
 * The link WARMS on hover, unlike the rest of the dashboard's links: `/dashboard/shared` is a
 * page a reader only reads, so it is exactly the case LabelledLink's `prefetch` was left
 * opt-in for (CLAUDE.md → the prefetch rule; the forms on this page must never warm).
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
    <headline :size="3" anchor-id="sharesSection" glow :align="align">
        <icon name="share" />
        {{ t("dashboard.shares.headline") }}
    </headline>

    <!-- A `div.form` rather than a <Form>: the section submits nothing, and the class is what
         gives it the same column, inset and rhythm as the four settings sections below it —
         a link floating at page width beside them would read as a stray paragraph. -->
    <div class="form">
        <p>{{ t("dashboard.shares.summary") }}</p>
        <labelled-link href="/dashboard/shared" icon="share" prefetch>
            {{ t("dashboard.shares.link") }}
        </labelled-link>
    </div>
</template>
