<?php

namespace App\Http\Requests\Shares;

use App\Http\Requests\Shares\Concerns\AuthorizesShareTrack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards a shared track's audio (`GET /s/{share}/tracks/{track}/stream`).
 *
 * Authorization only — a GET carries no fields. The two checks, and why every refusal is a
 * 404, are in AuthorizesShareTrack.
 */
class ShareStreamRequest extends FormRequest
{
    use AuthorizesShareTrack;
}
