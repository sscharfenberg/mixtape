<script setup lang="ts">
/******************************************************************************
 * ExportPresetPage
 * One export preset's four fields — the device's name, and the three answers that make an
 * .m3u play on it.
 *
 * ONE PAGE FOR BOTH DIRECTIONS: `/dashboard/export-presets/create` renders it with no preset
 * and posts, `/dashboard/export-presets/{id}/edit` renders it over an existing one and PUTs.
 * Both are Dashboard\ExportPresetController, which explains why the two halves are not split.
 * `preset` is what decides which it is, and the page asks nothing else — everything that
 * differs hangs off that single computed flag, so there is no second source of truth about
 * which mode the form is in. The same shape PlaylistMetadataPage has.
 *
 * THE FORMAT AND ENCODING ROWS REUSE THE EXPORT DIALOG'S OWN WORDS (`playlists.export.*`), and
 * that is deliberate rather than lazy: these are the same two questions that dialog asks, and a
 * second set of labels for one pair of options is how a reader ends up comparing a preset
 * against the dialog and wondering whether the two mean the same thing. The hints are the ones
 * that already name the real cases — the car head unit, the modern player.
 *
 * IT DOES NOT WARN ABOUT WINDOWS-1252 the way the export dialog does, and cannot: that warning
 * names the TRACKS whose paths the encoding would break, which is a fact about a playlist. A
 * preset is about a device and has no playlist in view. The dialog still warns at the moment
 * the file is actually made, which is the moment the reader can still choose otherwise.
 *
 * `is_default` IS NOT A FIELD HERE. Which preset the dialog opens on is one press from the
 * list, so a save cannot move it by accident (Dashboard\ExportPresetController says more).
 *****************************************************************************/
import { Form, Head, Link, useRemember } from "@inertiajs/vue3";
import type { Ref } from "vue";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { ExportEncoding, ExportFormat } from "Types/exportPresets";

const props = defineProps<{
    /**
     * The preset being edited, or null when creating one. Its `id` builds the action; the four
     * values seed the fields.
     */
    preset: { id: string; name: string; format: ExportFormat; encoding: ExportEncoding; pathPrefix: string } | null;
    /**
     * What the prefix field starts from when creating — the server's configured default, the
     * same value the export dialog falls back to for a reader with no presets. So a first
     * preset begins where the dialog they came from already was, rather than at an empty field.
     */
    fallbackPrefix: string;
}>();

const { t } = useI18n();

/** Which direction the form is running in. Everything that differs reads this and nothing else. */
const isEdit = computed<boolean>(() => props.preset !== null);

/**
 * The page's own heading — also the document title and the last breadcrumb.
 *
 * NOT called `headline`: a setup binding of that name shadows the <headline> COMPONENT in the
 * template, so Vue resolves the tag to this string instead (the trap PlaylistMetadataPage
 * records, where ESLint caught it as an unused import).
 */
const formHeadline = computed<string>(() =>
    isEdit.value ? t("dashboard.presets.form.editHeadline") : t("dashboard.presets.form.createHeadline")
);

/**
 * What the submit button says, at rest and mid-flight.
 *
 * A computed pair rather than ternaries in the template: the label depends on TWO booleans
 * (which direction, and whether a request is in the air), and nesting those in the markup
 * produces something nobody can read at a glance. Both keys are literals so the build-time key
 * check (vue-tsc against de.json) still covers them.
 */
const submitLabel = computed<string>(() =>
    isEdit.value ? t("dashboard.presets.form.editSubmit") : t("dashboard.presets.form.createSubmit")
);
const submittingLabel = computed<string>(() =>
    isEdit.value ? t("dashboard.presets.form.editSubmitting") : t("dashboard.presets.form.createSubmitting")
);

/** The legend's opening note, which on an edit says when the change takes effect. */
const intro = computed<string>(() =>
    isEdit.value ? t("dashboard.presets.form.editIntro") : t("dashboard.presets.form.createIntro")
);

