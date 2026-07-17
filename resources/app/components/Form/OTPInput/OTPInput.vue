<script setup lang="ts">
/******************************************************************************
 * OTPInput
 * A segmented one-time-code field (ported from cantrip.me), wrapping the
 * `vue-input-otp` package: the library keeps the real value in a single hidden
 * native input (so paste / autofill / password managers work) while we paint
 * the individual boxes via <Slots>. Used by the 2FA setup confirmation and the
 * login challenge. `v-model` is the code string; `@complete` fires once every
 * box is filled.
 *
 * inheritAttrs is off so parent attrs are forwarded explicitly onto the library
 * component via `v-bind="attrs"` (rather than landing on the wrapper root).
 *****************************************************************************/
import { nextTick, onMounted, ref, useAttrs } from "vue";
import { OTPInput as VueOTPInput } from "vue-input-otp";
import Slots from "./Slots.vue";

/** Prevents Vue from auto-applying parent attrs to the root element — they are forwarded explicitly via `v-bind="attrs"`. */
defineOptions({ inheritAttrs: false });
/** Two-way bound OTP string, updated as the user types into the individual slots. */
const model = defineModel<string>({ default: "" });
const attrs = useAttrs();
/** @emits complete — Fired with the full OTP value once all slots are filled. */
const emit = defineEmits<{
    complete: [value: string];
}>();
/** Template ref to the underlying VueOTPInput instance, used for autofocus fallback. */
const otpInputRef = ref<{ $el?: Element } | null>(null);
const props = withDefaults(
    defineProps<{
        /** HTML `id` attribute forwarded to the hidden input. */
        id?: string;
        /** Form field `name` for submission. */
        name?: string;
        /** Number of OTP digits/characters. */
        maxlength?: number;
        /** Virtual keyboard hint — `"numeric"` for digits, `"text"` for alphanumeric codes. */
        inputmode?: "numeric" | "text";
        /** Autocomplete hint for password managers (defaults to `"one-time-code"`). */
        autocomplete?: string;
        /** When true, the input is focused on mount. */
        autofocus?: boolean;
    }>(),
    {
        maxlength: 6,
        inputmode: "numeric",
        autocomplete: "one-time-code",
        autofocus: false
    }
);
/**
 * Auto-focuses the OTP input on mount when `autofocus` is true.
 * Tries multiple selectors as fallbacks because the underlying `vue-input-otp`
 * library renders a hidden native input whose DOM position varies.
 */
onMounted(async () => {
    if (!props.autofocus) return;
    await nextTick();
    const input =
        (props.id ? document.getElementById(props.id) : null)?.closest("input") ||
        document.querySelector<HTMLInputElement>(`input#${props.id ?? ""}`) ||
        otpInputRef.value?.$el?.querySelector<HTMLInputElement>("[data-input-otp]") ||
        document.querySelector<HTMLInputElement>("[data-input-otp]");
    input?.focus();
});
</script>

<template>
    <VueOTPInput
        :id="id"
        ref="otpInputRef"
        v-model="model"
        :name="name"
        :maxlength="maxlength"
        :inputmode="inputmode"
        :autocomplete="autocomplete"
        :autofocus="autofocus"
        container-class="otp"
        v-bind="attrs"
        @complete="emit('complete', $event)"
    >
        <template #default="{ slots }">
            <Slots :slots="slots" :inputmode="inputmode" />
        </template>
    </VueOTPInput>
</template>
