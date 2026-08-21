<?php

namespace App\Enums;

/**
 * Where a check gets its facts, which the report prints beside every finding count.
 *
 * IT IS NOT AN IMPLEMENTATION DETAIL, which is why it is in the document rather than only in
 * the code. A database answer is only as fresh as the last scan, and a disk answer is true
 * now — so a reader working through the report needs to know which they are reading. It is
 * also what makes the encoding check useful in the one moment it exists for: renaming files
 * and re-scanning are two steps, and between them the disk is right and the database is not.
 */
enum AuditSource: string
{
    /** Read from `tracks` / `collections` — true as of the last `app:update`. */
    case Database = 'database';

    /** Read by walking the library shares — true now. */
    case Disk = 'disk';

    /** Both, compared against each other. Only scan drift needs this. */
    case Both = 'both';

    /** What the summary table prints in its source column. */
    public function label(): string
    {
        return match ($this) {
            self::Database => 'database',
            self::Disk => 'disk',
            self::Both => 'disk + database',
        };
    }
}
