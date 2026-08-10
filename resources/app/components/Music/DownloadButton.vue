<script setup lang="ts">
/******************************************************************************
 * DownloadButton
 * "Keep a copy of this" — the download control in a detail page's hero, next to the
 * "add to playlist" area. One song downloads as the mp3 itself; one album as a .zip of
 * the whole record, the non-audio files on the shelf beside it included.
 *
 * A REAL LINK, not a <Button> with a click handler, and not an Inertia <Link>. Inertia
 * would fetch the URL over XHR and try to read a page out of a stream of mp3 bytes; a
 * click handler assigning `location` would work but throws away everything a browser
 * already knows how to do with an <a>: middle-click, "save link as", copy the address,
 * and a download that survives the page being navigated away from. It wears the shared
 * global `.btn` classes, which exist precisely so any element can take the neon look
 * (see Button.vue / styles/components/_button.scss).
 *
 * `download` is set as a hint only. The server sends `Content-Disposition: attachment`
 * with the real filename, and that wins — which is what we want, since the server knows
 * the file's own name on disk and this component does not.
 *
 * THE LABEL SAYS WHAT ARRIVES ("Download MP3" / "Download ZIP") rather than a bare
 * "Download": a zip and a single file are different enough that a reader should not have
 * to click to find out which one this is. Two keys per language rather than one with the
 * kind interpolated, for the same reason AddToPlaylist has four — German declines the
 * article, and a template with the noun slotted in is wrong in half the cases.
 *
 * No halo (see Button.vue's prop): it stands in the hero's tinted ActionPanel, and a neon
 * pool spilling across that tint reads as a smudge rather than as neon.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

defineProps<{
    /** Where the file comes from — a server-decided URL (`song.downloadUrl` / `album.downloadUrl`). */
    href: string;
    /** Which kind of subject this is: decides the label, and so what the reader is promised. */
    subject: "song" | "album";
}>();

const { t } = useI18n();
</script>

<template>
    <a :href="href" download class="btn btn-default btn--no-halo download-button">
        <icon name="download" :size="1" />
        <span>{{ t(`music.download.${subject}`) }}</span>
    </a>
</template>

<style scoped lang="scss">
@use "Abstracts/mixins" as m;

/* Pushed to the trailing edge of the ActionPanel it stands in — but only from `desktop`
   (1024px) up.

   `margin-inline-start: auto` on the item rather than `justify-content` on the panel, because
   the panel holds whatever a page slots into it and should not be deciding where any one of
   them sits — and this still works when the panel holds nothing else, which is the case for a
   reader with no playlists. Logical property, so it follows the writing direction rather than
   pinning to the right.

   BELOW 1024 THERE IS NO AUTO MARGIN, which shows exactly when the panel WRAPS this button
   onto a line of its own: it then starts at the leading edge, lined up under the select above
   it, rather than being pushed to the far end of an otherwise empty line where it reads as
   detached from the block it belongs to. Measured on the song hero: it wraps at 820 and 600,
   and does not at 1023 — where it still finishes flush right anyway, because the
   "add to playlist" block beside it grows into the free space and there is none left for a
   margin to take. So this only ever moves the wrapped case, which is the narrow one. */
.download-button {
    @include m.mq("desktop") {
        margin-inline-start: auto;
    }
}
</style>
