<script setup lang="ts">
/******************************************************************************
 * OTPInput/Slots
 * The visual per-digit boxes rendered inside OTPInput's default slot (ported
 * from cantrip.me). vue-input-otp hands each slot its current char and an
 * `isActive` flag; this just paints them. The actual value lives in the
 * library's hidden native input (see OTPInput.vue).
 *****************************************************************************/
import type { SlotProps } from "vue-input-otp";

defineProps<{
    slots: SlotProps[];
    inputmode: "numeric" | "text";
}>();
</script>

<template>
    <div v-for="(slot, index) in slots" :key="index" class="otp__char" :class="{ 'otp__char--active': slot?.isActive }">
        {{ slot?.char }}
    </div>
</template>
