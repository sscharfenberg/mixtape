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
 * WHERE IT STANDS changed on 2026-08-11: it sat inside the hero's tinted ActionPanel, pushed
 * to its trailing edge, and now sits on the row BELOW that panel next to the share button.
 * The panel holds what a reader is most likely to press — play, queue, add to a playlist —
 * and these two are the pair that take the subject somewhere else entirely: onto a disk, or
 * to somebody without an account. Its trailing-edge margin went with the move, since there is
 * no longer a growing sibling to be pushed away from.
 *
 * No halo (see Button.vue's prop): it stands on the hero's own panel, and a neon pool
 * spilling across that reads as a smudge rather than as neon.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

defineProps<{
    /** Where the file comes from — a server-decided URL (`song.downloadUrl` / `album.downloadUrl`). */
    href: string;
    /**
     * Which kind of subject this is: decides the label, and so what the reader is promised.
     *
     * `audiobook` sends the same ZIP an album does, and still gets a key of its own — the
     * German label names the subject ("Album als ZIP laden"), so sharing the album's key
     * would print the wrong noun on a book page. Which is the reason there are separate keys
     * at all, one area over.
     */
    subject: "song" | "album" | "audiobook";
}>();

const { t } = useI18n();
</script>

<template>
    <a :href="href" download class="btn btn-default btn--no-halo download-button">
        <icon name="download" :size="1" />
        <span>{{ t(`music.download.${subject}`) }}</span>
    </a>
</template>
