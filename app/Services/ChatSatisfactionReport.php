<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the star ratings add up to.
 *
 * The widget has been collecting `chat_conversations.rating` since the customer
 * inbox shipped, and the column was read in exactly two places — the operator's
 * own conversation payload, and the widget's. Nothing ever aggregated it, so
 * the scores existed and could not be looked at.
 *
 * Every figure here is derived from that one column. There is deliberately no
 * new table and no nightly rollup: the volume is one row per conversation, the
 * queries are grouped aggregates over an indexed status column, and a cached
 * summary would be a second version of the truth to reconcile.
 *
 * The response rate is reported alongside the average on purpose. An average of
 * 5.0 from three ratings out of four hundred closed chats is not a 5.0
 * satisfaction score, and a report that shows the average without the
 * denominator invites exactly that reading.
 */
class ChatSatisfactionReport
{
    /** How many individually rated conversations the page lists. */
    public const RECENT_LIMIT = 25;

    /**
     * What a null operator or department is called.
     *
     * One constant for both tables: they sit side by side on the same screen,
     * and two spellings of the same absence is how a reader concludes the two
     * tables are counting different things.
     */
    public const UNASSIGNED_LABEL = 'Unassigned';

    /**
     * @return array{
     *     closed: int,
     *     rated: int,
     *     response_rate: float,
     *     average: ?float,
     *     distribution: array<int, int>,
     *     by_operator: array<int, array{name: string, rated: int, average: float}>,
     *     by_department: array<int, array{department: string, rated: int, average: float}>,
     *     recent: Collection<int, ChatConversation>
     * }
     */
    public function build(?string $from = null, ?string $to = null, ?string $department = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $closed = $this->scope($start, $end, $department)->count();
        $rated = $this->scope($start, $end, $department)->whereNotNull('rating')->count();

        $average = $rated === 0
            ? null
            : round((float) $this->scope($start, $end, $department)->whereNotNull('rating')->avg('rating'), 2);

        return [
            'closed' => $closed,
            'rated' => $rated,
            // Guarded: a range with no closed conversations is 0%, not a
            // division by zero, and not "100% of nothing".
            'response_rate' => $closed === 0 ? 0.0 : round($rated / $closed * 100, 1),
            'average' => $average,
            'distribution' => $this->distribution($start, $end, $department),
            'by_operator' => $this->byOperator($start, $end, $department),
            'by_department' => $this->byDepartment($start, $end),
            'recent' => $this->recent($start, $end, $department),
        ];
    }

    /**
     * The population every figure is drawn from: closed customer conversations
     * in the window.
     *
     * Closed rather than all: a conversation that is still open has not had the
     * chance to be rated, and counting it in the denominator would make the
     * response rate a measure of how many chats are in progress.
     */
    private function scope(CarbonImmutable $start, CarbonImmutable $end, ?string $department): Builder
    {
        $query = ChatConversation::query()
            ->where('type', ChatConversation::TYPE_CUSTOMER_INBOX)
            ->where('status', ChatConversation::STATUS_CLOSED)
            // Fall back to created_at for the rows closed before closed_at was
            // being written; without it those conversations silently leave the
            // report rather than landing in a window.
            ->whereBetween(DB::raw('COALESCE(closed_at, created_at)'), [$start, $end]);

        if ($department !== null && $department !== '') {
            $query->where('department', $department);
        }

        return $query;
    }

    /**
     * @return array<int, int> 1..5 => count, every star present even at zero
     */
    private function distribution(CarbonImmutable $start, CarbonImmutable $end, ?string $department): array
    {
        $counts = $this->scope($start, $end, $department)
            ->whereNotNull('rating')
            ->groupBy('rating')
            ->select('rating', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'rating');

        $distribution = [];

        // Built from a fixed 1..5 rather than from what the query returned: a
        // bar chart that omits the stars nobody gave is unreadable.
        for ($star = 1; $star <= 5; $star++) {
            $distribution[$star] = (int) ($counts[$star] ?? 0);
        }

        return $distribution;
    }

    /**
     * @return array<int, array{name: string, rated: int, average: float}>
     */
    private function byOperator(CarbonImmutable $start, CarbonImmutable $end, ?string $department): array
    {
        // Unassigned conversations are INCLUDED, as their own row.
        //
        // They used to be filtered out, which made the screen read as broken
        // arithmetic: the headline said "2 chats rated" while this table summed
        // to 1, with nothing to account for the difference. The department
        // table beside it already labels the same null as "Unassigned", so
        // dropping it here was also two adjacent tables treating one missing
        // value two different ways. A rating given with no operator assigned is
        // a real rating and worth seeing.
        $rows = $this->scope($start, $end, $department)
            ->whereNotNull('rating')
            ->groupBy('assigned_operator_id')
            ->select(
                'assigned_operator_id',
                DB::raw('COUNT(*) as rated'),
                DB::raw('AVG(rating) as average'),
            )
            ->orderByDesc('average')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Names come from the model, not from the group-by. There is no `name`
        // column on `users` — it is an accessor over first_name/last_name with
        // an email fallback — so assembling the label in SQL would either
        // select a column that does not exist or need every part of it in the
        // GROUP BY to satisfy ONLY_FULL_GROUP_BY.
        $operatorIds = $rows->pluck('assigned_operator_id')->filter()->all();

        $operators = $operatorIds === []
            ? collect()
            : User::query()->whereIn('id', $operatorIds)->get()->keyBy('id');

        return $rows
            ->map(static fn ($row): array => [
                'name' => $row->assigned_operator_id === null
                    ? self::UNASSIGNED_LABEL
                    : (string) ($operators->get($row->assigned_operator_id)?->full_name ?? 'Unknown'),
                'rated' => (int) $row->rated,
                'average' => round((float) $row->average, 2),
            ])
            ->all();
    }

    /**
     * @return array<int, array{department: string, rated: int, average: float}>
     */
    private function byDepartment(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->scope($start, $end, null)
            ->whereNotNull('rating')
            ->groupBy('department')
            ->select(
                'department',
                DB::raw('COUNT(*) as rated'),
                DB::raw('AVG(rating) as average'),
            )
            ->orderByDesc('average')
            ->get()
            ->map(static fn ($row): array => [
                'department' => (string) ($row->department ?? self::UNASSIGNED_LABEL),
                'rated' => (int) $row->rated,
                'average' => round((float) $row->average, 2),
            ])
            ->all();
    }

    /**
     * @return Collection<int, ChatConversation>
     */
    private function recent(CarbonImmutable $start, CarbonImmutable $end, ?string $department): Collection
    {
        return $this->scope($start, $end, $department)
            ->whereNotNull('rating')
            ->with(['customer', 'assignedOperator'])
            ->orderByDesc(DB::raw('COALESCE(closed_at, created_at)'))
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * The window, defaulting to the last 30 days.
     *
     * Both ends are widened to whole days: a `to` of 2026-09-15 that means
     * midnight excludes everything that happened on the 15th, which reads as a
     * missing day rather than as an off-by-one.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(?string $from, ?string $to): array
    {
        $start = $this->parse($from) ?? CarbonImmutable::now()->subDays(30);
        $end = $this->parse($to) ?? CarbonImmutable::now();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }

    private function parse(?string $date): ?CarbonImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date);
        } catch (\Throwable) {
            // A hand-edited query string must not 500 a report.
            return null;
        }
    }
}
