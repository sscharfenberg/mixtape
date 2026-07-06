<script setup lang="ts">
/******************************************************************************
 * Icon Component
 * renders an <svg> that references a <symbol> in the inlined sprite sheet.
 * source icons live in resources/app/assets/icons/*.svg; `npm run icons`
 * merges them into storage/app/public/sprite.svg, which app.blade.php inlines
 * into the page so `#name` resolves. icon styles: @/styles/components/_icon.scss.
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
