<script setup lang="ts">
/******************************************************************************
 * ShareModal
 * What the reader sees once the server has minted a share link: what handing it out
 * actually means, and the link itself in a field that copies on click.
 *
 * IT OPENS ONLY WITH A LINK IN HAND. There is no loading state here because there is no
 * half-answer to show — the button that opens this waits for the mint and renders nothing
 * until it lands (ShareButton), so this component is never on screen without a URL. That is
 * also why `url` is a required prop rather than a nullable one.
 *
 * WHY IT IS A MODAL AT ALL, rather than the link appearing beside the button: a share link
 * is a capability that travels. Anyone holding it can listen, forwarding it cannot be
 * prevented, and it works for seven days whether or not the person it was meant for opens
 * it. A reader is entitled to read that BEFORE they paste it into a chat window, and a
 * dialog is the one shape that makes them look at it once.
 *
 * COPY ON CLICK, not a button beside the field. The field holds a long URL that will not fit
 * — the one thing a reader is certain to try is clicking it — and a click that selects the
 * text is a click that has already committed to copying it. The field stays `readonly` so
 * the value cannot be edited into a link that does not exist, and keyboard users get the
 * same action from `focus`, since tabbing into a field is how they "click" into it.
 *
 * The date is formatted here, in the reader's own locale and timezone, from the raw
 * ISO-8601 instant the server sent — the split every page in this app uses
 * (Utils/formatting.ts).
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import { useClipboard } from "Composables/useClipboard";
import { formatDateTime } from "Utils/formatting";

const props = defineProps<{
    /** The link to hand out, as the server minted it. */
    url: string;
    /** When it stops working — a raw ISO-8601 instant, formatted below. */
    validUntil: string;
}>();

const emit = defineEmits<{ close: [] }>();

const { t, locale } = useI18n();
const { copied, copy } = useClipboard();

/** DOM id of the link field, so its label points at it. */
const FIELD_ID = "share-link";

/** When the link dies, in the reader's own locale and timezone. */
const expires = computed(() => formatDateTime(props.validUntil, locale.value) ?? "");

/**
 * Put the link on the clipboard and select it in the field.
 *
 * The selection is not decoration: on a browser or OS that refuses clipboard access —
 * `useClipboard` swallows that deliberately, since a denied permission is common on mobile —
 * the text is at least sitting there highlighted, ready for a manual copy. So the gesture
 * degrades to something rather than to nothing.
 */
async function copyLink(event: Event): Promise<void> {
    (event.target as HTMLInputElement).select();
    await copy(props.url);
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("music.share.header") }}</template>

        <!-- The three things a reader has to know before they paste this anywhere, in the
             order they matter: how long it lives, that they can end it early, and that
             whoever holds it can listen. The last one is the honest note — a bearer
             capability travels, and no design short of per-recipient accounts changes that,
             so the expiry is what bounds the spread rather than a permission. -->
        <form-legend
            :items="[
                { slot: 'expires', icon: 'calendar' },
                { slot: 'revoke', icon: 'security' },
                { slot: 'bearer', icon: 'info' }
            ]"
        >
            <!-- `<i18n-t>` rather than `t(…, { date })`, because the date has to be an ELEMENT
                 to be tinted, and a string interpolation cannot carry one. The sentence still
                 comes from the catalog whole, so every language keeps the date where its own
                 grammar puts it. Same call shape as the required-fields hint on the auth
                 forms; `scope="global"` because the catalog is the app's, not this file's. -->
            <template #expires>
                <i18n-t keypath="music.share.expires" scope="global">
                    <template #date>
                        <span class="share-modal__expires">{{ expires }}</span>
                    </template>
                </i18n-t>
            </template>
            <template #revoke>{{ t("music.share.revoke") }}</template>
            <template #bearer>{{ t("music.share.bearer") }}</template>
        </form-legend>

        <!-- A `.form` wrapper around a single row, and a <div> rather than a <form>: there is
             nothing to submit here — the row was already written when this opened — so an
             element that can be submitted would be one Enter away from a page reload. The
             class is for what it gives every other field in the app: the standing margin and
             the comfortable measure, so the field is not flush against the modal's body. -->
        <div class="form">
            <!-- `validated` is FormRow's check indicator, borrowed here to mean "copied" rather
                 than "valid": there is nothing to validate in a field the reader cannot edit,
                 and the tick is exactly the acknowledgement a copy wants. The hint below it
                 carries the words, and swaps from the invitation to the confirmation. -->
            <!-- `copy`, not `share`: an addon names what the FIELD does, and this one copies.
                 The share glyph is on the button that opened this, where it named the act. -->
            <form-row :for-id="FIELD_ID" :label="t('music.share.linkLabel')" addon-icon="copy" :validated="copied">
                <form-input
                    :id="FIELD_ID"
                    :model-value="url"
                    type="text"
                    name="share-link"
                    readonly
                    autocomplete="off"
                    spellcheck="false"
                    class="share-modal__link"
                    @click="copyLink"
                    @focus="copyLink"
                />
                <template #text>
                    <span :class="{ 'share-modal__copied': copied }">
                        <icon v-if="copied" name="check" :size="1" />
                        {{ copied ? t("music.share.copied") : t("music.share.copyHint") }}
                    </span>
                </template>
            </form-row>
        </div>
    </modal>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;

/* The standing `.form` margin, minus its bottom half — HERE ONLY. Everywhere else that margin
   separates the last row from whatever follows the form; in this modal nothing does, so it
   only pads the dialog's own bottom, which ModalBody already owns. The top half stays, since
   it is what holds the field off the legend above it.

   Unlayered on purpose: the global `.form` lives in `@layer components`, and an unlayered
   scoped rule outranks a layered one whatever the specificity — the same seam FormInput's
   banner documents from the other side. */
.form {
    margin-bottom: 0;
}

/* The URL is data to be read character by character — a reader checks the domain before
   sending it on — so it takes the monospaced face for the same reason a song's file path
   does on its facts card. `cursor: pointer` because the field ACTS on a click rather than
   taking a caret, which nothing else about a text input would suggest. */
.share-modal__link {
    font-family: map.get(t.$c-share-modal, "link");

    cursor: pointer;
}

/* The one word in the legend a reader comes back for: WHEN the link dies. Given a tint and a
   little breathing room so the eye finds it inside a sentence, without the badge treatment —
   this is prose, and a pill in the middle of it would break the line it is part of.

   `padding-inline` only, with a radius: vertical padding on an inline box does not push the
   lines apart, it overlaps them, so a tinted date in a legend item that wrapped would paint
   over the line above. The date keeps the legend's own ink; only the ground changes. */
.share-modal__expires {
    padding-inline: 0.4ch;

    background-color: map.get(c.$c-share-modal, "expires-background");
    border-radius: map.get(s.$c-share-modal, "expires-radius");
}

/* The confirmation, in the same ink as the row's own valid indicator, so the tick beside
   the field and the words under it read as one answer. Deliberately no transition: an
   acknowledgement that fades in has already made the reader wait to find out whether their
   click worked. */
.share-modal__copied {
    display: inline-flex;
    align-items: center;

    gap: 0.5ch;

    color: map.get(c.$c-share-modal, "copied");
}
</style>
