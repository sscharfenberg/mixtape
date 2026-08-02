<script setup lang="ts">
/******************************************************************************
 * Container
 * Caps content to the shared app "cage" width (s.$c-app "max") and centres it,
 * so page content lines up under the header's inner row. <main> stays full-
 * bleed on purpose — the glowing-border headings need to reach the window edge
 * so their seam hides off-screen — so wrap the page *body* in a Container to
 * rein it back in, leaving those headings outside it. The inline padding keeps
 * content off the screen edge on viewports narrower than the cage.
 *****************************************************************************/
</script>

<template>
    <div class="container"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

.container {
    max-width: map.get(s.$c-app, "max");
    margin-inline: auto;

    // Held in a custom property so the trailing side can be added to below.
    @include m.mqset(
        "--container-padding-inline",
        #{map.get(s.$c-app, "padding", "base")},
        #{map.get(s.$c-app, "padding", "portrait")},
        #{map.get(s.$c-app, "padding", "landscape")},
        #{map.get(s.$c-app, "padding", "desktop")}
    );

    padding-inline: var(--container-padding-inline);

    /* Extra trailing room for the play queue, which floats ABOVE the page rather
       than taking a column from it — so nothing else has to move, and <main> keeps
       reaching the window edge for the full-bleed headings to run off (see
       FullLayout). `--content-inset-end` is published there and is 0 unless the
       queue is on screen beside the content, which is why the header's own
       Container — outside that element — is untouched by this. */
    padding-inline-end: calc(var(--container-padding-inline) + var(--content-inset-end, 0px));
}
</style>
