<script setup lang="ts">
/******************************************************************************
 * ExportPresetRow
 * One export preset in the list at /dashboard/export-presets — the device's name, the three
 * values that make a playlist file play on it, and the three things a reader does with one.
 *
 * IT SHOWS THE VALUES RATHER THAN A SUMMARY, and that is what the row is for. A list of names
 * alone ("MacBook", "Auto") cannot answer the question a reader arrives with, which is always
 * about a specific value — did I put the right path on the car one? The three pips make the
 * whole set readable down the column, so a wrong value is spotted rather than hunted for by
 * opening each form in turn.
 *
 * AN EMPTY PREFIX IS PRINTED IN WORDS ("relative Pfade"), never as a blank. Empty is a real
 * choice here — the USB stick where the playlist sits beside the music — and a row that simply
 * showed nothing would read as a preset somebody forgot to finish.
 *
 * THE ROW IS NOT A LINK. Every part of it is either a fact or one of three controls, and the
 * whole-row click would have to pick one of them arbitrarily. The same reasoning ShareLinkRow
 * records, and the same consequence: no hover fill, because nothing responds to a click on the
 * row itself.
 *
 * THE DEFAULT MARKER IS A BUTTON ON EVERY OTHER ROW AND A FACT ON THE ONE THAT HOLDS IT. One
 * press moves it, which is the whole reason the default is not a checkbox inside the edit form
 * — a reader changes which device they usually export to far more often than they change what
 * that device needs. On the holder it is inert: pressing it would be a no-op, and a control
 * that does nothing is worse than a label.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import type { ExportPreset } from "Types/exportPresets";

const props = defineProps<{
    /** The preset this row is about, as the server describes it. */
    preset: ExportPreset;
}>();

const emit = defineEmits<{
    /** Ask the page to confirm deleting this row — the dialog is the page's, so it is one dialog. */
    remove: [preset: ExportPreset];
    /** Make this preset the one the export modal opens on. Never raised by the row that already is. */
    makeDefault: [preset: ExportPreset];
}>();

const { t } = useI18n();

/**
 * The .m3u flavour in the export dialog's own words.
 *
 * READ FROM `playlists.export.*` RATHER THAN FROM A SECOND SET OF KEYS, so a preset is
 * described here exactly as the dialog describes the same choice. Two catalogues for one pair
 * of options is how a reader ends up comparing "einfache .m3u" here with something slightly
 * different there and wondering whether they are the same thing.
 */
const formatLabel = computed<string>(() =>
    props.preset.format === "extended" ? t("playlists.export.formatExtended") : t("playlists.export.formatSimple")
);

/** The encoding, likewise in the export dialog's words. */
const encodingLabel = computed<string>(() =>
    props.preset.encoding === "Windows-1252"
        ? t("playlists.export.encodingWindows")
        : t("playlists.export.encodingUtf8")
);

/**
 * The prefix, or what an empty one means.
 *
 * The empty case is the reason this is a computed rather than an interpolation: '' is a
 * decision the reader made, and printing it as nothing would lose that.
 */
const prefixLabel = computed<string>(() => props.preset.pathPrefix || t("dashboard.presets.relativePaths"));
</script>

<template>
    <li class="presets__row" :class="{ 'presets__row--default': preset.isDefault }">
        <span class="presets__identity">
            <span class="presets__name">{{ preset.name }}</span>

            <!-- THE MARKER, in the same pip shape the values below use, so the row reads as one
                 object rather than as a name with a badge bolted on. Inert on the holder and a
                 button everywhere else — see the banner. -->
            <span v-if="preset.isDefault" class="presets__marker">
                <icon name="check" :size="1" />
                {{ t("dashboard.presets.defaultMarker") }}
            </span>
            <button
                v-else
                type="button"
                class="presets__marker presets__marker--button"
                v-tooltip="t('dashboard.presets.setDefault')"
                :aria-label="t('dashboard.presets.setDefaultLabel', { name: preset.name })"
                @click="emit('makeDefault', preset)"
            >
                <icon name="check" :size="1" />
                {{ t("dashboard.presets.setDefault") }}
            </button>
        </span>

        <!-- The three values, in the order the export dialog asks for them, so a reader
             comparing a row against that dialog reads them in the same sequence. Each carries
             the icon that option wears there — the path glyph is the one the prefix field
             already has an addon for. -->
        <span class="presets__values">
            <span class="presets__value">
                <icon :name="preset.format === 'extended' ? 'info' : 'file'" :size="1" />
                {{ formatLabel }}
            </span>
            <span class="presets__value">
                <icon :name="preset.encoding === 'Windows-1252' ? 'abc' : 'language'" :size="1" />
                {{ encodingLabel }}
            </span>
            <span class="presets__value presets__value--prefix">
                <icon name="path" :size="1" />
                {{ prefixLabel }}
            </span>
        </span>

        <!-- BOTH CONTROLS IN ONE FLEX ITEM pinned to the trailing edge, the fix ShareLinkRow
             records: loose, they are laid out by whatever space the values leave, and a long
             prefix wrapping the row onto a second line takes them wherever that line ends. -->
        <span class="presets__controls">
            <!-- NO `prefetch`, and it is the app's standing rule rather than a preference: a
                 prefetch whose response lands after the reader has navigated to the same URL is
                 applied to the page they are now on, re-keying it — which in a form discards
                 what has been typed and saves what the server sent. This link leads to a form. -->
            <Link
                class="presets__control"
                :href="`/dashboard/export-presets/${preset.id}/edit`"
                v-tooltip="t('dashboard.presets.edit')"
                :aria-label="t('dashboard.presets.editLabel', { name: preset.name })"
            >
                <icon name="settings" :size="1" />
            </Link>

            <!-- Icon only, with the device in its accessible name: a column of identical
                 "delete" labels tells a screen-reader user which row they are on only by
                 counting. -->
            <button
                type="button"
                class="presets__control"
                v-tooltip="t('dashboard.presets.delete')"
                :aria-label="t('dashboard.presets.deleteLabel', { name: preset.name })"
                @click="emit('remove', preset)"
            >
                <icon name="delete" :size="1" />
            </button>
        </span>
    </li>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* ONE PRESET. Identity, values, controls — the values take the slack, so the controls line up
   down the list however long a device name or a path runs.

   It WRAPS rather than shrinking: a path prefix is the longest thing on the row and the one
   fact a reader came to check, so squeezing it to an ellipsis would defeat the row. */
