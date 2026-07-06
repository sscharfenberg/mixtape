<script setup lang="ts">
/******************************************************************************
 * Icon Component
 * renders an <svg> that references a <symbol> in the inlined sprite sheet.
 * source icons live in resources/app/assets/icons/*.svg; `npm run icons`
 * merges them into storage/app/public/sprite.svg, which app.blade.php inlines
 * into the page so `#name` resolves.
 *****************************************************************************/
import { computed } from "vue";
const props = defineProps({
    name: {
        type: String,
        required: true
    },
    size: {
        type: Number,
        default: 2,
        validator(value: number) {
            return [0, 1, 2, 3, 4, 5].includes(value);
        }
    },
    rotate: {
        type: Boolean,
        default: false
    },
    additionalClasses: {
        type: Array as () => string[],
        default: () => []
    }
});
const cssClasses = computed(() => {
    const sizeClasses = ["tiny", "small", "medium", "large", "xlarge", "max"];
    const classes = [...new Set(["icon", ...props.additionalClasses])];
    classes.push(sizeClasses[props.size]);
    classes.push(props.name);
    if (props.rotate) classes.push("rotate");
    return classes.join(" ");
});
</script>

<template>
    <svg :class="cssClasses">
        <use :xlink:href="`#${name}`"></use>
    </svg>
</template>

<style scoped lang="scss">
/**
 * sizing comes from the contextual s.$c-icon token (the global $icons scale).
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

.icon {
    display: inline-block;

    width: var(--icon-size);
    height: var(--icon-size);
    flex: 0 0 var(--icon-size);

    vertical-align: middle;

    fill: currentcolor;

    &.tiny {
        --icon-size: #{map.get(s.$c-icon, "tiny")};
    }

    &.small {
        --icon-size: #{map.get(s.$c-icon, "small")};
    }

    &.medium {
        --icon-size: #{map.get(s.$c-icon, "medium")};
    }

    &.large {
        --icon-size: #{map.get(s.$c-icon, "large")};
    }

    &.xlarge {
        --icon-size: #{map.get(s.$c-icon, "xlarge")};
    }

    &.max {
        --icon-size: #{map.get(s.$c-icon, "max")};
    }

    // continuously rotating icon (e.g. a spinner)
    &.rotate {
        @media (prefers-reduced-motion: no-preference) {
            // 1200ms == cantrip's $timings "slowish"; no timings token group yet.
            animation: icon-rotate 1200ms linear infinite;
        }
    }
}

@keyframes icon-rotate {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}
</style>
