<script setup lang="ts">
/******************************************************************************
 * UserMenu
 * the last item in the app header — an account popover, mirroring cantrip.me's
 * user menu. `user` (from the `auth` shared prop) is null until login and gates
 * guest-only vs. authenticated items; the `features` flags gate the reset-
 * password link. The two preference toggles — LanguageSwitch and ThemeSwitch —
 * sit at the bottom below a divider. Labels come from the i18n catalog.
 *****************************************************************************/
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import LanguageSwitch from "Components/Landmarks/Header/UserMenu/LanguageSwitch.vue";
import ThemeSwitch from "Components/Landmarks/Header/UserMenu/ThemeSwitch.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";

const { t } = useI18n();
const page = usePage();
/** The authenticated user object, or `null`/`undefined` when logged out — controls which menu items are visible. */
const user = computed(() => page.props.auth.user);
/** Backend feature flags (e.g. `resetPasswords`) gating guest-only links. Placeholder until Fortify. */
const features = computed(() => page.props.features);

/** Trigger modifiers: always rounded, plus a lit-up highlight while a user is signed in. */
const triggerClass = computed(() => `popover-button--rounded${user.value ? " popover-button--highlighted" : ""}`);

/** Programmatically hides the user-menu popover by its DOM id (on item click). */
function closePopover(): void {
    const dialog = document.getElementById("userMenu");
    if (dialog !== null) dialog.hidePopover();
}
</script>

<template>
    <nav class="user-menu" :aria-label="t('header.userMenu.nav')">
        <pop-over
            icon="account"
            :aria-label="t('header.userMenu.open')"
            reference="userMenu"
            :class-string="triggerClass"
            width="20ch"
        >
            <ul class="popover-list">
                <li v-if="!user">
                    <Link class="popover-list-item" href="/login" @click="closePopover">
                        <icon name="login" :size="1" />
                        {{ t("header.userMenu.login") }}
                    </Link>
                </li>
                <li v-if="!user && features.resetPasswords">
                    <Link class="popover-list-item" href="/forgot" @click="closePopover">
                        <icon name="support" :size="1" />
                        {{ t("header.userMenu.loginHelp") }}
                    </Link>
                </li>
                <li v-if="user">
                    <Link class="popover-list-item" href="/dashboard" @click="closePopover">
                        <icon name="user-settings" :size="1" />
                        {{ t("header.userMenu.dashboard") }}
                    </Link>
                </li>
                <li v-if="user">
                    <Link
                        class="popover-list-item"
                        href="/logout"
                        method="post"
                        as="button"
                        type="button"
                        @click="closePopover"
                    >
                        <icon name="logout" :size="1" />
                        {{ t("header.userMenu.logout") }}
                    </Link>
                </li>
                <li class="popover-list__divider" />
                <language-switch @close="closePopover" />
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
