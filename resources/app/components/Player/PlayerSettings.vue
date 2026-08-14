<script setup lang="ts">
/******************************************************************************
 * PlayerSettings
 * The transport's playback settings: a gear in the player bar, and a popover holding
 * the two questions that are about the QUEUE as a whole rather than the track playing
 * — what order it plays in, and what happens when it runs out.
 *
 * IN THE BAR, not in the queue panel's menu, which is the obvious place for repeat. Two
 * reasons, and the second is the load-bearing one. The panel is hidden behind a toggle
 * on a phone and absent entirely once the queue is emptied, so its menu is the wrong
 * home for a setting you want while listening; and repeat sitting in a menu next to
 * "clear the queue" put a harmless toggle one row from a destructive verb.
 *
 * BUBBLES rather than two checkboxes, because each of these is a CHOICE BETWEEN TWO
 * NAMED MODES, not a feature you switch on. "Shuffle off" is "in order" — a mode with
 * its own name and its own glyph — and a lone checkbox says only that something is
 * absent. It also makes the current mode readable without knowing what an unlit icon
 * means, which a checkbox in a popover never quite manages.
 *
 * It reads usePlayerQueue directly rather than taking props or emitting: the composable
 * is a module singleton, so going through PlayerBar would be prop-drilling between two
 * components that can both simply ask. The same call PlayerVolume and PlayQueueMenu make.
 *
 * The popover is deliberately NOT closed on a change: setting the play order is
 * something you do while looking at the queue, and both rows are things people flip
 * twice in a row before settling.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import OptionBubbles from "Components/UI/OptionBubbles.vue";
import type { BubbleOption } from "Components/UI/OptionBubbles.vue";
import PopOver from "Components/UI/PopOver.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { PLAYER_SPEEDS, usePlayerSpeed } from "Composables/usePlayerSpeed";

const { t } = useI18n();
const { repeat, shuffle, toggleRepeat, toggleShuffle } = usePlayerQueue();
const { speed, setSpeed, isSkimming, effectiveRate } = usePlayerSpeed();

/**
 * The play-order options. `off` first, so the pill's resting position is the plain
 * behaviour and moving it right reads as "turn something on".
 *
 * Computed rather than a constant because the labels are translated: a `const` built at
 * module scope would keep the locale that happened to be active when this file was first
 * imported, and would not re-render when the switcher changes it.
 */
const modeOptions = computed<BubbleOption[]>(() => [
    { value: "off", icon: "shuffle_off", label: t("player.settings.inOrder") },
    { value: "on", icon: "shuffle", label: t("player.settings.shuffle") }
]);

/** The repeat options, same shape and the same ordering rule. */
const repeatOptions = computed<BubbleOption[]>(() => [
    { value: "off", icon: "repeat_off", label: t("player.settings.repeatOff") },
    { value: "on", icon: "repeat", label: t("player.settings.repeatOn") }
]);

/**
 * The speed options, built from the composable's own list so the control and what it will
 * accept cannot disagree.
 *
 * TEXT rather than glyphs, unlike the two rows above — the sprite has no picture that means
 * "three times as fast", and any invention would be less legible than the two characters it
 * replaced. The `×` is the multiplication sign, not a letter x: this is a multiplier, and it
 * sits beside real numbers.
 *
 * `label` is spelled out for assistive tech ("dreifache Geschwindigkeit"), because "3×"
 * announced character by character is not a name.
 */
const speedOptions = computed<BubbleOption[]>(() =>
    PLAYER_SPEEDS.map(value => ({
        value: String(value),
        text: `${value}×`,
        label: t("player.settings.speedOption", { rate: value })
    }))
);

/**
 * Bridge the queue's booleans to the bubbles' string values.
 *
 * The composable exposes TOGGLES rather than setters — the flags are its own state and
 * flipping is the only operation a listener has — so a write here flips only when the
 * value actually differs. Without that guard, re-selecting the option already chosen
 * (a click on the lit half, which a radiogroup allows) would turn it off while leaving
 * the pill where it was.
 */
const modeValue = computed<string>({
    get: () => (shuffle.value ? "on" : "off"),
    set: value => {
        if ((value === "on") !== shuffle.value) toggleShuffle();
    }
});

/** The same bridge for repeat. */
const repeatValue = computed<string>({
    get: () => (repeat.value ? "on" : "off"),
    set: value => {
        if ((value === "on") !== repeat.value) toggleRepeat();
    }
});

/**
 * Speed as the bubbles' string value.
 *
 * No guard against re-selecting the current option here, unlike the two above: this
 * composable exposes a SETTER rather than a toggle, so writing the value already in force
 * is genuinely a no-op instead of flipping it off.
 */
const speedValue = computed<string>({
    get: () => String(speed.value),
    set: value => setSpeed(Number(value))
});
</script>

