<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin self-service profile.
 *
 * Admins manage their own account here (2FA setup / recovery codes, signature).
 * Identity fields are managed through the AdminLTE user management screens.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('admin.profile', compact('user'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ticket_signature' => ['nullable', 'string', 'max:2000'],
        ]);

        $request->user()->update([
            'ticket_signature' => $validated['ticket_signature'] ?? null,
        ]);

        return redirect()->route('admin.profile')->with('success', 'Profile updated.');
    }
}
