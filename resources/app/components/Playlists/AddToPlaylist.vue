<script setup lang="ts">
/******************************************************************************
 * AddToPlaylist
 * The "add this to a playlist" AREA in a detail page's hero — a sentence saying what will be
 * added, a select of the reader's playlists, and a save button that stays disabled until one
 * is chosen. Sits in HeroSection's `#actions` slot, under the facts, because it acts on the
 * thing those facts have just identified.
 *
 * AN AREA RATHER THAN THREE CONTROLS IN A ROW: the sentence and the two controls are one
 * offer, and on a hero panel they read as leftovers unless something holds them together.
 * That something is the TINTED BOX, and it is the shared ActionPanel the page wraps this in
 * rather than something this component draws — because the song and album heroes put a
 * download button in the same box, and it has to be there for a reader who has no playlists at
 * all, i.e. exactly when this component renders nothing. What is left here is the
 * block: a sentence over its controls.
 *
 * ONE COMPONENT FOR ALL FOUR SUBJECTS (song, album, artist, genre), which the server side
 * makes possible: the browser sends `{ subject, id }` and the tracks are worked out there, so
 * nothing here has to know that an artist is 900 tracks and a song is one. All that differs
 * per subject is the sentence.
 *
 * THE SENTENCE IS FOUR SEPARATE KEYS RATHER THAN ONE WITH A `{subject}` SLOT, and that is a
 * German problem rather than a stylistic one: the article declines with the noun's gender —
 * "Diesen Song", "Dieses Album", "Diesen Künstler", "Dieses Genre" — so a template with the
 * noun interpolated is wrong in half the cases. Four sentences cost four lines of catalog and
 * are right in every language that inflects.
 *
 * WHAT IT DOES WHEN THERE IS NOTHING TO OFFER, which is two different situations:
 *
 *   - the reader has playlists, but every one of them already holds this — a line saying so.
 *     The area disappearing would read as a bug, and "it is already in all of them" is
 *     genuinely useful: it is the answer to the question that was about to be asked.
 *   - the reader has no playlists at all — nothing at all. There is no decision to present,
 *     and the Playlists area already exists to make the first one.
 *
 * The offer itself comes from the page: `addablePlaylists` is the ids of the playlists that do
 * NOT already hold the subject, which is what keeps a playlist out of the list rather than in
 * it and disabled. Everything about the write — and about why the list refreshes itself
 * afterwards — is in useAddToPlaylist.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import Select from "Components/Form/Select/Select.vue";
import Icon from "Components/UI/Icon.vue";
import type { AddablePlaylistSubject, PlaylistOption } from "Composables/useAddToPlaylist";
import { useAddToPlaylist } from "Composables/useAddToPlaylist";

const props = defineProps<{
    /** Which kind of page this is — decides the sentence, and what the server is told. */
    subject: AddablePlaylistSubject;
    /** The subject's UUID. The server resolves it to tracks; nothing here needs them. */
    subjectId: string;
    /**
     * The ids of the reader's playlists that do not already hold this subject, from the page's
     * `addablePlaylists` prop. An empty array is a real answer — every playlist has it already.
     */
    addable: string[];
}>();

const { t } = useI18n();
const page = usePage();

const { options, selected, saving, canSave, save } = useAddToPlaylist(
    () => ({ subject: props.subject, ids: [props.subjectId] }),
    () => props.addable
);

/**
 * Whether the reader has any playlists at all — the difference between "no room left for this"
 * and "no playlists yet", which are the two ways this area can have nothing to offer and want
 * opposite treatment (see the banner). Read off the unnarrowed shared list, which is exactly
 * what `options` would be with no `addable` filter.
 */
const hasPlaylists = computed(() => ((page.props.playlists as PlaylistOption[] | undefined) ?? []).length > 0);

/**
 * The playlists as the Select wants them. `sort` is switched off on the control itself, so this
 * order — the reader's own, as the server sent it — is what shows.
 */
