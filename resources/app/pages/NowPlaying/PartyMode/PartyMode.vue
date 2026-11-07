<script setup lang="ts">
/******************************************************************************
 * PartyMode
 * The Now Playing page gone fullscreen: a visualiser filling the screen, and over it a box naming
 * the previous track, what is playing and what comes next — the same three the page shows.
 *
 * THREE PIECES, EACH OWNED ELSEWHERE, and this file only arranges them:
 *   - the SCREEN — fullscreen, the wake lock, the inert page behind, the idle pointer —
 *     is usePartyScreen;
 *   - the PICTURE — the audio tap, the analysis, the loop and the plugin — is useVisualizer, over
 *     the registry in visualizers.ts (the warp tunnel is the first plugin; adding one is a module
 *     and a line there);
 *   - the TRACKS are usePlayerQueue's, exactly as the page reads them, drawn by PartyTrack.
 *
 * MOUNTED BY THE PRESS THAT OPENS IT. The page renders this with `v-if` from the button's click,
 * so `onMounted` still holds the click's user activation — which fullscreen needs, and which the
 * audio context's resume needs if nothing has routed the player yet.
 *
 * TELEPORTED TO <body>, so no ancestor's transform or containment can turn `position: fixed` into
 * "fixed to that ancestor" in the windowed fallback, and so every OTHER child of <body> can be made
 * inert while it is open.
 *
 * THE PLAYER BAR IS NOT HERE, AND THE BOX STANDS IN FOR IT. Fullscreen draws only this element,
 * so the bar is gone — the entries step and pause the player, and the document's shortcuts (Space,
 * the arrows) still reach the player because they listen on the document, not on the bar.
 *
 * REDUCED MOTION: the party opens as a still picture, the analyser is never asked and the audio is
 * never routed — the promise the Now Playing bars make, kept here too. The box is unaffected; a
 * fullscreen "what is playing" is still useful to someone who asked the screen to keep still.
 *****************************************************************************/
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import PartyTrack from "./PartyTrack.vue";
import { usePartyScreen } from "./usePartyScreen";
import { useVisualizer } from "./useVisualizer";
import { VISUALIZERS } from "./visualizers";

