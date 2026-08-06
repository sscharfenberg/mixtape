<script setup lang="ts">
/******************************************************************************
 * SubjectMenu
 * The menu in a detail page's hero: everything you can do with the SUBJECT of that page
 * as a whole — play it now, or add it to the queue. One component for the artist, album,
 * genre and song pages, because the two verbs behave identically on all four and only the
 * word in the label and the tracks behind it differ.
 *
 * PLAY MEANS REPLACE. "Play this artist" empties the queue and puts their records in it,
 * which is what a listener means by it and what every player does; "enqueue" appends and
 * leaves what is playing alone. The two are one click apart in the same menu, so the
 * labels have to be unambiguous — hence "{subject} abspielen" rather than a bare "Play".
 *
 * IT FETCHES THE TRACKS WHEN PRESSED, not when the page loads. Every one of these pages
 * paginates its songs table, so the rows on screen are never the whole subject, and the
 * whole subject can be thousands of tracks. The controllers therefore declare
 * `queueTracks` as an OPTIONAL Inertia prop (App\Services\Music\QueuePayload) and this
 * asks for it by name with a partial reload. There is no endpoint to call because this app
 * has no REST API by design — a partial reload IS the Inertia way to fetch more of a page.
 *
 * The fetched payload is kept for the life of the page: pressing play and then enqueue
 * costs one round trip, not two, and the second press is instant.
 *
 * It reads usePlayerQueue and usePlayerAudio directly rather than emitting upward: both
 * are module singletons, so a page in between would only be prop-drilling. Same call
 * PlayerVolume, PlayQueueMenu and PlayerSettings make.
 *****************************************************************************/
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";

/** Which kind of thing this page is about — decides the wording, nothing else. */
type Subject = "artist" | "album" | "genre" | "song";

const props = defineProps<{
    /** The subject of the page this menu sits on. */
    subject: Subject;
}>();

const { t } = useI18n();
const page = usePage();
const { playNow, enqueue } = usePlayerQueue();
const { play } = usePlayerAudio();
const { addToast } = useToast();

/** DOM id of the popover, and the anchor name its panel is positioned against. */
const REFERENCE = "subjectMenu";

/** True while a partial reload is in flight, so neither verb can be pressed twice. */
const busy = ref(false);

/** The subject's own noun ("Künstler", "Album", …), for both item labels. */
const subjectLabel = computed<string>(() => t(`music.subjectMenu.subject.${props.subject}`));

/**
 * The tracks, fetched once per page and remembered.
 *
 * A partial reload rather than a request of our own: `only` names the optional prop, so the
 * server runs that closure and nothing else on the page re-renders. Wrapped in a promise
 * because Inertia reports through callbacks, and both verbs need to act AFTER the payload
 * has landed.
 */
async function loadTracks(): Promise<QueueTrack[]> {
    const alreadyThere = page.props.queueTracks as QueueTrack[] | undefined;
    if (alreadyThere) return alreadyThere;

    busy.value = true;

    return new Promise<QueueTrack[]>(resolve => {
        router.reload({
            only: ["queueTracks"],
            onSuccess: () => resolve((page.props.queueTracks as QueueTrack[] | undefined) ?? []),
            // A failed reload must not leave the menu stuck: resolve empty and let the caller
            // say so, rather than hanging on a promise nothing will settle.
            onError: () => resolve([]),
            onFinish: () => {
                busy.value = false;
            }
        });
    });
}

/** Close the panel, by the DOM id it was given — the pattern UserMenu uses for its links. */
function closePopover(): void {
    document.getElementById(REFERENCE)?.hidePopover();
}

/**
 * Replace the queue with this subject and start playing.
 *
 * `play()` is called explicitly, and it matters: loading a track does not start it, and a
 * browser only allows playback from a user gesture — this click is that gesture, so the
 * call has to happen inside the handler rather than in a watcher somewhere later.
 */
async function playSubject(): Promise<void> {
    const tracks = await loadTracks();
    if (tracks.length === 0) {
        addToast(t("music.subjectMenu.nothingToPlay"), "warning", 3000);

        return;
    }

    playNow(tracks);
    play();
    closePopover();
}

/** Append this subject to the queue, leaving whatever is playing alone. */
async function enqueueSubject(): Promise<void> {
    const tracks = await loadTracks();
    if (tracks.length === 0) {
        addToast(t("music.subjectMenu.nothingToPlay"), "warning", 3000);

        return;
    }

    enqueue(tracks);
    // `t(key, plural)` — the same call PlayQueue makes for its summary; the message carries
    // both branches and `{count}` is filled from the number.
    addToast(t("music.subjectMenu.enqueued", tracks.length), "success", 3000);
    closePopover();
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
                <button type="button" class="popover-list-item" :disabled="busy" @click="playSubject">
                    <!-- The spinner replaces the glyph rather than sitting beside it, so the
                         row does not change width while a big subject is being fetched. -->
                    <icon :name="busy ? 'refresh' : 'play'" :size="1" :rotate="busy" />
                    {{ t("music.subjectMenu.play", { subject: subjectLabel }) }}
                </button>
            </li>
            <li>
                <button type="button" class="popover-list-item" :disabled="busy" @click="enqueueSubject">
                    <icon :name="busy ? 'refresh' : 'enqueue'" :size="1" :rotate="busy" />
                    {{ t("player.enqueue") }}
                </button>
            </li>
        </ul>
    </pop-over>
</template>