const choices = computed(() => options.value.map(playlist => ({ value: playlist.id, label: playlist.name })));
</script>

<template>
    <div v-if="hasPlaylists" class="add-to-playlist">
        <p class="add-to-playlist__intro">{{ t(`playlists.add.${subject}`) }}</p>

        <div v-if="choices.length" class="add-to-playlist__controls">
            <!-- `:sort="false"` because the reader ARRANGED these: the playlists page has drag
                 handles, and a select that re-alphabetised them would be showing a different
                 list from the one they built. The placeholder doubles as the control's
                 accessible name — the trigger is a button whose text it is — which is why it
                 names the choice rather than saying "please select".

                 NO `addonIcon`, unlike the same select inside the queue's modal. The icon box
                 costs about 4ch, and on a phone that is the difference between the placeholder
                 sitting on one line and wrapping to two inside a control whose whole look is a
                 single line of text. The modal can afford it because its row is the full width
                 of a dialog; here the sentence directly above already says what the control is
                 for, so the glyph would be the third thing saying it. -->
            <Select
                class="add-to-playlist__select"
                :options="choices"
                :selected="selected"
                :placeholder="t('playlists.add.placeholder')"
                :sort="false"
                :disabled="saving"
                max="34ch"
                @change="selected = $event"
            />
            <!-- Disabled until a playlist is chosen, which is the whole of its state: there is
                 nothing to be uncertain about until then. The glyph carries the in-flight
                 state instead of a separate spinner, the same way SubjectMenu's items do.
                 `no-halo` because this button sits inside a tinted area on a hero panel —
                 the pooled reflection lands on that tint and reads as a smudge. -->
            <Button variant="default" type="button" no-halo :disabled="!canSave" @click="save()">
                <icon :name="saving ? 'refresh' : 'playlist_add'" :size="1" :rotate="saving" />
                <span>{{ t(saving ? "playlists.add.saving" : "playlists.add.submit") }}</span>
            </Button>
        </div>
        <!-- Subject-free on purpose, unlike the sentence above it: "already in all of your
             playlists" needs no article, so one key serves all four pages. -->
        <p v-else class="add-to-playlist__exhausted">{{ t("playlists.add.exhausted") }}</p>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* A sentence over its controls. What holds the two together — the tinted box — is the
   ActionPanel this now sits inside (see the banner), so all that is left here is the column
   and the gap down it.

   `flex: 1 1 auto` because it is a flex item of that panel: it TAKES the row it is given, so a
   download button beside it is pushed to the trailing edge and the sentence still gets the full
   width to wrap in. `min-width: 0` is the usual flex guard — without it a long playlist name in
   the select would refuse to shrink and push its neighbour off the edge. */
.add-to-playlist {
    display: flex;
    flex-direction: column;

    min-width: 0;
    flex: 1 1 auto;

    gap: map.get(s.$c-add-to-playlist, "gap");
}

/* The sentence, matched to the hero's own blurb (size and ink) so the area reads as part of
   the panel rather than as a form pasted into it. */
.add-to-playlist__intro,
.add-to-playlist__exhausted {
    margin: 0;

    color: map.get(c.$c-add-to-playlist, "intro");

    font-size: map.get(s.$c-add-to-playlist, "intro-font-size");
}

/* Wrapping, because at hero width on a phone the select and the button do not fit on one line
   — and when they do, the button sits beside the select rather than under it. `align-items:
   center` keeps the two aligned on their middles: the select is a text-height control and the
   button carries its own padding, so their boxes are different heights by design. */
.add-to-playlist__controls {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: map.get(s.$c-add-to-playlist, "controls-gap");
}

/* A basis rather than a width: the select grows into the row it is given and wraps the button
   below it once there is less than this to grow into. Its own `max` prop caps the other end —
   a select stretched across a desktop hero would be a 900px box holding one word. */
.add-to-playlist__select {
    flex: 1 1 map.get(s.$c-add-to-playlist, "select-basis");
}
</style>
