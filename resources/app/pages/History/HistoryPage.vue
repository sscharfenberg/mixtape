<script setup lang="ts">
/******************************************************************************
 * HistoryPage
 * What this reader has listened to, by day — at /history (route `history`, behind auth,
 * rendered by History\HistoryController).
 *
 * THE OTHER HALF OF `plays`. The app has been writing that table since the player was built
 * and until now only ever read it as an aggregate: the most-played widgets count these rows
 * without ever saying what any of them were. This is the one page that shows the events, which
 * is also what makes a half-finished evening easy to pick up again.
 *
 * DAYS, NOT A FEED. The unit of the page is a day that had listening in it — the accordion's
 * sections, and what the pager counts. A flat reverse-chronological list answers "what did I
 * play recently" and nothing else; days answer "what did I put on last Saturday", which is the
 * question somebody actually arrives with. The section headers carry the count, so the size of
 * an evening is readable before it is opened.
 *
 * THE STACK OPENS CLOSED, and only one section at a time (the Accordion's own default). Twenty-
 * five days of listening opened at once is a page nobody can find their way back up, and the
 * first thing worth seeing here is the run of days itself.
 *
 * THE PANELS ARE `v-if`, which is the Accordion's doing and worth knowing here: every play on
 * the page travels with it, but nothing is BUILT until a day is opened. That is what makes one
 * request per page reasonable rather than one per day — see the controller, which argues the
 * same trade from the other end.
 *
 * TWENTY-FIVE DAYS, FIXED. The pager has no size control (owner's call, and the shape agrees
 * with it): a history is read in days, twenty-five is about a month for a daily listener, and a
 * page-size Select on a list with one column is a setting nobody came here to make. The pager
 * itself is drawn only when there is a second page — the common case for a new account is one
 * day, and a "1–1 / 1" under it says only that a pager exists.
 *
 * IT IS A READING PAGE. Nothing here starts audio: a row is a link to the thing that was
 * played — see HistoryRow, which argues why a history that could add to itself would be the
 * wrong page.
 *
 * THE STACK SITS IN A `Card`, which is where its surface comes from. A run of accordion
 * sections needs something under it or it floats on the page background; the audiobooks page
 * gets that from the tabbed-navigation frame around its own accordion, and this page has no
 * tabs. The Accordion cannot carry the surface itself — there it would draw a bordered panel
 * inside the tab frame, around the accordion but not around the Books tab's discography beside
 * it — and a frame minted here would be a second copy of what `Card` already owns.
 *****************************************************************************/
import { Head, router } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTablePagination from "Components/DataTable/DataTablePagination.vue";
import type { AccordionSection } from "Components/UI/Accordion/Accordion.vue";
import Accordion from "Components/UI/Accordion/Accordion.vue";
import Card from "Components/UI/Card/Card.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { formatDay, formatDecimals } from "Utils/formatting";
import type { HistoryPlay } from "./HistoryRow.vue";
import HistoryRow from "./HistoryRow.vue";

/** One day that had listening in it, with the listens themselves. */
export interface HistoryDay {
    /** The calendar day as `YYYY-MM-DD` — a DAY, not an instant. See `formatDay`. */
    date: string;
    /** How many listens that day. The header's fact, and the length of `plays`. */
    count: number;
    /** That day's listens, newest first. */
    plays: HistoryPlay[];
}

const props = defineProps<{
    /** The page's days, newest first. Empty only for a reader with no listening at all. */
    days: HistoryDay[];
    /** Which page of days this is, 1-based. */
    page: number;
    /** How many days a page holds — fixed by the server, not the reader's to change. */
    perPage: number;
    /** How many days have listening in them altogether — what the pager counts. */
    totalDays: number;
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "history.title", icon: "plays" }]);

/**
 * The days as accordion sections.
 *
 * THE DATE IS FORMATTED HERE rather than on the server, for the reason every date in this app
 * is: only the client knows the reader's locale. The id stays the RAW `YYYY-MM-DD`, because it
 * is what the panel slot is named by and what a URL would carry — a localised string would
 * change the identity of a section with the language switcher.
 *
 * The count rides along as the header's one fact, with its noun AFTER it ("12 Titel") — a
 * count reads as a phrase, which is the distinction `AccordionFact` keeps two fields for.
 */
