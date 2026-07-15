<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZxcvbnPhp\Zxcvbn;

/**
 * Server-side password strength for the live registration meter (ported from
 * cantrip.me). Returns the zxcvbn score (0–4) for the submitted password so the
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
    public function __invoke(Request $request): JsonResponse
    {
        $password = $request->input('p');

        if (! is_string($password) || $password === '') {
            return response()->json(['score' => null], 422);
        }

        return response()->json([
            'score' => (new Zxcvbn)->passwordStrength($password)['score'],
        ]);
    }
}
