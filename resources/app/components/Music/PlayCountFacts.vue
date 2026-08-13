<script setup lang="ts">
/******************************************************************************
 * PlayCountFacts
 * The two hero tiles saying how often a subject has been listened to — VON DIR and
 * VON ANDEREN — for a song, an artist, a genre, an album or a playlist.
 *
 * ONE COMPONENT FOR ALL FIVE because only the tooltip's noun differs. The tiles, the
 * glyph, the "hide a zero" rule and the live refresh below are identical everywhere,
 * and four copies of that is how four pages start disagreeing about what a play is.
 * It renders FactPairs, so drop it straight into a HeroSection's `#metadata` slot
 * beside the artist / album / year tiles it belongs with.
 *
 * A COUNT OF ZERO IS NOT SHOWN AT ALL — the owner's rule, and the right one: a page
 * full of "0×" on a fresh library would say only that the feature exists. Each half
 * appears on its own terms, so a record only the reader has heard shows one tile.
 *
 * ONE GLYPH FOR BOTH, the ear (`plays`): the label carries WHOSE listening it is and
 * the icon says what KIND of fact it is, which is what an icon in a FactPair is for.
 *
 * A TOOLTIP AND A DESCRIPTION, because a bare number cannot say what counts as a play,
 * whether repeats count, or what the subject's figure includes. `v-tooltip` is
 * pointer-and-focus only, so the same sentence goes to FactPair's `description` — which
 * renders it visually hidden and wires up `aria-describedby`.
 *
 * IT KEEPS ITSELF UP TO DATE. The counts arrive as Inertia props rendered before the
 * listener had heard anything, so a track finishing on this very page would otherwise
 * leave the figure a request behind until the next navigation. See `refresh` below.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import FactPair from "Components/UI/Card/FactPair.vue";
import { usePlayEvents } from "Composables/usePlayEvents";
import type { PlayCountSubject } from "Types/plays";
import { formatTimesPlayed } from "Utils/formatting";

// Re-exported so `import PlayCountFacts, { type PlayCountSubject }` keeps working for the pages
// that pass the prop. The list itself moved to `Types/plays` on 2026-08-13, and had to:
// `<script setup>` may export types but not values, and it has to be a VALUE for the spec to loop
// over — which is what keeps a new subject from shipping without its two sentences. That file
// carries the story.
export type { PlayCountSubject };

const props = defineProps<{
    /** The reader's own listens and everybody else's, raw (App\Services\Player\PlayCounts). */
    plays: { own: number; others: number };
    /** Which noun the tooltips should use. */
    subject: PlayCountSubject;
}>();

const { t } = useI18n();
const { playsRecorded } = usePlayEvents();

/**
 * The two sentences for this subject.
 *
 * A switch over literal keys rather than one built from `subject`, because the catalog
 * types every `t()` path against de.json — an interpolated key hands the checker a string
 * it cannot resolve, and the build stops being able to tell a typo from a valid path.
 */
const tips = computed<{ own: string; others: string }>(() => {
    switch (props.subject) {
        case "artist":
            return { own: t("music.plays.artist.ownTip"), others: t("music.plays.artist.othersTip") };
        case "genre":
            return { own: t("music.plays.genre.ownTip"), others: t("music.plays.genre.othersTip") };
        case "album":
            return { own: t("music.plays.album.ownTip"), others: t("music.plays.album.othersTip") };
        case "playlist":
            return { own: t("music.plays.playlist.ownTip"), others: t("music.plays.playlist.othersTip") };
        default:
            return { own: t("music.plays.song.ownTip"), others: t("music.plays.song.othersTip") };
    }
});

/*
 * Re-read the counts whenever the server has accepted a listen.
 *
 * A PARTIAL RELOAD naming the `plays` prop — the Inertia-native way to fetch more of a
 * page, and the reason every controller sends these counts as their own top-level prop
 * rather than folding them into `artist` / `album` / `genre`. `router.reload` forces
 * `preserveState` and `preserveScroll`, so an open popover, a table's sort and the
 * reader's place on the page all survive; only the two numbers change.
 *
 * IT ASKS ON EVERY PLAY RATHER THAN GUESSING WHETHER THIS PAGE CARES. The obvious guard
 * — "only if the played track is the one on screen" — is right for a song page and wrong
 * for the other three: an artist, genre or album counts every listen to any of ITS
 * tracks, and the browser does not know which artist the played track belonged to (the
 * queue holds titles, not taxonomy). One rule for all four, and the server recomputing is
 * definitionally right.
 *
 * The watcher is stopped when the page unmounts, which is what keeps a reload from
 * firing at a page the listener has already left.
 */
watch(playsRecorded, () => {
    router.reload({ only: ["plays"] });
});
</script>

<template>
    <fact-pair
        v-if="plays.own > 0"
        v-tooltip="tips.own"
        icon="plays"
        :label="t('music.plays.own')"
        :value="formatTimesPlayed(plays.own)"
        :description="tips.own"
    />
    <fact-pair
        v-if="plays.others > 0"
        v-tooltip="tips.others"
        icon="plays"
        :label="t('music.plays.others')"
        :value="formatTimesPlayed(plays.others)"
        :description="tips.others"
    />
</template>
