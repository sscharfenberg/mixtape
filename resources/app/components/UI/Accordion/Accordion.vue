<script setup lang="ts">
/******************************************************************************
 * Accordion
 * A stack of disclosure sections — a header you press, a panel it reveals — with one named
 * slot per section. Built for the Audiobooks page's Authors and Narrators tabs, where the
 * header is a person and the panel is their shelf of books.
 *
 * SHAPED AFTER TabbedNavigation, deliberately: sections are declared as an array with string
 * ids, each renders `<slot :name="id">`, and the component owns the ARIA. A consumer never
 * touches visibility or wires up an aria-controls, which is the mistake a hand-rolled
 * disclosure stack makes and pays for.
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
 * `closeOther` decides whether opening one closes the rest — configurable rather than
 * chosen. It is TRUE by default: with eleven authors and a shelf of covers
 * under each, a stack that stays open is a page nobody can find their way back up.
 *****************************************************************************/
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";

/**
 * One fact in a section's header — a chip holding an icon, a number, and the word that says
 * what the number is.
 *
 * THE WORD SITS ON EITHER SIDE OF THE VALUE, and which side is a fact about the language
 * rather than about this component, which is why there are two fields instead of one and a
 * flag. A count reads as a phrase and takes its noun AFTER it ("3 Bücher", "1 Buch"); a
 * measurement reads as a labelled fact and takes its name BEFORE it ("Spielzeit 40:51:45").
 * Forcing both into one order gives you either "Bücher 3" or "40:51:45 Spielzeit", and both
 * read as mistakes.
 *
 * BOTH WORDS DISAPPEAR BELOW 480px, where the icon carries the meaning on its own and the
 * value is all the room there is. That is the whole reason the word cannot simply be part of
 * a pre-formatted string: something has to be able to hide it.
 */
export interface AccordionFact {
    /** Sprite icon, and the only thing naming the fact on a narrow screen. */
    icon: string;
    /** The number or clock, already formatted by the consumer against the reader's locale. */
    value: string;
    /** A name BEFORE the value ("Spielzeit"), already translated. Hidden below 480px. */
    label?: string;
    /** A noun AFTER the value ("Bücher"), already pluralised. Hidden below 480px. */
    unit?: string;
    /** Accessible name for the whole chip, since the icon is decorative and a word may be hidden. */
    title: string;
}

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
     * Optional facts shown after the heading, as one chip each.
     *
     * A LIST OF PARTS RATHER THAN ONE STRING. Joined by the consumer ("6 Bücher · 12:30:04")
     * this arrives as a sentence, and a sentence cannot be given a chip apiece — nor, the half
     * that actually forces the shape, drop its words on a narrow screen while keeping its
     * numbers.
     *
     * Still FORMATTED by the consumer, for the reason it always was: only it knows the
     * reader's locale and what the numbers mean.
     */
    facts?: AccordionFact[];
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
                    <!-- Down when closed, up when open — see the styles. Decorative: the state is
                         already spoken by aria-expanded, so announcing it twice would be noise. -->
                    <icon
                        name="chevron"
                        :size="1"
                        class="accordion__chevron"
                        :class="{ 'accordion__chevron--open': isOpen(section.id) }"
                    />
                    <icon v-if="section.icon" :name="section.icon" :size="1" />
                    <span class="accordion__label">{{ section.label }}</span>
                    <!-- One chip per fact. `title` names the whole chip because the icon is
                         decorative and one of the two words is gone below 480px — without it a
                         narrow screen would announce a bare number. -->
                    <span v-if="section.facts?.length" class="accordion__facts">
                        <span
                            v-for="fact in section.facts"
                            :key="fact.icon + fact.value"
                            class="accordion__fact"
                            :title="fact.title"
                        >
                            <icon :name="fact.icon" :size="1" />
                            <span v-if="fact.label" class="accordion__fact-word">{{ fact.label }}</span>
                            <span class="accordion__fact-value">{{ fact.value }}</span>
                            <span v-if="fact.unit" class="accordion__fact-word">{{ fact.unit }}</span>
                        </span>
                    </span>
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
@use "Abstracts/mixins" as m;
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

/* DOWN WHEN CLOSED, UP WHEN OPEN — "there is more below" turning into "put it away", which is
   the one thing a disclosure triangle is for.

   180°, because the sprite already points DOWN at rest (`chevron.svg`'s apex is its lowest
   point), so the closed state needs no transform at all. It was `rotate(90deg)` until
   2026-08-14, which pointed the glyph sideways and matched neither state — and the comment here
   claimed it pointed down when open, which is how it went unnoticed.

   The rotation is the only motion here and takes the guard every transition in this app does. */
.accordion__chevron {
    @media (prefers-reduced-motion: no-preference) {
        transition: transform ti.$c-button;
    }

    &--open {
        transform: rotate(180deg);
    }
}

.accordion__label {
    font-weight: 700;
}

/* The facts trail the name: `margin-inline-start: auto` pushes them to the far edge, where the
   eye finds them in one place down a list of eleven. */
.accordion__facts {
    display: flex;
    align-items: center;

    /* `flex-end` as well as the auto margin, because they answer different questions: the margin
       puts the BLOCK at the trailing edge of the header, and this puts each of its own wrapped
       LINES there too.

       PRE-EMPTIVE rather than a fix — the two chips have not been made to stack yet, since the
       words go at 480px and what is left is an icon and a number. It is here because `flex-wrap`
       is, and because the search rows had exactly this defect for real: a wrapped line of facts
       flush left under a block sitting on the right. The owner's call there (2026-08-14) is that
       facts belong at the trailing edge on every line, and this is that rule wherever this
       component's row does wrap. */
    justify-content: flex-end;

    flex-wrap: wrap;

    margin-inline-start: auto;
    gap: map.get(s.$c-accordion, "fact-gap");
}

/* EACH FACT IS ITS OWN CHIP, rather than the pair sharing one dialled-down
   sentence — "6 Bücher · 12:30:04" — with a middle dot holding it together.

   The fill is a rung off the trigger's own, which is the only thing that makes a chip visible
   here at all: see the colour partial, where the facts card's tile colour turns out to be the
   same pair as the widget cell this sits on. */
.accordion__fact {
    display: flex;
    align-items: center;

    padding: map.get(s.$c-accordion, "fact-padding");
    gap: map.get(s.$c-accordion, "fact-inner-gap");

    background-color: map.get(c.$c-accordion, "fact-background");
    color: map.get(c.$c-accordion, "fact-surface");

    border-radius: map.get(s.$c-accordion, "fact-radius");

    font-size: 0.9rem;

    /* Never broken across two lines: a chip that wrapped between its number and its word would
       read as two facts, and the row already wraps as whole chips. */
    white-space: nowrap;
}

/* THE WORDS GO BELOW 480px, leaving the icon and the number. A phone header
   has room for the credit's NAME and a number, and the icon is what says which number it is,
   which is the job an icon in a chip is for.

   `display: none` rather than a visually-hidden class, deliberately: the chip carries a `title`
   naming the whole fact, so nothing is lost to a screen reader by removing the word outright,
   and a hidden-but-present word would still be read out twice. */
.accordion__fact-word {
    display: none;

    color: map.get(c.$c-accordion, "fact-label");

    @include m.mq("portrait") {
        display: inline;
    }
}

/* The number is the fact; the words either side of it are furniture. Same split FactPair makes
   inside its own tiles, and the reason the words are the half that may disappear. */
.accordion__fact-value {
    font-weight: 700;
}

.accordion__panel {
    padding-block-start: map.get(s.$c-widget, "cell-gap");
}
</style>
