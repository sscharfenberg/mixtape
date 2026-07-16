<script setup lang="ts">
/******************************************************************************
 * LinkGroup
 * Ported from cantrip.me's UI/LinkGroup. A <nav> landmark wrapping a set of
 * LabelledLinks (e.g. the auth pages' "having trouble?" links), so they're
 * grouped for assistive tech and laid out consistently (stacked on mobile,
 * a wrapping row from "portrait" up).
 *****************************************************************************/
defineProps<{
    /** Accessible name for the <nav> landmark. */
    label: string;
}>();
</script>

<template>
    <nav :aria-label="label"><slot /></nav>
</template>

<style scoped lang="scss">
@use "Abstracts/mixins" as m;

nav {
    display: flex;
    flex-flow: column wrap;

    gap: 1ch;

    @include m.mq("portrait") {
        align-items: center;
        flex-direction: row;

        gap: 1.5ch;
    }
}
</style>
