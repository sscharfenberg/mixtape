<script setup lang="ts">
/**************************************************************************
 * PlayQueueMenu
 * The queue panel's action menu — the three-dot trigger in its header and the
 * list behind it.
 *
 * A menu rather than the bare trash button this replaces. Clearing the queue is
 * destructive and irreversible, and a lone icon in a 240px header strip is far
 * too easy to hit on the way to something else; behind a menu it takes a
 * deliberate second click and is named in words instead of guessed from a glyph.
 *
 * Its own component rather than markup inside PlayQueue because it is where the
 * queue's other verbs are going — "save queue as playlist", shuffle and repeat —
 * and those bring state and handlers with them. Keeping them out of the panel
 * leaves that file about *drawing the queue*, which is already the longer job.
 * Repeat is the first of them to arrive.
 *
 * It reads the queue directly rather than taking props or emitting events: the
 * composable is a module singleton, so going through the panel would be
 * prop-drilling between two components that can both simply ask.
 **************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";

const { t } = useI18n();
const { repeat, toggleRepeat, clear } = usePlayerQueue();
</script>

<template>
    <pop-over
        icon="more"
        reference="playQueueActions"
        class-string="popover-button--rounded popover-button--subtle"
        :aria-label="t('player.queue.actions')"
        width="22ch"
    >
        <ul class="popover-list">
            <li>
                <!-- A toggle, so it stays open-able twice and reads its own state: the
                     popover is deliberately NOT closed here, because flipping repeat is
                     the sort of thing you do while looking at the queue, and `aria-pressed`
                     plus the `--selected` fill are what say which way it is set. -->
                <button
                    type="button"
                    class="popover-list-item"
                    :class="{ 'popover-list-item--selected': repeat }"
                    :aria-pressed="repeat"
                    @click="toggleRepeat"
                >
                    <icon name="repeat" :size="1" />
                    {{ t("player.queue.repeat") }}
                </button>
            </li>
            <li>
                <!-- No explicit popover close: an empty queue unmounts the whole panel,
                     and taking an open popover out of the DOM removes it from the top
                     layer with it. An entry that LEAVES the queue standing — saving it
                     as a playlist, say — will need one. -->
                <button type="button" class="popover-list-item popover-list-item--caution" @click="clear">
                    <icon name="delete" :size="1" />
                    {{ t("player.queue.clear") }}
                </button>
            </li>
        </ul>
    </pop-over>
</template>
