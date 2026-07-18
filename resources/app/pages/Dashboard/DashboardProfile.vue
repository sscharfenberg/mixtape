<script setup lang="ts">
/******************************************************************************
 * DashboardProfile
 * The dashboard's "update profile" section (ported from cantrip.me's
 * Dashboard/DashboardProfile). PUT /user/profile-information (Fortify's own
 * ProfileInformationController, deferring to App\Actions\Fortify\
 * UpdateUserProfileInformation) — changing the email address on a verified
 * account revokes verification and sends a fresh confirmation link.
 *****************************************************************************/
import { Form, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";

withDefaults(
    defineProps<{
        /** Which edge the headline's glowing-border tab hugs. */
        align?: "left" | "right";
    }>(),
    {
        align: "left"
    }
);

const { t } = useI18n();

const user = usePage().props.auth.user;
const name = ref(user?.name ?? "");
const email = ref(user?.email ?? "");
</script>

<template>
    <headline :size="3" anchor-id="profileSection" glow :align="align">
        <icon name="mail" />
        {{ t("dashboard.profile.headline") }}
    </headline>

    <Form
        action="/user/profile-information"
        method="put"
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
                {{ t("dashboard.profile.intro") }}
            </template>
            <template #required>
                <i18n-t keypath="common.requiredFieldsHint" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
        </form-legend>

        <form-row
            for-id="name"
            :label="t('dashboard.profile.nameLabel')"
            :error="errors.name ?? ''"
            :invalid="invalid('name')"
            :validated="valid('name')"
            :validating="validating"
            addon-icon="register"
            :required="true"
        >
            <form-input
                id="name"
                v-model="name"
                type="text"
                name="name"
                autocomplete="username"
                maxlength="80"
                @change="validate('name')"
            />
        </form-row>

        <form-row
            for-id="email"
            :label="t('dashboard.profile.emailLabel')"
            :error="errors.email ?? ''"
            :invalid="invalid('email')"
            :validated="valid('email')"
            :validating="validating"
            addon-icon="mail"
            :required="true"
        >
            <form-input
                id="email"
                v-model="email"
                type="email"
                name="email"
                autocomplete="email"
                maxlength="255"
                @change="validate('email')"
            />
        </form-row>

        <form-row>
            <Button variant="default" type="submit" :disabled="processing">
                <icon name="save" :size="1" />
                <span>{{ processing ? t("dashboard.profile.submitting") : t("dashboard.profile.submit") }}</span>
            </Button>
        </form-row>
    </Form>
</template>
