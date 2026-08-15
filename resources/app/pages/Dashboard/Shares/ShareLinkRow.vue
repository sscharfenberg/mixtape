<script setup lang="ts">
/******************************************************************************
 * ShareLinkRow
 * One share link in the reader's list at /dashboard/shared — what was sent, how long it lives,
 * and the two things a reader does with a link they have already made.
 *
 * A COMPONENT BECAUSE THE PAGE DRAWS TWO LISTS. The live links and the expired
 * ones are now separate `<ul>`s under separate headings, and a row is a row in both: the only
 * differences are the two this takes as props. Written inline it would have been the same fifty
 * lines of markup twice, which is the shape a list that drifts in one half has.
 *
 * THE ROW IS NOT A LINK ITSELF. There is nowhere for it to go: the subject's own page is a
 * detail page the reader can reach from Music anyway, and the SHARE url is a guest page they
 * would only be visiting to check their own work. What it carries instead is the two things a
 * reader came here to do — send the link to somebody else, or stop it working.
 *
 * THE KIND IS A PIP IN FRONT OF THE NAME, because a name alone does not say what was sent:
 * sharing an artist and sharing one of their albums produce rows that would otherwise look
 * identical, and the wrong one gets revoked. It was "(Album) OK Computer" in running text for
 * about an hour — a parenthesis is punctuation a reader has to parse, where a chip is a label
 * they skip past to the name.
 *
 * IT OWNS ITS OWN CLIPBOARD STATE, which is what the extraction bought. `useClipboard` hands
 * every consumer its own `copied` flag on purpose, and a flag per row is exactly the shape a
 * per-row acknowledgement needs. One page-level composable instead means tracking "which id was
 * copied last" beside a single shared flag, or putting a tick on every row at once.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { useClipboard } from "Composables/useClipboard";
import type { ShareRow } from "Types/shares";

const props = withDefaults(
    defineProps<{
        /** The link this row is about, as the server describes it. */
        share: ShareRow;
        /**
         * Whether the link has run out of days — i.e. which of the page's two lists this row is
         * in. It changes what the row says and drops the copy button; the flag is passed rather
         * than read off the row because the LIST is the server's answer to that question.
         */
        expired?: boolean;
        /**
         * When the link stops working, already formatted in the reader's locale, or "" when
         * there is nothing to print. Formatted by the page so one date formatter serves the
         * whole list rather than one being constructed per row.
         */
        expiry?: string;
    }>(),
    { expired: false, expiry: "" }
);

const emit = defineEmits<{
    /** Ask the page to confirm revoking this row — the dialog is the page's, so it is one dialog. */
    revoke: [share: ShareRow];
    /**
     * Ask the page to confirm re-activating this row. Only a dead row can raise it (the pip that
     * sends it is not drawn on a live one), and the server refuses a live link anyway.
     */
    renew: [share: ShareRow];
}>();

const { t } = useI18n();

const { copied, copy } = useClipboard();

/** The glyph for the subject — the one the app uses for that kind of thing everywhere else. */
const iconOf = (kind: ShareRow["kind"]): string =>
    ({ song: "song", album: "album", artist: "artist", playlist: "playlist", audiobook: "audiobook" })[kind];
</script>

