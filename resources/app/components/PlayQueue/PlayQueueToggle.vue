<script setup lang="ts">
/**************************************************************************
 * PlayQueueToggle
 * The header button that shows and hides the play queue on a narrow screen.
 *
 * It exists only below the `landscape` step, and the CSS is what decides that
 * rather than a JS width check: from `landscape` up the panel is an ordinary
 * column in the layout grid, permanently on screen while the queue holds
 * anything, so a control for it would toggle nothing. Hiding it in CSS keeps the
 * single source of truth for "which layout am I in" in one place — a media query
 * beside the panel's own — instead of a matchMedia listener that has to be kept
 * in step with it.
 *
 * It also disappears when the queue is EMPTY, at any width. An empty queue draws
 * no panel at all, so the button would open nothing; worse, it would be a control
 * that appears to do something and does not.
 *
 * The glyph doubles as the state: `playlist` to open, `close` to shut again. It
 * borrows the global `.popover-button` classes rather than a look of its own, so
 * it sits beside the site and user menus as a peer — it is not a popover trigger,
 * but it is the same kind of round header control, and the alternative was a
 * second set of tokens saying the same thing.
 **************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { usePlayQueuePanel } from "Composables/usePlayQueuePanel";

const { t } = useI18n();
const { isEmpty } = usePlayerQueue();
const { isOpen, toggle } = usePlayQueuePanel();
</script>

<template>
    <button
        v-if="!isEmpty"
        type="button"
        class="play-queue-toggle popover-button popover-button--rounded popover-button--subtle"
        :class="{ 'popover-button--open': isOpen }"
        :aria-expanded="isOpen"
        :aria-label="isOpen ? t('player.queue.hide') : t('player.queue.show')"
        @click="toggle"
    >
        <icon :name="isOpen ? 'close' : 'playlist'" />
    </button>
</template>

<style scoped lang="scss">
@use "Abstracts/mixins" as m;

/* Narrow screens only. From `landscape` up the panel is a column that is simply
   there, so there is nothing to toggle — and a button that flips a flag nothing
   reads is worse than no button, because it looks like it should do something. */
.play-queue-toggle {
    @include m.mq("landscape") {
        display: none;
    }
}
</style>
