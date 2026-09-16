<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatOfficeHour;
use App\Models\ChatSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Is the live chat open, and if not, what should the visitor be told?
 *
 * This is the READ side of the chat settings; ChatSettingController is the
 * write side. The split matters because the widget is included from the shared
 * layout on every non-admin page: an uncached `ChatSetting::first()` here would
 * add a query to the storefront, the client portal and every auth page in the
 * app. The schedule is therefore read once and cached as a plain array for half
 * a minute, and the settings controller forgets the key when it saves.
 *
 * Two things can close the chat:
 *
 *   the clock       outside the weekday window in `chat_office_hours`
 *   the roster      `require_available_operator` is on and nobody with
 *                   chat.manage is both online and marked Available
 *
 * Both are off on a fresh install (`enforce_office_hours` defaults to false),
 * so the chat answers "open" exactly as it did before this class existed until
 * an admin says otherwise. That default is deliberate: this ships to installs
 * whose customers are mid-conversation.
 */
class ChatOfficeHours
{
    private const CACHE_KEY = 'chat:office-hours:config';

    /**
     * Short on purpose. An admin who has just changed their hours reloads the
     * page to check, and the settings controller forgets this key on save — the
     * TTL is only the backstop for a row changed by hand or by another process.
     */
    private const CACHE_SECONDS = 30;

    /** How far ahead nextOpensAt() will look before giving up. */
    private const LOOKAHEAD_DAYS = 8;

    public function __construct(private readonly ChatAvailability $availability = new ChatAvailability) {}

    /**
     * The whole answer, in the shape the widget and its endpoint both return.
     *
     * One method rather than a handful of booleans because the caller needs
     * them consistent with each other: "closed" with no message, or a message
     * that disagrees with the reason, is how a widget ends up telling a visitor
     * the team is offline while the composer is still enabled.
     *
     * @return array{open: bool, reason: string, message: string, offline_form: bool, next_opens_at: ?string}
     */
    public function status(?CarbonInterface $at = null): array
    {
        $config = $this->config();

        if (! $config['enforce']) {
            return [
                'open' => true,
                'reason' => 'not_enforced',
                'message' => '',
                'offline_form' => false,
                'next_opens_at' => null,
            ];
        }

        $now = $this->inZone($at, $config['timezone']);

        if (! $this->withinHours($now, $config)) {
            return [
                'open' => false,
                'reason' => 'outside_hours',
                'message' => $config['closed_message'],
                'offline_form' => $config['offline_form'],
                'next_opens_at' => $this->nextOpensAt($now, $config)?->format('l H:i'),
            ];
        }

        // Inside the hours, but the hours are a promise about people. An empty
        // support desk during its own opening window is closed as far as the
        // visitor waiting for a reply is concerned.
        if ($config['require_operator'] && ! $this->availability->hasAcceptingOperator()) {
            return [
                'open' => false,
                'reason' => 'no_operator',
                'message' => $config['closed_message'],
                'offline_form' => $config['offline_form'],
                // Deliberately null: the clock says open, so "opens at" would
                // be a time that has already passed.
                'next_opens_at' => null,
            ];
        }

        return [
            'open' => true,
            'reason' => 'within_hours',
            'message' => '',
            'offline_form' => false,
            'next_opens_at' => null,
        ];
    }

    public function isOpen(?CarbonInterface $at = null): bool
    {
        return $this->status($at)['open'];
    }

    /**
     * Is an out-of-hours message worth offering at all?
     *
     * False when the chat is open (there is a chat to have instead) and false
     * when the admin has turned the form off, in which case the visitor gets
     * the closed notice and nothing to fill in.
     */
    public function offersOfflineForm(?CarbonInterface $at = null): bool
    {
        $status = $this->status($at);

        return ! $status['open'] && $status['offline_form'];
    }

