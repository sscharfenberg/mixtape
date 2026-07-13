<script setup lang="ts">
/******************************************************************************
 * DashboardPage
 * The authenticated user's landing page — Fortify redirects here after login
 * (config/fortify.php → 'home' => '/dashboard'; route named 'dashboard', behind
 * the `auth` middleware). Stubbed for now (headline + placeholder copy); the
 * real dashboard — playlists, listen history, account settings, mirroring
 * cantrip.me's — lands later.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
</script>

<template>
    <Head>
        <title>Dashboard</title>
    </Head>
    <headline glow>
        <icon name="account" :size="3" />
        Dashboard
    </headline>

    <p>
        Lorem ipsum dolor sit amet, consectetur adipisicing elit. Accusamus aliquam aperiam
        consequuntur cum cumque dignissimos dolore doloremque ducimus eaque, eos, fugit id
        impedit ipsam iste laborum minus nam nemo nesciunt nihil odio officia perferendis quae
        quaerat quas qui quos ratione rem repellendus reprehenderit sapiente sequi suscipit
        tempora tempore vel voluptas voluptate. Ab accusantium ad, aut consequatur cupiditate
        dolorum eveniet ex fugiat id incidunt ipsum iure magni maxime necessitatibus nostrum
        odit officiis pariatur perferendis quia quibusdam quisquam quos repellat totam voluptas.
    </p>
</template>
