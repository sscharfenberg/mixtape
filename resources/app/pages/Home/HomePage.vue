<script setup lang="ts">
import { Head } from "@inertiajs/vue3";

defineProps<{
    greeting: string;
}>();
</script>

<template>
    <Head>
        <title>Hello</title>
    </Head>
    <main class="hello">
        <h1>{{ greeting }}</h1>
        <p>MixTape v2 — Inertia + Vue + TypeScript is up and running.</p>
    </main>
</template>

<style scoped lang="scss">
.hello {
    max-width: 40rem;
    padding: 0 1rem;
    margin: 4rem auto;

    text-align: center;

    h1 {
        margin-bottom: 0.5rem;

        font-size: 3rem;
    }
}
</style>
