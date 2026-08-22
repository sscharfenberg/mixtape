<script setup lang="ts">
/******************************************************************************
 * PlaylistExportModal
 * The three choices behind "export playlist file": the .m3u flavour, the text encoding, and
 * what to put in front of every path. Opened from a playlist's hero, from a row's menu on the
 * listing, and from that page's "export all".
 *
 * ONE DIALOG FOR ONE PLAYLIST AND FOR ALL OF THEM, because the questions are identical — the
 * device decides the flavour, the encoding and the prefix whether it is about to receive one
 * file or twelve. What differs is the endpoint and the wording, and both are props, so there is
 * no second dialog to keep in step with this one. The all-playlists endpoint answers with a
 * .zip; nothing here has to know that, since the browser does the download either way.
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
 * ALL THREE ANSWERS BELONG TO THE DEVICE, which is what an export PRESET is: the car head unit
 * wants a simple list in Windows-1252 with no prefix, a phone wants an extended list in UTF-8
 * under /storage/emulated/0/Music. So the picker at the top of this form seeds all three at
 * once from one of the reader's presets (/dashboard/export-presets), and a reader who keeps
 * none still gets a working dialog: `simple`, `UTF-8`, and the server's configured prefix.
 *
 * A PRESET SEEDS, IT DOES NOT LOCK. The three fields stay visible and editable underneath, so
 * "the MacBook one but extended, this once" costs a click rather than a second preset — and the
 * picker then falls back to saying "custom", because a dialog claiming to be set to "Auto"
 * while showing UTF-8 is worse than one claiming nothing.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import Select from "Components/Form/Select/Select.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import type { ExportEncoding, ExportFormat, ExportPreset } from "Types/exportPresets";
import { unencodableInWindows1252 } from "Utils/encoding";

const props = defineProps<{
    /**
     * Where the download goes — one playlist's export route, or the collection-level one that
     * answers with a .zip of every playlist.
     *
     * A URL rather than an id, because this dialog now serves both: what differs between "this
     * playlist" and "all of them" is the endpoint and the wording, and passing the endpoint puts
     * that decision at the call site that already knows which it made.
     */
    endpoint: string;
    /**
     * How many playlists the download covers — 1 from a playlist's own page or a row's menu,
     * the reader's whole list from "export all".
     *
     * It is the only thing the copy switches on: a dialog that said "Playlist-Datei" while
     * about to hand over twelve of them would be describing the wrong act.
     */
    count: number;
    /**
     * The prefix field's starting value when the reader keeps no presets — the server's
     * configured default, from config via the page.
     */
    fallbackPrefix: string;
    /**
     * The reader's own export presets, default first (the model's reading order). Empty is
     * normal: the picker is not drawn at all, and the dialog is what it was before presets
     * existed.
     */
    presets: ExportPreset[];
    /**
     * The entries, for the Windows-1252 check — a title to name and a path to test.
     *
     * A structural shape rather than PlaylistTrackRow: this modal needs two fields, and taking
     * the whole row type would tie it to a queue entry it never plays.
     *
     * IT MAY ARRIVE EMPTY AND FILL IN LATER, which is what the listing does: a playlist's own
     * page already holds its tracks, while the listing fetches them the moment a dialog opens
     * (an optional Inertia prop — see PlaylistsController). Nothing here waits on them: the
     * warning is a computed, so it appears when they land, which is still before a reader can
     * have picked the encoding it is about.
     */
    tracks: { name: string; path: string }[];
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

/**
 * The preset the dialog opens on, or null when the reader keeps none.
 *
 * `find` rather than trusting the first row, even though the server sends them default-first:
 * the flag is the fact, the order is a presentation of it, and reading the fact is what makes
 * this correct if the two ever disagree. Falling back to the first row keeps the dialog opening
 * on SOMETHING for a reader whose presets somehow carry no default — better than silently
 * reverting to the server's prefix while a list of their own devices sits one page away.
 */
