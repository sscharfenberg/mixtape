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
 * THE BUTTONS KNOW WHO IS READING. `/` is not a guests-only route — a signed-in reader who
 * clicks the logo lands here too — and "Anmelden" shown to somebody already signed in reads as a
 * broken session. So a guest is offered the sign-in, and a member is offered THE AREAS
 * THEMSELVES: all the music, all the audiobooks. One "go to the collection" button had to pick
 * one of the two silently, and picked music for everybody.
 *
 * THE ONE THEY USE COMES FIRST, ordered by how long they have listened in each rather than by
 * how much of each the library holds. Hours are the honest unit for that question: a chapter
 * runs half an hour against a song's three minutes, so counting plays would put music first for
 * somebody who spends every evening on audiobooks. A tie — a fresh account with no listening at
 * all — falls back to the bigger area, which is the rule a sign-in already lands by
 * (App\Services\Auth\LandingPage), so the two agree about what this instance is mostly about.
 *
 * AN EMPTY AREA IS NOT OFFERED. This instance may legitimately hold one kind and not the other,
 * and a button leading to a page with nothing on it is the same broken promise the header's site
 * menu refuses to make.
 *
 * NOTHING PREFETCHES, and that is not an omission. `/login` is a form holding a PASSWORD, and a
 * prefetch landing after the reader has navigated there is applied as a navigation to the page
 * they are on — re-keying the component and resetting every field on it (CLAUDE.md → the
 * prefetch rule). The area links are plain listings and could warm safely, but warming a landing
 * page's buttons buys nothing measurable and the two arms would then have to say so separately.
 *
 * A COMPONENT RATHER THAN MARKUP IN THE PAGE because it owns colour and size decisions — the
 * panel, the gradient, the prose measure — and tokens are scoped to components, never to pages.
 * It lives beside WelcomePage.vue, which is where a page's own parts go.
 *****************************************************************************/
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** Raw seconds this reader has listened to, keyed by track type — the buttons' order. */
    listening: Record<string, number>;
    /** How many music tracks the library holds: the tie-break, and whether music is offered at all. */
    musicTracks: number;
    /** The same for audiobook chapters. */
    audiobookTracks: number;
}>();

/** One area's button, as the template draws it. */
type AreaLink = {
    /** The listing this leads to. */
    href: string;
    /** Its label, already translated. */
    label: string;
    /** The glyph the app uses for that area everywhere else. */
    icon: string;
};

const { t } = useI18n();
const page = usePage();

/** Whether anyone is signed in — the one thing that decides which call to action is drawn. */
const user = computed(() => page.props.auth.user);

/**
 * The areas a signed-in reader is offered, most-listened first.
 *
 * SORTED ON HOURS, TIE-BROKEN ON SIZE — the banner says why hours rather than plays, and why the
 * tie-break is the one a sign-in lands by. Both comparisons are descending, so the sort reads as
 * "the bigger number wins" throughout.
 *
 * An area with no tracks is dropped rather than disabled: this instance may hold one kind and not
 * the other, and there is nothing to say about a link that must not be pressed.
 *
 * Empty for a guest, who is offered the sign-in instead — the template asks `user` rather than
 * this, so an instance with no media at all still draws a login button.
 */
const areas = computed<AreaLink[]>(() =>
    [
        {
            href: "/music",
            label: t("home.allMusic"),
            icon: "music",
            seconds: props.listening.music ?? 0,
            tracks: props.musicTracks
        },
        {
            href: "/audiobooks",
            label: t("home.allAudiobooks"),
            icon: "audiobook",
            seconds: props.listening.audiobook ?? 0,
            tracks: props.audiobookTracks
        }
    ]
        .filter(area => area.tracks > 0)
        .sort((a, b) => b.seconds - a.seconds || b.tracks - a.tracks)
        .map(({ href, label, icon }) => ({ href, label, icon }))
);
</script>

<template>
    <div class="welcome-intro">
        <div class="welcome-intro__prose">
            <p>{{ t("home.intro") }}</p>
            <p>{{ t("home.invite") }}</p>
        </div>
        <div class="welcome-intro__action">
            <!-- No `prefetch` on either arm: the guest one is a password form. See the banner. -->
            <template v-if="!user">
                <Link href="/login" class="btn btn-primary">
                    <!-- `size` is a STEP on the icon scale (0–5), not a length: a fractional
                         value matches no size class, so `--icon-size` never resolves and the SVG
                         falls back to its intrinsic size — a 130px arrow. `1` is what every
                         other button in the app passes. -->
                    <icon name="login" :size="1" />
                    <span>{{ t("home.signIn") }}</span>
                </Link>
            </template>

            <!-- One per area the library actually holds, most-listened first. `<template>` rather
                 than `v-else` on the loop itself: `v-if` and `v-for` on one element read as a
                 condition per iteration, which is not what either is doing here. -->
            <template v-else>
                <Link v-for="area in areas" :key="area.href" :href="area.href" class="btn btn-primary">
                    <icon :name="area.icon" :size="1" />
                    <span>{{ area.label }}</span>
                </Link>
            </template>
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

    overflow: hidden;
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

/* One button for a guest, up to two for a member. They wrap rather than shrink: a button whose
   label is squeezed onto two lines beside a neighbour reads as a layout accident, and there is
   room in this half for one of them at almost every width. `align-items: center` keeps a wrapped
   pair the same width as each other rather than stretching both to the half. */
.welcome-intro__action {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;

    gap: map.get(s.$c-welcome-intro, "gap");
}
</style>
