<script setup lang="ts">
/******************************************************************************
 * WelcomePage
 * The public landing page at `/` (rendered by HomeController) — a claim, a short explanation
 * with the one call to action, and the two cards that answer "how much is here?" with numbers:
 * the music collection's totals and the audiobook collection's.
 *
 * THE PROSE COMES FIRST because the numbers cannot introduce themselves. A visitor who was sent
 * a link arrives knowing nothing; "1.238 Alben" is only meaningful once they have been told
 * whose collection this is and how a person gets in. WelcomeIntro carries both, and its banner
 * has the rest.
 *
 * THE CARDS ARE THE MUSIC AND AUDIOBOOKS PAGES' OWN, minus their search field (`:searchable
 * ="false"`). Not a lookalike pair built for this page: the tiles, their tooltips, the
 * unbreakable values and the wrapping-flex-lines layout are each the answer to a bug found by
 * looking at a browser, and a second copy of them would be the copy that quietly stops
 * matching. The field is what a guest cannot have — `/search` is inside the auth group, so a
 * box here would answer 401 to everything typed into it.
 *
 * TOTALS ARE ALL THAT LEAVES. This route is outside `auth` and the instance is reachable from
 * the internet, so everything on this page is world-readable. Six numbers per collection is the
 * point of a landing page a friend was linked to; not one title, artist or file name appears,
 * and everything that names a row stays behind `auth` or behind a share.
 *
 * TWO EQUAL COLUMNS THAT COLLAPSE TO ONE, which is the WidgetGroup's own `auto-fit` grid doing
 * the work: it collapses the tracks no card lands in and lets the `1fr` on the rest absorb
 * their width, so at any viewport with room for two tracks the pair splits the row down the
 * middle however wide the cage is, and below that it is one column.
 *
 * `pair` MOVES WHERE "below that" IS. These are the densest cards in the app, and on the
 * group's ordinary floor they stayed two-up down to ~590px — where a written-out playtime
 * wrapped to three lines in a 275px card. The variant collapses them at about 800px instead.
 *
 * `wide` IS THE ONE THAT READS BACKWARDS, so it is worth stating: both cards default to wide
 * because on their own pages they want the room, and wide means "span two tracks". Two of them
 * asking for two tracks each, in a group that fits three, is one card per row — stacked, which
 * is exactly what this page does not want. Turning it off is what puts them side by side.
 *
 * The one page that deliberately sets NO breadcrumbs: every trail already opens with a home
 * chip pointing here, so a crumb for this page would be that chip repeating itself with
 * nowhere to go. main.ts clears the trail on navigation, so Breadcrumb renders nothing here.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import AudiobookStatsWidget from "Components/UI/Widget/Consumers/AudiobookStatsWidget.vue";
import StatsWidget from "Components/UI/Widget/Consumers/StatsWidget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
import type { AudiobookStats } from "Types/audiobooks";
import type { CollectionStats } from "Types/music";
import WelcomeIntro from "./WelcomeIntro.vue";

const { t } = useI18n();

defineProps<{
    /** The music collection's totals, for the left-hand card. */
    musicStats: CollectionStats;
    /** The audiobook collection's totals, for the right-hand one. */
    audiobookStats: AudiobookStats;
}>();
</script>

<template>
    <!-- A title of its own rather than none at all: without a Head the head manager leaves
         whatever the page navigated FROM had, so arriving here from Music would keep saying
         "Musik". -->
    <Head :title="t('home.title')" />
    <!-- Outside the Container like every other page heading — its glowing border has to reach
         the window edge so the seam hides off-screen (see Container). -->
    <headline glow>
        <icon name="home" :size="3" />
        {{ t("home.claim") }}
    </headline>
    <container>
        <welcome-intro />

        <widget-group pair>
            <stats-widget v-bind="musicStats" :searchable="false" :wide="false" />
            <audiobook-stats-widget v-bind="audiobookStats" :searchable="false" :wide="false" />
        </widget-group>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/sizes" as s;

/* The intro and the cards below it, spaced by the card gap — a page reads the token of the
   component that already defines it rather than minting one (CLAUDE.md → Design tokens). The
   Audiobooks page carries the same two lines for the same pair of blocks. */
.container > * + * {
    margin-block-start: map.get(s.$c-card, "gap");
}
</style>
