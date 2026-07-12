<script setup lang="ts">
/******************************************************************************
 * LoginPage
 * Session login via Fortify. Submits name + password (+ remember) to POST
 * /login; on success Fortify redirects to config('fortify.home') ('/') and
 * Inertia follows it. Validation / failed-credential errors come back on the
 * `name` field (Fortify::username() === 'name'). Registration is invite-only
 * (no link here); the forgot-password link is gated on the resetPasswords
 * feature flag and stays hidden until that flow lands.
 *
 * Intentionally style-free: it composes the shared components
 * (<headline> / .form / <form-row> / <form-input> / <Button>), so there are
 * no page-specific styles or tokens here.
 *****************************************************************************/
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import FormInput from "Components/Form/FormInput.vue";
import FormRow from "Components/Form/FormRow.vue";
import Button from "Components/UI/Button.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";

defineProps<{
    /** Optional session status message (e.g. after a future password reset). */
    status?: string;
}>();

const page = usePage();
/** Backend feature flags — gates the guest-only "forgot password" link. */
const features = computed(() => page.props.features);
const showPassword = ref(false);

const form = useForm({
    name: "",
    password: "",
    remember: true
});

/** POST credentials; clear the password field whether the attempt succeeds or fails. */
function submit(): void {
    form.post("/login", {
        preserveScroll: true,
        onFinish: () => form.reset("password")
    });
}
</script>

<template>
    <Head>
        <title>Anmelden</title>
    </Head>
    <headline glow>
        <icon name="key" :size="3" />
        Anmelden
    </headline>

    <p v-if="status" role="status">{{ status }}</p>

    <form class="form" novalidate @submit.prevent="submit">
        <form-row
            for-id="name"
            label="Benutzername"
            :error="form.errors.name ?? ''"
            :invalid="!!form.errors.name"
            addon-icon="register"
            :required="true"
        >
            <form-input
                id="name"
                v-model="form.name"
                type="text"
                name="name"
                autocomplete="username"
                autofocus
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
                autocomplete="current-password"
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

        <label>
            <input v-model="form.remember" type="checkbox" name="remember" />
            Angemeldet bleiben
        </label>

        <form-row>
            <Button variant="primary" type="submit" :disabled="form.processing">
                <icon name="login" :size="1" />
                <span>{{ form.processing ? "Wird angemeldet …" : "Anmelden" }}</span>
            </Button>
        </form-row>

        <p v-if="features.resetPasswords">
            <Link href="/forgot">Probleme beim Anmelden?</Link>
        </p>
    </form>
</template>