    /**
     * Which ticket department an out-of-hours message opens under.
     *
     * Resolved at send time against the departments that currently exist, so a
     * department that was renamed or disabled after it was chosen falls back to
     * the first enabled one instead of creating a ticket nobody can see.
     */
    public function offlineDepartment(): ?string
    {
        $configured = $this->config()['offline_department'];
        $departments = TicketService::departments();

        if ($configured !== null && array_key_exists($configured, $departments)) {
            return $configured;
        }

        $first = array_key_first($departments);

        return $first === null ? null : (string) $first;
    }

    /**
     * Is the clock inside a configured window?
     *
     * Two rows are consulted, not one. A window whose closing time is at or
     * before its opening time runs past midnight (22:00-02:00), so at 01:00 on
     * Tuesday the row that matters is MONDAY's. Checking only today's row is
     * how a night shift evaluates to "never open".
     *
     * @param  array<string, mixed>  $config
     */
    private function withinHours(CarbonImmutable $now, array $config): bool
    {
        /** @var array<int, array{is_open: bool, opens: int, closes: int}> $week */
        $week = $config['week'];

        $minutes = $now->hour * 60 + $now->minute;
        $today = $week[$now->dayOfWeek] ?? null;
        $yesterday = $week[($now->dayOfWeek + 6) % 7] ?? null;

        if ($today !== null && $today['is_open']) {
            if ($today['closes'] > $today['opens']) {
                if ($minutes >= $today['opens'] && $minutes < $today['closes']) {
                    return true;
                }
            } elseif ($minutes >= $today['opens']) {
                // Spans midnight: open from opens_at to the end of the day.
                return true;
            }
        }

        // The tail of a window that began yesterday.
        if ($yesterday !== null && $yesterday['is_open']
            && $yesterday['closes'] <= $yesterday['opens']
            && $minutes < $yesterday['closes']) {
            return true;
        }

        return false;
    }

    /**
     * When the chat next opens, or null if no day is marked open at all.
     *
     * @param  array<string, mixed>  $config
     */
    private function nextOpensAt(CarbonImmutable $now, array $config): ?CarbonImmutable
    {
        /** @var array<int, array{is_open: bool, opens: int, closes: int}> $week */
        $week = $config['week'];

        for ($offset = 0; $offset < self::LOOKAHEAD_DAYS; $offset++) {
            $day = $now->addDays($offset);
            $window = $week[$day->dayOfWeek] ?? null;

            if ($window === null || ! $window['is_open']) {
                continue;
            }

            $opens = $day->startOfDay()->addMinutes($window['opens']);

            if ($opens->greaterThan($now)) {
                return $opens;
            }
        }

        return null;
    }

    private function inZone(?CarbonInterface $at, string $timezone): CarbonImmutable
    {
        $moment = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at->toDateTimeImmutable());

        return $moment->setTimezone($timezone);
    }

    /**
     * The settings and the seven windows, as scalars.
     *
     * Scalars rather than models on purpose: this goes in the cache, and a
     * serialised Eloquent model in a cache that outlives a schema change is a
     * class of bug this application does not need.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, static function (): array {
            $settings = ChatSetting::current();

            $week = [];

            foreach (ChatOfficeHour::week() as $day => $row) {
                $week[$day] = [
                    'is_open' => (bool) $row->is_open,
                    'opens' => ChatOfficeHour::minutes($row->opens_at),
                    'closes' => ChatOfficeHour::minutes($row->closes_at),
                ];
            }

            return [
                'enforce' => (bool) $settings->enforce_office_hours,
                'timezone' => $settings->timezoneName(),
                'require_operator' => (bool) $settings->require_available_operator,
                'closed_message' => $settings->closedMessage(),
                'offline_form' => (bool) $settings->offline_form_enabled,
                'offline_department' => $settings->offline_ticket_department,
                'week' => $week,
            ];
        });
    }

    /**
     * Drop the cached schedule.
     *
     * Called by the settings controller after a save. Static so a caller that
     * has no reason to construct the service (a migration, a test tearing down)
     * can still invalidate it.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
