<script setup lang="ts">
/******************************************************************************
 * WidgetFooter
 * The widget's bottom action strip. Renders whatever the consumer drops in the
 * slot (typically a "see all" link) and, when given a `refresh` key — the
 * Inertia prop name for the owning widget's data — a tooltip'd icon-only button
 * that partial-reloads just that prop. That re-runs the widget's controller query,
 * so `random` reshuffles and latest/popular are re-read, updating the card in
 * place without a full navigation. A lone control hugs the trailing edge; the
 * refresh button + a link spread to both edges (see the CSS). While the reload
 * is in flight the button's icon spins and it emits `refreshing` so the parent
 * Widget can swap its body for a skeleton.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
// The wrapper form, not `v-tooltip` on the button: the button disables itself
// while a refresh is in flight, and a disabled control emits no mouse events —
// the hint has to hang off an enabled element around it.
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";

const { t } = useI18n();

const props = defineProps<{
    /** Inertia prop key to partial-reload on refresh (e.g. "artists"); when set, the refresh button renders. */
    refresh?: string;
}>();

const emit = defineEmits<{
    /** In-flight state of the refresh reload, so the parent Widget can show its skeleton. */
    refreshing: [value: boolean];
}>();

/** True while a refresh request is in flight — disables the button and spins its icon. */
const refreshing = ref(false);

/** Set the in-flight flag locally (button state) and notify the parent (skeleton). */
const setRefreshing = (value: boolean): void => {
    refreshing.value = value;
    emit("refreshing", value);
};

/**
 * Partial-reload only this widget's prop (`only: [refresh]`) so the controller
 * re-runs just its query — reshuffling `random`, re-reading latest/popular — and
 * Inertia swaps the prop in place. `reload` already forces preserveScroll +
 * preserveState, so the page doesn't jump and the widget keeps its selected mode.
 */
const onRefresh = (): void => {
    if (!props.refresh) return;
    router.reload({
        only: [props.refresh],
        onStart: () => setRefreshing(true),
        onFinish: () => setRefreshing(false)
    });
};
</script>

<template>
    <div class="widget__footer">
        <tooltip v-if="refresh" :text="t('music.reloadServerData')">
            <button
                type="button"
                class="btn btn-default widget__refresh"
                :aria-label="t('music.refresh')"
                :disabled="refreshing"
                @click="onRefresh"
            >
                <icon name="refresh" :rotate="refreshing" />
            </button>
        </tooltip>
        <slot />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.widget__footer {
    display: flex;
    align-items: center;

    // A lone action hugs the trailing edge; a pair (or more) spreads to both
    // edges — consumers just drop controls in, no alignment class (mirrors ModalFooter).
    justify-content: flex-end;

    padding: map.get(s.$c-widget, "padding");
    border-top: map.get(s.$c-widget, "border") solid map.get(c.$c-widget, "footer-border");
    gap: 1ch;

    color: map.get(c.$c-widget, "footer-surface");

    &:has(> :nth-child(2)) {
        justify-content: space-between;
    }
}

// icon-only refresh button: square off the inherited horizontal padding so the
// icon sits centred rather than in a wide pill.
.widget__refresh {
    padding: 0.5rem;
    aspect-ratio: 1;
}
</style>
