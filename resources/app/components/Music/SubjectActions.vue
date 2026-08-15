<script setup lang="ts">
/******************************************************************************
 * SubjectActions
 * The two verbs a Music detail page's hero offers for its subject AS A WHOLE: play it, or
 * put it in the queue.
 *
 * VISIBLE BUTTONS RATHER THAN A POPOVER MENU. Behind a "…" trigger beside the title
 * (SubjectMenu, which the playlist page wears for its own reasons), a detail page's two most
 * likely actions give no hint of themselves until it is opened.
 *
 * IT STANDS IN THE ActionPanel, beside "add to playlist". The tempting alternative is a row of
 * its own BELOW the panel, on the reasoning that the panel is about a reader's own library
 * while these are about the music — a split that reads better written down than on screen.
 * What the hero's first row should hold is what a reader is most likely to press, and it is
 * these two. `download` is the one that does belong under the panel, with `share`: those two
 * take the subject somewhere else.
 *
 * `variant="primary"` on both, so they rest LIT and read as the loudest thing in the panel —
 * which is what they should be on a page whose whole purpose is the subject they act on. No
 * halo, because they stand on the hero's own surface rather than on the page, and a neon pool
 * spilling across it reads as a smudge (Button.vue).
 *
 * THE LABELS NAME THE VERB, NOT THE SUBJECT — "Abspielen" / "Warteschlange" rather than
 * "Album abspielen". A popover needs the noun, because it opens over the page and its items have
 * to say what they act on; a button standing in the hero of the thing itself does not. Two short
 * labels also keep the row on one line at hero width, where two long ones wrap. That is why this
 * component takes no props about the subject at all — nothing it draws varies with one, and each
 * page that renders it is describing one thing.
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
                 rather than a list. The spinner replaces the
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
