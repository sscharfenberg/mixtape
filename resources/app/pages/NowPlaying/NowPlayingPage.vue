<script setup lang="ts">
/******************************************************************************
 * NowPlayingPage
 * The Now Playing area, at /now-playing (route `now-playing`, behind auth) and linked from the
 * header site menu — which offers it only while the queue holds something (useSiteAreas).
 *
 * FOUR ROWS, in the order the owner asked for and each answering a different question:
 *   1. WHAT IS PLAYING — the hero, with the artwork and the track's facts.
 *   2. WHAT IT SOUNDS LIKE — the visualiser, kept deliberately short so it never pushes row 1
 *      off the top of a phone.
 *   3. WHAT IS EITHER SIDE — the previous and next tracks, each card a button that steps there.
 *   4. WHAT IS LINED UP — the whole queue.
 *
 * ROWS 2–4 ARE NowPlayingSection, AND ONLY ROW 1 IS THIS PAGE'S OWN (2026-08-12). The three
 * describe playback rather than this page, and the guest share page wants exactly them under a
 * hero of its own; the hero here stays, because it is about the LOADED TRACK, which is the one
 * thing a share page has no room to say twice.
 *
 * IT READS THE PLAYER, IT IS NOT HANDED ONE. The queue and the loaded track live in the browser
 * so playback survives Inertia swapping pages (usePlayerQueue), so this page takes almost no
 * props: a server payload would only be a second, staler copy of what the composables already
 * hold. The controller's one prop is `genres`, argued below.
 *
 * "WHAT PLAYS NEXT" IS A REAL ANSWER UNDER SHUFFLE, which it was not until 2026-08-09. The draw
 * used to happen inside the press, so there was nothing to show until you asked; the queue now
 * decides it when the current track loads and keeps that promise when next is pressed
 * (docs/play-queue.md → the pre-draw). Row 3 is the whole reason that changed.
 *
 * WHAT THE QUEUE DOES NOT CARRY IS FETCHED, and it is fetched rather than stored for a reason
 * worth knowing before anyone "fixes" it: the stored queue track is trimmed to ~164 characters
 * precisely so a library-sized queue fits in a browser's few megabytes, and six more fields on
 * every entry would pay that twelve thousand times over to label three cards. So the page asks
 * for exactly the three ids it is drawing — genre, the three link URLs, the year and the play
 * counts — and re-asks when they change.
 *
 * The page renders EVERYTHING ELSE from the queue immediately — title, artwork, artist, album,
 * runtime are all in hand — so nothing is ever blank and nothing waits on a request. The linked
 * tiles simply become links, and the year and plays appear, a moment later.
 *****************************************************************************/
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import PlayCountFacts from "Components/Music/PlayCountFacts.vue";
import NowPlayingSection from "Components/Player/NowPlayingSection.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { formatClock } from "Utils/formatting";

const props = defineProps<{
    /**
     * Everything the queue does not carry, keyed by track id, for the three tracks this page
     * draws: the genre, the URLs that make artist / album / genre into links, the year and the
     * play counts.
     *
     * An absent id and a null field read the same way — the line is simply dropped — because
     * "this rip carried no genre frame" and "the scanner has since removed this file" are not a
     * distinction worth a card's one spare row (App\Services\Player\NowPlayingFacts says the
     * same from its end).
     */
    facts: Record<string, TrackFacts | undefined>;
}>();

/** One track's server-side facts — everything `QueueTrack` has no room for. */
type TrackFacts = {
    /** Its genre, or null for a file whose tag was empty. */
    genre: string | null;
    /** Where the performing artist's page is, or null when the file credits nobody. */
    artistUrl: string | null;
    /** Where the album's page is, or null for a track filed under none. */
    albumUrl: string | null;
    /** Where the genre's page is, or null when there is no genre. */
    genreUrl: string | null;
    /** The release year, off the album — null for a loose file or an untagged rip. */
    year: number | null;
    /** The reader's own listens and everybody else's, raw. */
    plays: { own: number; others: number };
};

const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "header.siteMenu.nowPlaying", icon: "now_playing" }]);

const { current, nextTrack, previousTrack } = usePlayerQueue();
const { isPlaying, queueFinished } = usePlayerAudio();

/** The three tracks the page draws, in reading order. Nulls at the ends of the queue. */
const trio = computed(() => [previousTrack.value, current.value, nextTrack.value]);

