<script setup lang="ts">
/******************************************************************************
 * PlayerSettings
 * The transport's playback settings: a gear in the player bar, and a popover holding
 * the two questions that are about the QUEUE as a whole rather than the track playing
 * — what order it plays in, and what happens when it runs out.
 *
 * IN THE BAR, not in the queue panel's menu, which is where repeat used to live. Two
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

const { t } = useI18n();
const { repeat, shuffle, toggleRepeat, toggleShuffle } = usePlayerQueue();

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
                    />
                </li>
                <li class="player-settings__row">
                    <span class="player-settings__label">{{ t("player.settings.repeat") }}</span>
                    <option-bubbles
                        v-model="repeatValue"
                        :options="repeatOptions"
                        name="playerRepeat"
                        :label="t('player.settings.repeat')"
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
</style>
