<script setup lang="ts">
/******************************************************************************
 * PasswordStrength
 * A live strength meter for the registration password (ported from cantrip.me).
 * Driven by the zxcvbn `score` (0–4) from usePasswordEntropy → the server
 * `/password/entropy` endpoint, so it reflects exactly what the PasswordEntropy
 * validation rule accepts (score ≥ 3). A red→amber→green track is progressively
 * revealed as the score climbs (a neutral cover retreats from the right), with a
 * pass / fail chip alongside. All colours/sizes/timings come from the contextual
 * Abstracts tokens (c.$c-password-strength / s.$c-password-strength /
 * ti.$c-password-strength).
 *****************************************************************************/
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** zxcvbn strength score, 0 (weakest) – 4 (strongest). */
    score: number;
}>();

/** Width of the cover that HIDES the unearned right portion of the track:
 *  widest at score 0, gone at score 4 (so more green shows as the score climbs). */
const coverWidth = computed(() => (props.score >= 4 ? "0%" : `${(4 - props.score) * 20 + 10}%`));

/** score ≥ 3 is the accepted threshold (matches the PasswordEntropy rule). */
const strong = computed(() => props.score >= 3);
</script>

<template>
    <div class="password-strength" :class="{ 'password-strength--strong': strong }">
        <div
            class="password-strength__meter"
            role="meter"
            aria-valuemin="0"
            aria-valuemax="4"
            :aria-valuenow="score"
            aria-label="Passwortstärke"
        >
            <div class="password-strength__cover" :style="{ width: coverWidth }" />
        </div>
        <icon class="password-strength__icon" :name="strong ? 'check' : 'warning'" :size="1" />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.password-strength {
    display: flex;
    align-items: center;

    padding: map.get(s.$c-password-strength, "padding");
    border: map.get(s.$c-password-strength, "border") solid map.get(c.$c-password-strength, "border");

    // Rendered in the form-row's #text slot, which already supplies the top
    // gap and constrains width to the input column — so no margin of its own.
    gap: 1ch;

    border-radius: map.get(s.$c-password-strength, "radius");

    &__meter {
        position: relative;
        flex-grow: 1;

        overflow: hidden;

        height: 2ex;

        // the red → amber → green track; the cover below hides the unearned part.
        background: linear-gradient(
            90deg,
            map.get(c.$c-password-strength, "weak"),
            map.get(c.$c-password-strength, "medium"),
            map.get(c.$c-password-strength, "strong")
        );
        border-radius: map.get(s.$c-password-strength, "meter-radius");
    }

    &__cover {
        position: absolute;
        inset: 0 0 0 auto; // anchored to the right edge, shrinks as the score climbs

        height: 100%;

        background: map.get(c.$c-password-strength, "cover");

        @media (prefers-reduced-motion: no-preference) {
            transition: width map.get(ti.$c-password-strength, "meter") linear;
        }
    }

    &__icon {
        padding: 0.2ex;

        // fail (warning) by default; pass (success) when strong.
        background-color: map.get(c.$c-password-strength, "fail");
        color: map.get(c.$c-password-strength, "fail-contrast");
        border-radius: 50%;
    }

    &--strong &__icon {
        background-color: map.get(c.$c-password-strength, "pass");
        color: map.get(c.$c-password-strength, "pass-contrast");
    }
}
</style>
