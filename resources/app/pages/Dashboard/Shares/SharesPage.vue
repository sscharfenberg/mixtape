<script setup lang="ts">
/******************************************************************************
 * SharesPage
 * The reader's own share links, at /dashboard/shared (route `dashboard.shares`, behind auth,
 * rendered by Dashboard\SharesController) — and the only place a link can be revoked
 * (docs/sharing.md → "Revoking").
 *
 * THE PAGE THE SHARE MODAL HAS BEEN PROMISING since minting was built: it tells a reader they
 * can withdraw a link from their dashboard, and until now that meant deleting a row by hand.
 *
 * TWO LISTS UNDER TWO HEADINGS (the owner's call, 2026-08-13), the live links first and the
 * expired ones below their own right-aligned heading. It was one mixed list with the dead rows
 * marked, and the marking was carrying too much: what a reader wants off this page at a glance
 * is "what am I sharing right now", and that answer was a count they had to make themselves by
 * discounting rows. Splitting it also puts every copy button in the half where copying makes
 * sense. The order inside each half differs, and the server explains why.
 *
 * THE DEAD ONES ARE STILL LISTED, because a link that has died is still a thing the reader
 * made — a page that dropped them would read as links going missing, and they can still be
 * revoked, which is how a reader tidies up before the sweep takes them.
 *
 * REVOKING REMOVES A ROW, IT DOES NOT MOVE ONE DOWN. Revoke deletes the share (that is the
 * whole mechanism — every `/s/` route resolves through the row), so the redraw that follows
 * has one row fewer wherever it was, rather than a new entry in the expired half. The second
 * list is for links that ran out of days, not for links their owner withdrew.
 *
 * THE SECOND HEADING SITS OUTSIDE THE CONTAINER, like the first: a glowing-border heading has
 * to reach the window edge so its seam hides off-screen (see Container), which is also why the
 * expired half has a `<container>` of its own rather than the heading being moved inside one.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { ShareRow } from "Types/shares";
import { formatDateTime } from "Utils/formatting";
import RenewShareModal from "./RenewShareModal.vue";
import RevokeShareModal from "./RevokeShareModal.vue";
import ShareLinkRow from "./ShareLinkRow.vue";

const props = defineProps<{
    /** The links that still work, soonest to expire first. Empty is a real state. */
    shares: ShareRow[];
    /** The links that have run out of days, most recently dead first. Usually empty. */
    expiredShares: ShareRow[];
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "dashboard.page.title", icon: "user-settings", href: "/dashboard" },
    { labelKey: "dashboard.shares.title", icon: "share" }
]);

/** The row a reader has asked to revoke, or null while the dialog is shut. */
const revoking = ref<ShareRow | null>(null);

/**
 * The row a reader has asked to re-activate, or null while that dialog is shut.
 *
 * A SECOND REF RATHER THAN ONE WITH A MODE, because the two dialogs say opposite things and are
 * reached from opposite halves of the page: a single "acting on" ref would have to carry which
 * verb it meant, and the failure mode of getting that wrong is a reader reviving the link they
 * meant to revoke. Only one can be open at a time in practice — both are raised by a click, and
 * a modal takes the focus.
 */
const renewing = ref<ShareRow | null>(null);

/**
 * When each LIVE link stops working, in the reader's own locale and timezone.
 *
 * Computed as a map rather than called per row, so the date formatter is constructed once for
 * the list instead of once per row — and so an unparseable instant is dealt with in one place,
 * where it reads as an empty cell rather than a broken date.
 *
 * The expired half is deliberately absent: those rows say "expired" in words rather than
 * printing a date that has quietly passed, so there is nothing to format for them.
 */
const expiries = computed<Record<string, string>>(() =>
    Object.fromEntries(props.shares.map(share => [share.id, formatDateTime(share.validUntil, locale.value) ?? ""]))
);
</script>

<template>
    <Head :title="t('dashboard.shares.title')" />
    <!-- Outside the Container like every other page heading — the glowing border has to reach
         the window edge so its seam hides off-screen (see Container). -->
    <headline glow align="left">
        <icon name="share" :size="3" />
        {{ t("dashboard.shares.title") }}
    </headline>
    <container>
        <p class="shares__intro">{{ t("dashboard.shares.intro") }}</p>

        <!-- A LIST, semantically: a screen reader gets "list, N items" before the rows, which
             is the one thing a bare stack of <div>s would say worse. Both lists are labelled by
             their own heading's words, so the two are told apart when read out of context. -->
        <ul v-if="shares.length" class="shares shares--active" :aria-label="t('dashboard.shares.title')">
            <share-link-row
                v-for="share in shares"
                :key="share.id"
                :share="share"
                :expiry="expiries[share.id]"
                @revoke="revoking = $event"
            />
        </ul>

        <!-- Nothing live. Reachable in two ordinary ways now: the reader revoked their last
             link, or every link they made has run out — in which case the expired half below
             is where their links are, and this line is the honest answer to "what am I sharing
             right now". -->
        <p v-else class="shares__empty">{{ t("dashboard.shares.empty") }}</p>
    </container>

    <!-- THE DEAD HALF, drawn only when there is one: a heading standing over an empty list
         would tell most readers about a state they have never been in. Right-aligned, which is
         both what the app does with a run of headings down a page (the tabs alternate) and what
         says at a glance that this is the lesser half — the live links are the page. -->
    <template v-if="expiredShares.length">
        <!-- `share_off` — the page's own `share` glyph, crossed out (the owner's call,
             2026-08-13). It was `calendar`, which said "this section is about dates" where what
             the section is actually about is links that have stopped working. The icon is new,
             so a deploy needs `npm run icons` (the sprite is built, not committed). -->
        <headline glow align="right">
            <icon name="share_off" :size="3" />
            {{ t("dashboard.shares.expiredHeadline") }}
        </headline>
        <container>
            <p class="shares__intro">{{ t("dashboard.shares.expiredIntro") }}</p>

            <ul class="shares shares--expired" :aria-label="t('dashboard.shares.expiredHeadline')">
                <share-link-row
                    v-for="share in expiredShares"
                    :key="share.id"
                    :share="share"
                    expired
                    @revoke="revoking = $event"
                    @renew="renewing = $event"
                />
            </ul>
        </container>
    </template>

    <revoke-share-modal
        v-if="revoking"
        :id="revoking.id"
        :name="revoking.name"
        @close="revoking = null"
    />

    <!-- The other half of what a reader does with a dead row. Mounted only while open, like the
         revoke dialog and the dashboard's own modals: nothing about either survives closing. -->
    <renew-share-modal
        v-if="renewing"
        :id="renewing.id"
        :name="renewing.name"
        @close="renewing = null"
    />
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* THE PAGE'S TWO BLOCKS, each given air of its own (the owner's call, 2026-08-13). Reaching a
   child component's root from here works because Vue puts this page's scope id on it, and it is
   the narrowest way to say "on this page": Container is shared by every page in the app and
   holds no inset of its own.

   `margin-block`, NOT the `3rem 0` shorthand — Container centres itself with
   `margin-inline: auto`, and the shorthand would reset that and drop the whole page body
   against the left edge. */
.container {
    margin-block: map.get(s.$c-shares, "block-margin");
}

.shares__intro {
    margin: 0 0 map.get(s.$c-card, "gap");
}

/* A column of rows at every width — a list of links is read down, and the order is
   information (soonest to expire first in the live half, most recently dead first in the
   other), which a fluid column count would scramble. */
.shares {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-shares, "gap");

    list-style: none;
}

.shares__empty {
    margin: 0;
}
</style>
