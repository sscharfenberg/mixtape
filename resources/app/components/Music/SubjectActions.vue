<script setup lang="ts">
/******************************************************************************
 * SubjectActions
 * The two verbs a Music detail page's hero offers for its subject AS A WHOLE: play it, or
 * put it in the queue.
 *
 * IT REPLACED THE HERO'S POPOVER MENU (2026-08-11). The four Music heroes used to keep both
 * behind a "…" trigger beside the title (SubjectMenu, which the playlist page still wears),
 * and a detail page's two most likely actions gave no hint of themselves until it was opened.
 *
 * IT STANDS IN THE ActionPanel, beside "add to playlist" — which is the second arrangement in
 * one day, and the owner's call. It briefly sat in a row of its own BELOW the panel, on the
 * reasoning that the panel was about a reader's own library while these are about the music.
 * That split reads better written down than on screen: what the hero's first row should hold
 * is what a reader is most likely to press, and it is these two. What actually moved out is
 * `download` — see the pages, where it now sits under the panel with `share`, the two actions
 * that take the subject somewhere else.
 *
 * `variant="primary"` on both, so they rest LIT and read as the loudest thing in the panel —
 * which is what they should be on a page whose whole purpose is the subject they act on. No
 * halo, because they stand on the hero's own surface rather than on the page, and a neon pool
 * spilling across it reads as a smudge (Button.vue).
 *
 * THE LABELS NAME THE VERB, NOT THE SUBJECT — "Abspielen" / "Warteschlange", where the menu
 * these replaced said "Album abspielen" (the owner's call, 2026-08-11). The menu needed the
 * noun: a popover opens over the page and its items had to say what they acted on. A button
 * standing in the hero of the thing itself does not, and two short labels keep the row on one
 * line at hero width where two long ones wrapped. That is also why this component takes no
 * props at all any more — nothing about it varies with the subject, and the four pages that
 * render it are describing one thing each.
 *
 * PLAY MEANS REPLACE, and both verbs — including the round trip that fetches a paginated
 * subject's tracks, and the toast an empty one earns — are `useSubjectTracks`'s. Nothing
 * about what these do is decided here; this file is the pair, the labels and the icons.
 *
 * The `tracks` prop is the page saying it already holds them, and it is SubjectMenu's prop
 * of the same name for the same reason: a page that is not paginated has its whole subject
 * on screen already, so a round trip to fetch it would ask the server for what the props
 * carried. The guest share page is that page (/s/{share}); the four Music heroes omit it and
 * the composable fetches `queueTracks` on the first press, exactly as before.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import Icon from "Components/UI/Icon.vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { useSubjectTracks } from "Composables/useSubjectTracks";

const props = defineProps<{
    /**
     * The subject's tracks, when the page already holds all of them. Omit it and the pair
     * fetches `queueTracks` on the first press instead. An empty array is a real answer, not
     * "unset" — the composable checks `!== undefined`, so a subject with nothing in it says
     * so through the toast rather than triggering a fetch.
     */
    tracks?: QueueTrack[];
}>();

const { t } = useI18n();
const { busy, playSubject, enqueueSubject } = useSubjectTracks(() => props.tracks);
</script>

<template>
    <div class="subject-actions">
        <Button variant="primary" no-halo class="subject-actions__play" :disabled="busy" @click="playSubject">
            <!-- `playlist`, not `play`: what this does is fill the QUEUE and start it, which
                 is a list operation — a bare play triangle reads as "play this one thing"
                 (the owner's call, carried over from SubjectMenu). The spinner replaces the
                 glyph rather than sitting beside it, so the button keeps its width while a
                 big subject loads. -->
            <icon :name="busy ? 'refresh' : 'playlist'" :size="1" :rotate="busy" />
            <span>{{ t("music.subjectActions.play") }}</span>
        </Button>

        <Button variant="primary" no-halo class="subject-actions__enqueue" :disabled="busy" @click="enqueueSubject">
            <icon :name="busy ? 'refresh' : 'playlist_add'" :size="1" :rotate="busy" />
            <!-- Its own key rather than `player.enqueue` ("In die Warteschlange"), which the
                 queue's own menus still wear: a hero button has less room, and a label the
                 whole app shares cannot be shortened for one of its wearers. -->
            <span>{{ t("music.subjectActions.enqueue") }}</span>
        </Button>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* The two buttons kept together as one block inside the ActionPanel, taking the panel's own
   gutter so the space between them matches the space between them and "add to playlist".
   `flex-wrap` so a phone can break the pair rather than widen the panel.

   NO `width: 100%` — it had one while this was a row of its own under the panel, and left in
   place it would take a whole line inside the panel and push the playlist controls off it. */
.subject-actions {
    display: flex;
    flex-wrap: wrap;

    gap: map.get(s.$c-action-panel, "gap");
}
</style>
