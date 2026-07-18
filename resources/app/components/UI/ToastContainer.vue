<script setup lang="ts">
/******************************************************************************
 * ToastContainer (ported from cantrip.me)
 * Teleports a fixed toast stack to <body> and renders useToast()'s active
 * list. Also bridges Inertia session flash messages into the toast queue:
 * HandleInertiaRequests shares { message, type, duration, nonce }, and we watch
 * the per-response `nonce` (not the flash object, whose reference can repeat
 * after Inertia's prop merge) so the watcher fires for every flash — including
 * two identical messages in a row. Login / logout flash a fast 3000ms toast
 * this way (duration comes from the flash payload).
 *
 * Styles live in @/styles/components/_toast.scss (the content is teleported to
 * <body>, so it is styled globally rather than scoped here).
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { watch } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { useToast } from "Composables/useToast";
import type { ToastType } from "Composables/useToast";

const { t } = useI18n();
const { activeToasts, addToast, removeToast } = useToast();
const page = usePage();

// { immediate: true } also catches a flash that arrives on the initial page load.
watch(
    () => page.props.flash?.nonce,
    nonce => {
        if (nonce === null || nonce === undefined) return;
        const flash = page.props.flash;
        if (!flash?.message) return;
        addToast(flash.message, (flash.type as ToastType) ?? "info", flash.duration ?? undefined);
    },
    { immediate: true }
);

/** Map a toast severity level to its sprite icon name. */
function iconName(type: ToastType): string {
    switch (type) {
        case "success":
            return "check";
        case "warning":
            return "warning";
        case "error":
            return "error";
        case "info":
        default:
            return "info";
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            class="toast-container"
            role="region"
            :aria-label="t('common.notifications')"
            aria-live="polite"
            aria-atomic="false"
        >
            <TransitionGroup name="toast">
                <div
                    v-for="toast in activeToasts"
                    :key="toast.id"
                    class="toast-container__item"
                    :class="`toast-container__item--${toast.type}`"
                    :style="toast.duration > 0 ? { '--toast-duration': `${toast.duration}ms` } : {}"
                    role="alert"
                >
                    <icon :name="iconName(toast.type)" />
                    <span>{{ toast.message }}</span>
                    <button class="toast-container__close" :aria-label="t('common.close')" @click="removeToast(toast.id)">
                        <icon name="close" :size="1" />
                    </button>
                    <div v-if="toast.duration > 0" class="toast-container__progress" />
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