const sections = computed<AccordionSection[]>(() =>
    props.days.map(day => ({
        id: day.date,
        label: formatDay(day.date, locale.value) ?? day.date,
        icon: "calendar",
        facts: [
            {
                icon: "plays",
                value: formatDecimals(day.count, locale.value),
                unit: t("history.playsUnit", day.count),
                title: t("history.playsTitle", day.count)
            }
        ]
    }))
);

/** Whether anything has been listened to at all — the page's one empty state. */
const isEmpty = computed<boolean>(() => props.totalDays === 0);

/**
 * Go to another page of days.
 *
 * A REAL VISIT rather than client-side slicing, unlike the Discography's pager: the days on
 * screen are the only ones the server sent, so the next twenty-five have to be fetched. State
 * is preserved so the page component is not re-created — which would also close whichever
 * section was open, and closing it is right here: the new page has entirely different days,
 * and an id that no longer exists is filtered out by the Accordion anyway.
 */
const goToPage = (page: number): void => {
    router.get(window.location.pathname, { page }, { preserveState: true, preserveScroll: false });
};
</script>

<template>
    <Head :title="t('history.title')" />
    <!-- Outside the Container like every other page heading — the glowing border has to reach
         the window edge so its seam hides off-screen (see Container). -->
    <headline glow align="left">
        <icon name="plays" :size="3" />
        {{ t("history.title") }}
    </headline>
    <container>
        <p class="history__intro">{{ t("history.intro") }}</p>

        <!-- THE PANEL THE STACK SITS IN. A stack of accordion sections needs a surface under it
             or it floats on the page background with nothing holding it — the sections read as
             loose strips rather than as a list in a panel. The audiobooks page gets that from
             the tabbed-navigation frame its accordion sits inside; this page has no tabs, so it
             asks for the same surface by name.
             A `Card`, NOT A FRAME OF THIS PAGE'S OWN, and not one on the Accordion either. The
             component cannot carry it: on the audiobooks page that would put a bordered panel
             inside the tab frame, around the accordion but not around the Books tab's
             discography beside it. And a page that minted the fill and the edge itself would be
             a second copy of a surface `Card` already owns — the duplicate the token rules exist
             to prevent. The pager is inside it for the reason the discography's is inside the
             tab frame: it belongs to the list it pages. -->
        <card>
            <!-- One wrapper inside the card, and only for the GAP: `.card__body` is a grid, so
                 the stack and the pager would otherwise sit against each other. The gap is the
                 card's own — read from the component that defines it rather than minted here. -->
            <div class="history__stack">
                <!-- Reachable only by typing the URL: the user menu draws its way in here off
                     the `hasPlays` shared prop, so a reader with no listening never meets a link
                     to this page. It is still a real state and still says something true. -->
                <p v-if="isEmpty" class="history__empty">{{ t("history.empty") }}</p>

                <accordion v-else name="history" :sections="sections">
                    <template v-for="day in props.days" #[day.date] :key="day.date">
                        <!-- A list, semantically: a screen reader gets "list, N items" before
                             the rows. Labelled by the day it belongs to, so it is told apart
                             when read out of context — every panel here is otherwise "list of
                             plays". -->
                        <ul class="history__day" :aria-label="formatDay(day.date, locale) ?? day.date">
                            <history-row v-for="play in day.plays" :key="play.id" :play="play" />
                        </ul>
                    </template>
                </accordion>

                <!-- Only when there is a second page — see the banner. `fixed-page-size` is what
                     drops the shared pager's page-size Select: the number of days is the
                     server's, not the reader's. -->
                <data-table-pagination
                    v-if="totalDays > perPage"
                    :page="page"
                    :page-size="perPage"
                    :total="totalDays"
                    fixed-page-size
                    @navigate="goToPage"
                />
            </div>
        </card>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

.history__intro {
    margin-block: 0 map.get(s.$c-card, "gap");
}

/* Inside the Card, and only for the gap between the stack and the pager — `.card__body` is a
   grid, so without this the two would sit against each other. Every value the panel itself is
   made of belongs to the Card; this reads its gap rather than minting one to keep in step. */
.history__stack {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

.history__empty {
    margin: 0;
}

/* One day's listens. The gap is the accordion's own between-sections gap, read from the card
   token both of them already use, so a panel's rows sit on the same rhythm as the sections
   they opened out of. */
.history__day {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-history, "row-gap");

    list-style: none;
}
</style>
