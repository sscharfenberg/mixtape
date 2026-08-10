<script setup lang="ts">
/******************************************************************************
 * PlaylistMetadataPage
 * A playlist's metadata — the two things it owns of its own, a name and a blurb.
 * ONE page for both directions: `/playlists/create` renders it with no playlist and
 * posts to `POST /playlists`, `/playlists/{id}/edit` renders it over an existing one
 * and PUTs to `/playlists/{id}`. Both are PlaylistMetadataController, which explains
 * why the two halves are not split.
 *
 * `playlist` is what decides which it is, and the page asks nothing else: a null prop
 * means creating. Everything that differs — the action, the method, the headline, the
 * intro, the submit label, the last breadcrumb — hangs off that single computed flag,
 * so there is no second source of truth about which mode the form is in.
 *
 * Two fields, because a playlist owns exactly two things of its own. Editing never
 * touches its tracks: they are added from wherever the listener already is (a song
 * page, an album), which is what the edit intro says out loud so nobody expects this
 * form to manage contents.
 *
 * Both rows validate on blur through Precognition (both routes carry the framework's
 * HandlePrecognitiveRequests — see that choice in routes/web.php: these rules live in a
 * FormRequest, so its dispatchers validate the request class and stop before the action),
 * which is what makes the name field worth
 * checking early: names are unique per owner, and "you already have one called that"
 * is far better heard on leaving the field than after submitting. On an edit the
 * server ignores the row being edited, so re-saving without renaming is not a clash.
 *****************************************************************************/
import { Form, Head, Link, useRemember } from "@inertiajs/vue3";
import { computed } from "vue";
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

const props = defineProps<{
    /**
     * The playlist being edited, or null when creating one. Only the metadata travels —
     * the page has no business with the tracks, and the id is here to build the action.
     */
    playlist: { id: string; name: string; description: string | null } | null;
}>();

const { t } = useI18n();

/** Which direction the form is running in. Everything that differs reads this and nothing else. */
const isEdit = computed<boolean>(() => props.playlist !== null);

/**
 * The page's own heading — also the document title and the last breadcrumb.
 *
 * NOT called `headline`, which is what it was first: a setup binding of that name
 * shadows the <headline> COMPONENT in the template, so Vue resolved the tag to this
 * string instead. ESLint caught it as an unused import, which is the same bug wearing a
 * milder hat.
 */
const formHeadline = computed<string>(() =>
    isEdit.value ? t("playlists.form.editHeadline") : t("playlists.form.createHeadline")
);

/**
 * What the submit button says, at rest and mid-flight.
 *
 * A computed pair rather than ternaries in the template: the label depends on TWO
 * booleans (which direction, and whether a request is in the air), and nesting those in
 * the markup produced something nobody could read at a glance. Both keys are literals so
 * the build-time key check (vue-tsc against de.json) still covers them.
 */
const submitLabel = computed<string>(() =>
    isEdit.value ? t("playlists.form.editSubmit") : t("playlists.form.createSubmit")
);
const submittingLabel = computed<string>(() =>
    isEdit.value ? t("playlists.form.editSubmitting") : t("playlists.form.createSubmitting")
);

/** The legend's opening note, which on an edit says the tracks are left alone. */
const intro = computed<string>(() =>
    isEdit.value ? t("playlists.form.editIntro") : t("playlists.form.createIntro")
);

/**
 * Where the form posts, and how.
 *
 * A PUT for the edit rather than another POST: the same request twice leaves the same
 * playlist, which is what PUT means and what makes a retried save harmless.
 */
const action = computed<string>(() => (isEdit.value ? `/playlists/${props.playlist!.id}` : "/playlists"));
const method = computed<"post" | "put">(() => (isEdit.value ? "put" : "post"));

const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "header.siteMenu.playlists", href: "/playlists", icon: "playlist" },
    // The trail names the ACTION rather than the playlist, because the playlist's own page
    // does not exist yet — a crumb for it would have nowhere to point.
    { label: formHeadline.value, icon: isEdit.value ? "settings" : "playlist" }
]);

