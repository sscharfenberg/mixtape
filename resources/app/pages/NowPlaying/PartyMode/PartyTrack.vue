<script setup lang="ts">
/******************************************************************************
 * PartyTrack
 * One of the three entries in party mode's track box — the previous track, what is playing, or the
 * next one — with the same artwork, title and facts the Now Playing page shows for them.
 *
 * THE SAME FACTS AS THE NEIGHBOUR CARDS, FROM THE SAME FUNCTION (trackFacts). The layout is its
 * own — a caption over a fullscreen picture is not a card on a page — but what it says about a
 * track is not allowed to differ from the card a reader saw a moment ago.
 *
 * EACH ENTRY IS A CONTROL, because in fullscreen the player bar is gone: previous and next step
 * the queue exactly as the neighbour cards do, and the current track plays or pauses. Built the
 * way NeighbourTrack is — a transparent button stretched over a plain container — so the title
 * can stay a real heading (a `<button>` prunes every heading inside it from the accessibility
 * tree; NeighbourTrack's banner has the whole argument).
 *
 * IT RENDERS WITH NO TRACK, and says so, for the same reason the neighbour cards do: an entry
 * that vanished at the end of the queue would shift the other two sideways mid-party.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import { trackFacts } from "Components/Player/trackFacts";
import Icon from "Components/UI/Icon.vue";
import type { QueueTrack } from "Composables/usePlayerQueue";

const props = withDefaults(
    defineProps<{
        /** Which entry this is: decides the label, the glyph, the size and what pressing it does. */
        kind: "previous" | "current" | "next";
        /** The track, or null at the end of the queue in that direction (or an emptied queue). */
        track: QueueTrack | null;
        /** Its genre, from the page's facts. Null when untagged or not yet fetched. */
        genre: string | null;
        /** Whether the player is playing — only read by the current entry, for its label and action. */
        playing?: boolean;
    }>(),
    { playing: false }
);

const emit = defineEmits<{ activate: [] }>();

const { t } = useI18n();

/** The small label over the entry: a direction for the neighbours, the player's state for the current one. */
const label = computed(() => {
    if (props.kind !== "current") return t(`nowPlaying.${props.kind}`);

    return t(`nowPlaying.status.${props.playing ? "playing" : "paused"}`);
});

/**
 * The label's glyph. The neighbours wear the transport's skip glyphs, like the cards on the page;
 * the current entry shows the STATE (a play triangle while playing), matching its label.
 */
const glyph = computed(() => {
    if (props.kind === "previous") return "first-page";
    if (props.kind === "next") return "last-page";

    return props.playing ? "play" : "pause";
});

/** What the entry says when there is no track in its place. */
const emptyText = computed(() => (props.kind === "current" ? t("nowPlaying.empty") : t(`nowPlaying.no.${props.kind}`)));

/**
 * The button's accessible name: what pressing it will do, and to which track — "Next track:
 * Paranoid Android", "Pause: Paranoid Android". The direction or action alone repeats across
 * entries; the title alone gives no clue what the press does.
 */
const action = computed(() => {
    if (props.track === null) return emptyText.value;
    if (props.kind !== "current") return t("nowPlaying.stepTo", { direction: label.value, name: props.track.name });

    return t(`nowPlaying.party.${props.playing ? "pause" : "play"}`, { name: props.track.name });
});

/** The chips under the title — shared with the neighbour cards, see the banner. */
const facts = computed(() => (props.track === null ? [] : trackFacts(props.track, props.genre)));
</script>

<template>
    <div class="party-track" :class="[`party-track--${kind}`, { 'party-track--empty': track === null }]">
        <span class="party-track__label">
            <icon :name="glyph" :size="1" />
            {{ label }}
        </span>

        <div v-if="track" class="party-track__body">
            <!-- `decorative`, because the title is right beside it. The same size in all three
                 entries: they are one size, and the current one stands out by its panel. -->
            <cover-image :src="track.coverUrl" :title="track.name" size="large" decorative />
            <div class="party-track__meta">
                <!-- h2 for the current track and h3 either side: the overlay is a dialog of its
                     own, and the neighbours hang under what is playing. -->
                <component :is="kind === 'current' ? 'h2' : 'h3'" class="party-track__title">{{
                    track.name
                }}</component>
                <span class="party-track__facts">
                    <span v-for="fact in facts" :key="fact.key" class="party-track__fact">
                        <icon :name="fact.icon" :size="1" />
                        {{ fact.value }}
                    </span>
                </span>
            </div>
        </div>
        <div v-else class="party-track__body party-track__body--empty">{{ emptyText }}</div>

        <!-- The control, over the whole entry and empty on purpose — see the banner. Last in the
             DOM so it paints over the content without a z-index of its own. -->
        <button
            type="button"
            class="party-track__action"
            :disabled="track === null"
            :aria-label="action"
            @click="emit('activate')"
        ></button>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* An entry: label over artwork-and-text, on a panel of its own. `position: relative` anchors the
   stretched button, and `min-width: 0` lets a long title ellipsise inside its grid track instead of
   widening it.

   ALL THREE ARE ONE SIZE — the grid stretches them to one height and one width — so the panel is
   what tells them apart: the neighbours a thinned black the tunnel shows through, the current
   track a nearly solid deep indigo with the ramp's violet round it. */
