<script setup lang="ts">
/******************************************************************************
 * Modal
 * A native <dialog>-backed modal (ported from cantrip.me): Teleported to
 * <body>, opened via showModal() on mount, closed on Escape / backdrop click /
 * the header's close button, with a CSS enter/exit animation when motion is
 * allowed. Slots: #header (title), default (body), #footer (optional — the
 * body drops its bottom radius padding when there's no footer).
 *****************************************************************************/
import { onMounted, ref } from "vue";
import ModalBody from "./ModalBody.vue";
import ModalFooter from "./ModalFooter.vue";
import ModalHeader from "./ModalHeader.vue";

const emit = defineEmits<{ close: [] }>();
const modalRef = ref<HTMLDialogElement | null>(null);
const contentRef = ref<HTMLDivElement | null>(null);
const isClosing = ref(false);

/**
 * Open the native dialog via `showModal()`.
 *
 * Resets the local closing flag first so that any previous close animation
 * state does not leak into the next open cycle.
 */
const openModal = () => {
    const modal = modalRef.value;
    if (!modal) return;
    isClosing.value = false;
    modal.showModal();
};

/**
 * Close the dialog with an optional exit animation.
 *
 * If motion isn't explicitly allowed (reduced, or the preference is
 * unknown/unsupported) or the content node is unavailable, the dialog closes
 * immediately. Otherwise, it applies the `is-closing` state and waits for the
 * content animation to finish before calling `close()`.
 */
const closeModal = () => {
    const modal = modalRef.value;
    const content = contentRef.value;
    if (!modal?.open || isClosing.value) return;
    if (!window.matchMedia("(prefers-reduced-motion: no-preference)").matches || !content) {
        modal.close();
        emit("close");
        return;
    }
    isClosing.value = true;
    const handleAnimationEnd = (event: AnimationEvent) => {
        if (event.target !== content) return;
        isClosing.value = false;
        modal.close();
        emit("close");
    };
    content.addEventListener("animationend", handleAnimationEnd, { once: true });
};

/**
 * Intercept native cancel requests (for example Escape key presses) so the
 * modal can run its exit animation before the dialog is actually closed.
 *
 * @param event - Native dialog cancel event.
 */
const onDialogCancel = (event: Event) => {
    event.preventDefault();
    closeModal();
};

/**
 * Close the modal when users click outside the content area on the dialog
 * backdrop itself.
 *
 * @param event - Mouse click event emitted by the dialog element.
 */
const onDialogClick = (event: MouseEvent) => {
    if (event.target === event.currentTarget) {
        closeModal();
    }
};

/**
 * Open the dialog immediately after mount so rendering this component
 * behaves like "show this modal now" for consumers.
 */
onMounted(() => {
    openModal();
});
</script>

<template>
    <!--
        Teleport to <body> so the modal's DOM lives outside whatever parent
        rendered <Modal>. Without this, mounting the modal inside a clickable
        ancestor means a click on the close button could still be treated as a
        click "inside" that ancestor.
    -->
    <Teleport to="body">
        <dialog
            id="modal"
            ref="modalRef"
            class="modal-dialog"
            :class="{ 'is-closing': isClosing }"
            closedby="closerequest"
            @cancel="onDialogCancel"
            @click="onDialogClick"
        >
            <div ref="contentRef" class="modal-dialog__content">
                <modal-header @close="closeModal"><slot name="header" /></modal-header>
                <modal-body :has-footer="!!$slots.footer"><slot /></modal-body>
                <modal-footer v-if="$slots.footer"><slot name="footer" /></modal-footer>
            </div>
        </dialog>
    </Teleport>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.modal-dialog {
    position: fixed;

    overflow-y: hidden;
    width: 100dvw;
    height: 100dvh;
    max-height: 100dvh;
    padding: 0;
    border: 0;
    margin: 0 auto;

    background: transparent;
    outline: 0;

    &::backdrop {
        background: map.get(c.$c-modal, "backdrop");
        backdrop-filter: blur(10px);
    }

    &__content {
        display: flex;
        flex-direction: column;

        // Bounded by viewport (minus the vertical margin below) but free to
        // shrink for small content — the body flex-grows to fill whatever
        // space is available inside this cap.
        overflow: hidden;
        width: 100%;
        max-width: map.get(s.$c-modal, "max-width");
        max-height: calc(100dvh - 2rem);
        border: map.get(s.$c-modal, "border") solid map.get(c.$c-modal, "border");
        margin: 1rem auto;

        background-color: map.get(c.$c-modal, "background");
        color: map.get(c.$c-modal, "surface");
        border-radius: map.get(s.$c-modal, "radius");
    }

    @media (prefers-reduced-motion: no-preference) {
        &[open]::backdrop {
            animation: modal-backdrop-in ti.$c-modal ease-out forwards;
        }

        &[open] .modal-dialog__content {
            animation: modal-content-in ti.$c-modal ease-out forwards;
        }

        &.is-closing::backdrop {
            animation: modal-backdrop-out ti.$c-modal ease-in forwards;
        }

        &.is-closing .modal-dialog__content {
            animation: modal-content-out ti.$c-modal ease-in forwards;
        }
    }
}

@media (prefers-reduced-motion: no-preference) {
    @keyframes modal-content-in {
        from {
            opacity: 0;

            transform: translateY(-10rem);
        }

        to {
            opacity: 1;

            transform: translateY(0);
        }
    }

    @keyframes modal-content-out {
        from {
            opacity: 1;

            transform: translateY(0);
        }

        to {
            opacity: 0;

            transform: translateY(-10rem);
        }
    }

    @keyframes modal-backdrop-in {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes modal-backdrop-out {
        from {
            opacity: 1;
        }

        to {
            opacity: 0;
        }
    }
}
</style>
