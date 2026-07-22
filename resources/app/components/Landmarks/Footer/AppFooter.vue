<script lang="ts" setup>
/******************************************************************************
 * AppFooter
 * The site footer landmark: a single localised copyright / version line. The
 * app name and version are threaded in from the environment and shared Inertia
 * props, so the footer never hard-codes them.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
const { t } = useI18n();
// Copyright span: just the launch year (2026), widening to a "2026 - <current>"
// range once a later year rolls around — so it stays correct without edits.
const startYear = 2026;
const currentYear = new Date().getFullYear();
let copyrightDate = `${startYear}`;
if (currentYear > startYear) {
    copyrightDate += " - " + currentYear;
}
const page = usePage();
/** App version (shared via Inertia props) shown in the footer line. */
const version = computed(() => page.props.version as string);

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;
</script>

<template>
    <footer>
        <span class="meta">{{ t("footer.copyright", { appName, date: copyrightDate, version }) }}</span>
    </footer>
</template>

