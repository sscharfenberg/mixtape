<script setup lang="ts">
/******************************************************************************
 * WidgetList
 * The shared list body for the music consumer widgets: each entry is a LINK to the
 * thing it names, carrying its facts as icon "pips" underneath.
 *
 * One component for all four widgets so the look is defined once — and so the four
 * agree on the part that is easiest to let drift: what a fact looks like. A pip is
 * an icon and a value, never a written label, because four widgets × three facts of
 * "Alben: 12" is a wall of repeated words in a card meant to be scanned. The label
 * survives in the TOOLTIP instead ("Anzahl Alben: 12"), which is also what keeps the
 * icon from being the sole carrier of the meaning.
 *
 * The widgets map their own entries to this shape rather than this component knowing
 * about albums or genres: which facts a card shows, and which icon stands for each,
 * is a decision about that widget.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

/** One fact on an entry: an icon, the value it stands for, and what it is called. */
export interface WidgetPip {
    /** Sprite icon name — the fact's whole visible label. */
    icon: string;
    /** The already-formatted value ("12", "1:04:22", "Radiohead"). */
    value: string;
    /**
     * What this fact IS, spelled out. Shown only in the tooltip, joined to the value
     * ("Anzahl Alben: 12"), so the glyph never has to carry the meaning alone.
     */
    label: string;
}

/** One row: a name, where it goes, and the facts to show beneath it. */
export interface WidgetListItem {
    id: string;
    name: string;
    href: string;
    pips: WidgetPip[];
}

const { t } = useI18n();

defineProps<{
    /** Rows to show. */
    items: WidgetListItem[];
    /** Override for the no-items line (e.g. songs' "not enough data" for popular); defaults to the generic empty message. */
    emptyText?: string;
}>();
</script>

<template>
    <ul v-if="items.length" class="widget-list">
        <li v-for="item in items" :key="item.id">
            <Link :href="item.href" class="widget-list__item" prefetch>
                <span class="widget-list__name">{{ item.name }}</span>
                <span v-if="item.pips.length" class="widget-list__pips">
                    <!-- The tip sits on the whole pip, not on the icon: the value is part of
                         what it says, and anchoring to the glyph alone would leave the number
                         beside it unexplained. -->
                    <span
                        v-for="pip in item.pips"
                        :key="pip.icon"
                        v-tooltip="`${pip.label}: ${pip.value}`"
                        class="widget-list__pip"
                    >
                        <icon :name="pip.icon" :size="0" />
                        {{ pip.value }}
                    </span>
                </span>
            </Link>
        </li>
    </ul>
    <p v-else class="widget-list__empty">{{ emptyText ?? t("music.empty") }}</p>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.widget-list {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-widget-list, "gap");

    list-style: none;

    /* Each entry is a filled, rounded block rather than a bare line of text: it leads
       somewhere, and a list of undecorated names gives no sign of that until the pointer is
       already on one. */
    &__item {
        display: flex;
        flex-direction: column;

        padding: map.get(s.$c-widget-list, "item-padding");
        gap: map.get(s.$c-widget-list, "item-gap");

        background-color: map.get(c.$c-widget-list, "background");
        color: inherit;
        border-radius: map.get(s.$c-widget-list, "radius");

        text-decoration: none;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-discography ease-out,
                box-shadow ti.$c-discography ease-out;
        }

        /* The house treatment — the same two-layer control-neon halo the DataTable's
           clickable rows, the Discography's tiles and the genre artist cards use, over a
           wash that only SHIFTS the entry's existing fill. */
        &:hover {
            position: relative;

            background-color: map.get(c.$c-widget-list, "hover-background");
            box-shadow:
                0 0 0.6em 0.1em map.get(c.$c-widget-list, "glow"),
                0 0 1.5em 0.25em map.get(c.$c-widget-list, "glow");
        }

        /* The block is already the target, so it needs no underline — but a keyboard user
           gets no halo to read, so focus keeps a ring of its own. */
        &:focus-visible {
            outline: 2px solid currentcolor;
        }
    }

    &__name {
        overflow: hidden;

        font-weight: 600;

        /* One line, ellipsised. A widget is a teaser in a narrow card, and a wrapped title
           would push its pips out of line with the entries around it — unlike the genre
           page's artist cards, where the name IS the content and wraps instead. The full
           name is one click away. */
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    &__pips {
        display: flex;
        flex-wrap: wrap;

        gap: map.get(s.$c-widget-list, "pip-gap");

        color: map.get(c.$c-widget-list, "surface-meta");

        font-size: 0.85em;
    }

    &__pip {
        display: inline-flex;
        align-items: center;

        padding: map.get(s.$c-widget-list, "pip-padding");
        gap: 0.4ch;

        background-color: map.get(c.$c-widget-list, "pip-background");
        border-radius: map.get(s.$c-widget-list, "pip-radius");

        white-space: nowrap;
    }

    &__empty {
        opacity: 0.75;

        margin: 0;

        font-style: italic;
    }
}
</style>
