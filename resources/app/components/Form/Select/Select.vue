<script setup lang="ts">
/******************************************************************************
 * Select
 * A custom single-select dropdown (ported from cantrip.me's MonoSelect,
 * renamed). A native <select> can't be styled to the retro palette or show
 * per-option thumbnails, so this is a button + ARIA listbox built by hand: the
 * trigger button shows the current label, and the options panel is a `popover`
 * promoted into the browser TOP LAYER (via the Popover API) so it escapes any
 * ancestor overflow / clipping / stacking context — e.g. when the Select sits
 * inside the DataTable pagination bar or a modal. CSS anchor positioning pins the
 * panel under the button.
 *
 * Emits `change` with the chosen value (or "" when cleared). The parent owns the
 * value via the `selected` prop; internal state mirrors it and re-syncs when the
 * prop changes. Long lists get multi-letter typeahead while open.
 *
 * All styling lives in the scoped block below (consuming the contextual
 * c/s/ti/z.$c-select tokens) so the component is self-contained.
 *****************************************************************************/
import { computed, nextTick, onMounted, onUnmounted, ref, useId, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
const { t } = useI18n();

/** One selectable entry. `imageUrl`/`meta` are optional decoration for richer lists. */
interface SelectOption {
    value: string;
    label: string;
    /** Optional thumbnail rendered before the label (e.g. a set icon). */
    imageUrl?: string;
    /** Optional right-aligned secondary text (e.g. a year) — low-priority metadata. */
    meta?: string;
}
const props = withDefaults(
    defineProps<{
        /** The choices to render in the listbox. */
        options: SelectOption[];
        /** Currently selected value (owned by the parent). */
        selected?: string;
        /** Placeholder shown when nothing is selected; falls back to an i18n default. */
        placeholder?: string;
        /** Optional leading addon icon (renders a fused icon box on the left). */
        addonIcon?: string;
        /** Sort options alphabetically by label ("other" always sinks to the bottom). */
        sort?: boolean;
        /** Max width of the control. */
        max?: string;
        /** Show the clear button when a value is selected. */
        clearable?: boolean;
        /** Disable the trigger + clear buttons. */
        disabled?: boolean;
    }>(),
    { sort: true, max: "100%", clearable: true, disabled: false }
);
/** The placeholder to show — the prop, or the shared i18n default. */
const effectivePlaceholder = computed(() => props.placeholder ?? t("components.select.placeholder"));
/**
 * Options in render order. When `sort` is on, alphabetical by label, but the
 * catch-all "other" value is always pinned to the bottom so it never competes
 * with named options.
 */
const effectiveOptions = computed(() =>
    props.sort
        ? [...props.options].sort((a, b) => {
              if (a.value === "other") return 1;
              if (b.value === "other") return -1;
              return a.label.localeCompare(b.label);
          })
        : props.options
);
const emit = defineEmits(["change"]);
// Unique ids tie the trigger button and listbox together for ARIA + anchor positioning.
const uid = useId();
const anchorName = `--select-${uid}`;
const buttonAnchorName = `--select-button-${uid}`;
const buttonId = `select-button-${uid}`;
const listboxId = `select-listbox-${uid}`;
const menuOpen = ref(false);
const selectedValue = ref(props.selected);
// Click-outside detection to close the dropdown.
const dropdown = useTemplateRef<HTMLDivElement>("dropdown");
// The listbox element — promoted into the top layer via the Popover API on open.
const listbox = useTemplateRef<HTMLDivElement>("listbox");
/** Human-readable label for the current value, shown in the trigger. */
const selectedLabel = computed(() => props.options.find(o => o.value === selectedValue.value)?.label);
/** Thumbnail (if any) for the current value, shown in the trigger. */
const selectedImageUrl = computed(() => props.options.find(o => o.value === selectedValue.value)?.imageUrl);
/**
 * Set the value and emit `change` if it actually changed, then close the menu.
 * @param value - The chosen option value ("" clears the selection).
 */
const select = (value: string) => {
    if (value !== selectedValue.value) {
        selectedValue.value = value;
        emit("change", value);
    }
    menuOpen.value = false;
};
/** Toggle the dropdown open/closed. */
const toggleMenu = () => {
    menuOpen.value = !menuOpen.value;
};
/** Scroll the currently-selected option into view (for long lists). */
const scrollSelectedIntoView = () => {
    listbox.value
        ?.querySelector<HTMLElement>(`button[data-value="${selectedValue.value}"]`)
        ?.scrollIntoView({ block: "nearest" });
};
/**
 * Close the dropdown on a click outside the component.
 * @param ev - The native click event.
 */
const onClickOutSide = (ev: MouseEvent) => {
    if (!(dropdown.value === ev.target || dropdown.value?.contains(ev.target as Node))) {
        menuOpen.value = false;
    }
};
// Keep internal state in sync when the parent updates `selected` externally.
watch(
    () => props.selected,
    value => {
        selectedValue.value = value;
    },
    { immediate: true }
);
/**
 * Multi-letter typeahead — while the menu is open, successive printable keys
 * within a 500ms idle window build a buffer and jump to the first option whose
 * (case-folded) label starts with it. After 500ms idle the buffer resets, so
 * "Fo" + pause + "B" jumps from "Fo…" to "B…" instead of searching "fob".
 */
let typeaheadBuffer = "";
let typeaheadTimer: ReturnType<typeof setTimeout> | null = null;
const onListboxKeydown = (event: KeyboardEvent) => {
    if (!menuOpen.value) return;
    if (event.key.length !== 1) return; // skip Tab, Enter, Arrow*, etc.
    if (event.metaKey || event.ctrlKey || event.altKey) return;
    typeaheadBuffer += event.key.toLowerCase();
    if (typeaheadTimer !== null) clearTimeout(typeaheadTimer);
    typeaheadTimer = setTimeout(() => {
        typeaheadBuffer = "";
    }, 500);
    const match = effectiveOptions.value.find(o => o.label.toLowerCase().startsWith(typeaheadBuffer));
    if (match === undefined) return;
    event.preventDefault();
    const buttons = listbox.value?.querySelectorAll<HTMLButtonElement>("button[data-value]");
    for (const btn of buttons ?? []) {
        if (btn.dataset.value === match.value) {
            btn.scrollIntoView({ block: "nearest" });
            btn.focus();
            break;
        }
    }
};
/**
 * Whether the options panel flipped ABOVE the button — the browser's
 * `position-try: flip-block` fallback kicked in because there was no room below.
 * Drives the border-radius so the button + list read as ONE fused object in
 * whichever direction it opens: the seam edge is squared, the outer edge rounded.
 * (CSS can't do this alone — `@position-try` may not set border/radius — so we
 * detect the chosen placement here and mirror the borders via a class.)
 */
const flippedUp = ref(false);
/**
 * Read the panel's actual (post-flip) position and set `flippedUp`.
 * getBoundingClientRect forces layout, so it reflects the fallback the browser
 * applied; the panel is "up" when its top sits above the button's top.
 */
function measureFlip() {
    const lb = listbox.value;
    const btn = document.getElementById(buttonId);
    if (!lb || !btn) return;
    flippedUp.value = lb.getBoundingClientRect().top < btn.getBoundingClientRect().top;
}
// position-try can re-flip on scroll/resize while the menu is open, so re-measure
// (rAF-throttled) to keep the fused-border direction in sync with the placement.
let measureRaf: number | null = null;
function scheduleMeasure() {
    if (measureRaf !== null) return;
    measureRaf = requestAnimationFrame(() => {
        measureRaf = null;
        measureFlip();
    });
}
// Promote the listbox into the top layer whenever it opens (escapes ancestor
// overflow/clipping/stacking contexts), and gate the document-level typeahead
// listener to only run while the menu is visible. v-if removes the element on
// close, so no explicit hidePopover() is needed.
watch(menuOpen, async open => {
    if (open) {
        await nextTick();
        listbox.value?.showPopover?.();
        measureFlip();
        requestAnimationFrame(measureFlip); // re-check after layout settles (belt + suspenders)
        scrollSelectedIntoView();
        document.addEventListener("keydown", onListboxKeydown);
        window.addEventListener("scroll", scheduleMeasure, { passive: true, capture: true });
        window.addEventListener("resize", scheduleMeasure);
    } else {
        document.removeEventListener("keydown", onListboxKeydown);
        window.removeEventListener("scroll", scheduleMeasure, true);
        window.removeEventListener("resize", scheduleMeasure);
        flippedUp.value = false;
        typeaheadBuffer = "";
        if (typeaheadTimer !== null) {
            clearTimeout(typeaheadTimer);
            typeaheadTimer = null;
        }
    }
});
onMounted(() => {
    document.addEventListener("click", onClickOutSide);
});
onUnmounted(() => {
    document.removeEventListener("click", onClickOutSide);
    document.removeEventListener("keydown", onListboxKeydown);
    window.removeEventListener("scroll", scheduleMeasure, true);
    window.removeEventListener("resize", scheduleMeasure);
    if (measureRaf !== null) cancelAnimationFrame(measureRaf);
    if (typeaheadTimer !== null) clearTimeout(typeaheadTimer);
});
</script>

<template>
    <div class="form-select" ref="dropdown" :style="{ 'max-width': max, 'anchor-name': anchorName }">
        <span v-if="addonIcon" class="form-select__addon"><icon :name="addonIcon" /></span>
        <button
            type="button"
            :id="buttonId"
            class="form-select__button"
            :class="{
                open: menuOpen,
                'form-select__button--no-clear': !clearable,
                'form-select__button--up': menuOpen && flippedUp,
                'form-select__button--down': menuOpen && !flippedUp
            }"
            :style="{ 'anchor-name': buttonAnchorName }"
            :aria-expanded="menuOpen"
            :aria-controls="listboxId"
            aria-haspopup="listbox"
            :disabled="disabled"
            @click.prevent="toggleMenu"
        >
            <span v-if="selectedValue" class="form-select__selected">
                <img v-if="selectedImageUrl" :src="selectedImageUrl" class="form-select__option-image" alt="" />
                {{ selectedLabel }}
            </span>
            <span v-else>{{ effectivePlaceholder }}</span>
            <span class="form-select__caret" aria-hidden="true" />
        </button>
        <button
            v-if="selectedValue && clearable && !disabled"
            type="button"
            class="form-select__clear"
            :style="{ 'position-anchor': buttonAnchorName }"
            @click.prevent="select('')"
            :aria-label="$t('components.select.clear')"
        >
            <icon name="close" aria-hidden="true" />
        </button>
        <div
            v-if="menuOpen"
            ref="listbox"
            :id="listboxId"
            role="listbox"
            :aria-labelledby="buttonId"
            popover="manual"
            class="form-select__options"
            :class="{ 'form-select__options--up': flippedUp }"
            :style="{ 'position-anchor': anchorName }"
        >
            <div class="form-select__scroll">
                <button
                    v-for="option in effectiveOptions"
                    :key="option.value"
                    type="button"
                    role="option"
                    :data-value="option.value"
                    :aria-selected="selectedValue === option.value"
                    :class="{ 'form-select__option--selected': selectedValue === option.value }"
                    class="form-select__option"
                    @click.prevent="select(option.value)"
                >
                    <img v-if="option.imageUrl" :src="option.imageUrl" class="form-select__option-image" alt="" />
                    <span class="form-select__option-label">{{ option.label }}</span>
                    <span v-if="option.meta" class="form-select__option-meta">{{ option.meta }}</span>
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

