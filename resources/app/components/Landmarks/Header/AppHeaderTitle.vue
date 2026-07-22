<script setup lang="ts">
/******************************************************************************
 * AppHeaderTitle
 * The wordmark <h1> in the header (an Inertia <Link> home). The app name is
 * printed in two stacked <span>s on purpose: they are the layers the scoped
 * style paints into the neon / gradient title effect. Name comes from
 * VITE_APP_NAME (mirrors the backend APP_NAME — see below).
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;
</script>

<template>
    <h1>
        <Link href="/">
            <span>{{ appName }}</span>
            <span>{{ appName }}</span>
        </Link>
    </h1>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/mixins" as m;
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

// semantic heading only — generates no box (`display: contents`) so the <a>
// stays the header flex item and the visual is unchanged; `font: inherit`
// stops the UA h1's bold/2em from inheriting through to the title text.
h1 {
    display: contents;

    font: inherit;
}

a {
    position: relative;

    margin: 0;
    transform: skew(-15deg);

    text-decoration: none;
    letter-spacing: 0.03em;

    @include m.mqset(
        "font-size",
        #{map.get(s.$c-title, "font-size", "base")},
        #{map.get(s.$c-title, "font-size", "portrait")},
        #{map.get(s.$c-title, "font-size", "landscape")},
        #{map.get(s.$c-title, "font-size", "desktop")}
    );

    &::after {
        position: absolute;
        top: -0.1em;
        right: 0.05em;

        width: 0.4em;
        height: 0.4em;

        --twinkle: #{map.get(c.$c-title, "twinkle")};

        background:
            radial-gradient(
                var(--twinkle) 3%,
                color-mix(in srgb, var(--twinkle) 30%, transparent) 15%,
                color-mix(in srgb, var(--twinkle) 5%, transparent) 60%,
                transparent 80%
            ),
            radial-gradient(color-mix(in srgb, var(--twinkle) 20%, transparent) 50%, transparent 60%) 50% 50% / 5% 100%,
            radial-gradient(color-mix(in srgb, var(--twinkle) 20%, transparent) 50%, transparent 60%) 50% 50% / 70% 5%;
        background-repeat: no-repeat;

        content: "";
    }

    span:first-child {
        display: block;

        @include m.mq("landscape") {
            text-shadow:
                0 0 0.1em map.get(c.$c-title, "c7"),
                0 0 0.2em #000,
                0 0 5em map.get(c.$c-title, "c8");
            -webkit-text-stroke: 0.06em rgb(black, 0.5);
        }
    }

    span:last-child {
        position: absolute;
        top: 0;
        left: 0;

        background-color: light-dark(map.get(c.$c-title, "c5"), map.get(c.$c-title, "c6"));
        background-clip: text;
        -webkit-text-stroke: 0.01em map.get(c.$c-title, "stroke");
        -webkit-text-fill-color: transparent;

        @include m.mq("landscape") {
            background-color: transparent;
            background-image: linear-gradient(
                map.get(c.$c-title, "c1") 25%,
                map.get(c.$c-title, "c2") 35%,
                #fff 50%,
                map.get(c.$c-title, "c4") 50%,
                map.get(c.$c-title, "c5") 55%,
                map.get(c.$c-title, "c6") 75%
            );
        }
    }
}
</style>
