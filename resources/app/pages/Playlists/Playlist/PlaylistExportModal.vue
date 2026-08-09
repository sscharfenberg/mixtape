<script setup lang="ts">
/******************************************************************************
 * PlaylistExportModal
 * The three choices behind "export playlist file": the .m3u flavour, the text encoding, and
 * what to put in front of every path. Opened from the hero's action row.
 *
 * THE DOWNLOAD IS A PLAIN NAVIGATION, not a fetch. Submitting builds the export URL and hands
 * it to the browser, which does what browsers do with an `attachment` response: streams it to
 * disk, shows its own progress, offers its own cancel, and leaves this page exactly where it
 * was. The alternative — fetch, read a blob, mint an object URL, click a hidden anchor, revoke
 * — buys nothing and costs the whole file in memory plus a leak if the revoke is ever missed.
 * It is also why the endpoint is a GET with query params (PlaylistExportController says more).
 *
 * WHICH MEANS THERE IS NOTHING TO AWAIT, and so no submitting state, no spinner and no error
 * branch here: once the URL is handed over this modal's job is finished. A server-side refusal
 * (a playlist that is not yours, an option outside its list) is the browser's to show, not
 * this component's — nothing on this page is stale afterwards either way.
 *
 * IT WARNS BEFORE THE DOWNLOAD, not after. Windows-1252 carries about 250 characters, and a
 * path outside them comes out with "?" where the character was — which on a PATH line is not
 * a cosmetic loss but a dead line, since "?" is not a legal filename character on FAT and the
 * player looks for a file that cannot exist. No substitute fixes it (see Utils/encoding), so
 * the only honest thing is to name the tracks that will be missing while the reader can still
 * choose UTF-8.
 *
 * Checked in the browser, against the paths the page already holds for the sort — so the
 * warning appears the instant Windows-1252 is picked, with no round trip.
 *
 * THE THREE DEFAULTS ARE NOT THE SAME KIND OF THING. Format and encoding default to what most
 * readers want and are remembered by nothing: they are per-export decisions. The PREFIX comes
 * from the server (`exportPrefix`, out of config/mixtape.php) because it describes the machine
 * that will PLAY the file — a Mac's /Volumes mount, a USB stick's root — which this app cannot
 * know and has no business storing per playlist.
 *****************************************************************************/
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import { unencodableInWindows1252 } from "Utils/encoding";