@layer components {
    .form-select {
        display: flex;
        position: relative;

        flex-grow: 1;

        // leading addon (optional) — a fused icon box on the left, styled from the
        // select's own palette so the component stays self-contained.
        &__addon {
            display: flex;
            align-items: center;

            padding: 0.75ex 1ch 0.75ex 1.5ch;
            border: map.get(s.$c-select, "border") solid map.get(c.$c-select, "border");

            background-color: map.get(c.$c-select, "background");
            color: map.get(c.$c-select, "surface");

            border-radius: map.get(s.$c-select, "radius") 0 0 map.get(s.$c-select, "radius");
        }

        &:has(.form-select__addon) .form-select__button {
            border-left-width: 0;

            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        &:has(.form-select__button.open) .form-select__addon {
            background-color: map.get(c.$c-select, "background-open");
            color: map.get(c.$c-select, "surface-open");
            border-color: map.get(c.$c-select, "border-open");
        }

        // fuse the addon's left corners to the open panel: square the seam side
        // (bottom-left when the list opens down, top-left when it flips up).
        &:has(.form-select__button--down) .form-select__addon {
            border-bottom-left-radius: 0;
        }

        &:has(.form-select__button--up) .form-select__addon {
            border-top-left-radius: 0;
        }
    }

    .form-select__button {
        display: flex;
        position: relative;
        align-items: center;

        width: 100%;
        padding: 0.75ex calc(3ch + #{map.get(s.$c-select, "caret") * 2} + #{map.get(s.$c-select, "clear")}) 0.75ex 2ch;
        border: map.get(s.$c-select, "border") solid map.get(c.$c-select, "border");

        background-color: map.get(c.$c-select, "background");
        color: map.get(c.$c-select, "surface");
        outline: 0;
        border-radius: map.get(s.$c-select, "radius");

        line-height: map.get(s.$c-select, "height");

        &:not(:disabled) {
            cursor: pointer;
        }

        &.open {
            background-color: map.get(c.$c-select, "background-open");
            color: map.get(c.$c-select, "surface-open");
            border-color: map.get(c.$c-select, "border-open");

            .form-select__caret {
                transform: translateY(-50%) rotate(180deg);
            }
        }

        // not clearable → no clear button is rendered, so don't reserve its space.
        &--no-clear {
            padding-right: calc(3ch + #{map.get(s.$c-select, "caret") * 2});
        }

        // fuse to the open panel by squaring the seam-side corners: `--down` (panel
        // below) flattens the bottom, `--up` (panel flipped above) flattens the top.
        &--down {
            border-bottom-right-radius: 0;
            border-bottom-left-radius: 0;
        }

        &--up {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }
    }

    .form-select__caret {
        display: block;
        position: absolute;
        top: 50%;
        right: 2ch;

        width: 0;
        height: 0;
        border-width: map.get(s.$c-select, "caret") map.get(s.$c-select, "caret") 0;

        transform: translateY(-50%);

        border-style: solid;
        border-color: map.get(c.$c-select, "caret") transparent transparent;
    }

    .form-select__clear {
        position: absolute;
        top: calc(anchor(top) + #{map.get(s.$c-select, "border")});
        right: calc(anchor(right) + 3ch + #{map.get(s.$c-select, "caret") * 2});
        bottom: calc(anchor(bottom) + #{map.get(s.$c-select, "border")});

        width: map.get(s.$c-select, "clear");
        border: 0;

        background: transparent;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-select linear,
                color ti.$c-select linear;
        }

        &:hover {
            background: map.get(c.$c-select, "clear-background");
            color: map.get(c.$c-select, "clear-surface");
        }
    }

    .form-select__options {
        position: fixed;
        inset: unset;
        top: anchor(bottom);
        right: anchor(right);
        left: anchor(left);
        z-index: z.$c-select;

        overflow: hidden;

        width: auto;
        height: auto;
        padding: 1ex 0;
        border: map.get(s.$c-select, "border") solid map.get(c.$c-select, "border");

        // default: opens DOWNWARD, fused to the button's bottom edge — no top border
        // (the button's bottom border is the seam), square top, rounded bottom.
        border-top-width: 0;
        margin: 0;

        background-color: map.get(c.$c-select, "options-background");
        border-bottom-right-radius: map.get(s.$c-select, "radius");
        border-bottom-left-radius: map.get(s.$c-select, "radius");

        // Flip ABOVE the button when there's no room below (a short footer at the
        // viewport bottom) so the list is never clipped by the browser edge. The
        // `--up` class (set from JS once the browser picks the flip fallback)
        // mirrors the fused borders: no bottom border, square bottom, rounded top.
        position-try-fallbacks: flip-block;

        &--up {
            border-top-width: map.get(s.$c-select, "border");
            border-bottom-width: 0;

            border-radius: map.get(s.$c-select, "radius") map.get(s.$c-select, "radius") 0 0;
        }
    }

    .form-select__scroll {
        display: flex;
        flex-direction: column;

        overflow-y: auto;

        max-height: 30vh;
        padding: 0 1ch;
    }

    .form-select__option {
        display: flex;
        position: relative;
        align-items: center;

        width: 100%;
        padding: map.get(s.$c-select, "option");
        border: 0;

        color: map.get(c.$c-select, "option", "surface");

        text-align: left;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-select linear,
                color ti.$c-select linear;
        }

        &:not(:first-child) {
            border-top: map.get(s.$c-select, "border") solid map.get(c.$c-select, "border");
        }

        &:not(.form-select__option--selected, :hover):nth-child(odd) {
            background-color: map.get(c.$c-select, "option", "background-odd");
        }

        &:not(.form-select__option--selected, :hover):nth-child(even) {
            background-color: map.get(c.$c-select, "option", "background-even");
        }

        &:not(.form-select__option--selected):hover {
            background-color: map.get(c.$c-select, "option", "background-hover");
            color: map.get(c.$c-select, "option", "surface-hover");
        }

        &--selected {
            background-color: map.get(c.$c-select, "option", "background-selected");
            color: map.get(c.$c-select, "option", "surface-selected");
        }

        // subtract the __scroll padding from the radius so the last row's corners sit right.
        &:last-child {
            border-bottom-right-radius: calc(#{map.get(s.$c-select, "radius")} - 1ch);
            border-bottom-left-radius: calc(#{map.get(s.$c-select, "radius")} - 1ch);
        }
    }

    // Thumbnail before an option label / selected label — shared by the listbox
    // option and the trigger, so it lives at the form-select level.
    .form-select__option-image {
        flex-shrink: 0;

        width: map.get(s.$c-select, "image", "width");
        height: map.get(s.$c-select, "image", "width");
        margin-right: map.get(s.$c-select, "image", "margin");

        object-fit: contain;

        vertical-align: middle;
    }

    // Primary label — takes the remaining space so `meta` can sit flush right.
    .form-select__option-label {
        overflow: hidden;
        min-width: 0;

        flex: 1 1 auto;

        white-space: nowrap;
        text-overflow: ellipsis;
    }

    // Right-aligned secondary text (muted, tabular) — low-priority metadata.
    .form-select__option-meta {
        flex-shrink: 0;

        opacity: 0.7;

        margin-left: 1ch;

        font-variant-numeric: tabular-nums;
    }

    .form-select__selected {
        display: inline-flex;
        align-items: center;

        overflow: hidden;
        min-width: 0;

        white-space: nowrap;
        text-overflow: ellipsis;
    }
}
</style>