/**
 * The loaded track's runtime as a clock, or "" when the file carried no duration.
 *
 * A computed rather than a call in the template, because `formatClock` answers null for an unknown
 * duration and a FactPair wants a string: reducing it once here keeps the `v-if` and the value
 * reading the same thing, where two calls could disagree.
 */
const runtime = computed(() => formatClock(current.value?.duration ?? null) ?? "");

/** A track's facts, or null when it has none yet, or the id is unknown. */
const factsOf = (id: string | undefined): TrackFacts | null => (id === undefined ? null : (props.facts[id] ?? null));

/** The loaded track's facts, which the hero reads five things off. */
const playing = computed<TrackFacts | null>(() => factsOf(current.value?.id));

/**
 * The genres alone, keyed by track id — the whole of what NowPlayingSection needs from `facts`.
 *
 * Narrowed here rather than handing the block the whole map, because that map carries play
 * counts and three URLs that only a signed-in reader has any use for: the block is rendered on
 * the guest share page too, and a prop it can only ever half-fill is one that invites a card
 * down there to start reading the other half.
 */
const genres = computed<Record<string, string | null>>(() =>
    Object.fromEntries(Object.entries(props.facts).map(([id, fact]) => [id, fact?.genre ?? null]))
);

/**
 * What the player is doing, as one word.
 *
 * THREE STATES, NOT TWO. "Paused" and "the queue is finished" look identical to the element — it
 * is stopped either way — but they are entirely different things to a listener: one is waiting for
 * a press and the other has nothing left to press.
 *
 * IT READS `queueFinished`, NOT `hasNext`, and the difference is a bug the owner caught: on the
 * LAST track `hasNext` is false however you got there, so pausing at the end of a queue announced
 * "end of queue" when the listener had simply pressed pause. The player records the real event
 * instead — a track ending with nothing to follow — which is the only moment the two can be told
 * apart.
 */
const status = computed<"playing" | "paused" | "end">(() => {
    if (isPlaying.value) return "playing";

    return queueFinished.value ? "end" : "paused";
});

/**
 * The ids to ask the server about, as a stable string.
 *
 * A STRING RATHER THAN THE ARRAY, because that is what makes the watcher below fire on a real
 * change: an array literal is a new object every time `current` moves, so watching it would
 * re-fetch on every tick of the pointer even when the same three tracks are showing — which
 * happens whenever a track is re-loaded, and on every visit back to this page.
 */
const wanted = computed(() =>
    trio.value
        .map(track => track?.id)
        .filter((id): id is string => id !== undefined)
        .join(",")
);

/**
 * Fetch the facts for whatever three tracks are on screen.
 *
 * `only: ["facts"]` so the round trip carries one small map rather than re-rendering the page.
 * `router.reload` already preserves state and scroll by definition — it is a re-fetch of the page
 * you are on, not a navigation — which is exactly the behaviour wanted here: the player, the queue
 * and this component's own state all have to survive a genre lookup. `replace` keeps it out of
 * the history, or the back button would step through every track change.
 *
 * Immediate, because the first three tracks are known as soon as the queue hydrates and the page
 * would otherwise show its genre and year lines only from the second track onwards.
 */
watch(
    wanted,
    ids => {
        if (ids === "") return;

        router.reload({
            only: ["facts"],
            data: { tracks: ids.split(",") },
            replace: true
        });
    },
    { immediate: true }
);
</script>