.presets__row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    box-sizing: border-box;

    padding: map.get(s.$c-presets, "row-padding");
    border: map.get(s.$c-presets, "border") solid map.get(c.$c-presets, "border");
    gap: map.get(s.$c-presets, "row-gap");

    background-color: map.get(c.$c-presets, "background");
    border-radius: map.get(s.$c-presets, "radius");
}

/* The row the export dialog opens on, lifted by its edge alone. Not by a fill: the fill is
   what says "this is a row", and a second one would read as a different kind of object rather
   than as the same object marked. */
.presets__row--default {
    border-color: map.get(c.$c-presets, "border-default");
}

.presets__identity {
    display: flex;
    align-items: center;

    /* `min-content` rather than 0: a device name does not wrap, so a floor of 0 lets the box
       shrink under its own text and the PADDING is what visibly goes — which reads as a
       spacing bug rather than as a layout one. */
    min-width: min-content;
    flex: 1 1 auto;
    gap: map.get(s.$c-presets, "row-gap");
}

.presets__name {
    font-weight: bold;
}

/* The marker and its button share every measurement — they are the same pip in the same place,
   one of them pressable — so a row that holds the default and a row that offers it do not jump
   about as the reader moves the flag. */
.presets__marker {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-presets, "chip-padding");
    border: 0;
    gap: map.get(s.$c-presets, "chip-gap");

    background-color: map.get(c.$c-presets, "chip");
    color: map.get(c.$c-presets, "marker");
    border-radius: map.get(s.$c-presets, "chip-radius");

    font-size: map.get(s.$c-presets, "font-size");
}

.presets__marker--button {
    color: map.get(c.$c-presets, "control");

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            color ti.$c-presets,
            background-color ti.$c-presets;
    }

    &:hover,
    &:focus-visible {
        background-color: map.get(c.$c-presets, "control-background-active");
        color: map.get(c.$c-presets, "control-active");
    }
}

/* The three values, wrapping among themselves before the row does. */
.presets__values {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: map.get(s.$c-presets, "row-gap");

    color: map.get(c.$c-presets, "surface-muted");

    font-size: map.get(s.$c-presets, "font-size");
}

.presets__value {
    display: inline-flex;
    align-items: center;

    gap: map.get(s.$c-presets, "chip-gap");
}

/* The prefix is a path: it may break anywhere rather than pushing the row wide. */
.presets__value--prefix {
    word-break: break-all;
}

/* Pinned to the trailing edge on whichever line they end up on — see the template. */
.presets__controls {
    display: flex;
    align-items: center;

    margin-inline-start: auto;
    gap: map.get(s.$c-presets, "chip-gap");
}

/* Both controls are the same object — one is a <Link>, one a <button> — so they are drawn
   once. A real fill at rest, like the share row's: a bare glyph is a glyph-sized target, and
   one of these two deletes something. */
.presets__control {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: map.get(s.$c-presets, "control-padding");
    border: 0;

    background-color: map.get(c.$c-presets, "control-background");

    color: map.get(c.$c-presets, "control");
    border-radius: map.get(s.$c-presets, "control-radius");

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            color ti.$c-presets,
            background-color ti.$c-presets;
    }

    &:hover,
    &:focus-visible {
        background-color: map.get(c.$c-presets, "control-background-active");
        color: map.get(c.$c-presets, "control-active");
    }
}
</style>
