<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ScorePasswordRequest;
use Illuminate\Http\JsonResponse;
use ZxcvbnPhp\Zxcvbn;

/**
 * Server-side password strength for the live registration meter. Returns the
 * zxcvbn score (0–4) for the submitted password so the
 * frontend PasswordStrength meter reflects exactly what the PasswordEntropy
 * validation rule will accept (score ≥ 3).
 *
 * Deliberately a plain web route, not a data API: it is a single stateless
 * utility — reads a password, returns a number, changes nothing — so it stays
 * within the "no REST API" convention (which is about not exposing the app's
 * data over REST). Invoked by usePasswordEntropy via fetch.
 */
class EntropyController extends Controller
{
    /**
     * Score the submitted password (`p`) with zxcvbn and return `{score: 0–4}`.
     *
     * Anything the rules refuse — absent, empty, or longer than a storable password — answers
     * with the framework's 422. `usePasswordEntropy` throws on any non-2xx before it reads a
     * body, so the meter simply keeps its previous score rather than showing a wrong one.
     */
    public function __invoke(ScorePasswordRequest $request): JsonResponse
    {
        return response()->json([
            'score' => (new Zxcvbn)->passwordStrength($request->validated('p'))['score'],
        ]);
    }
}
