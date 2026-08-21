<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Contracts;

/**
 * A check whose findings do not fit a table.
 *
 * ONE CHECK NEEDS THIS and it is worth the interface rather than a special case in the renderer.
 * The encoding audit's findings are not a list of rows: half of what it finds is INVISIBLE on
 * screen — a private-use character a tagger swapped in, an exotic space, a combining accent that
 * renders exactly like a normal one — so a name in a cell tells the reader nothing. It needs a
 * character inventory with code points and Unicode names, grouped by what to rename, which is a
 * document rather than a table.
 *
 * Everything else answers in rows, and a renderer that asked every check for its own Markdown
 * would put twenty-five layouts in the codebase for one that needed it.
 */
interface RendersOwnSection
{
    /**
     * The body of this check's section, as Markdown at heading level 3 and below.
     *
     * Called after {@see Check::run}, so an implementation may render from what that found rather
     * than asking the library again.
     */
    public function sectionBody(): string;
}