const props = defineProps<{
    /**
     * Each drawn track's genre, keyed by track id — the page's facts, narrowed. The one thing the
     * box shows that the queue does not carry; an absent id drops the chip, as on the page.
     */
    genres: Record<string, string | null | undefined>;
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const { current, previousTrack, nextTrack, next, previous } = usePlayerQueue();
const { isPlaying, toggle } = usePlayerAudio();

/** The overlay — the element that goes fullscreen, and the one the palette is read from. */
const root = ref<HTMLElement | null>(null);
/** The canvas the visualiser draws on. */
const canvas = ref<HTMLCanvasElement | null>(null);

/** Ask the page to close the party. Every way out — the button, Escape, leaving fullscreen — comes here. */
const leave = (): void => emit("close");

const { idle, wake } = usePartyScreen(root, leave);
const { status, active, start, cycle, stop } = useVisualizer({ canvas, root, isPlaying });

/** One track's genre, or null when it has none, is unknown, or there is no track at all. */
const genreOf = (id: string | undefined): string | null => (id === undefined ? null : (props.genres[id] ?? null));

/**
 * The cycle button's label: what is on screen now, which is also what the press moves away from.
 * Only rendered when there is more than one visualiser to cycle through.
 */
const visualizerName = computed(() =>
    t("nowPlaying.party.visualizer", {
        name: t(`nowPlaying.party.visualizers.${VISUALIZERS[active.value]?.id ?? "warpTunnel"}`)
    })
);

onMounted(() => {
    void start();
});

onBeforeUnmount(() => {
    stop();
});
</script>

<template>
    <Teleport to="body">
        <!-- A modal dialog in all but element: `<dialog>` cannot go fullscreen (the Fullscreen
             spec refuses it), so the role and the inert page behind do what showModal() would.
             `tabindex="-1"` is what lets usePartyScreen focus it — see there for why the overlay
             and not its first button. -->
        <div
            ref="root"
            class="party"
            :class="{ 'party--idle': idle }"
            role="dialog"
            aria-modal="true"
            :aria-label="t('nowPlaying.party.open')"
            tabindex="-1"
            :data-visualizer="status"
            @pointermove="wake"
            @pointerdown="wake"
            @keydown="wake"
        >
            <!-- Keyed on the plugin, so switching gives the next one a canvas with a fresh context
                 rather than the one the last plugin released. `aria-hidden`: a picture of the
                 music says nothing a listener cannot hear, and the box below names the tracks. -->
            <canvas :key="active" ref="canvas" class="party__canvas" aria-hidden="true"></canvas>

            <div class="party__controls">
                <button v-if="VISUALIZERS.length > 1" type="button" class="party__control" @click="cycle">
                    <icon name="refresh" :size="1" />
                    <span>{{ visualizerName }}</span>
                </button>
                <button type="button" class="party__control party__exit" @click="leave">
                    <icon name="close" :size="1" />
                    <span>{{ t("nowPlaying.party.close") }}</span>
                </button>
            </div>

            <section class="party__box" :aria-label="t('nowPlaying.party.tracks')">
                <party-track
                    kind="previous"
                    :track="previousTrack"
                    :genre="genreOf(previousTrack?.id)"
                    @activate="previous"
                />
                <party-track
                    kind="current"
                    :track="current"
                    :genre="genreOf(current?.id)"
                    :playing="isPlaying"
                    @activate="toggle"
                />
                <party-track kind="next" :track="nextTrack" :genre="genreOf(nextTrack?.id)" @activate="next" />
            </section>
        </div>
    </Teleport>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

/* The whole viewport, over everything — and, once fullscreen, the only thing drawn.

   ALWAYS DARK, whatever the theme: a tunnel through space on a white page is not a choice anyone
   would make. `color-scheme: dark` resolves every `light-dark()` token inside (the cover
   placeholder, the icons) to its dark side, so nothing here needs a second theme.

   THE PALETTE IS PUBLISHED AS CUSTOM PROPERTIES for the visualiser to read back (useVisualizer →
   readPalette): a shader cannot read a stylesheet, and writing the colours into TypeScript would
   be a second palette outside the token system. */
.party {
    --party-space: #{map.get(c.$c-party-mode, "space")};
    --party-grid: #{map.get(c.$c-party-mode, "grid")};
    --party-ramp-low: #{map.get(c.$c-party-mode, "ramp-low")};
    --party-ramp-mid: #{map.get(c.$c-party-mode, "ramp-mid")};
    --party-ramp-high: #{map.get(c.$c-party-mode, "ramp-high")};
    --party-glow: #{map.get(c.$c-party-mode, "glow")};

    position: fixed;
    inset: 0;
    z-index: z.$c-party-mode;

    overflow: hidden;

    background-color: map.get(c.$c-party-mode, "space");
    color: map.get(c.$c-party-mode, "text");

    color-scheme: dark;

    &:focus {
        outline: none;
    }
}

/* Nobody is moving the pointer: hide it, everywhere — the entries' own `cursor: pointer` would
   otherwise bring it back over the box. */
.party--idle,
.party--idle :deep(*) {
    cursor: none;
}

/* Stretched to the overlay and scaled by the browser: the drawing buffer is deliberately smaller
   than the screen (useVisualizer → drawingBufferSize), and CSS is what fills the difference. */
.party__canvas {
    display: block;
    position: absolute;
    inset: 0;

    width: 100%;
    height: 100%;
}

/* Top right, clear of the notch where there is one. They fade while the pointer rests — but not
   while one of them has keyboard focus, which would hide the thing a keyboard reader is on. */
.party__controls {
    display: flex;
    position: absolute;
    top: max(#{map.get(s.$c-party-mode, "gutter")}, env(safe-area-inset-top));
    right: max(#{map.get(s.$c-party-mode, "gutter")}, env(safe-area-inset-right));

    gap: map.get(s.$c-party-mode, "track-gap");

    @media (prefers-reduced-motion: no-preference) {
        transition: opacity ti.$c-party-mode linear;
    }
}

.party--idle .party__controls:not(:focus-within) {
    opacity: 0;
}

.party__control {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-party-mode, "control-padding");
    border: map.get(s.$c-party-mode, "track-border") solid map.get(c.$c-party-mode, "edge");

    gap: 0.5ch;

    background-color: map.get(c.$c-party-mode, "track");
    color: map.get(c.$c-party-mode, "text");
    border-radius: map.get(s.$c-party-mode, "track-radius");

    font: inherit;

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition: border-color ti.$c-party-mode linear;
    }

    &:hover,
    &:focus-visible {
        border-color: map.get(c.$c-party-mode, "edge-active");
    }
}

/* The caption: three entries along the bottom, each a panel of its own (PartyTrack) — so this is
   layout only, with no surface, and the tunnel shows in the gaps between them.

   THREE EQUAL COLUMNS AND ONE ROW HEIGHT on a wide screen: the grid's default `stretch` makes
   every entry as tall as the tallest, and equal tracks make them equally wide, whatever their
   titles. On a narrow screen the current track takes the first row and its neighbours share the
   second, each half — three across a phone would leave a title a word wide.

   `minmax(0, …)` on every track, never a bare `fr`: a title is `nowrap`, and a bare `1fr`'s floor
   is the whole title — the trap the Now Playing grids document. */
.party__box {
    display: grid;
    position: absolute;
    right: max(#{map.get(s.$c-party-mode, "gutter")}, env(safe-area-inset-right));
    bottom: max(#{map.get(s.$c-party-mode, "gutter")}, env(safe-area-inset-bottom));
    left: max(#{map.get(s.$c-party-mode, "gutter")}, env(safe-area-inset-left));

    grid-template-areas:
        "current current"
        "previous next";
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);

    max-width: map.get(s.$c-party-mode, "box-max-width");
    gap: map.get(s.$c-party-mode, "track-gap");

    margin-inline: auto;

    @include m.mq("landscape") {
        grid-template-areas: "previous current next";
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    :deep(.party-track--previous) {
        grid-area: previous;
    }

    :deep(.party-track--current) {
        grid-area: current;
    }

    :deep(.party-track--next) {
        grid-area: next;
    }
}
</style>
