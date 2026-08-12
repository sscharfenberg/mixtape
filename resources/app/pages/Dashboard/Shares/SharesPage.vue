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
 * ONE ROW PER LINK, AND THE ROW IS NOT A LINK ITSELF. There is nowhere for it to go: the
 * subject's own page is a detail page the reader can reach from Music anyway, and the SHARE
 * url is a guest page they would only be visiting to check their own work. What a row carries
 * instead is the two things a reader came here to do with a link they have already made —
 * send it to somebody else, or stop it working.
 *
 * EXPIRED LINKS ARE LISTED, marked rather than hidden. A link that has died is still a thing
 * the reader made — a list that quietly shrank would read as links going missing — and it can
 * still be revoked, which is how a reader tidies up until pruning exists. That is also why the
 * server sorts by expiry: the row nearest death is the one most likely being looked for.
 *
 * THE KIND IS A PIP IN FRONT OF THE NAME, because a name alone does not say what was sent:
 * sharing an artist and sharing one of their albums produce rows that would otherwise look
 * identical, and the wrong one gets revoked. It was "(Album) OK Computer" in running text for
 * about an hour — a parenthesis is punctuation a reader has to parse, where a chip is a label
 * they skip past to the name.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { useClipboard } from "Composables/useClipboard";
import { formatDateTime } from "Utils/formatting";
import RevokeShareModal from "./RevokeShareModal.vue";

/** One link, as the server describes it. Mirrors Dashboard\SharesController's row. */
export type ShareRow = {
    /** The share's UUID — its identity, and the id the DELETE names. */
    id: string;
    /** Which kind of thing it grants: `App\Enums\ShareSubject`, and nothing else. */
    kind: "song" | "album" | "artist";
    /** The subject's name, as data — printed, never translated. */
    name: string;
    /** The link itself, ABSOLUTE — it is copied into a chat window, not into an <a href>. */
    url: string;
    /** ISO-8601 instant, formatted here since the server knows neither locale nor timezone. */
    validUntil: string;
    /** True once `validUntil` has passed. The link still exists and can still be revoked. */
    expired: boolean;
};

const props = defineProps<{
    /** Every link this reader has minted, soonest to expire first. Empty is a real state. */
    shares: ShareRow[];
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "dashboard.page.title", icon: "user-settings", href: "/dashboard" },
    { labelKey: "dashboard.shares.title", icon: "share" }
]);

/** The row a reader has asked to revoke, or null while the dialog is shut. */
const revoking = ref<ShareRow | null>(null);

const { copied, copy } = useClipboard();

/**
 * Which row was copied last, or null.
 *
 * ONE COMPOSABLE, ONE FLAG, MANY BUTTONS — so the acknowledgement has to say *which*. The
 * composable's `copied` is a single boolean that resets itself after two seconds, which is
 * exactly the timing wanted and exactly the wrong shape for a list: used alone it would put a
 * tick on every row at once. So the id is tracked here and the composable is left owning the
 * clock, rather than a second timer being started beside it.
 */
const copiedId = ref<string | null>(null);

// The composable clears `copied` on its own; this follows it down so the tick and the flag
// cannot disagree — the alternative is a row stuck showing "copied" until the next copy.
watch(copied, isCopied => {
    if (!isCopied) copiedId.value = null;
});

/**
 * Put a link on the clipboard, and say so on the row it came from.
 *
 * The id is recorded BEFORE awaiting, so a slow clipboard cannot leave the tick behind the
 * reset — and it is cleared on failure, since a permission-blocked copy that still showed a
 * tick would be the worst of both.
 */
async function copyLink(share: ShareRow): Promise<void> {
    copiedId.value = share.id;
    await copy(share.url);
    if (!copied.value) copiedId.value = null;
}

/** The glyph for a row's subject — the one the app uses for that kind of thing everywhere else. */
const iconOf = (kind: ShareRow["kind"]): string => ({ song: "song", album: "album", artist: "artist" })[kind];

