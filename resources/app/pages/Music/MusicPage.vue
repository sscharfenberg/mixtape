<script setup lang="ts">
/******************************************************************************
 * MusicPage
 * The Music browse area, reached at /music (route `music`, behind auth) and
 * linked from the header site menu (useSiteAreas). Scaffold: a glowing headline
 * over a demo WidgetGroup (two Widgets) standing in for the real browse UI.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";

const { t } = useI18n();

const demoLoading = ref(false);
/** Demo only — briefly flash the WidgetLoader overlay so its loading state is visible. */
const runDemoLoad = (): void => {
    demoLoading.value = true;
    setTimeout(() => (demoLoading.value = false), 1500);
};
</script>

<template>
    <Head :title="t('header.siteMenu.music')" />
    <headline glow>
        <icon name="music" :size="3" />
        {{ t("header.siteMenu.music") }}
    </headline>

    <container>
        <widget-group>
            <widget>
                <template #title>
                    <icon name="music" :size="1" />
                    Recently added
                </template>
                <p>
                    Lorem ipsum dolor sit amet, consectetur adipisicing elit. Asperiores commodi dolor eum, labore
                    omnis pariatur quaerat quis veritatis? Animi aperiam consectetur deleniti facilis hic id ipsum.
                </p>
                <template #footer>
                    <labelled-link href="/music">Show all</labelled-link>
                </template>
            </widget>

            <widget :loading="demoLoading">
                <template #title>
                    <icon name="playlist" :size="1" />
                    Most played
                </template>
                <p>
                    Lorem ipsum dolor sit amet, consectetur adipisicing elit. Amet consequatur delectus dolorum eos in
                    magni obcaecati recusandae tempora, quaerat quis veritatis reiciendis.
                </p>
                <template #footer>
                    <Button variant="primary" type="button" @click="runDemoLoad">Simulate loading</Button>
                </template>
            </widget>
        </widget-group>
    </container>
</template>
