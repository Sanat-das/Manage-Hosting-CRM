<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One weekday's opening window.
 *
 * `day_of_week` is Carbon's numbering (0 = Sunday), so a lookup is an index
 * rather than a conversion. The times are stored as `time` columns and read
 * back as plain "HH:MM:SS" strings on purpose — casting them to Carbon would
 * attach today's date to a value that has no date, and comparing two of those
 * across a midnight boundary is how "open until 02:00" becomes "never open".
 * ChatOfficeHours compares them as minutes-since-midnight instead.
 */
#[Fillable(['day_of_week', 'is_open', 'opens_at', 'closes_at'])]
class ChatOfficeHour extends Model
{
    /** Index is `day_of_week`; used by the settings form and nothing else. */
    public const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    /**
     * The seven rows, keyed by day, with any missing day filled in as closed.
     *
     * The migration creates all seven, but a row can be deleted by hand and a
     * settings form that silently drops Thursday is worse than one that shows
     * it closed.
     *
     * @return array<int, self>
     */
    public static function week(): array
    {
        $rows = static::query()->orderBy('day_of_week')->get()->keyBy('day_of_week');

        $week = [];

        foreach (array_keys(self::DAY_NAMES) as $day) {
            $week[$day] = $rows->get($day) ?? new self([
                'day_of_week' => $day,
                'is_open' => false,
                'opens_at' => '09:00:00',
                'closes_at' => '18:00:00',
            ]);
        }

        return $week;
    }

    public function dayName(): string
    {
        return self::DAY_NAMES[(int) $this->day_of_week] ?? 'Unknown';
    }

    /** "09:00", for an <input type="time">. */
    public function opensAtInput(): string
    {
        return substr((string) $this->opens_at, 0, 5);
    }

    public function closesAtInput(): string
    {
        return substr((string) $this->closes_at, 0, 5);
    }

    /**
     * Does this window run past midnight into the next day?
     *
     * 09:00-18:00 does not. 22:00-02:00 does, and so does 09:00-09:00, which is
     * the only sane reading of a window that would otherwise be zero-length.
     */
    public function spansMidnight(): bool
    {
        return self::minutes($this->closes_at) <= self::minutes($this->opens_at);
    }

    /** Minutes since midnight for a "HH:MM[:SS]" string. */
    public static function minutes(mixed $time): int
    {
        $parts = explode(':', (string) $time);

        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }
}
