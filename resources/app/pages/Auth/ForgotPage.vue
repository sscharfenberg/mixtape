<script setup lang="ts">
/******************************************************************************
 * ForgotPage
 * "Forgot password / username" (ported from cantrip.me's Auth/Forgot). One
 * form, one `type` radio toggle: `password` additionally asks for the
 * username and requests a Fortify password-reset link (App\Http\Controllers\
 * Auth\ForgotController::sendPasswordResetLink); `name` only needs the email
 * and requests a username-reminder mail. POST /forgot always redirects home
 * with the same success toast regardless of whether a matching account
 * exists, so the form can't be used to enumerate registered emails.
 *
 * Uses Inertia's <Form> with Precognition, matching RegisterPage: each field
 * validates server-side on blur (@change → validate(field)).
 *****************************************************************************/
import { Form, Head, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import LinkGroup from "Components/UI/LinkGroup.vue";

const { t } = useI18n();
const page = usePage();
/** Backend feature flags — gates the "resend verification" link. */
const features = computed(() => page.props.features);

/** The two recovery types the radio group can submit as `type`. */
const types = [
    { value: "password", label: t("auth.forgot.typePassword"), checked: true, icon: "key" },
    { value: "name", label: t("auth.forgot.typeName"), checked: false, icon: "account" }
];
const type = ref(types.find(option => option.checked)?.value ?? "password");

/** Track the selected recovery type so the username field can be toggled. */
function onTypeChange(event: Event): void {
    type.value = (event.target as HTMLInputElement).value;
}
</script>

<template>
    <Head>
        <title>{{ t("auth.forgot.pageTitle") }}</title>
    </Head>
    <headline glow>
        <icon name="support" :size="3" />
        {{ t("auth.forgot.title") }}
    </headline>

    <Form
        action="/forgot"
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
            <template #intro>{{ t("auth.forgot.introHint") }}</template>
            <template #required>
                <i18n-t keypath="common.requiredFieldsHint" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
        </form-legend>

        <form-row>
            <radio-button-group name="type" :radio-buttons="types" @change="onTypeChange" />
        </form-row>

        <form-row
            v-if="type === 'password'"
            for-id="name"
            :label="t('auth.forgot.nameLabel')"
            :error="errors.name ?? ''"
            :invalid="invalid('name')"
            :validated="valid('name')"
            :validating="validating"
            addon-icon="register"
            :required="true"
        >
            <form-input
                id="name"
                type="text"
                name="name"
                autocomplete="username"
                maxlength="80"
                @change="validate('name')"
            />
        </form-row>

        <form-row
            for-id="email"
            :label="t('auth.forgot.emailLabel')"
            :error="errors.email ?? ''"
            :invalid="invalid('email')"
            :validated="valid('email')"
            :validating="validating"
            addon-icon="mail"
            :required="true"
        >
            <form-input
                id="email"
                type="email"
                name="email"
                autocomplete="email"
                maxlength="255"
                @change="validate('email')"
            />
        </form-row>

        <form-row>
            <Button variant="primary" type="submit" :disabled="processing">
                <icon name="save" :size="1" />
                <span>{{ processing ? t("auth.forgot.submitting") : t("auth.forgot.submit") }}</span>
            </Button>
        </form-row>

        <form-row v-if="features.emailVerification" style="margin-top: 2rem">
            <link-group :label="t('auth.forgot.helpLinksLabel')">
                <labelled-link href="/resend-verification">{{ t("auth.forgot.resendLink") }}</labelled-link>
            </link-group>
        </form-row>
    </Form>
</template>
