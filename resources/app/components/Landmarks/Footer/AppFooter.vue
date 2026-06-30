<script lang="ts" setup>
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
const startYear = 2026;
const currentYear = new Date().getFullYear();
let copyrightDate = `${startYear}`;
if (currentYear > startYear) {
    copyrightDate += " - " + currentYear;
}
const page = usePage();
const version = computed(() => page.props.version as string);
</script>

<template>
    <footer>
        <span class="meta">&copy; MixTape contributors {{ copyrightDate }} · {{ version }}</span>
    </footer>
</template>