<template>
    <div class="player-settings">
        <pop-over
            icon="settings"
            reference="playerSettings"
            class-string="popover-button--rounded popover-button--subtle player-settings__trigger"
            :aria-label="t('player.bar.settings')"
            width="auto"
        >
            <ul class="player-settings__panel">
                <li class="player-settings__row">
                    <span class="player-settings__label">{{ t("player.settings.mode") }}</span>
                    <option-bubbles
                        v-model="modeValue"
                        :options="modeOptions"
                        name="playerMode"
                        :label="t('player.settings.mode')"
                        :size="1"
                    />
                </li>
                <li class="player-settings__row">
                    <span class="player-settings__label">{{ t("player.settings.repeat") }}</span>
                    <option-bubbles
                        v-model="repeatValue"
                        :options="repeatOptions"
                        name="playerRepeat"
                        :label="t('player.settings.repeat')"
                        :size="1"
                    />
                </li>
                <!-- Speed sits LAST: the two above are about the queue, which is what this
                     popover was built for, and this one is about the track playing. It is
                     also the only row here whose effect is audible the instant it changes,
                     so it wants to be nearest the bar rather than buried above the rest. -->
                <li class="player-settings__row">
                    <span class="player-settings__label">{{ t("player.settings.speed") }}</span>
                    <!-- What is playing RIGHT NOW, while a held Space doubles the setting.
                         The pill cannot show this and must not try: it marks which option is
                         chosen, and the skim's rate is usually not one of them (3× skims at
                         6×). So the pill keeps telling the truth about the setting and this
                         says what is actually happening.

                         `aria-hidden`, because the bar's badge is already a `role="status"`
                         announcing the same figure — two live regions for one change means
                         hearing it twice.

                         ALWAYS RENDERED, hidden rather than removed: the popover is `width:
                         auto`, so a readout that came and went would resize the whole panel
                         under a reader who is holding a key down. The reserved slot costs a
                         few characters of width at all times, which is the cheaper of the
                         two — and it is constant, since every rate is one digit and the
                         figures are tabular. -->
                    <span
                        class="player-settings__live"
                        :class="{ 'player-settings__live--on': isSkimming }"
                        aria-hidden="true"
                        >▸ {{ effectiveRate }}×</span
                    >
                    <option-bubbles
                        v-model="speedValue"
                        :options="speedOptions"
                        name="playerSpeed"
                        :label="t('player.settings.speed')"
                        :size="1"
                    />
                </li>
            </ul>
        </pop-over>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.player-settings {
    display: inline-flex;
    align-items: center;

    /* Sizes the gear DOWN to match prev/next, exactly as PlayerVolume does and for the
       same reason: PopOver takes no icon-size prop and Icon defaults to step 2, which is
       the step the play/pause button uses — so out of the box this trigger rendered as
       large as the primary control. `--icon-size` is Icon's own hook. */
    :deep(.popover-button .icon) {
        --icon-size: #{map.get(s.$c-icon, "small")};
    }

    /* OPENS UPWARD, and this is why the wrapper element exists — the same override
       PlayerVolume documents at length: PopOver's shared style pins the dialog under its
       trigger, which is wrong for a bar fixed to the bottom of the viewport, and the
       `position-try-fallbacks` flip alone lands it a couple of pixels inside the button
       because the margin stays on the edge it was written for. Anchoring the panel's
       bottom edge to the trigger's top makes both the side and the gap deterministic. */
    :deep(.popover-content) {
        inset: auto anchor(right) anchor(top) auto;

        margin-block: 0 map.get(s.$c-player-settings, "gap");
    }
}

/* A list, because that is what it is: two settings, each a name and its options. The
   markers go, the layout stays a stack. */
.player-settings__panel {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-player-settings, "gap");

    list-style: none;
}

/* Label left, bubbles right, with the gap doing the work: `space-between` alone would
   let a long German label sit against the control. The row does NOT wrap — the popover
   is `auto` wide, so it grows to whatever the longest label needs instead. */
.player-settings__row {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: map.get(s.$c-player-settings, "row-gap");

    white-space: nowrap;

    /* The rule between the two settings — the same weight and the same token any
       `popover-list` in the app draws between its entries, read from the popover's own
       tokens rather than minted here because this is popover chrome rather than something
       this component owns (the mute button in PlayerVolume borrows the bar's colours for
       the same reason). `padding-bottom` matches the panel's gap, so the rule sits centred
       between the rows instead of hugging the first one. */
    &:not(:last-child) {
        padding-bottom: map.get(s.$c-player-settings, "gap");
        border-bottom: map.get(s.$c-popover, "border") solid map.get(c.$c-popover, "border");
    }
}

.player-settings__label {
    color: map.get(c.$c-player-settings, "label");

    font-size: 0.85em;
}

/* The live rate, while a held Space doubles the setting.
   `visibility` rather than `v-if` or `display: none` — all three hide it, only this one
   keeps its box, and keeping the box is the whole point: the popover is `width: auto`, so a
   readout that came and went would resize the panel under a reader who is holding a key.
   Tabular figures make the reserved width constant across 2×, 4× and 6×.
   It sits BEFORE the bubbles rather than after them, which is the one placement that keeps
   all three rows' controls flush to the same right edge — put it last and this row's bubbles
   sit inboard of the two above, and the panel reads as misaligned rather than annotated.
   The `▸` earns its place there: without it "6× 1× 2× 3×" reads as four options rather than
   a readout beside three, which is precisely the confusion the pill exists to avoid.
   It borrows the CHOSEN option's own ink (`c.$c-option-bubbles` "surface-selected") rather
   than minting a colour: this and the pill are the two things in the row saying "in force",
   and they should say it in the same voice. */
.player-settings__live {
    visibility: hidden;

    color: map.get(c.$c-option-bubbles, "surface-selected");

    font-size: 0.85em;
    font-weight: 600;
    font-variant-numeric: tabular-nums;

    &--on {
        visibility: visible;
    }
}
</style>
