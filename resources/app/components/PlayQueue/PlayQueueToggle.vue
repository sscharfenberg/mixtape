<script setup lang="ts">
/**************************************************************************
 * PlayQueueToggle
 * The header button that shows and hides the play queue — at EVERY width.
 *
 * It used to hide itself from `landscape` up, because up there the panel stood
 * permanently open and a button flipping a flag nothing read would have been worse
 * than no button. That arrangement is gone (see PlayQueue's banner: the dashboard's
 * right-aligned headings left no room to inset), so the panel is now opened the same
 * way on a desktop as on a phone, and this is the control that does it everywhere.
 * One consequence worth naming: there is no longer any "which layout am I in"
 * question here, so nothing needs a media query or a matchMedia listener.
 *
 * It disappears when the queue is EMPTY, at any width. An empty queue draws
 * no panel at all, so the button would open nothing; worse, it would be a control
 * that appears to do something and does not.
 *
 * The glyph doubles as the state: `play_queue` to open, `close` to shut again. It
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
        <icon :name="isOpen ? 'close' : 'play_queue'" />
    </button>
</template>
