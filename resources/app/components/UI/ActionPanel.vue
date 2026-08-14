<script setup lang="ts">
/******************************************************************************
 * ActionPanel
 * The tinted box a detail page's hero puts its controls in — what the reader can DO with
 * the subject, held together as one offer. Goes in HeroSection's `#actions` slot; takes
 * whatever the page slots into it and only decides the box and the row.
 *
 * WHY A BOX AT ALL: the hero is already carrying a title, a cover and a row of fact
 * tiles, and a sentence with a select beside it reads as something left over from another
 * page unless something says the controls belong together. One step off the hero's own
 * fill, no border and no heading — enough to group them, not enough to compete with the
 * facts above.
 *
 * IT USED TO BE AddToPlaylist's OWN BOX, and moving it out is the whole point of this
 * component. Whatever stands beside those controls must appear whether or not
 * the reader has any playlists, which a box owned by AddToPlaylist could not manage — that
 * component renders NOTHING for a reader with none.
 *
 * WHAT IT HOLDS is "what a reader is most likely to press": play, queue, and add to a
 * playlist. NOT the download button, which sits on the row below beside share: those two are
 * the pair that take the subject somewhere else entirely.
 *
 * NOT the hero's `__actions` row itself, deliberately: the playlist page and Now Playing
 * both slot plain buttons and a link in there, untinted, and a box drawn by HeroSection
 * would give them one they never asked for. A page opts in by wrapping. That is also what
 * makes the row below it free: the panel is full-width, so anything a page slots in after it
 * wraps onto its own line with no wrapper of its own.
 *
 * ONE ROW, bottom-aligned. The blocks in it are different heights — "add to playlist" is
 * a sentence over a control row, the two verbs are single controls — and aligning their
 * MIDDLES would float the buttons in the middle of the taller block. On their baselines they
 * sit level with the controls they stand beside. It wraps, because at hero width on a phone
 * that row is the first thing to run out of room.
 *****************************************************************************/
</script>

<template>
    <div class="action-panel"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* Full width of the hero's action row rather than shrink-to-fit: a box hugging its content
   would sit in the middle of the panel with nothing explaining where it stops, and the
   sentence inside it would wrap to the width of a select. */
.action-panel {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;

    width: 100%;
    padding: map.get(s.$c-action-panel, "padding");

    gap: map.get(s.$c-action-panel, "gap");

    background-color: map.get(c.$c-action-panel, "background");
    border-radius: map.get(s.$c-action-panel, "radius");
}
</style>
