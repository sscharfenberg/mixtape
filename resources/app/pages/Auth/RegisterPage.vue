<script setup lang="ts">
/******************************************************************************
 * RegisterPage
 * Invite-only account creation via Fortify. Reachable only through a valid
 * invite link (AuthController::registerView bounces bad/expired codes to /login
 * before this page renders). Submits name + email + password (+ confirmation)
 * and the invite `code` to POST /register; CreateNewUser validates + consumes
 * the invite, creates the user, logs them in, and Fortify redirects to
 * config('fortify.home') ('/dashboard'). Validation errors come back on their
 * fields; a stale/raced invite errors on `code`.
 *
 * Intentionally style-free (like LoginPage): it composes the shared components
 * (<headline> / .form / <form-row> / <form-input> / <Button>), so there are no
 * page-specific styles or tokens here. The `code` is carried in the useForm
 * data object and posted back automatically — no hidden <input> needed.
 *****************************************************************************/
import { Head, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormRow from "Components/Form/FormRow.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** Invite code from the registration link; posted back so the backend can
     *  re-validate and consume the one-time invite. */
    code: string;
}>();

const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const form = useForm({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
    code: props.code
});

/** POST the registration; clear the password fields whether it succeeds or fails. */
function submit(): void {
    form.post("/register", {
        preserveScroll: true,
        onFinish: () => form.reset("password", "password_confirmation")
    });
}
</script>

<template>
    <Head>
        <title>Registrieren</title>
    </Head>
    <headline glow>
        <icon name="register" :size="3" />
        Registrierung
    </headline>

    <form class="form" novalidate @submit.prevent="submit">
        <form-row
            for-id="name"
            label="Benutzername"
            :error="form.errors.name ?? ''"
            :invalid="!!form.errors.name"
            addon-icon="account"
            :required="true"
        >
            <form-input
                id="name"
                v-model="form.name"
                type="text"
                name="name"
                autocomplete="username"
                maxlength="80"
                autofocus
            />
        </form-row>

        <form-row
            for-id="email"
            label="E-Mail"
            :error="form.errors.email ?? ''"
            :invalid="!!form.errors.email"
            addon-icon="mail"
            :required="true"
        >
            <form-input
                id="email"
                v-model="form.email"
                type="email"
                name="email"
                autocomplete="email"
                maxlength="255"
            />
        </form-row>

        <form-row
            for-id="password"
            label="Passwort"
            :error="form.errors.password ?? ''"
            :invalid="!!form.errors.password"
            addon-icon="key"
            :required="true"
        >
            <form-input
                id="password"
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                autocomplete="new-password"
            />
            <template #button>
                <button
                    type="button"
                    tabindex="-1"
                    :aria-label="showPassword ? 'Passwort verbergen' : 'Passwort anzeigen'"
                    @mousedown.prevent
                    @click="showPassword = !showPassword"
                >
                    <icon :name="showPassword ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showPassword ? "Verbergen" : "Anzeigen" }}</span>
                </button>
            </template>
        </form-row>

        <form-row
            for-id="password_confirmation"
            label="Passwort bestätigen"
            :error="form.errors.password_confirmation ?? ''"
            :invalid="!!form.errors.password_confirmation"
            addon-icon="key"
            :required="true"
        >
            <form-input
                id="password_confirmation"
                v-model="form.password_confirmation"
                :type="showPasswordConfirmation ? 'text' : 'password'"
                name="password_confirmation"
                autocomplete="new-password"
            />
            <template #button>
                <button
                    type="button"
                    tabindex="-1"
                    :aria-label="showPasswordConfirmation ? 'Passwort verbergen' : 'Passwort anzeigen'"
                    @mousedown.prevent
                    @click="showPasswordConfirmation = !showPasswordConfirmation"
                >
                    <icon :name="showPasswordConfirmation ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showPasswordConfirmation ? "Verbergen" : "Anzeigen" }}</span>
                </button>
            </template>
        </form-row>

        <form-row>
            <Button variant="primary" type="submit" :disabled="form.processing">
                <icon name="register" :size="1" />
                <span>{{ form.processing ? "Wird registriert …" : "Registrieren" }}</span>
            </Button>
        </form-row>
    </form>
</template>
