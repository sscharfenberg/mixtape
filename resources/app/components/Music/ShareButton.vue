<script setup lang="ts">
/******************************************************************************
 * ShareButton
 * "Send this to someone who has no account" — the third control in a Music hero's action
 * row, and the only one that talks to the server before it can show anything.
 *
 * TWO STEPS, DELIBERATELY IN THIS ORDER: press, then read. The click mints the share
 * (useShareLink) and only the answer opens the modal, so there is never a dialog standing
 * open with a spinner where a link should be. The button carries the waiting itself — its
 * glyph becomes the spinner — which is the honest place for it, since the press is what is
 * pending.
 *
 * WHY IT MUST BE THE SERVER'S ANSWER and not a URL assembled here from the id this
 * component already has: a share is a ROW. Its address, its expiry, and whether the reader
 * already has a live link for this subject are all decisions taken in ShareController — it
 * hands the same link back rather than minting a second — and a link built in the browser
 * would be a guess at a row that may not exist.
 *
 * NOT OFFERED FOR EVERY SUBJECT. `ShareableSubject` is genre-less by construction, so a hero
 * that cannot be shared cannot pass a subject that would be — the genre page renders no
 * ShareButton at all rather than one that fails on submit. A PLAYLIST can be shared since
 * 2026-08-13, and is the only subject whose id the server checks the OWNER of; the playlist
 * page only ever renders this for a playlist the reader owns (it cannot open somebody else's),
 * so that check is a backstop rather than something this button has to think about.
 *
 * `variant="default"` WHERE ITS TWO NEIGHBOURS ARE PRIMARY, which is the one visual decision
 * this file makes. The two looks are mirrors — primary rests LIT and dims to the outline on
 * hover, default rests as the glowing outline and lights up — so a default button beside two
 * primaries reads as the quieter third option without being a different kind of control. That
 * is what it is: play and enqueue are what a reader came to the page to do, and sharing is what
 * they do occasionally and deliberately. Three lit buttons would claim all three are equally
 * likely.
 *
 * No halo, like every other button in a hero (see Button.vue's prop): it stands on the
 * hero's own panel, and a neon pool spilling across that reads as a smudge.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import ShareModal from "Components/Music/ShareModal.vue";
import Icon from "Components/UI/Icon.vue";
import type { ShareableSubject } from "Composables/useShareLink";
import { useShareLink } from "Composables/useShareLink";

const props = defineProps<{
    /** Which kind of thing is being shared — decides nothing here but what the server is told. */
    subject: ShareableSubject;
    /** The subject's id, as the page's own prop carries it. */
    subjectId: string;
}>();

const { t } = useI18n();
const { minting, link, mint, dismiss } = useShareLink();

/** Ask the server for this subject's link; the modal opens on the answer, not on the click. */
async function share(): Promise<void> {
    await mint(props.subject, props.subjectId);
}
</script>

<template>
    <Button variant="default" no-halo class="share-button" :disabled="minting" @click="share">
        <!-- The spinner REPLACES the glyph rather than sitting beside it, so the button keeps
             its width while the row is minted — the same trick SubjectMenu's items use. -->
        <icon :name="minting ? 'refresh' : 'share'" :size="1" :rotate="minting" />
        <span>{{ t("music.share.button") }}</span>
    </Button>

    <share-modal v-if="link" :url="link.url" :valid-until="link.validUntil" @close="dismiss" />
</template>
