<script setup lang="ts">
/******************************************************************************
 * SubjectMenu
 * The menu in a detail page's hero: everything you can do with the SUBJECT of that page
 * as a whole — play it now, or add it to the queue.
 *
 * THE PLAYLIST PAGE'S CONTROL, and since 2026-08-11 only its. The four Music heroes wore
 * one too until the owner replaced their popover with a visible row of buttons
 * (SubjectActions): a detail page's two most likely actions were behind a "…" that gave no
 * hint of what it held. A playlist keeps the menu because its hero already carries a row of
 * its own — edit, export, delete (PlaylistMenu) — and a second row of buttons beside that
 * one would read as six equal choices.
 *
 * WHAT THE TWO VERBS MEAN is not decided here: `useSubjectTracks` owns the fetch, the
 * "play means replace" rule, and the toast a subject with no tracks earns, so this component
 * and SubjectActions cannot come to disagree about any of it. All that is left here is the
 * popover, the labels, and closing the panel — on success only, so a subject with nothing to
 * play leaves the menu open under the toast that says why.
 *
 * The `tracks` prop is the page saying it already holds them (a playlist is not paginated —
 * see the composable). Deliberately a prop rather than a second component: what the verbs
 * mean does not change with where the tracks came from.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { useSubjectTracks } from "Composables/useSubjectTracks";

/** Which kind of thing this page is about — decides the wording, nothing else. */
type Subject = "artist" | "album" | "genre" | "song" | "playlist";

const props = defineProps<{
    /** The subject of the page this menu sits on. */
    subject: Subject;
    /**
     * The subject's tracks, when the page already holds all of them — a playlist. Omit it
     * and the menu fetches `queueTracks` on the first press instead (see the banner). An
     * empty array is a real answer, not "unset": a playlist with nothing in it says so.
     */
    tracks?: QueueTrack[];
}>();

const { t } = useI18n();
const { busy, playSubject, enqueueSubject } = useSubjectTracks(() => props.tracks);

/** DOM id of the popover, and the anchor name its panel is positioned against. */
const REFERENCE = "subjectMenu";

/** The subject's own noun ("Künstler", "Album", …), for both item labels. */
const subjectLabel = computed<string>(() => t(`music.subjectMenu.subject.${props.subject}`));

/** Close the panel, by the DOM id it was given — the pattern UserMenu uses for its links. */
function closePopover(): void {
    document.getElementById(REFERENCE)?.hidePopover();
}

/** Play the subject, and put the menu away only if that actually queued something. */
async function play(): Promise<void> {
    if (await playSubject()) closePopover();
}

/** Queue the subject, and put the menu away only if that actually queued something. */
async function enqueue(): Promise<void> {
    if (await enqueueSubject()) closePopover();
}
</script>

<template>
    <pop-over
        icon="more"
        :reference="REFERENCE"
        class-string="popover-button--rounded popover-button--subtle"
        :aria-label="t('music.subjectMenu.actions', { subject: subjectLabel })"
        width="24ch"
    >
        <ul class="popover-list">
            <li>
                <button type="button" class="popover-list-item" :disabled="busy" @click="play">
                    <!-- `playlist`, not `play`: what this does is fill the QUEUE and start it,
                         which is a list operation — a bare play triangle reads as "play this one
                         thing" (the owner's call). The spinner replaces the glyph rather than
                         sitting beside it, so the row keeps its width while a big subject loads. -->
                    <icon :name="busy ? 'refresh' : 'playlist'" :size="1" :rotate="busy" />
                    {{ t("music.subjectMenu.play", { subject: subjectLabel }) }}
                </button>
            </li>
            <li>
                <button type="button" class="popover-list-item" :disabled="busy" @click="enqueue">
                    <icon :name="busy ? 'refresh' : 'playlist_add'" :size="1" :rotate="busy" />
                    {{ t("player.enqueue") }}
                </button>
            </li>
        </ul>
    </pop-over>
</template>