/**
 * Where the form posts, and how.
 *
 * A PUT for the edit rather than another POST: the same request twice leaves the same preset,
 * which is what PUT means and what makes a retried save harmless.
 */
const action = computed<string>(() =>
    isEdit.value ? `/dashboard/export-presets/${props.preset!.id}` : "/dashboard/export-presets"
);
const method = computed<"post" | "put">(() => (isEdit.value ? "put" : "post"));

const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "dashboard.page.title", icon: "user-settings", href: "/dashboard" },
    { labelKey: "dashboard.presets.title", icon: "file_export", href: "/dashboard/export-presets" },
    // The trail names the ACTION rather than the preset — on a create there is no preset to
    // name, and one crumb shape for both directions is what keeps the trail from jumping.
    { label: formHeadline.value, icon: isEdit.value ? "settings" : "playlist_add" }
]);

/**
 * The four values this form owns, as one object.
 *
 * A named type because {@link useRemember} is declared `Ref<T> | T` — it hands back the object
 * itself for a Form, and a ref for a plain one like this — so the two radio groups below, which
 * read the fields in SCRIPT rather than in the template, need the ref arm narrowed. The
 * template would not have cared: refs unwrap there either way.
 */
type PresetFields = { name: string; format: ExportFormat; encoding: ExportEncoding; pathPrefix: string };

/*
 * Seeded from the props, deliberately NOT kept in sync with them afterwards (a re-render must
 * not throw away what the reader has typed) — and REMEMBERED, for the reason
 * PlaylistMetadataPage documents in full: Inertia's Vue adapter re-keys the page component on
 * any swap that does not preserve state, `setup()` runs again, and `<Form>` serialises the DOM
 * at submit — so a form rebuilt underneath a reader saves the value the SERVER sent rather than
 * the one they typed. `useRemember` writes every change into the history entry, so the typed
 * values survive the page being re-created, and survive Back-then-Forward as the same
 * mechanism.
 *
 * NOTHING HERE IS A SECRET, which is what makes remembering safe: remembered state goes into
 * the history entry, so a password or a 2FA code could not use this.
 *
 * KEYED PER SUBJECT so the create form and each preset's edit form remember separately;
 * without that, opening one preset would offer you the half-finished name of another.
 */
const fields = useRemember<PresetFields>(
    {
        name: props.preset?.name ?? "",
        format: props.preset?.format ?? "simple",
        encoding: props.preset?.encoding ?? "UTF-8",
        // `??` rather than `||`: an existing preset's empty prefix is a CHOICE (the car case),
        // and `||` would silently replace it with the config default on every edit.
        pathPrefix: props.preset?.pathPrefix ?? props.fallbackPrefix
    },
    `export-preset-${props.preset?.id ?? "create"}`
) as Ref<PresetFields>;

/**
 * The format options, shaped the way RadioButtonGroup wants them.
 *
 * A `computed` rather than a plain array, for two reasons: `checked` has to follow the field
 * (the group is told which option is selected, it does not track that itself), and the labels
 * have to re-read on a locale switch. Each option carries its OWN icon, which is what the
 * export dialog does with the same pair.
 */
const formats = computed(() => [
    {
        value: "simple",
        label: t("playlists.export.formatSimple"),
        checked: fields.value.format === "simple",
        icon: "file",
        tooltip: t("playlists.export.formatSimpleHint")
    },
    {
        value: "extended",
        label: t("playlists.export.formatExtended"),
        checked: fields.value.format === "extended",
        icon: "info",
        tooltip: t("playlists.export.formatExtendedHint")
    }
]);

/**
 * The encoding options.
 *
 * Both icons sit on ONE AXIS — which alphabets fit — because that is the actual difference: the
 * globe is every script there is, `abc` is basic Latin and a little of Western Europe. The
 * export dialog's own reasoning, and the same two glyphs, so the choice reads identically in
 * both places.
 */
