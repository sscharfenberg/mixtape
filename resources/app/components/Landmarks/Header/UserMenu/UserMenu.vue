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

/**
 * Whether this reader keeps any .m3u export presets — gates the entry to
 * /dashboard/export-presets.
 *
 * The same rule as `hasShares` and `hasPlays`, applied to a feature whose dashboard section is
 * deliberately NOT gated: this menu is a shortcut list rather than a table of contents, so an
 * entry leading to a page that can only offer a "create one" button does not belong in it. A
 * reader meets presets on the dashboard, or in the export dialog; they come back to them here.
 */
const hasExportPresets = computed(() => page.props.hasExportPresets === true);

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
                <!-- LOGOUT FIRST, AND ALONE ABOVE THE RULE. Everything under it is somewhere to
                     GO — pages the reader is choosing between — and this is the one entry that
                     is not navigation at all: it ends the session. Putting it at the top with a
                     rule under it means it is never one of a run of similar-looking links, which
                     is how a menu gets a misclick that costs a queue and a sign-in. -->
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
                <li v-if="user" class="popover-list__divider" aria-hidden="true" />

                <!-- NO `prefetch` ON THE TWO GUEST FORMS below, per CLAUDE.md's prefetch rule: a
                     prefetch that lands after you have navigated to the same URL is applied to the
                     page you are on and re-creates it, which on a form discards what has been typed
                     and saves what the server sent. `/login` is the worst case in the app — it holds
                     a password, so `useRemember` is not an option there either (remembered state
                     goes into the history entry). Every authenticated link below keeps its warming:
                     each leads to a page a reader only reads. -->
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

                <!-- THE PLACES A READER GOES, in one run between two rules: their account, the
                     links they have sent, the devices they export for, and what they have
                     listened to. Three of the four are gated, so most accounts see a shorter
                     list than this markup suggests — which is the point of the gates rather
                     than an accident of them. -->
                <li v-if="user">
                    <Link class="popover-list-item" href="/dashboard" prefetch @click="closePopover">
                        <icon name="user-settings" :size="1" />
                        {{ t("header.userMenu.dashboard") }}
                    </Link>
                </li>
                <!-- Only for a reader who has actually shared something (the `hasShares` shared
                     prop): a menu entry leading to a list of nothing is a promise the page
                     cannot keep. -->
                <li v-if="user && hasShares">
                    <Link class="popover-list-item" href="/dashboard/shared" prefetch @click="closePopover">
                        <icon name="share" :size="1" />
                        {{ t("header.userMenu.shares") }}
                    </Link>
                </li>
                <!-- The reader's export presets, gated for the same reason: a shortcut to a page
                     that would only offer a "create one" button is not a shortcut. -->
                <li v-if="user && hasExportPresets">
                    <Link class="popover-list-item" href="/dashboard/export-presets" prefetch @click="closePopover">
                        <icon name="file_export" :size="1" />
                        {{ t("header.userMenu.presets") }}
                    </Link>
                </li>
                <!-- The listening history, last of the run and gated on `hasPlays` — a fresh
                     account would otherwise meet a page that can only say it has listened to
                     nothing before it ever meets the music. -->
                <li v-if="user && hasPlays">
                    <Link class="popover-list-item" href="/history" prefetch @click="closePopover">
                        <icon name="plays" :size="1" />
                        {{ t("header.userMenu.history") }}
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
