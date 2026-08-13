<script setup lang="ts">
/******************************************************************************
 * SearchToggle
 * The header button that opens and closes the search overlay.
 *
 * It borrows the global `.popover-button` classes rather than a look of its own, so it sits beside
 * the site menu, the user menu and the queue toggle as a peer — it is not a popover trigger, but
 * it is the same kind of round header control, and the alternative was a second set of tokens
 * saying the same thing. The `search` glyph was already in the sprite; no new icon.
 *
 * IT DISAPPEARS WHERE NO OVERLAY IS RENDERED — the guest share space, where a library search would
 * be an invitation to a login form. The condition is not "am I on a share page" or "is anybody
 * signed in": it is the overlay itself saying it exists (`noteSearchOverlay`), because a layout's
 * decision restated in the header is a copy that eventually disagrees. That also covers the guest
 * case for free, since the overlay only ever mounts in the signed-in layout.
 *
 * THE TOOLTIP NAMES THE KEY, the app's convention (`withKey`): what the control does, then the
 * chord that does the same thing. It is the only place ⌘K is discoverable at all — and it is
 * spelled for the keyboard it will be pressed on (⌘ on an Apple one, Ctrl elsewhere), because a
 * hint naming a key the reader cannot find is worse than no hint.
 *
 * IT SAYS "TOGGLE" IN THE TOOLTIP AND WHAT THE NEXT PRESS DOES IN THE LABEL, the same split
 * PlayQueueToggle uses: a screen reader should hear what pressing will do NOW, while a tooltip
 * naming a key is described once rather than re-read every time the panel opens.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { useSearchOverlay } from "Composables/useSearchOverlay";
import { commandKeyLabel, shortcut, withKey } from "Utils/platform";

const { t } = useI18n();
const { exists, isOpen, toggle } = useSearchOverlay();
</script>

<template>
    <button
        v-if="exists"
        v-tooltip="withKey(t('search.toggleHint'), shortcut(commandKeyLabel(), 'K'))"
        type="button"
        class="search-toggle popover-button popover-button--rounded popover-button--subtle"
        :class="{ 'popover-button--open': isOpen }"
        :aria-expanded="isOpen"
        :aria-label="isOpen ? t('search.close') : t('search.open')"
        @click="toggle"
    >
        <icon :name="isOpen ? 'close' : 'search'" />
    </button>
</template>