/*
 * Seeded from the prop, deliberately NOT kept in sync with it afterwards (a re-render must
 * not throw away what the reader has typed) — and REMEMBERED, which is the half that took a
 * flaky E2E and a trace to earn.
 *
 * WHAT WENT WRONG WITHOUT IT: about once in twenty saves, under load, the form submitted the
 * value it had been GIVEN rather than the one just typed. Measured at 10ms resolution — the
 * `<form>` ELEMENT was replaced twelve milliseconds after the field was filled, and the
 * replacement carried the server's props again:
 *
 *     t=613  form #2  "Erste Fassung."     ← the form we typed into
 *     t=618  form #2  "Zweite Fassung."    ← the reader's text
 *     t=630  form #3  "Erste Fassung."     ← a NEW element, seeded from the prop
 *
 * A replaced element means the page component was RE-CREATED: Inertia's Vue adapter re-keys it
 * (`key = Date.now()`) on any swap that does not preserve state, so `setup()` runs again and
 * these two refs go back to the prop. `<Form>` then reads the DOM at submit
 * (`new FormData(formElement)`), so what got saved was the old text. No request was made in
 * that window, and a late-arriving hover prefetch was ruled out by holding one back two seconds
 * on purpose — the field survived that. So the trigger is inside Inertia's response handling and
 * not something this page can prevent.
 *
 * `useRemember` makes that harmless: every change is written into the history entry
 * (`router.remember` → `replaceState`, which updates the in-memory `current` SYNCHRONOUSLY, so a
 * remount one tick later still reads it) and `router.restore` seeds from it. The typed text
 * survives the page being rebuilt underneath it — and, as the same mechanism, an accidental
 * Back-then-Forward.
 *
 * KEYED PER SUBJECT so the create form and each playlist's edit form remember separately;
 * without that, opening one playlist would offer you the half-finished name of another.
 *
 * ONE OBJECT rather than two remembered refs, because `useRemember` types its argument as an
 * object — which suits it anyway: the two fields are one form, they are restored together, and one
 * key is one decision.
 */
const fields = useRemember(
    {
        name: props.playlist?.name ?? "",
        description: props.playlist?.description ?? ""
    },
    `playlist-metadata-${props.playlist?.id ?? "create"}`
);
</script>

<template>
    <Head :title="formHeadline" />
    <headline glow>
        <icon :name="isEdit ? 'settings' : 'playlist'" :size="3" />
        {{ formHeadline }}
    </headline>

    <container>
        <Form
            :action="action"
            :method="method"
            class="form"
            #default="{ errors, valid, invalid, validating, validate, processing }"
        >
            <form-legend
                :items="[
                    { slot: 'intro', icon: 'info' },
                    { slot: 'required', icon: 'info' }
                ]"
            >
                <template #intro>{{ intro }}</template>
                <template #required>
                    <i18n-t keypath="common.requiredFieldsHint" scope="global">
                        <template #icon><icon name="required" /></template>
                    </i18n-t>
                </template>
            </form-legend>

            <form-row
                for-id="name"
                :label="t('playlists.form.nameLabel')"
                :error="errors.name ?? ''"
                :invalid="invalid('name')"
                :validated="valid('name')"
                :validating="validating"
                addon-icon="playlist"
                :required="true"
            >
                <form-input
                    id="name"
                    v-model="fields.name"
                    type="text"
                    name="name"
                    autocomplete="off"
                    maxlength="255"
                    @change="validate('name')"
                />
                <template #text>{{ t("playlists.form.nameHint") }}</template>
            </form-row>

            <form-row
                for-id="description"
                :label="t('playlists.form.descriptionLabel')"
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
                    v-model="fields.description"
                    name="description"
                    rows="4"
                    maxlength="1000"
                    @change="validate('description')"
                />
                <template #text>{{ t("playlists.form.descriptionHint") }}</template>
            </form-row>

            <form-row>
                <div class="playlist-metadata__actions">
                    <Button variant="primary" type="submit" :disabled="processing">
                        <icon name="save" :size="1" />
                        <span>{{ processing ? submittingLabel : submitLabel }}</span>
                    </Button>
                    <Link href="/playlists" class="btn btn-default">
                        <icon name="close" :size="1" />
                        <span>{{ t("playlists.form.cancel") }}</span>
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
.playlist-metadata__actions {
    display: flex;

    align-items: center;
    flex-wrap: wrap;

    gap: 1ex 1ch;
}
</style>