<template>
    <li class="shares__row" :class="{ 'shares__row--expired': expired }">
        <span class="shares__subject">
            <!-- WHAT KIND OF THING WAS SENT, as the app's usual pip rather than as "(Album)" in
                 the running text, which is how this was first drawn: a parenthesis is
                 punctuation a reader has to parse, where a chip is a label they can skip past to
                 the name. Each kind wears its own glyph — the one the app uses for that thing
                 everywhere else — so the three are told apart before the words are read at all. -->
            <span class="shares__kind">
                <icon :name="iconOf(props.share.kind)" :size="1" />
                {{ t(`dashboard.shares.kind.${props.share.kind}`) }}
            </span>
            <span class="shares__name">{{ props.share.name }}</span>
        </span>

        <!-- The one fact about the LINK rather than about the music, in the same pip so the two
             read as a matched pair either side of the name. A live row prints the date; a dead
             one says so in words instead of printing a date that has quietly passed — the same
             date read two ways is exactly what a reader misreads.

             ON A DEAD ROW THE PIP IS A BUTTON, and it is the one
             control in this list that reads as a fact until you point at it. That is deliberate:
             the word a reader is looking for is "abgelaufen", so hanging the remedy off that word
             puts it exactly where they are already looking, rather than adding a fourth control
             to a row and asking them to work out which of them undoes the state. It is the same
             pip in the same place, pressable — which is also why the element changes rather than
             the wording.

             It opens the PAGE's dialog rather than acting: reviving a link is not the sort of
             thing to do on a stray click, and the sentence a reader needs ("the URL you already
             sent starts working again") does not fit on a chip. -->
        <button
            v-if="expired"
            type="button"
            class="shares__validity shares__renew"
            v-tooltip="t('dashboard.shares.renew.open')"
            :aria-label="t('dashboard.shares.renew.label', { name: props.share.name })"
            @click="emit('renew', props.share)"
        >
            <icon name="calendar" :size="1" />
            {{ t("dashboard.shares.expired") }}
        </button>

        <span v-else class="shares__validity">
            <icon name="calendar" :size="1" />
            <template v-if="expiry">{{ t("dashboard.shares.validUntil", { date: expiry }) }}</template>
        </span>

        <!-- THE TWO CONTROLS TRAVEL TOGETHER, in one flex item pinned to the trailing edge.
             Loose, they were laid out by whatever space the facts left — and when a long album
             name wrapped the row onto two lines, they went with it and landed wherever the
             second line happened to end. One item with an auto margin puts them at the right
             edge on whichever line they are, which is where a reader's hand goes looking.

             COPY IT AGAIN, which is the other half of what a list of links is for: the modal
             shows the URL once, at mint time, and a reader who wants to send the same album to a
             second person should not have to re-press "share" on a page they would first have to
             find.

             NOT OFFERED ON A DEAD LINK. Copying one means pasting a 404 into somebody's chat
             window believing you have sent them music, which is worse than having no button —
             the row already says why, and revoke is still there.

             The tick replaces the glyph for two seconds rather than sitting beside it, so the
             button keeps its width and the row does not shift under the pointer. -->
        <span class="shares__controls">
            <button
                v-if="!expired"
                type="button"
                class="shares__copy"
                v-tooltip="t('dashboard.shares.copy.open')"
                :aria-label="t('dashboard.shares.copy.label', { name: props.share.name })"
                @click="copy(props.share.url)"
            >
                <icon :name="copied ? 'check' : 'copy'" :size="1" />
            </button>

            <!-- Icon only, with the subject in its accessible name: a column of identical
                 "revoke" labels tells a screen-reader user which row they are on only by
                 counting, and this is the one control in the app that breaks something already
                 in somebody else's hands. -->
            <button
                type="button"
                class="shares__revoke"
                v-tooltip="t('dashboard.shares.revoke.open')"
                :aria-label="t('dashboard.shares.revoke.label', { name: props.share.name })"
                @click="emit('revoke', props.share)"
            >
                <icon name="delete" :size="1" />
            </button>
        </span>
    </li>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* ONE LINK. Subject, validity, controls — the subject takes the slack, so the buttons line up
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

/* THE DEAD ROW'S PIP, WHICH IS A BUTTON — and it wears the
   REVOKE BUTTON'S BOX rather than the pip's: the same fill at rest, the same corners and the same
   height, so a dead row reads as two matched buttons with the subject between them rather than one
   button beside a chip that happens to be pressable.

   `control-padding` / `control-background` are the same two tokens `.shares__revoke` below reads —
   named after the control rather than copied from it, so retuning that button retunes this one. The
   INK needs no line here: the pip's `surface-muted` and the control's `control` are deliberately
   the same colour (see c.$c-shares), so there is nothing to keep in step.

   `line-height: 1` IS WHAT MAKES THE HEIGHTS EQUAL, and it is the whole trick. The revoke button is
   an inline-flex box holding one 16px glyph, so its height is that glyph plus its padding. This one
   holds a glyph AND a word, and a 0.9rem word's default line box is ~18px — taller than the icon,
   so with identical padding it would still stand ~2px higher. At `line-height: 1` the text box is
   14.4px, the ICON governs again, and both buttons come out at exactly `16px + 2 ×
   control-padding`. It also survives a change to the icon scale, which a hard-coded height would
   not. Nothing is clipped: the descender in "abgelaufen" reaches past its own line box, and what it
   reaches into is the padding.

   The fill and the ink come up together on hover, exactly as the row's two icon buttons do — so
   "this word is the way back" is learned from behaviour the reader has already met on this page. */
.shares__renew {
    padding: map.get(s.$c-shares, "control-padding");
    border: 0;

    background-color: map.get(c.$c-shares, "control-background");
    border-radius: map.get(s.$c-shares, "control-radius");

    font: inherit;
    font-size: map.get(s.$c-shares, "font-size");
    line-height: 1;

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

/* THE COPY BUTTON — the revoke button's box exactly, fill included. Leaving it transparent is
   the tempting alternative, on the argument that two equally loud controls make a reader read
   both before pressing either; what a finished row shows is the opposite — three controls in
   three different weights (a transparent glyph, a filled glyph, a pressable word) read as three
   kinds of thing, and a reader has to work out which. Uniform surfaces say "these are the
   buttons" once, and the difference that matters is still there in the GLYPHS: a copy sheet, a
   bin, a word. */
.shares__copy {
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

/* IT CARRIES A FILL AT REST, which is where this row parts company with every other per-row
   control in the app — and it is the rule for all three of this row's controls, not for this
   one alone (see the copy button above).
   The queue's and the playlist's stay transparent until aimed at, because those rows hold
   several controls and a list of lit glyphs reads as a row of warnings. These are different:
   one of them is the only control in the app whose consequence lands in somebody else's hands,
   so they should look like buttons rather than glyphs resting on the row. The INK still starts
   quiet and comes up to the app's control neon when aimed at; only the surface is new.

   The three blocks stay separate rather than being one selector list, because they do not
   describe one thing: the copy button is drawn only on a live row, the renew button only on a
   dead one, and each carries a note about why it looks the way it does. */
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
</style>
