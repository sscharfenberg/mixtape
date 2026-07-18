<script setup lang="ts">
/******************************************************************************
 * ResendVerificationPage
 * "Resend verification email" (ported from cantrip.me's Auth/ResendVerification).
 * For a user whose signed verification link expired — they can't log in to
 * trigger a fresh one (login is blocked until verified), so this page asks for
 * name + email and dispatches App\Http\Controllers\Auth\ResendVerification-
 * Controller::store. POST /resend-verification always redirects home with the
 * same success toast regardless of whether a matching, unverified account
 * exists, so the form can't be used to enumerate registered emails.
 *
 * Uses Inertia's <Form> with Precognition, matching ForgotPage: each field
 * validates server-side on blur (@change → validate(field)).
 *****************************************************************************/
import { Form, Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";

const { t } = useI18n();
</script>

<template>
    <Head>
        <title>{{ t("auth.resend.pageTitle") }}</title>
    </Head>
    <headline glow>
        <icon name="mail" :size="3" />
        {{ t("auth.resend.title") }}
    </headline>

    <Form
        action="/resend-verification"
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
            <template #intro>{{ t("auth.resend.introHint") }}</template>
            <template #required>
                <i18n-t keypath="common.requiredFieldsHint" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
        </form-legend>

        <form-row
            for-id="name"
            :label="t('auth.resend.nameLabel')"
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
            :label="t('auth.resend.emailLabel')"
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
                <span>{{ processing ? t("auth.resend.submitting") : t("auth.resend.submit") }}</span>
            </Button>
        </form-row>
    </Form>
</template>
