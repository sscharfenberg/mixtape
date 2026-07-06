<script setup lang="ts">
import { Link } from "@inertiajs/vue3";

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;
</script>

<template>
    <Link href="/">
        <span>{{ appName }}</span>
        <span>{{ appName }}</span>
    </Link>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/mixins" as m;
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

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

        text-shadow:
            0 0 0.1em #8ba2d0,
            0 0 0.2em #000,
            0 0 5em #165ff3;
        -webkit-text-stroke: 0.06em rgb(black, 0.5);
    }

    span:last-child {
        position: absolute;
        top: 0;
        left: 0;

        background-image: linear-gradient(
            map.get(c.$c-title, "c1") 25%,
            map.get(c.$c-title, "c2") 35%,
            map.get(c.$c-title, "c3") 50%,
            map.get(c.$c-title, "c4") 50%,
            map.get(c.$c-title, "c5") 55%,
            map.get(c.$c-title, "c6") 75%
        );

        // background-image: linear-gradient(
        //    map.get(c.$c-title, "color1") 25%,
        //    map.get(c.$c-title, "color2") 35%,
        //    map.get(c.$c-title, "color3") 50%,
        //    map.get(c.$c-title, "color4") 65%,
        //    map.get(c.$c-title, "color5") 75%
        // );
        background-clip: text;
        -webkit-text-stroke: 0.01em #94a0b9;
        -webkit-text-fill-color: transparent;
    }
}
</style>
