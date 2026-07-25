<script setup lang="ts">
/******************************************************************************
 * DataTableActions
 * The shared row-action popover for DataTable — ONE instance for the whole
 * table, repositioned per click (not one popover per row). When a row's
 * three-dot button is clicked, DataTable sets `row` + `triggerEl` and this opens
 * a native `popover` anchored to that button via CSS anchor positioning. Light
 * dismiss / Escape close it (emitting `close` so DataTable can return focus);
 * `defineExpose({ hide })` lets slot consumers dismiss it after an in-place
 * action. Reuses the app's global popover-content / popover-list classes; only
 * the anchor placement is scoped here.
 *****************************************************************************/
import { computed, ref, watch, nextTick, onBeforeUnmount } from "vue";
const props = defineProps<{
    /** The active row whose actions are shown, or null when the popover is closed. */
    row: unknown | null;
    /** The three-dot button element that triggered the popover, for CSS anchor positioning. */
    triggerEl: HTMLElement | null;
}>();
const emit = defineEmits<{
    /** Emitted when the popover closes (light dismiss, Escape, or action taken). */
    close: [];
}>();
/** Reference to the native popover element for programmatic show/hide. */
const popoverRef = ref<HTMLElement | null>(null);
/** The CSS anchor name to bind to, read off the trigger button's inline style. */
const anchorName = computed(() => {
    if (!props.triggerEl) return "";
    return props.triggerEl.style.getPropertyValue("anchor-name") || "";
});
/** Open the popover when a row becomes active (three-dot clicked in Body/Cards). */
watch(
    () => props.row,
    async newRow => {
        if (newRow && popoverRef.value) {
            await nextTick();
            popoverRef.value.showPopover();
        }
    }
);
/** Emit close when the native popover dismisses (light dismiss / outside click). */
function onToggle(event: Event) {
    const toggleEvent = event as ToggleEvent;
    if (toggleEvent.newState === "closed") {
        emit("close");
    }
}
/** Dismiss the popover on Escape (keyboard accessibility). */
function onKeydown(event: KeyboardEvent) {
    if (event.key === "Escape") {
        popoverRef.value?.hidePopover();
    }
}
/** Clean up if the component unmounts while the popover is still open. */
onBeforeUnmount(() => {
    if (popoverRef.value?.matches(":popover-open")) {
        popoverRef.value.hidePopover();
    }
});
/**
 * Programmatic dismissal handle for slot consumers. Native `auto` popovers only
 * dismiss on outside click / Escape / another popover opening — so an action
 * that runs in-place (e.g. an Inertia delete with preserveScroll) leaves this
 * open. Consumers get this via the slot's `close` to dismiss before firing.
 */
defineExpose({
    hide: () => popoverRef.value?.hidePopover()
});
</script>

<template>
    <div
        ref="popoverRef"
        popover
        class="popover-content dt-actions"
        :style="{ 'position-anchor': anchorName }"
        :aria-label="$t('components.datatable.row_actions')"
        @toggle="onToggle"
        @keydown="onKeydown"
    >
        <ul class="popover-list">
            <slot />
        </ul>
    </div>
</template>

<style scoped lang="scss">
.dt-actions {
    /* Anchor to the trigger's bottom-right; the popover extends leftward to avoid right-edge overflow. */
    inset: anchor(bottom) anchor(right) auto auto;
}
</style>
