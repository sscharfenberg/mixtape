<?php

namespace App\Enums;

/**
 * Audio channel layout, read from the mp3 stream by the library scanner. Ported
 * verbatim from the legacy `config/collection.php` enum; on Postgres an `enum`
 * column compiles to `varchar` + a value CHECK (data-model.md → (c), portability
 * notes).
 */
enum Channel: string
{
    case Stereo = 'stereo';
    case DualMono = 'dual_mono';
    case JointStereo = 'joint_stereo';
    case Mono = 'mono';
}
