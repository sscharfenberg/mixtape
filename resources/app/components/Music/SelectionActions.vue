<script setup lang="ts">
/******************************************************************************
 * SelectionActions
 * The three verbs a DataTable's TICKED ROWS offer: play them, queue them, or put them in a
 * playlist. It goes in the table's `toolbar-actions` slot and draws nothing until something is
 * ticked, so a table nobody has touched looks exactly as it did before.
 *
 * A POPOVER MENU RATHER THAN A ROW OF BUTTONS, which is the opposite of what SubjectActions does
 * in a hero and for the opposite reason. A hero is a page ABOUT one thing, with a whole column to
 * spend on its two most likely actions; this shares the table's toolbar with a search field, a
 * narrowing chip and a selection count, and it appears without warning the moment a box is
 * ticked. Three labelled buttons fit that strip only on a very wide screen — everywhere else
 * they wrap the toolbar onto a second line, or shove the search field, at the exact moment the
 * reader's attention is on the rows. One trigger costs a click and always fits.
 *
 * The count beside it is DataTableToolbar's, not this component's: the menu says what can be
 * done, the toolbar says how much it would be done to.
 *
 * IT INJECTS THE TABLE RATHER THAN TAKING THE SELECTION AS A PROP. The slot hands out
 * `selectedIds` and `clear`, so either would work — but this component is meaningless outside a
 * DataTable, and injection says so at the seam instead of leaving eight pages to wire the same
 * three-line template correctly. What each page then declares is the one thing that genuinely
 * differs: what KIND of thing its rows are.
 *
 * WHAT A ROW IS DECIDES EVERYTHING ELSE. `song` on the five track tables, `album` / `artist` /
 * `genre` on the three listings whose rows are containers — and the server expands the latter,
 * which is why ticking a screenful of artists sends eight ids rather than nine hundred. The two
 * player verbs go through `useSelectionActions`.
 *
 * ALL THREE VERBS SEND THE SAME PAIR, and that is what keeps them agreeing about two things a
 * reader would notice instantly if they diverged. Both services resolve `song` as exact track
 * ids with no music-only filter (QueueSelection, PlaylistAdditions), so a ticked audiobook
 * chapter can be played AND added; and both order what comes back album-then-disc-then-track,
 * so a book's chapters arrive in chapter order whichever button was pressed. The tempting
 * alternative — sending a track table's ticks as `{ tracks: [...] }` — writes them in the order
 * the boxes were CLICKED, which would make a checkbox a position, exactly what
 * PlaylistAdditions says it is not.
 *
 * A COMPLETED ACTION CLEARS THE TICKS, and it has to say so: the table clears only when the
 * QUESTION changes — a re-sort, a search, a filter — and none of these three change any of
 * those. Ticks that have been played or added describe nothing the reader is still asking for,
 * and on a table whose selection deliberately survives paging they would otherwise ride along
 * to the next page and into whatever was pressed there.
 *
 * ONLY ON SUCCESS, though. A failed press leaves the selection exactly as it was, which is what
 * makes pressing again the retry rather than a fresh round of ticking.
 *
 * THE LABELS CARRY THE DISTINCTION, NOT THE GLYPHS. Enqueue and add-to-playlist share
 * `playlist_add` — both put the selection at the end of a list, and that is what the glyph
 * draws — so what separates them is the word beside it, which a menu row has room for and a
 * toolbar button did not. Play takes `play`, the only one of the three that starts something.
 *
 * THE DIALOG IS A SIBLING OF THE MENU, NOT AN ITEM IN IT. "Add to playlist" is the one verb with
 * an argument to it, so it cannot act on press like the other two; a select nested inside the
 * panel would be a popover within a popover, with a save button that must dismiss neither.
 * Pressing it therefore closes the menu and opens the modal, which QueuePlaylistModal's banner
 * argues at length for the same control.
 *****************************************************************************/
import { computed, inject, ref } from "vue";
import { useI18n } from "vue-i18n";
import AddToPlaylistModal from "Components/Playlists/AddToPlaylistModal.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { AddablePlaylistSubject, AddToPlaylistBody } from "Composables/useAddToPlaylist";
import { useSelectionActions } from "Composables/useSelectionActions";
import { DATA_TABLE_KEY } from "Types/dataTable";

const props = defineProps<{
    /**
     * What one row of this table IS — `song` where the rows are tracks (the songs listing, an
     * album's or a book's own table), otherwise the container the row names. It is the only
     * thing that varies between the eight tables that wear this.
     */
    subject: AddablePlaylistSubject;
}>();

const { t } = useI18n();
const { busy, playSelection, enqueueSelection } = useSelectionActions();

