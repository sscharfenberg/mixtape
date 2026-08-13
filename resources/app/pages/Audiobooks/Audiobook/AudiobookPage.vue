<script setup lang="ts">
/******************************************************************************
 * AudiobookPage
 * One book's detail page, at /audiobooks/{id} (route `audiobooks.show`) — where a tile of
 * the books listing leads, and where a queued chapter points.
 *
 * Three blocks, the same three AlbumPage has and in the same order: the <Headline> naming
 * the book, the HeroSection that identifies it, and below them its CHAPTERS in the
 * server-driven DataTable. A reader moving between the two areas should not have to learn a
 * second layout.
 *
 * WHAT DIFFERS, and all of it follows from the data rather than from taste:
 *
 * - **The credits are LISTS.** An album has one album-artist; a book has as many authors and
 *   narrators as its chapters name — six and five on the two anthologies in this library —
 *   so both arrive as arrays and are printed as one comma-separated fact each.
 * - **Author and narrator are COLUMNS.** On an ordinary book they repeat down the page and
 *   say little; on an anthology they are what tells one story from the next, which is the
 *   case the columns exist for and the reason the author moved onto the chapter.
 * - **THE ROWS ARE NOT LINKS.** A chapter has no page of its own, and what a reader wants
 *   from a chapter row is to hear it — so each row carries a play button instead, and the
 *   name cell is plain text rather than an <A> to nowhere.
 * - **No genre, no add-to-playlist.** The tracks CHECK forbids an audiobook a genre, and
 *   `PlaylistAdditions` resolves a subject's tracks music-only on purpose.
 *
 * PRESSING A CHAPTER QUEUES THE WHOLE BOOK and starts there (`playSubjectFrom`), rather than
 * playing that one chapter. Starting a single chapter would strand a listener at the end of
 * it, which for a book is the one thing a player must not do.
 *
 * The controller sends raw values (seconds, bytes, an ISO-8601 instant, plain counts) and the
 * formatting happens here against the active locale — the split every page here uses.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import Button from "Components/Form/Button.vue";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import DownloadButton from "Components/Music/DownloadButton.vue";
import PlayCountFacts from "Components/Music/PlayCountFacts.vue";
import ShareButton from "Components/Music/ShareButton.vue";
import SubjectActions from "Components/Music/SubjectActions.vue";
import ActionPanel from "Components/UI/ActionPanel.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import Icon from "Components/UI/Icon.vue";
import type { AudiobookBookmark } from "Composables/useAudiobookBookmark";
import { useAudiobookBookmark } from "Composables/useAudiobookBookmark";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { useSubjectTracks } from "Composables/useSubjectTracks";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import { formatClock, formatDateTime, formatDecimals, formatFileSize, formatPosition } from "Utils/formatting";

/** One book as AudiobookController shaped it — every value raw. */
export interface AudiobookDetail {
    id: string;
    name: string;
    /** Every author its chapters name, sorted. One entry for an ordinary book, six for an anthology. */
    authors: string[];
    /** Every narrator its chapters name, sorted. Same shape and the same reason. */
    narrators: string[];
    /** Release year, or null for an untagged rip. */
    year: number | null;
    /** How many chapters the book has. */
    chapters: number;
    /** Distinct discs, floored to 1 — a rip with no disc tags is still one disc. */
    discs: number;
    /** Total playing time in SECONDS, or null when no chapter carried a duration. */
    duration: number | null;
    /** Total size in BYTES, or null when no chapter carried one. */
    size: number | null;
    /** ISO-8601 instant of the newest chapter file, standing in for "last changed". */
    modifiedAt: string | null;
    /** The hero's <img> source, or null when the book has no art at all. */
    coverUrl: string | null;
    /** Where the download button points — the whole book as a .zip. */
    downloadUrl: string;
}

/** One chapter row, mirroring AudiobookController's `rowMapper`. */
export interface ChapterRow {
    id: string;
    /** Disc number, or null when the file carried none. */
    disc: number | null;
    /** How many discs the book has, so the cell can print "2/5". */
    discTotal: number;
    /** Chapter number within its disc, or null when untagged. */
    track: number | null;
    /** How many chapters share this row's disc, for the "3/33" denominator. */
    trackTotal: number;
    name: string;
    /** Who wrote this chapter — null on an untagged file, and then the cell is empty. */
    author: string | null;
    /** Who reads it. Null on the same condition. */
    narrator: string | null;
    /** Playing time in SECONDS; the page clocks it. */
    duration: number | null;
    /** The chapter's audio, carried so a row can be played without a second round trip. */
    streamUrl: string;
}