const opening = props.presets.find(preset => preset.isDefault) ?? props.presets[0] ?? null;

/** The .m3u flavour. `simple` is a bare list of paths; `extended` adds `#EXTINF` metadata. */
const format = ref<ExportFormat>(opening?.format ?? "simple");

/**
 * The file's text encoding.
 *
 * UTF-8 unless a preset or the reader says otherwise, which is right for every modern player.
 * Windows-1252 is here for one real device rather than for completeness — see the labels, which
 * name the cases instead of leaving the reader to guess what an encoding is for.
 */
const encoding = ref<ExportEncoding>(opening?.encoding ?? "UTF-8");

/**
 * What goes in front of every path.
 *
 * `??` rather than `||` on the preset's value: an empty prefix is a CHOICE — the USB stick
 * where the playlist sits beside the music — and `||` would quietly replace the car preset's
 * empty string with the server's /Volumes path on every export.
 */
const prefix = ref(opening?.pathPrefix ?? props.fallbackPrefix);

/** Which preset the reader last picked, before the fields are compared against it below. */
const pickedPresetId = ref<string>(opening?.id ?? "");

/**
 * What the picker actually shows: the picked preset, or nothing once the fields have moved
 * away from it.
 *
 * DERIVED RATHER THAN WATCHED, so there is one answer to "which preset is this" and it cannot
 * fall out of step with the fields — a watcher clearing the selection would have to fire on
 * three refs and be right about every path through them. Editing any field drops the picker to
 * its placeholder, and editing it back picks the preset up again, which is the honest reading
 * in both directions.
 */
const activePresetId = computed<string>(() => {
    const picked = props.presets.find(preset => preset.id === pickedPresetId.value);

    if (picked === undefined) return "";

    const matches =
        picked.format === format.value && picked.encoding === encoding.value && picked.pathPrefix === prefix.value;

    return matches ? picked.id : "";
});

/**
 * The picker's options — the reader's devices, in the order the server sent them.
 *
 * `sort: false` on the Select below, because that order is a decision (the default first) and
 * alphabetising it would bury the one preset most exports use.
 */
const presetOptions = computed(() => props.presets.map(preset => ({ value: preset.id, label: preset.name })));

/**
 * Fill the three fields from a preset.
 *
 * It writes the values rather than binding to them, which is what keeps a preset a STARTING
 * POINT: everything below stays editable, and the picker above reports honestly (see
 * `activePresetId`) once an edit has moved the form away from what the preset says.
 */
function applyPreset(id: string): void {
    pickedPresetId.value = id;

    const preset = props.presets.find(entry => entry.id === id);

    if (preset === undefined) return;

    format.value = preset.format;
    encoding.value = preset.encoding;
    prefix.value = preset.pathPrefix;
}

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

/** Whether this dialog is about the whole list rather than one playlist. Everything below reads it. */
const isAll = computed<boolean>(() => props.count > 1);

/** The dialog's title, and the note under it — both say how many files are about to arrive. */
const header = computed<string>(() =>
    isAll.value ? t("playlists.export.headerAll", { count: props.count }) : t("playlists.export.header")
);
const intro = computed<string>(() =>
    isAll.value ? t("playlists.export.introAll", { count: props.count }) : t("playlists.export.intro")
);

/**
 * What the button says.
 *
 * It names the FILE the reader is about to receive — an .m3u or a .zip — because that is the
 * one thing they cannot infer from a dialog that otherwise looks identical in both modes.
 */
