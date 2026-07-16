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

const user = usePage().props.auth.user;
const name = ref(user?.name ?? "");
const email = ref(user?.email ?? "");
</script>

<template>
    <headline :size="3" anchor-id="profileSection" glow :align="align">
        <icon name="mail" />
        Profil aktualisieren
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
                Du kannst deine E-Mail-Adresse und/oder deinen Benutzernamen ändern. Wenn du die E-Mail-Adresse
                änderst, musst du die neue Adresse bestätigen — wir schicken dir einen Link zur Bestätigung.
            </template>
            <template #required>
                Felder, die mit einem <icon name="required" /> gekennzeichnet sind, müssen ausgefüllt werden.
            </template>
        </form-legend>

        <form-row
            for-id="name"
            label="Benutzername"
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
            label="E-Mail"
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
                <span>{{ processing ? "Wird geändert …" : "Ändern" }}</span>
            </Button>
        </form-row>
    </Form>
</template>
