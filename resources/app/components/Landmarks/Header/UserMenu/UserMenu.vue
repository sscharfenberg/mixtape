<script setup lang="ts">
/******************************************************************************
 * UserMenu
 * the last item in the app header — an account popover, mirroring cantrip.me's
 * user menu. The `auth` and `features` shared props are wired now as prep for
 * Fortify: `user` is null until login, and the feature flags are placeholders
 * (see HandleInertiaRequests) until Fortify supplies real values. The /login
 * and /forgot routes arrive with Fortify too; labels are literal for now.
 *****************************************************************************/
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import ThemeSwitch from "Components/Landmarks/Header/UserMenu/ThemeSwitch.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";

const page = usePage();
/** The authenticated user object, or `null`/`undefined` when logged out — controls which menu items are visible. */
const user = computed(() => page.props.auth.user);
/** Backend feature flags (e.g. `resetPasswords`) gating guest-only links. Placeholder until Fortify. */
const features = computed(() => page.props.features);

/** Programmatically hides the user-menu popover by its DOM id (on item click). */
function closePopover(): void {
    const dialog = document.getElementById("userMenu");
    if (dialog !== null) dialog.hidePopover();
}
</script>

<template>
    <nav class="user-menu" aria-label="User menu">
        <pop-over
            icon="account"
            aria-label="Open user menu"
            reference="userMenu"
            class-string="popover-button--rounded"
            width="20ch"
        >
            <ul class="popover-list">
                <li v-if="!user">
                    <Link class="popover-list-item" href="/login" @click="closePopover">
                        <icon name="login" :size="1" />
                        Anmelden
                    </Link>
                </li>
                <li v-if="!user && features.resetPasswords">
                    <Link class="popover-list-item" href="/forgot" @click="closePopover">
                        <icon name="support" :size="1" />
                        Probleme beim Anmelden?
                    </Link>
                </li>
                <li class="popover-list__divider" />
                <li><theme-switch /></li>
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
