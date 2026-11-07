<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\PerUserNotificationMap;
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
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();

        $notificationPreferences = PerUserNotificationMap::rows(
            PerUserNotificationMap::staffFor($user),
            $user,
            null,
            $this->preferences,
        );

        return view('admin.profile', compact('user', 'notificationPreferences'));
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
