<script setup lang="ts">
/******************************************************************************
 * NowPlayingQueue
 * The last row of NowPlayingSection: a heading with the queue's totals, over the queue itself.
 *
 * IT LIVES BESIDE THE PANEL rather than in `pages/NowPlaying/`, because it is the queue's SECOND
 * container and this folder is where the queue's containers are (2026-08-12): `PlayQueue` is the
 * sliding panel, this is the one that sits on a page, and `QueueList` is the rows both of them
 * draw. It takes no props and asks `usePlayerQueue` itself, so any page may render it.
 *
 * THE ROWS ARE THE PANEL'S ROWS — `QueueList`, the one definition of them, asked for its `page`
 * layout. This file used to draw its own, which lasted about an hour: two copies of a row drift,
 * and the first thing to drift was the remove glyph (a plain close here, `playlist_remove` in the
 * panel). All this component owns now is the heading and the totals, which the panel puts in its
 * own header for the same reason — a list of its length needs its size stated somewhere.
 *
 * The two-column grid, and why it stops at two, is QueueList's decision and is argued there.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import QueueList from "Components/PlayQueue/QueueList.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { formatClock } from "Utils/formatting";

const { t } = useI18n();
const { tracks, totalDuration } = usePlayerQueue();

/** How long the whole queue runs, for the heading — the same total the panel prints. */
const totalClock = computed(() => formatClock(totalDuration.value));
</script>

<template>
    <section v-if="tracks.length" class="np-queue" :aria-label="t('player.queue.label')">
        <h2 class="np-queue__title">
            <icon name="playlist" :size="1" />
            {{ t("player.queue.label") }}
            <span class="np-queue__total">{{ t("player.queue.summary", tracks.length) }} · {{ totalClock }}</span>
        </h2>

        <queue-list layout="page" />
    </section>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.np-queue {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-now-playing-queue, "gap");
}

/* The heading carries the totals, so the list itself needs no footer — the panel puts them in its
   header for the same reason. */
.np-queue__title {
    display: flex;
    align-items: center;

    margin: 0;

    gap: 1ch;

    font-size: map.get(s.$c-now-playing-queue, "title-font-size");
}

.np-queue__total {
    color: map.get(c.$c-now-playing-queue, "muted");

    font-size: map.get(s.$c-now-playing-queue, "font-size");
    font-weight: 400;
}
</style>
