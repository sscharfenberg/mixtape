<script lang="ts" setup>
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
const { t } = useI18n();
const startYear = 2026;
const currentYear = new Date().getFullYear();
let copyrightDate = `${startYear}`;
if (currentYear > startYear) {
    copyrightDate += " - " + currentYear;
}
const page = usePage();
const version = computed(() => page.props.version as string);

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;
</script>

<template>
    <footer>
        <span class="meta">{{ t("footer.copyright", { appName, date: copyrightDate, version }) }}</span>
    </footer>
</template>

