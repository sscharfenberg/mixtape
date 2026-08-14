<script setup lang="ts">
/******************************************************************************
 * AudiobooksPage
 * The Audiobooks area's entry page, at /audiobooks (route `audiobooks`) — the collection's
 * stats card over three ways into it: the books themselves, and the same books grouped by who
 * wrote them and by who reads them.
 *
 * NOT A DATATABLE, which is the one thing that makes this page look unlike every Music
 * listing (the owner's call): this app is a music player that also holds audiobooks, and
 * twenty books do not need sorting, paging or a column of file sizes. They need to be
 * recognisable, which means covers — so all three tabs draw the shared `Discography` grid,
 * the component the artist and genre pages already use for exactly this.
 *
 * THE CREDIT TABS ARE ACCORDIONS over that same grid: eleven authors, each with a shelf, is
 * more than a page can show at once and exactly what a disclosure stack is for.
 *
 * BOTH THE TAB AND THE OPEN AUTHOR LIVE IN THE URL, which is what makes either linkable.
 * `useTabParam` owns `?tab=` and this page owns `?open=` alongside it — both written with
 * `history.replaceState` rather than an Inertia visit, because every panel is already on the
 * page and a visit would only raise a loader over content that is already on screen.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { DiscographyAlbum } from "Components/Music/Discography/Discography.vue";
import Discography from "Components/Music/Discography/Discography.vue";
import type { AccordionSection } from "Components/UI/Accordion/Accordion.vue";
import Accordion from "Components/UI/Accordion/Accordion.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import type { TabDefinition } from "Components/UI/TabbedNavigation/TabbedNavigation.vue";
import TabbedNavigation from "Components/UI/TabbedNavigation/TabbedNavigation.vue";
import AudiobookStatsWidget from "Components/UI/Widget/Consumers/AudiobookStatsWidget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { useTabParam } from "Composables/useTabParam";
import type { AudiobookCredit, AudiobookStats } from "Types/audiobooks";
import { formatClock } from "Utils/formatting";

const props = defineProps<{
    /** The six numbers on the stats card. */
    stats: AudiobookStats;
    /** Every book, newest first — the Books tab. */
    books: DiscographyAlbum[];
    /** Every author with their shelf — the Authors tab. */
    authors: AudiobookCredit[];
    /** Every narrator with theirs. */
    narrators: AudiobookCredit[];
}>();

const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();

setBreadcrumbs([{ labelKey: "header.siteMenu.audiobooks", icon: "audiobook" }]);

const { tab: openTab } = useTabParam();

/**
 * Which credit section stands open, mirrored into `?open=`.
 *
 * A LIST because that is the Accordion's shape in both its modes, though `closeOther` keeps
 * it to one here — eleven authors with a shelf each is a page nobody can find their way back
 * up. Seeded from the URL on arrival, which is the half that makes a link to one author work.
 */
const openCredits = ref<string[]>(initialOpen());

/** The `?open=` value the page was loaded with, as the Accordion's list. */
function initialOpen(): string[] {
    if (typeof window === "undefined") return [];

    const open = new URLSearchParams(window.location.search).get("open");

    return open === null || open === "" ? [] : [open];
}

/**
 * Write the open section back into the URL, so the address bar always describes what is on
 * screen and a reload — or a shared link — comes back to it.
 *
 * `replaceState`, never an Inertia visit, for the reason `useTabParam` gives: every panel is
 * already here, so a visit would fetch a page identical to this one and raise a loader over
 * it. `history.state` is carried through untouched, or Inertia loses track of the page it
 * thinks it is on.
 */
watch(openCredits, ids => {
    if (typeof window === "undefined") return;

    const url = new URL(window.location.href);

    if (ids.length === 0) url.searchParams.delete("open");
    else url.searchParams.set("open", ids[0]);

    window.history.replaceState(window.history.state, "", url);
});

/** The three tabs, counted so a reader sees the size of each before opening it. */
const tabs = computed<TabDefinition[]>(() => [
    { id: "books", label: t("audiobooks.tabs.books"), icon: "audiobook", count: props.books.length },
    { id: "authors", label: t("audiobooks.tabs.authors"), icon: "author", count: props.authors.length },
    { id: "narrators", label: t("audiobooks.tabs.narrators"), icon: "narrator", count: props.narrators.length }
]);

/**
 * One credit list as accordion sections.
 *
 * The header's facts are composed here rather than on the server, because only the client
 * knows the locale: a book COUNT is pluralised through the catalogue and a duration is
 * clocked. The middle dot joins two facts that are already words — it is punctuation, not a
 * sentence, so it survives the language switch.
 */
const sectionsFor = (credits: AudiobookCredit[]): AccordionSection[] =>
    credits.map(credit => {
        const books = t("audiobooks.credit.books", credit.bookCount);
        const clock = formatClock(credit.duration);

        return {
            id: credit.id,
            label: credit.name,
            meta: clock === null ? books : `${books} · ${clock}`
        };
    });

const authorSections = computed(() => sectionsFor(props.authors));
const narratorSections = computed(() => sectionsFor(props.narrators));

/** The books behind one credit id, for that section's panel. */
const booksOf = (credits: AudiobookCredit[], id: string): DiscographyAlbum[] =>
    credits.find(credit => credit.id === id)?.books ?? [];
</script>

<template>
    <Head :title="t('header.siteMenu.audiobooks')" />
    <!-- Outside the Container like every other page heading — its glowing border has to reach
         the window edge so the seam hides off-screen (see Container). -->
    <headline glow>
        <icon name="audiobook" :size="3" />
        {{ t("header.siteMenu.audiobooks") }}
    </headline>
    <container>
        <widget-group>
            <audiobook-stats-widget v-bind="stats" />
        </widget-group>

        <tabbed-navigation
            v-model:selected-tab="openTab"
            name="audiobooks"
            :tabs="tabs"
            :label="t('audiobooks.tabs.label')"
        >
            <!-- `count-key` on every grid: a book's tracks are CHAPTERS, and the shared
                 component's default word is the one an album wants. -->
            <template #books>
                <discography :albums="books" :page-size="25" count-key="audiobooks.chapterCount" />
            </template>
            <template #authors>
                <accordion v-model:open="openCredits" name="authors" :sections="authorSections">
                    <template v-for="author in authors" :key="author.id" #[author.id]>
                        <discography
                            :albums="booksOf(authors, author.id)"
                            :page-size="25"
                            count-key="audiobooks.chapterCount"
                        />
                    </template>
                </accordion>
                <p v-if="authors.length === 0">{{ t("audiobooks.noCredits") }}</p>
            </template>
            <template #narrators>
                <accordion v-model:open="openCredits" name="narrators" :sections="narratorSections">
                    <template v-for="narrator in narrators" :key="narrator.id" #[narrator.id]>
                        <discography
                            :albums="booksOf(narrators, narrator.id)"
                            :page-size="25"
                            count-key="audiobooks.chapterCount"
                        />
                    </template>
                </accordion>
                <p v-if="narrators.length === 0">{{ t("audiobooks.noCredits") }}</p>
            </template>
        </tabbed-navigation>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/sizes" as s;

/* The card and the tabs below it, spaced by the card gap — a page reads the token of the
   component that already defines it rather than minting one (CLAUDE.md → Design tokens). */
.container > * + * {
    margin-block-start: map.get(s.$c-card, "gap");
}
</style>
