<?php

namespace App\Enums;

/**
 * Audio channel layout, read from the mp3 stream by the library scanner. On Postgres
 * an `enum` column compiles to `varchar` + a value CHECK (data-model.md → Indexes,
 * portability notes).
 */
enum Channel: string
{
    case Stereo = 'stereo';
    case DualMono = 'dual_mono';
    case JointStereo = 'joint_stereo';
    case Mono = 'mono';
}
