<script setup lang="ts">
/******************************************************************************
 * LabelledLink
 * Ported from cantrip.me's UI/LabelledLink. A styled inline link: an Inertia
 * <Link> for internal paths, a plain <a> (new tab) for http(s) URLs, and a
 * plain <a> for mailto: links — each auto-picking a sensible leading icon
 * (external-link / mail) unless overridden.
 *****************************************************************************/
import type { RequestPayload } from "@inertiajs/core";
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";

const props = withDefaults(
    defineProps<{
        /** Target: an internal path (Inertia <Link>), an http(s) URL (new tab), or a mailto: link. */
        href: string;
        /** HTTP verb for the internal-<Link> case; ignored for external / mailto links. */
        method?: "get" | "post" | "put" | "patch" | "delete";
        /** Payload passed to the internal <Link> for non-GET requests. */
        data?: RequestPayload;
        /** Icon name. Defaults to "external-link" for https links, "mail" for mailto. Pass "" to suppress. */
        icon?: string;
    }>(),
    {
        method: "get"
    }
);

/** True for absolute http(s) URLs — rendered as a plain <a target="_blank">. */
const isExternal = computed(() => props.href.startsWith("https://") || props.href.startsWith("http://"));
/** True for mailto: links — rendered as a plain <a>. */
const isMailto = computed(() => props.href.startsWith("mailto:"));

/** Which leading icon to show: an explicit `icon` wins ("" suppresses it); otherwise auto-pick by link kind. */
const resolvedIcon = computed(() => {
    if (props.icon === "") return undefined;
    if (props.icon) return props.icon;
    if (isExternal.value) return "external-link";
    if (isMailto.value) return "mail";
    return undefined;
});
</script>

<template>
    <Link v-if="!isExternal && !isMailto" class="text-link" :href="href" :method="method" :data="data">
        <icon v-if="resolvedIcon" :name="resolvedIcon" :size="1" />
        <slot />
    </Link>
    <a v-else-if="isExternal" :href="href" target="_blank" rel="noopener nofollow" class="text-link">
        <icon v-if="resolvedIcon" :name="resolvedIcon" :size="1" />
        <slot />
    </a>
    <a v-else :href="href" class="text-link">
        <icon v-if="resolvedIcon" :name="resolvedIcon" :size="1" />
        <slot />
    </a>
</template>

<style scoped lang="scss">
/**
 * Colour / size / timing come from the contextual Abstracts tokens (c.$c-textlink
 * / s.$c-textlink / ti.$c-textlink).
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.text-link {
    color: map.get(c.$c-textlink, "surface");

    text-decoration-color: map.get(c.$c-textlink, "decoration");
    text-decoration-style: solid;
    text-decoration-thickness: map.get(s.$c-textlink, "underline-thickness");
    text-underline-offset: map.get(s.$c-textlink, "underline-offset");

    @media (prefers-reduced-motion: no-preference) {
        transition:
            color ti.$c-textlink linear,
            text-decoration-color ti.$c-textlink linear;
    }

    &:hover {
        color: map.get(c.$c-textlink, "surface-hover");

        text-decoration-color: map.get(c.$c-textlink, "decoration-hover");
    }

    > .icon {
        margin-right: 0.5ch;
    }
}
</style>
