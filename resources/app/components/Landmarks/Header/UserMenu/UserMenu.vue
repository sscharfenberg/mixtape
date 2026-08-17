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
import { flushQueueWrites } from "Composables/usePlayerQueue";

const { t } = useI18n();
const page = usePage();
/** The authenticated user object, or `null`/`undefined` when logged out — controls which menu items are visible. */
const user = computed(() => page.props.auth.user);
/** Backend feature flags (e.g. `resetPasswords`) gating guest-only links. Placeholder until Fortify. */
const features = computed(() => page.props.features);
/** Whether this reader has share links to manage — gates the entry to /dashboard/shared. */
const hasShares = computed(() => page.props.hasShares === true);

/**
 * Whether this reader has listened to anything — gates the entry to /history, and the two
 * rules that set that entry apart from the account items above it.
 *
 * Same rule as `hasShares`, for the same reason: a menu entry leading to a page that can only
 * say "you have not listened to anything yet" is a promise the page cannot keep, and a fresh
 * account would meet it before it ever met the music.
 */
const hasPlays = computed(() => page.props.hasPlays === true);

/** Trigger modifiers: always rounded, plus a lit-up highlight while a user is signed in. */
const triggerClass = computed(() => `popover-button--rounded${user.value ? " popover-button--highlighted" : ""}`);

/** Programmatically hides the user-menu popover by its DOM id (on item click). */
function closePopover(): void {
    const dialog = document.getElementById("userMenu");
    if (dialog !== null) dialog.hidePopover();
}

/**
 * Close the menu and write the queue out while there is still a session to write it to.
 *
 * THE PRESS IS THE LAST MOMENT IT CAN HAPPEN. Queue writes are coalesced behind a 500ms
 * timer, so a track dragged or enqueued just before this click is still only in memory —
 * and by the time the logout response lands, FullLayout has abandoned the queue and the
 * cookie that would have authorised the PUT is gone. Flushed here it goes up as the queue
 * the reader left, which is the copy they meet again on their next sign-in.
 *
 * The same ordering, and the same reason, as `beginEphemeralQueue`: flush first, then
 * change what persistence means.
 */
function handleLogout(): void {
    flushQueueWrites();
    closePopover();
}
</script>

<template>
    <nav class="user-menu" :aria-label="t('header.userMenu.nav')">
        <pop-over
            icon="account"
            :ariaLabel="t('header.userMenu.open')"
            reference="userMenu"
            :class-string="triggerClass"
            width="20ch"
        >
            <ul class="popover-list">
                <!-- NO `prefetch` ON THE TWO FORMS below, per CLAUDE.md's prefetch rule: a
                     prefetch that lands after you have navigated to the same URL is applied to the
                     page you are on and re-creates it, which on a form discards what has been typed
                     and saves what the server sent. `/login` is the worst case in the app — it holds
                     a password, so `useRemember` is not an option there either (remembered state
                     goes into the history entry). The dashboard link below keeps its warming: that
                     page is one a reader only reads. -->
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
                    <Link class="popover-list-item" href="/dashboard" prefetch @click="closePopover">
                        <icon name="user-settings" :size="1" />
                        {{ t("header.userMenu.dashboard") }}
                    </Link>
                </li>
                <!-- Below the dashboard, and only for a reader who has actually shared
                     something (the `hasShares` shared prop): a menu entry leading to a list of
                     nothing is a promise the page cannot keep. Warmed on hover for the same
                     reason the dashboard link above it is — a page one only reads, never a
                     form (CLAUDE.md → the prefetch rule). -->
                <li v-if="user && hasShares">
                    <Link class="popover-list-item" href="/dashboard/shared" prefetch @click="closePopover">
                        <icon name="share" :size="1" />
                        {{ t("header.userMenu.shares") }}
                    </Link>
                </li>
                <!-- THE LISTENING HISTORY, IN A GROUP OF ITS OWN — a rule above it and a rule
                     below. The two entries above are about the ACCOUNT (its settings, the links
                     it has sent); this one is about the music, and logout is about neither. The
                     rules are what say so, since three items in one run read as three settings.
                     Drawn only for a reader who has actually listened to something, off the
                     `hasPlays` shared prop — the same gate the shares entry above it has. Warmed
                     on hover like both of them: a page one only reads, never a form
                     (CLAUDE.md → the prefetch rule). -->
                <li v-if="user && hasPlays" class="popover-list__divider" aria-hidden="true" />
                <li v-if="user && hasPlays">
                    <Link class="popover-list-item" href="/history" prefetch @click="closePopover">
                        <icon name="plays" :size="1" />
                        {{ t("header.userMenu.history") }}
                    </Link>
                </li>
                <li v-if="user && hasPlays" class="popover-list__divider" aria-hidden="true" />
                <li v-if="user">
                    <Link
                        class="popover-list-item"
                        href="/logout"
                        method="post"
                        as="button"
                        type="button"
                        @click="handleLogout"
                    >
                        <icon name="logout" :size="1" />
                        {{ t("header.userMenu.logout") }}
                    </Link>
                </li>
                <!-- `aria-hidden` because it is a rule, not an entry: without it the list
                     announces one more item than the menu actually offers. -->
                <li class="popover-list__divider" aria-hidden="true" />
                <language-switch @close="closePopover" />
                <li><theme-switch /></li>
            </ul>
        </pop-over>
    </nav>
</template>
