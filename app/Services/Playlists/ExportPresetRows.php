<?php

declare(strict_types=1);

namespace App\Services\Playlists;

use App\Models\ExportPreset;
use Illuminate\Support\Collection;

/**
 * One preset as the client draws it — the shape behind `resources/app/types/exportPresets.ts`.
 *
 * A CLASS RATHER THAN A PRIVATE MAPPER IN EACH CONTROLLER, because there are two consumers and
 * they must agree: the presets page lists them, and the playlist page hands the same rows to
 * the export modal's picker. Written twice, the two would drift — and the drift is invisible,
 * since each surface is self-consistent and only the reader notices that the preset they
 * marked as default is not the one the dialog opened on.
 *
 * NOTHING IS PRE-FORMATTED AND NOTHING IS COMPOSED. `format` and `encoding` travel as their
 * stored values, which the client turns into the labels the export modal already has words for
 * — a server-side "einfache .m3u, UTF-8" would be German on a page being read in English, the
 * rule docs/search.md states for its own second line.
 */
final class ExportPresetRows
{
    /**
     * @param  Collection<int, ExportPreset>  $presets  already in reading order
     * @return list<array<string, mixed>>
     */
    public static function for(Collection $presets): array
    {
        return $presets->map(fn (ExportPreset $preset): array => self::one($preset))->all();
    }

    /** @return array<string, mixed> */
    public static function one(ExportPreset $preset): array
    {
        return [
            'id' => $preset->id,
            'name' => $preset->name,
            'format' => $preset->format,
            'encoding' => $preset->encoding,
            // camelCase on the wire like every other row type, over a snake_case column.
            'pathPrefix' => $preset->path_prefix,
            'isDefault' => $preset->is_default,
        ];
    }
}
