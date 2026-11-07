<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\PerUserNotificationMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Staff self-service notification preferences.
 *
 * Writes one notification_preferences row per shown cell for the signed-in
 * staff user; an unchecked box is an explicit enabled=false override. The
 * scheduled.report row is only written for admin|staff|support roles.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $input = (array) $request->input('notification_preferences', []);

        foreach (PerUserNotificationMap::staffFor($user) as $key => $entry) {
            foreach ($entry['channels'] as $channel) {
                $enabled = PerUserNotificationMap::boolValue($input, $key, $channel);
                $this->preferences->setPreference($user, $key, $enabled, $channel);
            }
        }

        return redirect()
            ->route('admin.profile')
            ->with('success', 'Notification preferences saved.');
    }
}
