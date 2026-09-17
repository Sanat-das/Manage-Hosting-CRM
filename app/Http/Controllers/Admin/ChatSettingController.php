<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\UpdateChatSettingsRequest;
use App\Models\ChatOfficeHour;
use App\Models\ChatSetting;
use App\Services\ChatOfficeHours;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The chat's settings page: office hours, the offline form, and whether a
 * closed conversation emails the customer a transcript.
 *
 * The single writer of `chat_settings` and `chat_office_hours`, the way
 * GstSettingController is the single writer of `gst_settings`. ChatOfficeHours
 * is the only reader, and it caches — so every write here ends with the cache
 * forgotten. Without that an admin changes their hours, reloads, and sees the
 * old schedule for up to half a minute, which reads as a save that did not
 * work.
 *
 * Deliberately NOT a tab on the main Settings page. That page is where ~160 of
 * ~200 controls turned out to be decoys, and these five are wired to behaviour
 * a customer sees; keeping them on the chat's own screen keeps the relationship
 * between a control and its effect visible.
 */
class ChatSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.chat.settings', [
            'settings' => ChatSetting::current(),
            'week' => ChatOfficeHour::week(),
            'dayNames' => ChatOfficeHour::DAY_NAMES,
            'departments' => TicketService::departments(),
            // The full IANA list, because the support desk's hours are local to
            // wherever the support desk is, which is not necessarily where the
            // server is. Grouped by region into <optgroup>s, the way the main
            // Settings page already renders its two timezone selects — a flat
            // list of ~420 identifiers in one dropdown is the same control the
            // rest of the panel decided against.
            'timezonesGrouped' => $this->groupedTimezones(),
            'defaultTimezone' => (string) config('app.timezone', 'UTC'),
        ]);
    }

    /**
     * IANA identifiers keyed by region, regions alphabetical, "Other" last.
     *
     * "Other" collects the identifiers with no `/` in them (UTC, CET, GMT...),
     * which would otherwise each become a one-entry region of their own.
     *
     * @return array<string, array<int, string>>
     */
    private function groupedTimezones(): array
    {
        $grouped = [];

        foreach (timezone_identifiers_list() as $identifier) {
            $region = str_contains($identifier, '/')
                ? explode('/', $identifier, 2)[0]
                : 'Other';

            $grouped[$region][] = $identifier;
        }

        uksort($grouped, static function (string $a, string $b): int {
            if ($a === 'Other') {
                return 1;
            }

            if ($b === 'Other') {
                return -1;
            }

            return strcmp($a, $b);
        });

        return $grouped;
    }

    public function update(UpdateChatSettingsRequest $request): RedirectResponse
    {
        $week = $request->week();

        // One transaction for the toggles and all seven windows: a half-applied
        // schedule (Monday saved, Tuesday not) would be a set of hours nobody
        // chose, and this form is the only thing that writes either table.
        DB::transaction(function () use ($request, $week): void {
            ChatSetting::current()->update([
                'enforce_office_hours' => $request->boolean('enforce_office_hours'),
                'require_available_operator' => $request->boolean('require_available_operator'),
                'offline_form_enabled' => $request->boolean('offline_form_enabled'),
                'send_transcript_on_close' => $request->boolean('send_transcript_on_close'),
                'customer_chat_enabled' => $request->boolean('customer_chat_enabled'),
                'timezone' => (string) $request->input('timezone', ''),
                'closed_message' => $request->input('closed_message'),
                'offline_ticket_department' => $request->input('offline_ticket_department') ?: null,
            ]);

            foreach ($week as $day => $window) {
                ChatOfficeHour::query()->updateOrCreate(['day_of_week' => $day], $window);
            }
        });

        // The read side caches for 30 seconds; the save is worthless until it
        // does not.
        ChatOfficeHours::forget();

        return redirect()
            ->route('admin.chat.settings.edit')
            ->with('success', 'Chat settings updated.');
    }
}
