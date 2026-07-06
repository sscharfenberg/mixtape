<script setup lang="ts">
/******************************************************************************
 * UserMenu
 * the last item in the app header — an account popover, mirroring cantrip.me's
 * user menu. Placeholder for now: mixtape has no auth/i18n/routes yet, so every
 * entry points at home. Real auth-gated items (sign in / dashboard / logout)
 * arrive with Fortify; the popover-list structure is here to build on.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import PopOver from "Components/UI/PopOver.vue";

/** Programmatically hides the user-menu popover by its DOM id (on item click). */
function closePopover(): void {
    const dialog = document.getElementById("userMenu");
    if (dialog !== null) dialog.hidePopover();
}
</script>

<template>
    <nav class="user-menu" aria-label="User menu">
        <pop-over icon="account" aria-label="Open user menu" reference="userMenu" class-string="popover-button--rounded">
            <ul class="popover-list">
                <li>
                    <Link class="popover-list-item popover-list-item--selected" href="/" @click="closePopover">
                        Home
                    </Link>
                </li>
                <li>
                    <Link class="popover-list-item" href="/" @click="closePopover">Playlists</Link>
                </li>
                <li>
                    <Link class="popover-list-item" href="/" @click="closePopover">Search</Link>
                </li>
                <li class="popover-list__divider" />
                <li>
                    <Link class="popover-list-item" href="/" @click="closePopover">Sign in</Link>
                </li>
            </ul>
        </pop-over>
    </nav>
</template>

<style scoped lang="scss">
.user-menu {
    // push the menu to the trailing edge of the header's flex row.
    margin-inline-start: auto;
}
</style>
