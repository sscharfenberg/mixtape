<script setup lang="ts">
/******************************************************************************
 * WelcomeIntro
 * The welcome page's explanation and its one call to action: a bordered panel holding two short
 * paragraphs about what MixTape is, with the button centred in the half beside them.
 *
 * IT EXISTS BECAUSE THE PAGE ANSWERED "HOW MUCH" AND NEVER "WHAT". The stats cards below it are
 * six numbers about a collection a visitor has no context for — somebody who was sent a link
 * lands on a domain, reads "1.238 Alben" and still does not know whose, or why they can see it,
 * or what to do next. The second paragraph is the load-bearing one: it says accounts are by
 * invitation, so a stranger is not left hunting for a sign-up that does not exist, and it says a
 * shared link needs no account, which is the case most visitors are actually in.
 *
 * TWO HALVES THAT BECOME TWO ROWS. Prose and button are one wrapping flex row, both taking the
 * same `column-min` basis and growing equally from it — so they are genuinely halves rather than
 * "text, then whatever is left", which is what lets the button be CENTRED in its half. Below
 * `2 × column-min + gap` the row wraps and the button sits under the text, full width, still
 * centred. The prose measure is capped on the paragraphs INSIDE the half, never on the half
 * itself; capping the flex item would hand the slack to the other side and the halves would stop
 * being halves at exactly the widths where it matters.
 *
 * THE BUTTON KNOWS WHO IS READING. `/` is not a guests-only route — a signed-in reader who
 * clicks the logo lands here too — and "Anmelden" shown to somebody already signed in reads as a
 * broken session. So the arm is chosen from the shared `auth.user` prop: sign in, or go to the
 * collection.
 *
 * IT DOES NOT PREFETCH, and that is not an omission. `/login` is a form holding a PASSWORD, and
 * a prefetch landing after the reader has navigated there is applied as a navigation to the page
 * they are on — re-keying the component and resetting every field on it (CLAUDE.md → the
 * prefetch rule). The `/music` arm is a plain listing and could warm safely, but one `<Link>`
 * cannot prefetch conditionally on its own href without saying so twice, and warming a landing
 * page's single button buys nothing measurable.
 *
 * A COMPONENT RATHER THAN MARKUP IN THE PAGE because it owns colour and size decisions — the
 * panel, the gradient, the prose measure — and tokens are scoped to components, never to pages.
 * It lives beside WelcomePage.vue, which is where a page's own parts go.
 *****************************************************************************/
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

const { t } = useI18n();
const page = usePage();

/** Whether anyone is signed in — the one thing that decides which call to action is drawn. */
const user = computed(() => page.props.auth.user);

/** Where the button goes: into the collection for a member, to the login form for everyone else. */
const target = computed(() => (user.value ? "/music" : "/login"));
</script>

<template>
    <div class="welcome-intro">
        <div class="welcome-intro__prose">
            <p>{{ t("home.intro") }}</p>
            <p>{{ t("home.invite") }}</p>
        </div>
        <div class="welcome-intro__action">
            <!-- No `prefetch`: the guest arm is a password form. See the banner. -->
            <Link :href="target" class="btn btn-primary">
                <!-- `size` is a STEP on the icon scale (0–5), not a length: a fractional value
                     matches no size class, so `--icon-size` never resolves and the SVG falls
                     back to its intrinsic size — a 130px arrow. `1` is what every other button
                     in the app passes. -->
                <icon :name="user ? 'music' : 'login'" :size="1" />
                <span>{{ user ? t("home.browse") : t("home.signIn") }}</span>
            </Link>
        </div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* The panel: a bordered box on a gradient of one grey step, drawn by the Widget's own metrics so
   the introduction and the cards it introduces look like the same app.

   `align-items: center` is what puts the button on the prose's optical middle rather than at the
   top of a two-paragraph column — and it costs nothing once the row wraps, where each line holds
   one item. */
.welcome-intro {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    padding: map.get(s.$c-welcome-intro, "padding");
    border: map.get(s.$c-welcome-intro, "border") solid map.get(c.$c-welcome-intro, "border");
    gap: map.get(s.$c-welcome-intro, "gap");

    background-image: linear-gradient(
        135deg,
        map.get(c.$c-welcome-intro, "gradient-from"),
        map.get(c.$c-welcome-intro, "gradient-to")
    );
    color: map.get(c.$c-welcome-intro, "surface");
    border-radius: map.get(s.$c-welcome-intro, "radius");
}

/* Both halves grow from the SAME basis, which is what makes them halves — see the banner. The
   basis is also the wrap threshold, so there is one number to change rather than two that have
   to agree. */
.welcome-intro__prose,
.welcome-intro__action {
    flex: 1 1 map.get(s.$c-welcome-intro, "column-min");
}

/* The paragraphs stack on the panel's own rhythm; the browser's margins would double it. */
.welcome-intro__prose {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-welcome-intro, "gap");

    p {
        max-width: map.get(s.$c-welcome-intro, "measure");
        margin: 0;
    }
}

/* The button centred in its own half — and still centred on its own line once the row has
   wrapped, which is the one place a `justify-content` earns its keep twice. */
.welcome-intro__action {
    display: flex;
    justify-content: center;
}
</style>
