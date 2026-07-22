<script setup lang="ts">
/******************************************************************************
 * ModalHeader
 * The title bar of a Modal: the heading (default slot) plus a close button
 * that emits `close` for the parent Modal to act on. Close label is localised.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

const { t } = useI18n();
</script>

<template>
    <div class="modal-dialog__header">
        <h3 class="modal-dialog__title"><slot /></h3>
        <button type="button" class="btn-close" @click="$emit('close')" :aria-label="t('common.close')" tabindex="-1">
            <span>{{ t("common.close") }}</span>
            <icon name="close" :size="1" />
        </button>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/typography" as t;

.modal-dialog {
    &__header {
        display: flex;
        position: relative;
        align-items: flex-start;

        padding: map.get(s.$c-modal, "padding");
        gap: 1rem;

        background-color: map.get(c.$c-modal, "title-background");

        color: map.get(c.$c-modal, "title-surface");
        border-start-start-radius: calc(#{map.get(s.$c-modal, "radius")} - #{map.get(s.$c-modal, "border")});
        border-start-end-radius: calc(#{map.get(s.$c-modal, "radius")} - #{map.get(s.$c-modal, "border")});
    }

    &__title {
        margin: 5px 0 0;

        font-family: t.$c-headline;
        font-size: 1.3rem;
        font-weight: 500;
        line-height: 1.4;
    }

    .btn-close {
        display: inline-flex;
        align-items: center;

        padding: 0.5ch 0.75ch;
        border: 0;
        margin-left: auto;
        gap: 0.5ch;

        background: transparent;
        color: inherit;
        border-radius: map.get(s.$c-modal, "radius");

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition: background-color ti.$c-modal linear;
        }

        &:hover,
        &:focus-visible {
            background-color: map.get(c.$c-modal, "close-background-hover");
        }

        > span {
            display: none;

            @include m.mq("portrait") {
                display: block;
            }
        }
    }
}
</style>
