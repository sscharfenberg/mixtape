<script setup lang="ts">
/******************************************************************************
 * RadioButtonGroup
 * Ported from cantrip.me's Form/Radio/RadioButtonGroup. Renders a list of
 * mutually-exclusive RadioButtons sharing one `name`, laid out as a column
 * (default) or a row.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import RadioButton from "Components/Form/Radio/RadioButton.vue";

const { t } = useI18n();

/** Describes a single radio option within the group. */
interface RadioButtonOption {
    /** Form value submitted when this option is selected. */
    value: string;
    /** Visible label text. */
    label: string;
    /** Whether this option is currently selected. */
    checked: boolean;
    /** Optional icon name rendered alongside the label. */
    icon?: string;
}

const props = withDefaults(
    defineProps<{
        /** Shared `name` attribute for all radio inputs in this group. */
        name: string;
        /** The list of radio options to render. */
        radioButtons: RadioButtonOption[];
        /** Visual layout direction — `"column"` stacks vertically, `"row"` lays out horizontally. */
        layout?: "column" | "row";
    }>(),
    { layout: "column" }
);

const emit = defineEmits<{
    change: [event: Event];
}>();

/** Forwards the native change event from any child RadioButton to the parent. */
function onChange(event: Event): void {
    emit("change", event);
}

/** BEM class list combining the base block with the layout modifier. */
const classList = computed(() => ["radio-group", `radio-group--${props.layout}`]);
</script>

<template>
    <ul role="list" :class="classList" :aria-label="t('common.availableOptions')">
        <li v-for="button in radioButtons" :key="button.value" class="radio-group__item">
            <radio-button
                :value="button.value"
                :name="name"
                :label="button.label"
                :checked="button.checked"
                :icon="button.icon"
                @change="onChange"
            />
        </li>
    </ul>
</template>

<style scoped lang="scss">
.radio-group {
    display: flex;
    flex-wrap: wrap;

    padding: 0;
    margin: 0;
    gap: 0.25lh;

    list-style-type: none;

    &--column {
        flex-grow: 1;
        flex-direction: column;
    }

    &--row {
        flex-direction: row;
    }
}
</style>