<template>
    <Head :title="current ? current.name : t('header.siteMenu.nowPlaying')" />
    <container>
        <div class="now-playing">
            <!-- ROW 1 — what is playing. The hero the four Music detail pages use, so a track
                 looks the same here as on its own page. -->
            <hero-section v-if="current">
                <template #cover>
                    <cover-image :src="current.coverUrl" :title="current.name" size="xlarge" />
                </template>
                <template #title
                    ><h2>{{ current.name }}</h2></template
                >
                <template #metadata>
                    <!-- The three names that LEAD somewhere, each linked only when the server
                         handed over a URL — the queue holds them as plain strings, so this is the
                         one thing about them the page cannot know for itself. Same rule as the
                         Music detail pages: a filled tile where there is a page, plain text where
                         there is not, and never a dead link. -->
                    <fact-pair
                        v-if="current.artist"
                        icon="artist"
                        :label="t('music.columns.artist')"
                        :value="current.artist"
                        :href="playing?.artistUrl ?? undefined"
                    />
                    <fact-pair
                        v-if="current.album"
                        icon="album"
                        :label="t('music.columns.album')"
                        :value="current.album"
                        :href="playing?.albumUrl ?? undefined"
                    />
                    <fact-pair
                        v-if="playing?.genre"
                        icon="genre"
                        :label="t('music.columns.genre')"
                        :value="playing.genre"
                        :href="playing.genreUrl ?? undefined"
                    />
                    <fact-pair
                        v-if="playing?.year"
                        icon="calendar"
                        :label="t('music.columns.year')"
                        :value="String(playing.year)"
                    />
                    <fact-pair
                        v-if="runtime"
                        icon="duration"
                        :label="t('music.columns.duration')"
                        :value="runtime"
                    />
                    <!-- What this track's listening amounts to, in the hero's own tiles — the same
                         component, the same zero rule and the same live refresh the four Music
                         detail pages use. -->
                    <play-count-facts v-if="playing" :plays="playing.plays" subject="song" />
                </template>
                <!-- Under the facts, because it acts on the thing they have just identified. The
                     song's own page is the one place every remaining fact about it lives — the
                     codec, the path, the clones — so the page that plays it links there rather
                     than growing a fifth card. -->
                <template #actions>
                    <Link :href="current.href" class="now-playing__link">
                        <icon name="song" :size="1" />
                        <span>{{ t("nowPlaying.toSongPage") }}</span>
                    </Link>
                    <!-- Beside the link and matched to its height, because it describes the PLAYER
                         rather than the song: among the fact tiles it read as one more thing about
                         the track. An OUTLINE pill rather than the shared Badge, which is built
                         for the dashboard and brought a filled slab and a backdrop blur with it. -->
                    <span class="now-playing__status" :class="`now-playing__status--${status}`" role="status">
                        {{ t(`nowPlaying.status.${status}`) }}
                    </span>
                </template>
            </hero-section>

            <!-- ROWS 2–4 — what it sounds like, what is either side, and what is lined up. One
                 block, because all three are about PLAYBACK rather than about this page, and the
                 guest share page renders the same three under a hero of its own. -->
            <now-playing-section :genres="genres" />

            <p v-if="!current" class="now-playing__empty">{{ t("nowPlaying.empty") }}</p>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* Stacks the hero and the block below it, taking the CardGroup's own gutter (s.$c-card "gap") so
   the rhythm down the page matches the rhythm between two cards — the same rule, for the same
   reason, as the four Music detail pages. NowPlayingSection is a flex column with the same gutter,
   so its three rows land exactly where they did when this file held them. */
.now-playing {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

/* The status pill, sized to the link beside it rather than to its own text: the two share the
   hero's action row and a pill half the link's height would read as a label stuck to it. Same
   padding, border width and radius, so the pair is one line of equal boxes.

   AN OUTLINE, not a fill. The state lives in the ink and the border; a filled pill next to an
   outlined link is a button next to a link, which is not what either of them is. */
.now-playing__status {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-neighbour-track, "gap");
    border: map.get(s.$c-card, "border") solid currentcolor;

    border-radius: map.get(s.$c-card, "radius");

    font-weight: 700;
}

.now-playing__status--playing {
    color: map.get(c.$c-now-playing-status, "playing");
}

.now-playing__status--paused {
    color: map.get(c.$c-now-playing-status, "paused");
}

.now-playing__status--end {
    color: map.get(c.$c-now-playing-status, "end");
}

/* An <Link> styled as a quiet action rather than a Button: the hero's action row holds exactly
   one thing here, and a filled button for "go and read more" would out-shout the transport in the
   bar below. Reads the card's tokens for its surface, like the neighbour cards do. */
.now-playing__link {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-neighbour-track, "gap");
    border: map.get(s.$c-card, "border") solid map.get(c.$c-card, "border");

    gap: 0.5ch;

    color: inherit;
    border-radius: map.get(s.$c-card, "radius");

    text-decoration: none;

    @media (prefers-reduced-motion: no-preference) {
        transition: border-color ti.$c-neighbour-track linear;
    }

    &:hover,
    &:focus-visible {
        border-color: map.get(c.$c-neighbour-track, "edge-active");
    }
}

.now-playing__empty {
    margin: 0;
}
</style>