const submitLabel = computed<string>(() =>
    isAll.value ? t("playlists.export.submitAll") : t("playlists.export.submit")
);

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

    window.location.assign(`${props.endpoint}?${query.toString()}`);
    emit("close");
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ header }}</template>

        <form id="playlist-export-form" class="form" @submit.prevent="download">
            <form-legend :items="[{ slot: 'intro', icon: 'question' }]">
                <template #intro>{{ intro }}</template>
            </form-legend>

            <!-- THE PICKER, and only when the reader has something to pick from: a select with
                 one empty state in it is a control that explains nothing. Without presets this
                 dialog is exactly what it was before they existed.

                 It sits ABOVE the three fields it fills, because that is the order the reader
                 works in — name the device, then adjust if this export is unusual. `sort:
                 false` keeps the server's order, which puts the default first; alphabetising
                 would bury the preset most exports use. Not `clearable`: there is nothing to
                 clear to, since editing any field below already returns the picker to
                 "custom". -->
            <form-row v-if="presets.length" :label="t('playlists.export.presetLabel')" addon-icon="file_export">
                <!-- NO `for-id`/`id` PAIR, which is what the two other Select call sites in this
                     app also leave off. A Select is a button plus an ARIA listbox rather than a
                     native control, so an `id` here would fall through as an undeclared
                     attribute onto its root <div> — leaving the row's <label for> pointing at a
                     div that cannot take focus, and the trigger button with no name of its own.
                     `ariaLabel` is the declared prop that names the button, and it is needed
                     precisely because the trigger's text is a preset's NAME once one is picked:
                     without it the control announces itself as "MacBook". -->
                <Select
                    :options="presetOptions"
                    :selected="activePresetId"
                    :placeholder="t('playlists.export.presetPlaceholder')"
                    :ariaLabel="t('playlists.export.presetLabel')"
                    :sort="false"
                    :clearable="false"
                    @change="applyPreset($event)"
                />
                <template #text>{{ t("playlists.export.presetHint") }}</template>
            </form-row>

            <!-- Bare rows, no `addonIcon`: the icon belongs on each OPTION here, which is what
                 the recovery-type group on the "trouble signing in" page does. An addon is
                 sized for one input and stretches into a tall box beside a stack of radios.
                 The label stays — unlike that page, this form has TWO groups, so each needs
                 naming rather than leaning on the legend above. -->
            <form-row :label="t('playlists.export.formatLabel')">
                <radio-button-group
                    name="format"
                    :radio-buttons="formats"
                    @change="format = ($event.target as HTMLInputElement).value as ExportFormat"
                />
            </form-row>

            <form-row :label="t('playlists.export.encodingLabel')">
                <radio-button-group
                    name="encoding"
                    :radio-buttons="encodings"
                    @change="encoding = ($event.target as HTMLInputElement).value as ExportEncoding"
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
                <span>{{ submitLabel }}</span>
            </Button>

            <!-- THE WAY IN THAT MATCHES THE MOMENT. A reader learns they want a preset while
                 standing here retyping a path, so this is where the offer belongs — the
                 dashboard section is for the reader who came looking. It NAVIGATES, which
                 closes this dialog: there is nothing here worth keeping (the fields start from
                 the default preset every time the modal mounts), and a reader going to manage
                 their presets is not mid-export.

                 No `prefetch`, and for once not because of the form rule — the presets list is
                 a page one only reads. Warming it from inside a modal would spend a request on
                 a link most readers will not press. -->
            <Link class="export__manage" href="/dashboard/export-presets" @click="emit('close')">
                <icon name="settings" :size="1" />
                <span>{{ t("playlists.export.managePresets") }}</span>
            </Link>
        </template>
    </modal>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* The way to the presets page, in the dialog's footer beside the download.

   A TEXT LINK RATHER THAN A SECOND BUTTON, because the footer holds one action — the download
   is what this dialog is for — and two buttons of equal weight would make a reader choose
   between them. This is an aside that happens to be clickable. */
.export__manage {
    display: inline-flex;
    align-items: center;

    gap: map.get(s.$c-presets, "chip-gap");

    color: map.get(c.$c-presets, "control");

    font-size: map.get(s.$c-presets, "font-size");

    text-decoration: none;

    @media (prefers-reduced-motion: no-preference) {
        transition: color ti.$c-presets;
    }

    &:hover,
    &:focus-visible {
        color: map.get(c.$c-presets, "control-active");
    }
}
</style>
