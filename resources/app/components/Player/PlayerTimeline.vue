<script setup lang="ts">
/******************************************************************************
 * PlayerTimeline
 * The transport's scrub bar: where the track is, how much of it the browser already
 * holds, and a way to move the cursor. Its own component rather than markup inside
 * PlayerBar because it is the one part of the bar with interaction state of its own
 * (a drag in progress), and PlayerBar's job is already "arrange the transport".
 *
 * THREE LAYERS, in this order, and the middle one is the point of the whole
 * component: the rail (the track, undownloaded), the BUFFER (what has arrived and
 * would not be fetched again), and the played fill. A listener deciding whether to
 * drag ahead wants to know whether that costs a round-trip over a home uplink — the
 * buffer indicator is that answer, and nothing else on the page can give it.
 *
 * A NATIVE <input type="range"> does the interaction, laid transparent over those
 * layers. A div with pointer handlers would have meant re-implementing keyboard
 * support, drag capture outside the element's own box, touch, and the ARIA slider
 * contract — all of which the platform already has, and all of which are easy to get
 * half-right. It is `hit` tall rather than the rail's 6px so there is something to
 * actually grab (see sizes/components/_player-timeline.scss).
 *
 * SCRUBBING IS COMMITTED ON RELEASE, not continuously. `input` fires on every pixel
 * of a drag; seeking on each one would fire a Range request per pixel at a server
 * that may be reading a 96GB collection off a spinning disk. So a drag updates a
 * LOCAL position (the fill follows the thumb, so it still feels live) and only
 * `change` — mouse-up, or a keyboard step — emits the seek. That local value is also
 * what stops the fill snapping back mid-drag when the still-playing element reports
 * its own, older position.
 *****************************************************************************/
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { BufferedRange } from "Composables/usePlayerAudio";
import { formatClock } from "Utils/formatting";

const props = defineProps<{
    /** Where the play cursor is, in seconds. */
    currentTime: number;
    /** The track's total playing time in seconds, or 0 while it is unknown. */
    duration: number;
    /** The stretches the browser has downloaded, in seconds. */
    buffered: BufferedRange[];
}>();

const emit = defineEmits<{
    /** The listener let go of the thumb: move the cursor here, in seconds. */
    seek: [seconds: number];
}>();

const { t } = useI18n();

/** Where the thumb is while it is being dragged; null whenever it is not. */
const scrubTo = ref<number | null>(null);

/**
 * A track with a duration is scrubbable; one without is not.
 *
 * A duration of 0 means neither the database nor the element knows how long the file
 * is, which makes every position on the rail meaningless — so the input is disabled
 * rather than left offering a seek it cannot compute.
 */
const isSeekable = computed(() => props.duration > 0);

/** The position the bar should DRAW: the drag if there is one, the element otherwise. */
const position = computed(() => scrubTo.value ?? props.currentTime);

/**
 * A time as a percentage of the track, clamped into the rail.
 *
 * Clamped because both inputs can legitimately overshoot: a buffered range runs to
 * the end of the FILE, while the duration comes from the database's measurement of
 * it, and a file whose tags disagree with its bytes would otherwise paint a segment
 * past the end of the rail.
 */
const percent = (seconds: number): number =>
    props.duration > 0 ? Math.min(Math.max((seconds / props.duration) * 100, 0), 100) : 0;

/** Width of the played fill, as a CSS percentage. */
const playedWidth = computed(() => `${percent(position.value)}%`);

/**
 * The buffered stretches as absolute left/width pairs.
 *
 * Segments rather than a single "buffered up to here" width, because after a seek
 * past the buffer there genuinely are two: what was downloaded before, and what is
 * arriving at the new position. Drawing only the first would claim the gap between
 * them is held when it is not — which is exactly the question the indicator exists
 * to answer.
 */
const bufferSegments = computed(() =>
    props.buffered.map(range => ({
        left: `${percent(range.start)}%`,
        width: `${percent(range.end) - percent(range.start)}%`
    }))
);

/** The elapsed reading, left of the rail. `formatClock` never returns null for a real number. */
const elapsedClock = computed(() => formatClock(position.value) ?? "0:00");

/** The total, right of the rail — an em dash while nothing knows the length. */
const totalClock = computed(() => (isSeekable.value ? formatClock(props.duration) : "–:––"));

/**
 * Follow the thumb without seeking.
 *
 * The fill has to track the drag or the control feels dead, but the seek itself waits
 * for release — see the component note on why one seek per pixel is not an option.
 */
const onInput = (event: Event): void => {
    scrubTo.value = Number((event.target as HTMLInputElement).value);
};

/**
 * The drag ended (or a keyboard step happened): commit the seek.
 *
 * `scrubTo` is cleared LAST, so the fill hands over to the element's own reading only
 * once the seek has been asked for — clearing it first would flash the old position.
 */
const onChange = (event: Event): void => {
    const seconds = Number((event.target as HTMLInputElement).value);
    emit("seek", seconds);
    scrubTo.value = null;
};
</script>

