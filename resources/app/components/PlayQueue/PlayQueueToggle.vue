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
 * AND IT DISAPPEARS WHERE NO PANEL IS RENDERED AT ALL (2026-08-12), which is the same rule
 * applied one level up: the guest share space has its queue on the page and deliberately mounts
 * no panel, so there is nothing here to open. The condition is not "am I on a share page" or
 * "is anybody signed in" — it is the panel itself saying it exists (`notePlayQueuePanel`),
 * because a layout's decision restated in the header is a copy that eventually disagrees.
 *
 * The glyph doubles as the state: `play_queue` to open, `close` to shut again. It
 * borrows the global `.popover-button` classes rather than a look of its own, so
 * it sits beside the site and user menus as a peer — it is not a popover trigger,
 * but it is the same kind of round header control, and the alternative was a
 * second set of tokens saying the same thing.
 *
 * THE TOOLTIP NAMES THE KEY, the transport's convention (`withKey`): a control's
 * job, then the shortcut that does the same thing. It is the only place `Q` is
 * discoverable at all — the panel has no other affordance advertising it — which
 * is why this button has one where the header's other round controls do not.
 *
 * IT SAYS "TOGGLE", NOT WHAT THE NEXT PRESS DOES, unlike the `aria-label` beside
 * it. The label flips with the state because a screen reader should hear what
 * pressing will do NOW; the tooltip names the key, and a key that toggles is
 * described once rather than re-read every time the panel opens.
 **************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { usePlayQueuePanel } from "Composables/usePlayQueuePanel";
import { withKey } from "Utils/platform";

const { t } = useI18n();
const { isEmpty } = usePlayerQueue();
const { exists, isOpen, toggle } = usePlayQueuePanel();
</script>

<template>
    <button
        v-if="exists && !isEmpty"
        type="button"
        class="play-queue-toggle popover-button popover-button--rounded popover-button--subtle"
        :class="{ 'popover-button--open': isOpen }"
        :aria-expanded="isOpen"
        v-tooltip="withKey(t('player.queue.toggleHint'), t('player.bar.keys.queue'))"
        :aria-label="isOpen ? t('player.queue.hide') : t('player.queue.show')"
        @click="toggle"
    >
        <icon :name="isOpen ? 'close' : 'play_queue'" />
    </button>
</template>
