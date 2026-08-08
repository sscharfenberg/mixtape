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

    /* NO trailing exception for the play queue any more. This used to add a
       `--content-inset-end` published by FullLayout, so the page's trailing column stayed
       clear of a panel that stood permanently open from `landscape` up. The panel is an
       overlay at every width now (PlayQueue's banner says why the dashboard settled that),
       so there is nothing to clear and every page's box is symmetrical again. */
}
</style>