.party-track {
    display: flex;
    position: relative;
    flex-direction: column;

    min-width: 0;
    padding: map.get(s.$c-party-mode, "track-padding");
    border: map.get(s.$c-party-mode, "track-border") solid map.get(c.$c-party-mode, "edge");

    gap: map.get(s.$c-party-mode, "track-gap");

    background-color: map.get(c.$c-party-mode, "track");
    border-radius: map.get(s.$c-party-mode, "track-radius");

    @media (prefers-reduced-motion: no-preference) {
        transition: border-color ti.$c-party-mode linear;
    }

    &:not(.party-track--empty):hover,
    &:has(.party-track__action:focus-visible) {
        border-color: map.get(c.$c-party-mode, "edge-active");
    }
}

/* What is playing: the solid panel and the full violet edge — see the rule above. Its hover and
   focus edge is still the neon, so pressing it looks like pressing anything else. */
.party-track--current {
    background-color: map.get(c.$c-party-mode, "track-current");
    color: map.get(c.$c-party-mode, "text");
    border-color: map.get(c.$c-party-mode, "edge-current");
}

/* The neighbours step back in their ink as well as their panel. */
.party-track--previous,
.party-track--next {
    color: map.get(c.$c-party-mode, "muted");
}

.party-track__label {
    display: flex;
    align-items: center;

    gap: 0.5ch;

    color: map.get(c.$c-party-mode, "muted");

    font-size: map.get(s.$c-party-mode, "label-font-size");
    text-transform: uppercase;
}

/* Artwork beside the text — or the text below the artwork where the entry is too narrow for both
   (a neighbour on a phone): the meta column's basis is what makes it wrap rather than shrink to a
   sliver. */
.party-track__body {
    display: flex;
    flex-wrap: wrap;

    min-width: 0;

    gap: map.get(s.$c-party-mode, "track-gap");
}

.party-track__body--empty {
    color: map.get(c.$c-party-mode, "muted");
}

/* `min-width: 0` so the title ellipsises rather than pushing the entry wider; the basis is the
   width it would rather wrap below the artwork than go under (see the body). */
.party-track__meta {
    display: flex;
    flex-direction: column;

    overflow: hidden;
    min-width: 0;
    flex: 1 1 map.get(s.$c-party-mode, "meta-basis");

    gap: map.get(s.$c-party-mode, "chip-gap");
}

/* A heading for its semantics, sized by design: normalize.css leaves the UA's heading margins and
   sizes in place, and neither is what this caption wants. */
.party-track__title {
    overflow: hidden;

    margin: 0;

    font-size: map.get(s.$c-party-mode, "title-font-size", "base");
    font-weight: 700;

    white-space: nowrap;
    text-overflow: ellipsis;

    @include m.mq("landscape") {
        font-size: map.get(s.$c-party-mode, "title-font-size", "landscape");
    }
}

.party-track__facts {
    display: flex;
    flex-wrap: wrap;

    gap: map.get(s.$c-party-mode, "chip-gap");
}

/* One fact on a faint fill — the neighbour cards' chip, in the overlay's own colours. */
.party-track__fact {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-party-mode, "chip-padding");

    gap: 0.5ch;

    background-color: map.get(c.$c-party-mode, "chip");
    color: map.get(c.$c-party-mode, "muted");
    border-radius: map.get(s.$c-party-mode, "chip-radius");

    font-size: map.get(s.$c-party-mode, "label-font-size");
    white-space: nowrap;
}

/* The whole entry as one target. Radius matches the entry's, so the focus ring follows it. */
.party-track__action {
    position: absolute;
    inset: 0;

    padding: 0;
    border: 0;

    background: none;

    border-radius: map.get(s.$c-party-mode, "track-radius");

    &:not(:disabled) {
        cursor: pointer;
    }
}
</style>
