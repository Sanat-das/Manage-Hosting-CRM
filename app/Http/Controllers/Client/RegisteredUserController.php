<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Registered;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Illuminate\Http\RedirectResponse;

/**
 * Handles self-registration for client accounts.
 * Replaces Fortify's built-in registration route so the
 * redirect can be role-aware and gated by the admin setting.
 */
class RegisteredUserController extends Controller
{
    public function store(Request $request, CreatesNewUsers $creator): RedirectResponse
    {
        $user = $creator->create($request->all());

        Auth::login($user);

        Event::dispatch(new Registered($user));

        return redirect()->intended(route('client.dashboard'));
    }
}
