<script setup lang="ts">
/******************************************************************************
 * NowPlayingPage
 * The Now Playing area, reached at /now-playing (route `now-playing`, behind auth)
 * and linked from the header site menu — which only offers it while the queue holds
 * something (useSiteAreas). Scaffold only: a glowing headline over placeholder copy,
 * pending the real view.
 *
 * The page it will become reads the player's own composables rather than props: the
 * queue and the loaded track live in the browser so playback survives Inertia
 * swapping pages, and a server payload would only be a second, staler copy.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";

const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "header.siteMenu.nowPlaying", icon: "now_playing" }]);
</script>

<template>
    <Head :title="t('header.siteMenu.nowPlaying')" />
    <headline glow>
        <icon name="now_playing" :size="3" />
        {{ t("header.siteMenu.nowPlaying") }}
    </headline>
    <container>
        <p>
            Lorem ipsum dolor sit amet, consectetur adipisicing elit. Accusantium aliquid, aperiam consequatur dolorum
            eaque eos exercitationem facere fugit inventore ipsa iste laboriosam nam neque nulla, quis repudiandae
            sapiente sit voluptas. Ab, accusamus atque cumque dolor eaque eveniet fuga fugiat harum, nam nulla numquam
            odio placeat quae quaerat quas ratione sint voluptate!
        </p>
    </container>
</template>
