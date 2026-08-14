<script lang="ts" setup>
/******************************************************************************
 * AppFooter
 * The site footer landmark: the copyright / version line, and a link to the source.
 *
 * DRESSED AS THE PLAYER BAR'S QUIET TWIN. The two are
 * ALTERNATIVES in FullLayout — once a track is loaded the bar takes this element's
 * place — so a reader should meet the same shelf at the bottom of the window either
 * way, with only its contents changing. Hence the same fill and the same top edge, from the
 * same globals. What is deliberately NOT shared: the frosting (a footer in flow has nothing
 * to blur) and the block padding — the bar is dense
 * because it is full of controls, and one line of text at that number reads cramped.
 *
 * A CONTAINER INSIDE IT, not around it: the fill and the top edge have to reach both
 * window edges, while the text should line up with the page content above it. The
 * <footer> spans and the Container centres — the same split every full-bleed band in
 * this app makes.
 *
 * THE VERSION COMES FROM package.json, threaded through `config('app.version')` and
 * shared by HandleInertiaRequests. This component has ALWAYS read `props.version`;
 * nothing ever shared it, so an `as string` cast quietly rendered `undefined` in the
 * copyright line until the prop was added. It is nullable now and the line drops the
 * separator rather than printing a hole.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";

const { t } = useI18n();
const page = usePage();

/**
 * Where the source lives. A constant rather than a prop or a config value: it is a fact
 * about this project that changes when the project moves, which is never in a way the
 * server needs to know about.
 */
const REPOSITORY = "https://github.com/sscharfenberg/mixtape";

/**
 * The copyright span: just the launch year, widening to "2026 - <current>" once a later
 * year rolls around — so it stays correct without edits.
 */
const copyrightDate = computed<string>(() => {
    const startYear = 2026;
    const currentYear = new Date().getFullYear();

    return currentYear > startYear ? `${startYear} - ${currentYear}` : `${startYear}`;
});

/**
 * The version shared by the server, or an empty string when it could not be read.
 *
 * EMPTY RATHER THAN "unknown": the catalogue line puts the version after a middle dot,
 * and a footer reading "· unbekannt" draws the eye to a fact nobody came for. The dot
 * goes with it — see the template.
 */
const version = computed<string>(() => page.props.version ?? "");

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;
</script>

<template>
    <footer class="app-footer">
        <container>
            <div class="app-footer__row">
                <!-- Two catalogue lines rather than one with an optional tail: German and
                     English both want the dot attached to the version, and an empty
                     interpolation would leave "2026 · " trailing into nothing. -->
                <span class="app-footer__meta">{{
                    version === ""
                        ? t("footer.copyrightPlain", { appName, date: copyrightDate })
                        : t("footer.copyright", { appName, date: copyrightDate, version })
                }}</span>
                <!-- THE MARK ALONE (the owner, 2026-08-14): no word, and not LabelledLink's
                     external-link glyph either. GitHub's own logo is the most recognisable
                     label this link could carry, and two icons plus a noun for one
                     destination was three ways of saying it.

                     `icon=""` suppresses the glyph LabelledLink would otherwise pick for an
                     https href; the mark goes in the slot instead, so the component still
                     owns the parts that matter — `target="_blank"` and `rel="noopener
                     nofollow"`.

                     THE WORD BECOMES THE ACCESSIBLE NAME rather than disappearing. An
                     icon-only link is unlabelled to a screen reader, and `aria-label` falls
                     through to the anchor; the tooltip gives a pointer user the same
                     sentence. Pushed to the trailing edge by the row rather than by a margin
                     here, so the two halves simply stack when it wraps. -->
                <labelled-link
                    v-tooltip="t('footer.repository')"
                    :href="REPOSITORY"
                    icon=""
                    :aria-label="t('footer.repository')"
                    class="app-footer__source"
                >
                    <icon name="github" :size="1" />
                </labelled-link>
            </div>
        </container>
    </footer>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* The shelf: spans the window so its fill and top edge reach both sides, with the
   Container inside doing the centring and the inline gutter. See the token partials for
   which numbers are the player bar's and which are this component's own. */
.app-footer {
    padding-block: map.get(s.$c-footer, "padding-block");

    border-top: map.get(s.$c-footer, "border") solid map.get(c.$c-footer, "border");

    background-color: map.get(c.$c-footer, "background");
    color: map.get(c.$c-footer, "surface");
}

/* Copyright leading, source trailing — and simply stacked once there is not room for
   both, which is what `wrap` plus `space-between` gives without a breakpoint: two items
   on a line go to the two ends, and each gets its own line when they no longer fit.
   (The search rows learned the other half of that rule the hard way — there a wrapped
   line had to stay at the trailing edge, so it uses an auto margin instead.) */
.app-footer__row {
    display: flex;
    align-items: center;
    justify-content: space-between;

    flex-wrap: wrap;

    gap: map.get(s.$c-footer, "gap");
}

/* The mark inherits the footer's quiet ink rather than the app's link blue: it sits in a
   band whose whole job is to be unobtrusive. No underline either — `text-decoration` under
   a lone glyph is a stray rule rather than a link affordance, and the logo is the
   affordance. Hover hands it the text-link colour the rest of the app uses, which is what
   says it is pressable. */
.app-footer__source {
    display: inline-flex;

    color: inherit;

    text-decoration: none;

    &:hover {
        color: map.get(c.$c-textlink, "surface");
    }
}
</style>
