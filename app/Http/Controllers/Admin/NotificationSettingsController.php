<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\EventCatalogue;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin editor for the centralized notification matrix
 * (event x audience x channel). Non-applicable combinations are not
 * writable: only the (event, audience, channel) triples present in
 * EventCatalogue defaults are read from the request and upserted.
 */
class NotificationSettingsController extends Controller
{
    public function index(): View
    {
        $groups = EventCatalogue::groups();

        $roleLabels = Role::pluck('label', 'name')->all();

        $audiences = [];

        foreach (EventCatalogue::audiences() as $audience) {
            $label = $audience === 'customer'
                ? 'Customer'
                : trim((string) ($roleLabels[$audience] ?? ''));

            $audiences[$audience] = $label !== '' ? $label : ucfirst($audience);
        }

        $state = [];
        foreach (EventCatalogue::events() as $event) {
            foreach ($event['channels'] as $channel) {
                foreach (array_keys($audiences) as $audience) {
                    if (! EventCatalogue::isApplicable($event['key'], $audience, $channel)) {
                        continue;
                    }

                    $state[$event['key']][$audience][$channel] = NotificationMatrix::allows($event['key'], $audience, $channel);
                }
            }
        }

        return view('admin.notification_settings.index', compact('groups', 'audiences', 'state'));
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = $request->input('settings');
        $settings = is_array($settings) ? $settings : [];

        $changes = [];
        $rows = [];

        foreach (EventCatalogue::events() as $event) {
            $key = $event['key'];

            foreach ($event['channels'] as $channel) {
                foreach (EventCatalogue::audiences() as $audience) {
                    if (! EventCatalogue::isApplicable($key, $audience, $channel)) {
                        continue;
                    }

                    $raw = $settings[$key][$audience][$channel] ?? null;
                    $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN);

                    $old = NotificationMatrix::allows($key, $audience, $channel);

                    if ($old !== $enabled) {
                        $changes[$key.'.'.$audience.'.'.$channel] = [
                            'old' => $old ? '1' : '0',
                            'new' => $enabled ? '1' : '0',
                        ];
                    }

                    $rows[] = [
                        'event_key' => $key,
                        'audience' => $audience,
                        'channel' => $channel,
                        'enabled' => $enabled,
                    ];
                }
            }
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $where = [
                    'event_key' => $row['event_key'],
                    'audience' => $row['audience'],
                    'channel' => $row['channel'],
                ];

                $values = [
                    'enabled' => $row['enabled'],
                    'updated_at' => now(),
                ];

                if (! DB::table('notification_event_settings')->where($where)->exists()) {
                    $values['created_at'] = now();
                }

                DB::table('notification_event_settings')->updateOrInsert($where, $values);
            }
        });

        NotificationMatrix::flush();

        if ($changes !== []) {
            try {
                app(AuditRecorder::class)->activity(
                    'notification_settings.updated',
                    null,
                    [
                        'changed_keys' => array_keys($changes),
                        'changes' => $changes,
                    ],
                    'Notification settings updated: changed '.implode(', ', array_keys($changes)),
                );
            } catch (\Throwable $e) {
                // Audit must never break the save.
                report($e);
            }
        }

        return redirect()
            ->route('admin.notification-settings.index')
            ->with('success', 'Notification settings saved.');
    }
}