const encodings = computed(() => [
    {
        value: "UTF-8",
        label: t("playlists.export.encodingUtf8"),
        checked: fields.value.encoding === "UTF-8",
        icon: "language",
        tooltip: t("playlists.export.encodingUtf8Hint")
    },
    {
        value: "Windows-1252",
        label: t("playlists.export.encodingWindows"),
        checked: fields.value.encoding === "Windows-1252",
        icon: "abc",
        tooltip: t("playlists.export.encodingWindowsHint")
    }
]);
</script>

<template>
    <Head :title="formHeadline" />
    <headline glow>
        <icon :name="isEdit ? 'settings' : 'file_export'" :size="3" />
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
                :label="t('dashboard.presets.form.nameLabel')"
                :error="errors.name ?? ''"
                :invalid="invalid('name')"
                :validated="valid('name')"
                :validating="validating"
                addon-icon="file_export"
                :required="true"
            >
                <!-- maxlength matches the server's `max:60`, so the field cannot be overrun;
                     the rule stays because a client-side limit is a convenience, never the
                     check. Validated on blur through Precognition, which is what makes "you
                     already have one called that" arrive on leaving the field rather than after
                     submitting. -->
                <form-input
                    id="name"
                    v-model="fields.name"
                    type="text"
                    name="name"
                    autocomplete="off"
                    maxlength="60"
                    @change="validate('name')"
                />
                <template #text>{{ t("dashboard.presets.form.nameHint") }}</template>
            </form-row>

            <!-- Bare rows, no `addonIcon`: the icon belongs on each OPTION here, which is what
                 the export dialog does with the same two groups. An addon is sized for one
                 input and stretches into a tall box beside a stack of radios. -->
            <form-row :label="t('playlists.export.formatLabel')" :error="errors.format ?? ''">
                <radio-button-group
                    name="format"
                    :radio-buttons="formats"
                    @change="fields.format = ($event.target as HTMLInputElement).value as ExportFormat"
                />
            </form-row>

            <form-row :label="t('playlists.export.encodingLabel')" :error="errors.encoding ?? ''">
                <radio-button-group
                    name="encoding"
                    :radio-buttons="encodings"
                    @change="fields.encoding = ($event.target as HTMLInputElement).value as ExportEncoding"
                />
            </form-row>

            <!-- Free text rather than a picker: the path names a place on ANOTHER machine — a
                 Mac's mount point, a car's USB stick — so there is nothing here to browse. Not
                 `required`: an empty prefix is a real answer, and the one the car case needs. -->
            <form-row
                for-id="path_prefix"
                :label="t('playlists.export.prefixLabel')"
                :error="errors.path_prefix ?? ''"
                :invalid="invalid('path_prefix')"
                :validated="valid('path_prefix')"
                :validating="validating"
                addon-icon="path"
            >
                <form-input
                    id="path_prefix"
                    v-model="fields.pathPrefix"
                    type="text"
                    name="path_prefix"
                    autocomplete="off"
                    maxlength="255"
                    @change="validate('path_prefix')"
                />
                <template #text>{{ t("playlists.export.prefixHint") }}</template>
            </form-row>

            <form-row>
                <div class="export-preset__actions">
                    <Button variant="primary" type="submit" :disabled="processing">
                        <icon name="save" :size="1" />
                        <span>{{ processing ? submittingLabel : submitLabel }}</span>
                    </Button>
                    <Link href="/dashboard/export-presets" class="btn btn-default">
                        <icon name="close" :size="1" />
                        <span>{{ t("dashboard.presets.form.cancel") }}</span>
                    </Link>
                </div>
            </form-row>
        </Form>
    </container>
</template>

<style scoped lang="scss">
/* The submit sits beside its escape hatch, wrapping to a column on a narrow screen rather than
   letting two buttons squeeze. `1ch`/`1ex` gutters, the same character-relative units the form
   rows themselves space with — the playlist metadata form's actions row. */
.export-preset__actions {
    display: flex;

    align-items: center;
    flex-wrap: wrap;

    gap: 1ex 1ch;
}
</style>