const props = defineProps<{
    /** The book itself — everything the hero draws. */
    audiobook: AudiobookDetail;
    /** Its chapters, paginated and sorted by the server. */
    table: TableResponse<ChapterRow>;
    /** Listening events on this book: the reader's own, and everybody else's. */
    plays: { own: number; others: number };
    /** Where this reader left off, or null for a book they have not started. */
    bookmark: AudiobookBookmark | null;
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();

// The book's NAME rather than a key, because a name is data — the same call every detail
// page here makes for its last crumb.
setBreadcrumbs([
    { labelKey: "header.siteMenu.audiobooks", icon: "audiobook", href: "/audiobooks" },
    { label: props.audiobook.name }
]);

// The hero's play/enqueue and every row's play button share one fetch of the book: the first
// press pays for the round trip and the rest are instant.
const { busy, playSubjectFrom } = useSubjectTracks();

/*
 * Resume, and the write that keeps it current. The chapter ids come from the TABLE, which is
 * one page of the book — enough to recognise "is what is playing one of ours", since the
 * player only ever loads a chapter this page put in the queue.
 */
const { bookmark, resume, restart } = useAudiobookBookmark(
    props.audiobook.id,
    props.bookmark,
    () => props.table.rows.map(row => row.id),
    playSubjectFrom
);

/** Whether a row is the one the reader left off at — what the bookmark glyph marks. */
const isBookmarked = (row: ChapterRow): boolean => bookmark.value?.trackId === row.id;

/** The book's total playing time as a clock, or empty when nothing carried a duration. */
const playingTime = computed(() => formatClock(props.audiobook.duration) ?? "");

/** Its total size, humanised — null stays empty rather than printing "0 B". */
const totalSize = computed(() =>
    props.audiobook.size === null ? "" : formatFileSize(props.audiobook.size, locale.value)
);

/** When the newest chapter file last changed, in the reader's locale. */
const modified = computed(() => formatDateTime(props.audiobook.modifiedAt, locale.value) ?? "");

/**
 * The authors as one fact, and the narrators as another.
 *
 * A comma-separated list rather than a fact each: an anthology names six authors, and six
 * tiles of the same label would push everything that describes the BOOK off the first
 * screen. The comma is safe to compose client-side where a sentence would not be — a list of
 * names reads the same in both catalogues.
 */
const authorList = computed(() => props.audiobook.authors.join(", "));
const narratorList = computed(() => props.audiobook.narrators.join(", "));

/**
 * The chapter columns, as a computed so the labels re-evaluate on a locale switch.
 *
 * CD and Track are `align: "right"` like the album's, being numbers; the two credit columns
 * are text. Playtime trails, which is where every listing in this app puts a duration.
 */
const columns = computed<ColumnDef<ChapterRow>[]>(() => [
    { key: "disc", label: t("music.columns.discs"), sortable: true, align: "right" },
    { key: "track", label: t("audiobooks.columns.chapter"), sortable: true, align: "right" },
    { key: "name", label: t("music.columns.title"), sortable: true, cardPrimary: true },
    { key: "author", label: t("audiobooks.columns.author"), sortable: true },
    { key: "narrator", label: t("audiobooks.columns.narrator"), sortable: true },
    { key: "duration", label: t("audiobooks.columns.playtime"), sortable: true, align: "right" }
]);
</script>

<template>
    <Head :title="audiobook.name" />
    <!-- Outside the Container like every other page heading — its glowing border has to reach
         the window edge so the seam hides off-screen (see Container). -->
    <headline glow>
        <icon name="audiobook" :size="3" />
        {{ audiobook.name }}
    </headline>
    <container>
        <div class="audiobook">
            <hero-section>
                <!-- The book's own name as the alt text, not "cover of …" — a screen reader
                     already says "image". Not `decorative`: here the artwork IS the subject
                     of the page. CoverImage draws its glyph when there is no art. -->
                <template #cover>
                    <cover-image :src="audiobook.coverUrl" :title="audiobook.name" size="xlarge" />
                </template>
                <!-- The facts that belong to the book as a whole. Each is skipped when there
                     is nothing to say: an anthology chapter may credit nobody, an untagged
                     rip has no year. The counts always exist. -->
                <template #metadata>
                    <fact-pair
                        v-if="authorList"
                        icon="author"
                        :label="t(audiobook.authors.length > 1 ? 'audiobooks.columns.authors' : 'audiobooks.columns.author')"
                        :value="authorList"
                    />
                    <fact-pair
                        v-if="narratorList"
                        icon="narrator"
                        :label="t(audiobook.narrators.length > 1 ? 'audiobooks.columns.narrators' : 'audiobooks.columns.narrator')"
                        :value="narratorList"
                    />
                    <fact-pair
                        v-if="audiobook.year !== null"
                        icon="calendar"
                        :label="t('music.columns.year')"
                        :value="String(audiobook.year)"
                    />
                    <fact-pair
                        icon="track"
                        :label="t('audiobooks.columns.chapters')"
                        :value="formatDecimals(audiobook.chapters, locale)"
                    />
                    <fact-pair icon="album" :label="t('music.columns.discs')" :value="String(audiobook.discs)" />
                    <fact-pair
                        v-if="playingTime"
                        icon="duration"
                        :label="t('audiobooks.columns.playtime')"
                        :value="playingTime"
                    />
                    <fact-pair v-if="totalSize" icon="file" :label="t('music.columns.size')" :value="totalSize" />
                    <fact-pair
                        v-if="modified"
                        icon="calendar"
                        :label="t('music.columns.modifiedAt')"
                        :value="modified"
                    />
                    <!-- Last, and only when there is something to say: what has actually been
                         listened to comes after what the book IS. -->
                    <play-count-facts :plays="plays" subject="album" />
                </template>
                <!-- The tinted ActionPanel for what a reader came for, and under it the two
                     actions that take the book somewhere else: onto a disk, or to somebody
                     without an account. -->
                <template #actions>
                    <action-panel>
                        <!-- Play RESUMES (the owner's call), with a separate way back to the
                             start beside it — on a book part-way through, resuming is what the
                             obvious big button should do, and restarting is the rarer verb
                             that still has to be reachable. -->
                        <Button variant="primary" no-halo :disabled="busy" @click="resume">
                            <icon :name="busy ? 'refresh' : 'playlist'" :size="1" :rotate="busy" />
                            <span>{{ t(bookmark ? "audiobooks.actions.resume" : "audiobooks.actions.play") }}</span>
                        </Button>
                        <Button v-if="bookmark" no-halo :disabled="busy" @click="restart">
                            <icon name="first-page" :size="1" />
                            <span>{{ t("audiobooks.actions.restart") }}</span>
                        </Button>
                        <subject-actions />
                    </action-panel>
                    <download-button :href="audiobook.downloadUrl" subject="audiobook" />
                    <share-button subject="audiobook" :subject-id="audiobook.id" />
                </template>
            </hero-section>

            <!-- `has-actions` for the play column; the rows carry no `href`, so the DataTable
                 renders them as plain rows rather than making the whole row clickable. -->
            <data-table
                :columns="columns"
                :response="table"
                :base-url="`/audiobooks/${audiobook.id}`"
                has-actions
            >
                <!-- The chapter the reader left off at, marked where the eye already is: in
                     the title cell rather than a column of its own, which would be empty on
                     every other row of a 673-chapter book. -->
                <template #cell-name="{ row }">
                    <span class="chapter-name">
                        <icon
                            v-if="isBookmarked(row)"
                            name="bookmark"
                            :size="1"
                            class="chapter-name__mark"
                            :aria-label="t('audiobooks.chapter.bookmarked')"
                        />
                        {{ row.name }}
                    </span>
                </template>
                <template #cell-disc="{ row }">
                    {{ formatPosition(row.disc, row.discTotal) ?? "" }}
                </template>
                <template #cell-track="{ row }">
                    {{ formatPosition(row.track, row.trackTotal) ?? "" }}
                </template>
                <template #cell-duration="{ row }">
                    {{ formatClock(row.duration) ?? "" }}
                </template>
                <!-- One button per row: queue the whole book and start here. Disabled while
                     the book is being fetched, so a second press cannot start a race. -->
                <template #actions="{ row }">
                    <!-- `default` (the neon outline) and no halo: it sits inside a table row
                         rather than on the page, where the pooled glow reads as a smudge —
                         the same call the hero's buttons make. -->
                    <Button
                        variant="default"
                        no-halo
                        :aria-label="t('audiobooks.chapter.play')"
                        :title="t('audiobooks.chapter.play')"
                        :disabled="busy"
                        @click="playSubjectFrom(row.id)"
                    >
                        <icon name="play" :size="1" />
                    </Button>
                </template>
                <template #empty>{{ t("components.datatable.no_results") }}</template>
            </data-table>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/sizes" as s;

/* The page's two blocks, spaced by the card gap — a page reads the token of the component
   that already defines it rather than minting one of its own (CLAUDE.md → Design tokens). */
.audiobook {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

/* One flex item around the glyph and the title, so a long chapter name wraps as ONE block
   under its own first line rather than the mark being pushed onto a line of its own — the
   trap a bare text node beside an icon falls into in a wrapping row. */
.chapter-name {
    display: flex;
    align-items: center;

    min-width: 0;

    gap: 0.4ch;
}

.chapter-name__mark {
    flex: none;
}
</style>