/**
 * When each link stops working, in the reader's own locale and timezone.
 *
 * Computed as a map rather than called per row in the template, so the date formatter is
 * constructed once for the list instead of once per row — and so an unparseable instant is
 * dealt with in one place, where it reads as an empty cell rather than a broken date.
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
             is the one thing a bare stack of <div>s would say worse. -->
        <ul v-if="shares.length" class="shares" :aria-label="t('dashboard.shares.title')">
            <li v-for="share in shares" :key="share.id" class="shares__row" :class="{ 'shares__row--expired': share.expired }">
                <span class="shares__subject">
                    <!-- WHAT KIND OF THING WAS SENT, as the app's usual pip rather than as
                         "(Album)" in the running text, which is how this was first drawn: a
                         parenthesis is punctuation a reader has to parse, where a chip is a
                         label they can skip past to the name. Each kind wears its own glyph —
                         the one the app uses for that thing everywhere else — so the three are
                         told apart before the words are read at all. -->
                    <span class="shares__kind">
                        <icon :name="iconOf(share.kind)" :size="1" />
                        {{ t(`dashboard.shares.kind.${share.kind}`) }}
                    </span>
                    <span class="shares__name">{{ share.name }}</span>
                </span>

                <!-- The one fact about the LINK rather than about the music, in the same pip so
                     the two read as a matched pair either side of the name. An expired row says
                     so in words instead of printing a date that has quietly passed — the same
                     date read two ways is exactly what a reader misreads. -->
                <span class="shares__validity">
                    <icon name="calendar" :size="1" />
                    <template v-if="share.expired">{{ t("dashboard.shares.expired") }}</template>
                    <template v-else-if="expiries[share.id]">
                        {{ t("dashboard.shares.validUntil", { date: expiries[share.id] }) }}
                    </template>
                </span>

                <!-- THE TWO CONTROLS TRAVEL TOGETHER, in one flex item pinned to the
                     trailing edge. Loose, they were laid out by whatever space the facts left
                     — and when a long album name wrapped the row onto two lines, they went
                     with it and landed wherever the second line happened to end. One item
                     with an auto margin puts them at the right edge on whichever line they
                     are, which is where a reader's hand goes looking.

                     COPY IT AGAIN, which is the other half of what a list of links is for:
                     the modal shows the URL once, at mint time, and a reader who wants to send
                     the same album to a second person should not have to re-press "share" on a
                     page they would first have to find.

                     NOT OFFERED ON A DEAD LINK. Copying one means pasting a 404 into somebody's
                     chat window believing you have sent them music, which is worse than having
                     no button — the row already says why, and revoke is still there.

                     The tick replaces the glyph for two seconds rather than sitting beside it,
                     so the button keeps its width and the row does not shift under the pointer. -->
                <span class="shares__controls">
                    <button
                        v-if="!share.expired"
                        type="button"
                        class="shares__copy"
                        v-tooltip="t('dashboard.shares.copy.open')"
                        :aria-label="t('dashboard.shares.copy.label', { name: share.name })"
                        @click="copyLink(share)"
                    >
                        <icon :name="copiedId === share.id ? 'check' : 'copy'" :size="1" />
                    </button>

                    <!-- Icon only, with the subject in its accessible name: a column of
                         identical "revoke" labels tells a screen-reader user which row they are
                         on only by counting, and this is the one control in the app that breaks
                         something already in somebody else's hands. -->
                    <button
                        type="button"
                        class="shares__revoke"
                        v-tooltip="t('dashboard.shares.revoke.open')"
                        :aria-label="t('dashboard.shares.revoke.label', { name: share.name })"
                        @click="revoking = share"
                    >
                        <icon name="delete" :size="1" />
                    </button>
                </span>
            </li>
        </ul>

        <!-- Reachable in one case only: the reader revoked their last link and is still on the
             page. Both entry points to it are drawn off the `shares` shared prop, so nobody
             arrives here with nothing to see. -->
        <p v-else class="shares__empty">{{ t("dashboard.shares.empty") }}</p>
    </container>

    <revoke-share-modal
        v-if="revoking"
        :id="revoking.id"
        :name="revoking.name"
        @close="revoking = null"
    />
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.shares__intro {
    margin: 0 0 map.get(s.$c-card, "gap");
}

/* A column of rows at every width — a list of links is read down, and the order is
   information (soonest to expire first), which a fluid column count would scramble. */
.shares {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-shares, "gap");

    list-style: none;
}

/* ONE LINK. Subject, validity, revoke — the subject takes the slack, so the buttons line up
   down the list however long the names run.

   It WRAPS on a phone rather than shrinking: the validity is a sentence, not a chip, and
   squeezing it beside a long album title would ellipsise the one fact the row is here for. */
