<script setup lang="ts">
/******************************************************************************
 * PopOver
 * a trigger button + floating [popover] dialog built on the native HTML
 * Popover API and CSS anchor positioning. component styles live in
 * @/styles/components/popover; only the anchor custom properties are set here
 * (v-bind only resolves inside an SFC's scoped style).
 *****************************************************************************/
import { ref } from "vue";
import Icon from "Components/UI/Icon.vue";
const props = withDefaults(
    defineProps<{
        icon: string;
        label?: string;
        ariaLabel?: string;
        classString?: string;
        reference?: string;
        width?: string;
    }>(),
    {
        reference: () => Math.random().toString(36).substring(2),
        width: "25ch"
    }
);
const reference = ref("--" + props.reference);

/** Toggle the popover imperatively. `preventDefault` lets it work inside <a>/<Link>. */
function toggle(): void {
    const el = document.getElementById(props.reference);
    if (el) el.togglePopover();
}
</script>

<template>
    <div class="popover">
        <button
            :popovertarget="props.reference"
            :aria-label="ariaLabel || 'Open menu'"
            class="popover-button"
            :class="classString"
            @click.stop.prevent="toggle"
        >
            <icon :name="icon" />
            {{ label }}
        </button>
        <dialog :id="props.reference" popover class="popover-content">
            <slot />
        </dialog>
    </div>
</template>

<style lang="scss" scoped>
/**
 * styles are in @/styles/components/popover. the v-binds below are duplicated
 * here so the anchor-positioning custom properties resolve at runtime and the
 * setup vars aren't flagged as unused.
 */
.popover-button {
    anchor-name: v-bind(reference);
}

.popover-content {
    width: v-bind(width);

    position-anchor: v-bind(reference);
}
</style>