/*
 * The table this sits in. Non-null asserted rather than guarded: rendering this outside a
 * DataTable is a mistake at the call site, not a state to degrade into, and a silent no-op
 * would hide it until somebody wondered why the buttons did nothing.
 */
const table = inject(DATA_TABLE_KEY)!;

/** Whether the modal is open — the only state this component owns. */
const choosingPlaylist = ref(false);

/** The ticked ids, read fresh on every use: the reader may tick more while the dialog is open. */
const selected = computed<string[]>(() => table.selectedIds.value);

/**
 * What to add — the same kind-and-ids pair the two player verbs send.
 *
 * A getter rather than a value, because the dialog evaluates it at the press: the reader may
 * tick more rows while it stands open, and what gets written is what is ticked then.
 */
const playlistBody = (): AddToPlaylistBody => ({ subject: props.subject, ids: selected.value });

/** DOM id of the popover, and the anchor name its panel is positioned against. */
const REFERENCE = "selectionActions";

/** Close the panel, by the DOM id it was given — the pattern SubjectMenu and UserMenu use. */
function closePopover(): void {
    document.getElementById(REFERENCE)?.hidePopover();
}

/**
 * Play the ticked rows, and drop the ticks only if that actually queued something.
 *
 * The menu is put away on success ONLY, so a selection that turned out to hold nothing playable
 * leaves the panel open under the toast explaining why — SubjectMenu's rule, for the same
 * reason: closing on the attempt hides the answer behind the thing that was pressed.
 */
async function play(): Promise<void> {
    if (await playSelection(props.subject, selected.value)) {
        closePopover();
        table.clearSelection();
    }
}

/** Queue the ticked rows, and drop the ticks only if that actually queued something. */
async function enqueue(): Promise<void> {
    if (await enqueueSelection(props.subject, selected.value)) {
        closePopover();
        table.clearSelection();
    }
}

/**
 * Hand over to the dialog: shut the menu, then open it.
 *
 * Unconditional, unlike the two above — this verb has not done anything yet, so there is no
 * success to wait for. The menu must go first regardless: it is in the top layer, and a modal
 * opening underneath a popover that is still up would be covered by it.
 */
function choosePlaylist(): void {
    closePopover();
    choosingPlaylist.value = true;
}
</script>

<template>
    <div v-if="selected.length > 0" class="selection-actions">
        <pop-over
            icon="more"
            :reference="REFERENCE"
            class-string="popover-button--rounded popover-button--subtle"
            :ariaLabel="t('components.datatable.selection.actions', { count: selected.length })"
            width="28ch"
        >
            <ul class="popover-list">
                <li>
                    <button type="button" class="popover-list-item" :disabled="busy" @click="play">
                        <!-- The spinner replaces the glyph rather than sitting beside it, so the
                             row keeps its width while a big selection resolves. -->
                        <icon :name="busy ? 'refresh' : 'play'" :size="1" :rotate="busy" />
                        {{ t("components.datatable.selection.play") }}
                    </button>
                </li>
                <li>
                    <button type="button" class="popover-list-item" :disabled="busy" @click="enqueue">
                        <icon :name="busy ? 'refresh' : 'playlist_add'" :size="1" :rotate="busy" />
                        {{ t("components.datatable.selection.enqueue") }}
                    </button>
                </li>
                <li>
                    <button type="button" class="popover-list-item" :disabled="busy" @click="choosePlaylist">
                        <icon name="playlist_add" :size="1" />
                        {{ t("components.datatable.selection.addToPlaylist") }}
                    </button>
                </li>
            </ul>
        </pop-over>

        <add-to-playlist-modal
            v-if="choosingPlaylist"
            :title="t('components.datatable.selection.modalTitle')"
            :body="playlistBody"
            @saved="table.clearSelection()"
            @close="choosingPlaylist = false"
        >
            <!-- The ROW count, not a track count: three ticked artists is the only number this
                 side knows, since what they expand to is the server's answer. -->
            <template #intro>{{ t("components.datatable.selection.intro", selected.length) }}</template>
        </add-to-playlist-modal>
    </div>
</template>

<style scoped lang="scss">
/* One trigger beside the selection count, and that is the whole of what this block is for: it
   holds the menu and the dialog together so the `v-if` can take both away at once.

   No gap, no wrapping, no width — there is a single visible child, and the toolbar (a flex row
   with its own gap) already places it. Anything more here would be styling a container against
   a layout its parent owns, which is what made a row of three buttons crowd the search field on
   everything but a very wide screen. */
.selection-actions {
    display: contents;
}
</style>
