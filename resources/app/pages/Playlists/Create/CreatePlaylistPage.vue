<script setup lang="ts">
/******************************************************************************
 * CreatePlaylistPage
 * The "new playlist" form (/playlists/create, route `playlists.create`, behind
 * auth), posting to POST /playlists — CreatePlaylistController::store, which
 * creates the playlist, flashes a toast and redirects back to the listing.
 *
 * Two fields, because a playlist owns exactly two things of its own: a name and a
 * blurb. It starts EMPTY on purpose — tracks are added later from wherever the
 * listener already is (a song page, an album), not picked out of a modal here.
 *
 * Both rows validate on blur through Precognition (the route carries
 * HandleControllerPrecognitiveRequest), which is what makes the name field worth
 * checking early: names are unique per owner, and "you already have one called
 * that" is far better heard on leaving the field than after submitting the form.
 *****************************************************************************/
import { Form, Head, Link } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import FormTextarea from "Components/Form/FormTextarea.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";

const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "header.siteMenu.playlists", href: "/playlists", icon: "playlist" },
    { labelKey: "playlists.create.headline", icon: "playlist" }
]);

const name = ref("");
const description = ref("");
</script>

<template>
    <Head :title="t('playlists.create.headline')" />
    <headline glow>
        <icon name="playlist" :size="3" />
        {{ t("playlists.create.headline") }}
    </headline>

    <container>
        <Form
            action="/playlists"
            method="post"
            class="form"
            #default="{ errors, valid, invalid, validating, validate, processing }"
        >
            <form-legend
                :items="[
                    { slot: 'intro', icon: 'info' },
                    { slot: 'required', icon: 'info' }
                ]"
            >
                <template #intro>
                    {{ t("playlists.create.intro") }}
                </template>
                <template #required>
                    <i18n-t keypath="common.requiredFieldsHint" scope="global">
                        <template #icon><icon name="required" /></template>
                    </i18n-t>
                </template>
            </form-legend>

            <form-row
                for-id="name"
                :label="t('playlists.create.nameLabel')"
                :error="errors.name ?? ''"
                :invalid="invalid('name')"
                :validated="valid('name')"
                :validating="validating"
                addon-icon="playlist"
                :required="true"
            >
                <form-input
                    id="name"
                    v-model="name"
                    type="text"
                    name="name"
                    autocomplete="off"
                    maxlength="255"
                    @change="validate('name')"
                />
                <template #text>{{ t("playlists.create.nameHint") }}</template>
            </form-row>

            <form-row
                for-id="description"
                :label="t('playlists.create.descriptionLabel')"
                :error="errors.description ?? ''"
                :invalid="invalid('description')"
                :validated="valid('description')"
                :validating="validating"
                addon-icon="info"
            >
                <!-- maxlength matches the server's `max:1000`, so the field simply
                     cannot be overrun; the rule stays because a client-side limit is a
                     convenience, never the check. -->
                <form-textarea
                    id="description"
                    v-model="description"
                    name="description"
                    rows="4"
                    maxlength="1000"
                    @change="validate('description')"
                />
                <template #text>{{ t("playlists.create.descriptionHint") }}</template>
            </form-row>

            <form-row>
                <div class="create-playlist__actions">
                    <Button variant="primary" type="submit" :disabled="processing">
                        <icon name="save" :size="1" />
                        <span>{{
                            processing ? t("playlists.create.submitting") : t("playlists.create.submit")
                        }}</span>
                    </Button>
                    <Link href="/playlists" class="btn btn-default">
                        <icon name="close" :size="1" />
                        <span>{{ t("playlists.create.cancel") }}</span>
                    </Link>
                </div>
            </form-row>
        </Form>
    </container>
</template>

<style scoped lang="scss">
/* The submit sits beside its escape hatch, wrapping to a column on a narrow screen
   rather than letting two buttons squeeze. `1ch`/`1ex` gutters, the same
   character-relative units the form rows themselves space with. */
.create-playlist__actions {
    display: flex;

    align-items: center;
    flex-wrap: wrap;

    gap: 1ex 1ch;
}
</style>
