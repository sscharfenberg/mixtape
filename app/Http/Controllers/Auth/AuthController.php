<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    /**
     * Display the login page.
     *
     * Passes the session status (e.g. a "password reset successful" notice, once
     * that flow exists) to the Inertia view so it can be surfaced to the user.
     */
    public function loginView(Request $request): Response
    {
        return Inertia::render('Auth/LoginPage', [
            'status' => $request->session()->get('status'),
        ]);
    }
}
