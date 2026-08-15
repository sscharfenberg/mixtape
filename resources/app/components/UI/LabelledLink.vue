<script setup lang="ts">
/******************************************************************************
 * LabelledLink
 * Ported from cantrip.me's UI/LabelledLink. A styled inline link: an Inertia
 * <Link> for internal paths, a plain <a> (new tab) for http(s) URLs, and a
 * plain <a> for mailto: links — each auto-picking a sensible leading icon
 * (external-link / mail) unless overridden.
 *
 * It does NOT warm its target on hover unless asked to — see the `prefetch`
 * prop, which is the CALLER's decision rather than this component's, because
 * the question is what the link LEADS TO rather than what the link is.
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
        /**
         * Warm the target on hover (internal GET links only).
         *
         * OFF BY DEFAULT, AND THAT DEFAULT IS THE POINT. The tempting rule is
         * `:prefetch="method === 'get'"` — every GET link warmed — which decides from the LINK's
         * shape and never from what is at the other end. That is the wrong question: a prefetch
         * whose response lands after you have navigated to the same URL is applied to the page you
         * are now on, re-creating its component, and on a form that silently discards what the
         * reader has typed and saves the value the server sent (CLAUDE.md → the prefetch rule; it
         * cost one E2E run in five for weeks). Several of this component's links do point at a
         * form — `/forgot`, `/resend-verification` — and a link cannot tell from its own shape
         * that it does, so the caller who knows the target is the one that opts in.
         *
         * Turn it on where the target is a page a reader only reads: a listing, a detail page.
         */
        prefetch?: boolean;
    }>(),
    {
        method: "get",
        prefetch: false
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
    <Link
        v-if="!isExternal && !isMailto"
        class="text-link"
        :href="href"
        :method="method"
        :data="data"
        :prefetch="method === 'get' && prefetch"
    >
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
