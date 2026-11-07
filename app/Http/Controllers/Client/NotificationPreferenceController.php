<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\PerUserNotificationMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Client self-service notification preferences.
 *
 * Writes one notification_preferences row per shown cell; an unchecked box is
 * an explicit enabled=false override, not a "delete my preference" signal.
 * Customer-keyed rows are skipped silently when the account has no linked
 * Customer record yet.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $customer = $user->customer;
        $input = (array) $request->input('notification_preferences', []);

        foreach (PerUserNotificationMap::client() as $key => $entry) {
            $notifiable = PerUserNotificationMap::notifiable($entry, $user, $customer);

            if ($notifiable === null) {
                continue;
            }

            foreach ($entry['channels'] as $channel) {
                $enabled = PerUserNotificationMap::boolValue($input, $key, $channel);
                $this->preferences->setPreference($notifiable, $key, $enabled, $channel);
            }
        }

        return redirect()
            ->route('client.profile')
            ->with('success', 'Notification preferences saved.');
    }
}
