<?php

namespace App\Enums;

/**
 * The four kinds of thing `app:audit` reports, which is also the order the document runs in.
 *
 * A GROUP IS A PROMISE ABOUT WHAT A FINDING MEANS, not a severity. Structure and hygiene are
 * both faults — something in the library is wrong and the reader can fix it — but a queue is
 * not: most of its entries are legitimate and the reader is being asked to look, not to
 * correct. Mixing those in one list is what makes a report get ignored, because a reader who
 * finds three false alarms in a row stops trusting the ones above them.
 */
enum AuditGroup: string
{
    /** How far the database has drifted from the disk — read first, because it qualifies everything after it. */
    case Drift = 'drift';

    /** Something about how the library is organised: albums that are short, split, merged, unnameable. */
    case Structure = 'structure';

    /** A tag that is missing or implausible. Usually clean on a well-kept collection, and the first thing wrong with a new one. */
    case Hygiene = 'hygiene';

    /** Candidates for a human to look at, where most entries are expected to be fine. */
    case Queue = 'queue';

    /** The heading this group gets in the report. */
    public function title(): string
    {
        return match ($this) {
            self::Drift => 'Scan drift',
            self::Structure => 'Library structure',
            self::Hygiene => 'Tag hygiene',
            self::Queue => 'Review queues',
        };
    }

    /**
     * The sentence under that heading, which is where a group's promise is actually made.
     *
     * Written for a reader meeting the report for the first time and deciding whether to act on
     * it — particularly the queue's, which has to say outright that a finding there is not
     * necessarily a fault, or the section reads as 113 mistakes.
     */
    public function blurb(): string
    {
        return match ($this) {
            self::Drift => 'How far the database has drifted from the files on disk. Every database check below is only '
                .'true as of the last scan, and nothing records when that was — so this is the caveat, in numbers. '
                .'Two zeroes mean the rest of this document describes the disk as it is now.',
            self::Structure => 'Faults in how the library is organised. Each of these is fixable, and the fix is in a '
                .'tagger or a file manager rather than in MixTape.',
            self::Hygiene => 'Tags that are missing or cannot be right. A meticulously tagged collection reports nothing '
                .'here; a new one usually reports plenty.',
            self::Queue => 'NOT FAULTS — candidates for a human to look at. Most entries in these sections are '
                .'legitimate, and the point of listing them is that only you can tell which are not.',
        };
    }
}