<template>
    <div class="player-timeline">
        <span class="player-timeline__time">{{ elapsedClock }}</span>

        <div class="player-timeline__rail">
            <span
                v-for="(segment, index) in bufferSegments"
                :key="index"
                class="player-timeline__buffer"
                :style="{ left: segment.left, width: segment.width }"
            />
            <span class="player-timeline__played" :style="{ width: playedWidth }" />
            <!-- The real control, transparent over the three layers above. `aria-valuetext`
                 is what makes a screen reader say "1:23" instead of "83": the value a range
                 input announces is its number, and seconds are not how anyone reads a
                 position in a song. -->
            <input
                class="player-timeline__input"
                type="range"
                min="0"
                :max="duration || 0"
                step="0.1"
                :value="position"
                :disabled="!isSeekable"
                :aria-label="t('player.bar.seek')"
                :aria-valuetext="elapsedClock"
                @input="onInput"
                @change="onChange"
            />
        </div>

        <span class="player-timeline__time">{{ totalClock }}</span>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

$rail: map.get(s.$c-player-timeline, "rail");
$hit: map.get(s.$c-player-timeline, "hit");
$thumb: map.get(s.$c-player-timeline, "thumb");
$radius: map.get(s.$c-player-timeline, "radius");

.player-timeline {
    display: flex;
    align-items: center;

    min-width: 0;
    gap: map.get(s.$c-player-timeline, "gap");

    &__time {
        color: map.get(c.$c-player-timeline, "time");

        font-size: 0.8em;

        /* Without this the elapsed reading changes width as the digits change and
           shoves the rail sideways once a second. */
        font-variant-numeric: tabular-nums;
    }

    /* The rail is the positioning context for all three layers AND for the input, so
       every one of them measures against the same box. It is `hit` tall rather than
       `rail` tall — the drawn rail is a centred pseudo-element inside it — because the
       thumb needs a target and the box is what receives the pointer. */
    &__rail {
        display: flex;
        position: relative;
        align-items: center;

        min-width: map.get(s.$c-player-timeline, "min");
        height: $hit;
        flex: 1 1 auto;

        &::before {
            position: absolute;
            inset: calc(50% - #{$rail} / 2) 0 auto;

            height: $rail;

            background-color: map.get(c.$c-player-timeline, "rail");

            border-radius: $radius;

            content: "";
        }
    }

    /* Buffer and fill share their geometry — same height, same vertical centring, same
       rounding — and differ only in colour and in how their width is decided. */
    &__buffer,
    &__played {
        position: absolute;
        top: calc(50% - #{$rail} / 2);

        height: $rail;

        border-radius: $radius;
    }

    &__buffer {
        background-color: map.get(c.$c-player-timeline, "buffer");
    }

    &__played {
        left: 0;

        background-color: map.get(c.$c-player-timeline, "played");

        /* The house "this one is live" halo, on the same two-layer pattern as the
           queue's current row and the neon Button. */
        box-shadow: 0 0 0.4em 0.05em map.get(c.$c-player-timeline, "glow");
    }

    /* SMOOTHING, not animation: `timeupdate` arrives about four times a second, so
       without this the fill advances in visible steps. Under reduced motion it steps,
       which is honest for a position readout. */
    @media (prefers-reduced-motion: no-preference) {
        &__buffer,
        &__played {
            transition: width ti.$c-player-timeline linear;
        }
    }

    /* The actual control, invisible except for its thumb. Stretched over the whole
       rail box so the hit area is the full `hit` height rather than the 6px the eye
       sees, and `background: none` is what lets the layers below show through — a
       range input paints its own track otherwise. */
    &__input {
        position: absolute;
        inset: 0;

        width: 100%;
        height: 100%;
        margin: 0;

        appearance: none;

        background: none;

        cursor: pointer;

        &:disabled {
            cursor: default;
        }

        /* Vendor thumb pseudo-elements CANNOT be combined into one selector — a rule
           listing both is dropped whole by both engines, which is why this looks
           duplicated. `appearance: none` on the WebKit one is required before any of
           its own styling applies. */
        &::-webkit-slider-thumb {
            width: $thumb;
            height: $thumb;
            border: 0;

            appearance: none;

            background-color: map.get(c.$c-player-timeline, "thumb");

            border-radius: 50%;
            box-shadow: 0 0 0.4em 0.1em map.get(c.$c-player-timeline, "glow");
        }

        &::-moz-range-thumb {
            width: $thumb;
            height: $thumb;
            border: 0;

            background-color: map.get(c.$c-player-timeline, "thumb");

            border-radius: 50%;
            box-shadow: 0 0 0.4em 0.1em map.get(c.$c-player-timeline, "glow");
        }

        /* A track with no known length offers nothing to aim at, so the thumb goes
           away rather than sitting at 0:00 pretending to be draggable. */
        &:disabled::-webkit-slider-thumb {
            display: none;
        }

        &:disabled::-moz-range-thumb {
            visibility: hidden;
        }
    }
}
</style>