const props = defineProps<{
    /** Which playlist to export — its id builds the URL. */
    playlistId: string;
    /** The prefix field's starting value, from config via the page. */
    defaultPrefix: string;
    /**
     * The entries, for the Windows-1252 check — a title to name and a path to test.
     *
     * A structural shape rather than PlaylistTrackRow: this modal needs two fields, and taking
     * the whole row type would tie it to a queue entry it never plays.
     */
    tracks: { name: string; path: string }[];
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

/** The .m3u flavour. `simple` is a bare list of paths; `extended` adds `#EXTINF` metadata. */
const format = ref<"simple" | "extended">("simple");

/**
 * The file's text encoding.
 *
 * UTF-8 unless told otherwise, which is right for every modern player. Windows-1252 is here
 * for one real device rather than for completeness — see the labels, which name the cases
 * instead of leaving the reader to guess what an encoding is for.
 */
const encoding = ref<"UTF-8" | "Windows-1252">("UTF-8");

/** What goes in front of every path. Seeded from config; the reader edits it per export. */
const prefix = ref(props.defaultPrefix);

/**
 * The format options, shaped the way RadioButtonGroup wants them.
 *
 * A `computed` rather than a plain array, for two reasons: `checked` has to follow the ref
 * (the group is told which option is selected, it does not track that itself), and the labels
 * have to re-read on a locale switch.
 *
 * Each option carries its OWN icon — the pattern the recovery-type group on the "trouble
 * signing in" page follows. The row itself gets no `addonIcon`: that addon is sized for a
 * single-line input and stretches into a tall box beside a stack of radios.
 */
const formats = computed(() => [
    {
        value: "simple",
        label: t("playlists.export.formatSimple"),
        checked: format.value === "simple",
        icon: "file",
        tooltip: t("playlists.export.formatSimpleHint")
    },
    {
        value: "extended",
        label: t("playlists.export.formatExtended"),
        checked: format.value === "extended",
        icon: "info",
        tooltip: t("playlists.export.formatExtendedHint")
    }
]);

/**
 * The encoding options.
 *
 * BOTH ICONS SIT ON ONE AXIS — which alphabets fit — because that is the actual difference:
 * the globe is every script there is, `abc` is basic Latin and a little of Western Europe. A
 * reader who knows nothing about codepages still reads "the wide one" against "the narrow one",
 * which is the decision in front of them.
 *
 * The first attempt paired a monitor with a WARNING triangle and failed twice over. The two
 * were on different axes — one said "a computer", the other "this is bad" — so neither meant
 * anything next to the other; and the warning nagged about the option that is CORRECT for the
 * device it exists for. Windows-1252 is not dangerous, it is narrow.
 */
const encodings = computed(() => [
    {
        value: "UTF-8",
        label: t("playlists.export.encodingUtf8"),
        checked: encoding.value === "UTF-8",
        icon: "language",
        tooltip: t("playlists.export.encodingUtf8Hint")
    },
    {
        value: "Windows-1252",
        label: t("playlists.export.encodingWindows"),
        checked: encoding.value === "Windows-1252",
        icon: "abc",
        tooltip: t("playlists.export.encodingWindowsHint")
    }
]);

/**
 * The tracks Windows-1252 would break, and the characters that break them.
 *
 * Empty unless Windows-1252 is actually selected — UTF-8 carries everything, so there is
 * nothing to say — which is what makes this a warning about a CHOICE rather than about the
 * playlist. Recomputed by the radio, so it appears and disappears as the reader tries each.
 */
const unplayable = computed(() =>
    encoding.value !== "Windows-1252"
        ? []
        : props.tracks
              .map(track => ({ name: track.name, bad: unencodableInWindows1252(track.path) }))
              .filter(entry => entry.bad.length > 0)
);

/**
 * The warning's sentence: how many, which titles, and which characters are to blame.
 *
 * Titles are capped at four and the rest counted, because the failure clusters — a playlist of
 * one Taiwanese band is 27 dead lines, and listing all of them would push the buttons off the
 * modal. The CHARACTERS are listed in full and de-duplicated: they are short, and they are the
 * actionable half — "ł" says which record and what to rename.
 */
const warning = computed<string>(() => {
    const names = unplayable.value.map(entry => entry.name);
    const characters = [...new Set(unplayable.value.flatMap(entry => entry.bad))];
    const shown = names.slice(0, 4).join(", ");

    // `t(key, named, plural)` — named params FIRST, the count last. The two-argument form
    // (`t(key, count)`) that the duration helpers use cannot also carry names.
    const named =
        names.length > 4
            ? t("playlists.export.andMore", { shown, count: names.length - 4 }, names.length - 4)
            : shown;

    return t(
        "playlists.export.unplayable",
        { named, characters: characters.join(" "), count: names.length },
        names.length
    );
});

/**
 * Hand the export URL to the browser and close.
 *
 * `URLSearchParams` rather than string concatenation, so a prefix containing a space or a
 * plus reaches the server as itself. `location.assign` rather than `window.open`, because an
 * attachment response opens no window and a popup blocker has no opinion about a navigation
 * the page did to itself.
 */
function download(): void {
    const query = new URLSearchParams({
        format: format.value,
        encoding: encoding.value,
        prefix: prefix.value
    });

    window.location.assign(`/playlists/${props.playlistId}/export?${query.toString()}`);
    emit("close");
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("playlists.export.header") }}</template>

        <form id="playlist-export-form" class="form" @submit.prevent="download">
            <form-legend :items="[{ slot: 'intro', icon: 'question' }]">
                <template #intro>{{ t("playlists.export.intro") }}</template>
            </form-legend>

            <!-- Bare rows, no `addonIcon`: the icon belongs on each OPTION here, which is what
                 the recovery-type group on the "trouble signing in" page does. An addon is
                 sized for one input and stretches into a tall box beside a stack of radios.
                 The label stays — unlike that page, this form has TWO groups, so each needs
                 naming rather than leaning on the legend above. -->
            <form-row :label="t('playlists.export.formatLabel')">
                <radio-button-group
                    name="format"
                    :radio-buttons="formats"
                    @change="format = ($event.target as HTMLInputElement).value as 'simple' | 'extended'"
                />
            </form-row>

            <form-row :label="t('playlists.export.encodingLabel')">
                <radio-button-group
                    name="encoding"
                    :radio-buttons="encodings"
                    @change="encoding = ($event.target as HTMLInputElement).value as 'UTF-8' | 'Windows-1252'"
                />
            </form-row>

            <!-- Only while Windows-1252 is selected, and only when it would actually cost
                 something. `modifier: "warning"` recolours this one item; the legend above keeps
                 its own neutral note. -->
            <form-legend v-if="unplayable.length" :items="[{ slot: 'unplayable', icon: 'warning', modifier: 'warning' }]">
                <template #unplayable>{{ warning }}</template>
            </form-legend>

            <!-- Free text rather than a picker: the path names a place on ANOTHER machine — a
                 Mac's mount point, a car's USB stick — so there is nothing here to browse. -->
            <form-row for-id="export-prefix" :label="t('playlists.export.prefixLabel')" addon-icon="path">
                <form-input v-model="prefix" type="text" name="prefix" id="export-prefix" autocomplete="off" />
                <!-- `#text`, which is FormRow's hint slot — it has no `hint` prop. The hint
                     matters more here than on most fields: the value names a place on ANOTHER
                     machine, so without it the field reads as a setting about this server. -->
                <template #text>{{ t("playlists.export.prefixHint") }}</template>
            </form-row>
        </form>

        <template #footer>
            <Button variant="default" type="submit" form="playlist-export-form">
                <icon name="download" :size="1" />
                <span>{{ t("playlists.export.submit") }}</span>
            </Button>
        </template>
    </modal>
</template>