.shares__row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    box-sizing: border-box;

    padding: map.get(s.$c-shares, "row-padding");
    border: map.get(s.$c-shares, "border") solid map.get(c.$c-shares, "border");
    gap: map.get(s.$c-shares, "row-gap");

    background-color: map.get(c.$c-shares, "background");
    border-radius: map.get(s.$c-shares, "radius");
}

/* A dead link is quieter, not hidden — see the banner. The ink drops rather than the row
   being greyed wholesale, so the revoke button keeps its contrast: tidying up is exactly
   what a reader comes to an expired row to do. */
.shares__row--expired {
    color: map.get(c.$c-shares, "surface-muted");
}

.shares__subject {
    display: flex;
    align-items: center;

    min-width: 0;
    flex: 1 1 auto;
    gap: 0.5ch;
}

/* THE TWO PIPS — the kind, and how long the link lives. One rule for both, because they are
   the same object doing the same job at opposite ends of the row: a small labelled fact the
   eye can skip over on its way to the name.

   `nowrap` on the pip, not on the row: a pip never breaks mid-fact, and it is the ROW that
   wraps on a narrow screen. */
.shares__kind,
.shares__validity {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-shares, "chip-padding");
    gap: map.get(s.$c-shares, "chip-gap");

    background-color: map.get(c.$c-shares, "chip");
    color: map.get(c.$c-shares, "surface-muted");
    border-radius: map.get(s.$c-shares, "chip-radius");

    font-size: map.get(s.$c-shares, "font-size");
    white-space: nowrap;
}

/* `min-width: 0` so a long album title ellipsises instead of pushing the row wider — the
   flex trap every list in this app documents once. */
.shares__name {
    overflow: hidden;

    min-width: 0;

    font-weight: 700;
    white-space: nowrap;
    text-overflow: ellipsis;
}

/* THE PAIR, PINNED RIGHT ON WHICHEVER LINE THEY END UP ON. `margin-inline-start: auto` on
   the GROUP rather than on the facts beside it, which is what makes that survive a wrap: the
   two buttons are one flex item, so they cannot be separated from each other, and the auto
   margin eats whatever slack is left on their own line. A long album name pushing the row onto
   two lines therefore moves them down, never left.

   No breakpoint on it, unlike the auto margin this replaces: "the controls sit at the trailing
   edge" is true at every width, and it was only ever conditional because it was attached to
   the validity, which a phone stacks. */
.shares__controls {
    display: inline-flex;
    align-items: center;

    margin-inline-start: auto;
    gap: map.get(s.$c-shares, "chip-gap");
}

/* THE COPY BUTTON — the revoke button's shape and target size, deliberately quieter: it sits
   beside it, and two equally loud controls on one row would make the reader read both before
   pressing either. Copying is also the harmless one of the pair. */
.shares__copy {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-shares, "control-padding");
    border: 0;

    background: none;
    color: map.get(c.$c-shares, "control");
    border-radius: map.get(s.$c-shares, "control-radius");

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-shares ease-out,
            color ti.$c-shares ease-out;
    }

    &:hover,
    &:focus-visible {
        background-color: map.get(c.$c-shares, "control-background-active");
        color: map.get(c.$c-shares, "control-active");
    }
}

/* IT CARRIES A FILL AT REST, which is where this parts company with every other per-row
   control in the app (the owner's call, 2026-08-12). The queue's and the playlist's stay
   transparent until aimed at, because those rows hold several controls and a list of lit
   glyphs reads as a row of warnings. This row holds exactly one — and it is the one control
   in the app whose consequence lands in somebody else's hands, so it should look like a
   button rather than like a glyph resting on the row. The INK still starts quiet and comes up
   to the app's control neon when aimed at; only the surface is new. */
.shares__revoke {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-shares, "control-padding");
    border: 0;

    background-color: map.get(c.$c-shares, "control-background");
    color: map.get(c.$c-shares, "control");
    border-radius: map.get(s.$c-shares, "control-radius");

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-shares ease-out,
            color ti.$c-shares ease-out;
    }

    &:hover,
    &:focus-visible {
        background-color: map.get(c.$c-shares, "control-background-active");
        color: map.get(c.$c-shares, "control-active");
    }
}

.shares__empty {
    margin: 0;
}
</style>
