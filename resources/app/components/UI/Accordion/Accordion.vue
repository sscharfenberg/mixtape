<script setup lang="ts">
/******************************************************************************
 * Accordion
 * A stack of disclosure sections — a header you press, a panel it reveals — with one named
 * slot per section. Built for the Audiobooks page's Authors and Narrators tabs, where the
 * header is a person and the panel is their shelf of books.
 *
 * SHAPED AFTER TabbedNavigation, deliberately: sections are declared as an array with string
 * ids, each renders `<slot :name="id">`, and the component owns the ARIA. A consumer never
 * touches visibility or wires up an aria-controls, which is the mistake the legacy tab strip
 * made and paid for.
 *
 * THE DISCLOSURE PATTERN, not tabs. Each header is a real `<button aria-expanded>` pointing
 * at its own region — so a screen reader says "collapsed/expanded" and the whole stack can be
 * open at once, which a tablist cannot express. Arrow-key roving is deliberately not
 * implemented: the ARIA authoring practices make it optional for accordions, every header is
 * already in the tab order as a button, and a roving tabindex over an unknown number of
 * sections buys little for what it costs to get right.
 *
 * WHICH SECTIONS ARE OPEN IS A MODEL, and always a LIST even when only one may be open at a
 * time — one shape for both modes, so a consumer switching `closeOther` changes nothing else.
 * Bind it with `v-model:open` to own the state from the page, which is what makes a section
 * deep-linkable: the Audiobooks page reads an id out of the URL and writes one back, so a
 * link can open on one author. Pass it once (or not at all) and the stack keeps its own.
 *
 * `closeOther` decides whether opening one closes the rest (the owner's call: configurable
 * rather than chosen). It is TRUE by default — with eleven authors and a shelf of covers
 * under each, a stack that stays open is a page nobody can find their way back up.
 *****************************************************************************/
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";

/** One section: its id, its heading, and the optional extras the heading carries. */
export interface AccordionSection {
    /**
     * Stable identifier — it names the slot that fills the panel, is the value `open`
     * carries, and is what a URL would point at. So it should read as the thing the section
     * shows rather than as a position.
     */
    id: string;
    /** The visible, already-translated heading. */
    label: string;
    /** Optional sprite icon rendered before the heading. */
    icon?: string;
    /**
     * Optional already-formatted facts shown after the heading — "6 Bücher · 12:30:04".
     * Formatted by the consumer, because only it knows the reader's locale and what the
     * numbers mean.
     */
    meta?: string;
}

const props = withDefaults(
    defineProps<{
        /**
         * Unique name for this stack, used to build the header / panel DOM ids. Two stacks on
         * one page need different names or their aria-controls would cross-wire.
         */
        name: string;
        /** The sections, in display order. Each needs a matching named slot. */
        sections: AccordionSection[];
        /**
         * Whether opening a section closes the others. True by default; false lets any number
         * stand open at once.
         */
        closeOther?: boolean;
    }>(),
    { closeOther: true }
);

/**
 * The ids currently open.
 *
 * Optional in both directions, like TabbedNavigation's selection: unbound it is ordinary
 * internal state, and bound with `v-model:open` it hands the page control — which is how an
 * author becomes linkable. Empty by default, so the stack opens closed and the first thing a
 * reader sees is the whole list of names.
 */
const open = defineModel<string[]>("open", { default: () => [] });

/**
 * Only ids that actually exist, so a stale one — out of a URL, or a book that has since been
 * re-tagged — degrades to a closed stack instead of a section nobody can see or close.
 */
const openIds = computed(() => {
    const known = new Set(props.sections.map(section => section.id));

    return open.value.filter(id => known.has(id));
});

/** Whether one section stands open. */
const isOpen = (id: string): boolean => openIds.value.includes(id);

/**
 * Toggle one section, honouring `closeOther`.
 *
 * Closing is always allowed — including closing the last open one, so a reader can collapse
 * the stack back to a plain list of names. An accordion that insists on keeping one section
 * open is a tablist wearing the wrong clothes.
 */
const toggle = (id: string): void => {
    if (isOpen(id)) {
        open.value = openIds.value.filter(openId => openId !== id);

        return;
    }

    open.value = props.closeOther ? [id] : [...openIds.value, id];
};

/** The header's DOM id, which its panel points back at with aria-labelledby. */
const headerId = (id: string): string => `${props.name}-accordion-header-${id}`;

/** The panel's DOM id, which its header points at with aria-controls. */
const panelId = (id: string): string => `${props.name}-accordion-panel-${id}`;
</script>

<template>
    <div class="accordion">
        <section v-for="section in sections" :key="section.id" class="accordion__section">
            <h3 class="accordion__heading">
                <button
                    :id="headerId(section.id)"
                    type="button"
                    class="accordion__trigger"
                    :aria-expanded="isOpen(section.id)"
                    :aria-controls="panelId(section.id)"
                    @click="toggle(section.id)"
                >
                    <!-- Rotates to point down when open. Decorative: the state is already
                         spoken by aria-expanded, so announcing it twice would be noise. -->
                    <icon
                        name="chevron"
                        :size="1"
                        class="accordion__chevron"
                        :class="{ 'accordion__chevron--open': isOpen(section.id) }"
                    />
                    <icon v-if="section.icon" :name="section.icon" :size="1" />
                    <span class="accordion__label">{{ section.label }}</span>
                    <span v-if="section.meta" class="accordion__meta">{{ section.meta }}</span>
                </button>
            </h3>
            <!-- `v-if` rather than hidden-but-rendered: a section's panel is a grid of covers,
                 and eleven of them built up front would be eleven shelves of <img> nobody has
                 asked for. -->
            <div
                v-if="isOpen(section.id)"
                :id="panelId(section.id)"
                role="region"
                :aria-labelledby="headerId(section.id)"
                class="accordion__panel"
            >
                <slot :name="section.id" />
            </div>
        </section>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.accordion {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

.accordion__section {
    display: flex;
    flex-direction: column;
}

/* The heading element is structural — it puts each section into the document outline, which
   is what lets a screen reader jump between them. Its own look comes from the button. */
.accordion__heading {
    margin: 0;

    font-size: inherit;
    font-weight: inherit;
}

.accordion__trigger {
    display: flex;
    align-items: center;

    width: 100%;

    padding: map.get(s.$c-widget, "cell-padding");
    border: 0;
    gap: 0.5ch;

    background-color: map.get(c.$c-widget, "cell-background");
    color: map.get(c.$c-widget, "surface");

    border-radius: map.get(s.$c-widget, "cell-radius");

    font-size: 1.1rem;
    text-align: start;

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition: background-color ti.$c-button;
    }
}

/* Points down when the section is open. The rotation is the only motion here and takes the
   guard every transition in this app does. */
.accordion__chevron {
    @media (prefers-reduced-motion: no-preference) {
        transition: transform ti.$c-button;
    }

    &--open {
        transform: rotate(90deg);
    }
}

.accordion__label {
    font-weight: 700;
}

/* The facts trail the name and are dialled down: they describe the section, they are not what
   a reader is scanning for. `margin-inline-start: auto` pushes them to the far edge, where
   the eye finds them in one place down a list of eleven. */
.accordion__meta {
    margin-inline-start: auto;

    color: map.get(c.$c-widget, "footer-surface");

    font-size: 0.9rem;
}

.accordion__panel {
    padding-block-start: map.get(s.$c-widget, "cell-gap");
}
</style>
