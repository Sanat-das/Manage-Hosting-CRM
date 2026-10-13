<?php

declare(strict_types=1);

namespace App\ViewModels\Admin;

use App\Models\Ticket;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Risk snapshot for the admin dashboard "Support queue risk" card.
 *
 * Counts the tickets nobody owns and the tickets nobody has answered —
 * neither of which the existing open/urgent ticket metrics surface.
 * Every query is defensive (missing table, failed query) so the widget
 * falls back to 0 / empty instead of breaking the dashboard.
 *
 * Vocabulary: "open" means status <> 'closed' over the WHMCS-style status
 * enum (open | answered | customer_reply | on_hold | in_progress | closed,
 * migration 2026_09_01_000001). 'resolved' is NOT a status — it was mapped
 * to 'closed' by that migration and must not be filtered on.
 *
 * `awaitingReplyCount` counts the tickets sitting in `customer_reply` —
 * the status TicketService::reply() assigns when the author is NOT staff
 * (TicketService.php:305-308), documented as "client reply -> status
 * 'customer_reply' (waiting on staff)" (TicketService.php:29-30). A staff
 * reply moves the very same ticket to 'answered' (TicketService.php:306),
 * so a row in this state means: a customer has replied and no staff member
 * has answered since.
 *
 * It deliberately does NOT use `last_reply_at IS NULL`. TicketService
 * stamps that column at creation (TicketService.php:193) and on every
 * public reply (TicketService.php:307), so a NULL almost never happens on
 * a healthy install and the widget would show a green all-clear while
 * customers wait. Internal notes leave it untouched, which is why NULL on
 * some rows says "no recorded public message at all" — not "unanswered".
 */
final class SupportQueueRiskViewModel
{
    /** @var array{urgent: int, high: int, medium: int, low: int} */
    public readonly array $byPriority;

    public readonly int $unassignedCount;

    public readonly int $unassignedUrgent;

    public readonly int $awaitingReplyCount;

    public readonly int $awaitingReplyUrgent;

    public readonly int $oldestUnassignedDays;

    public function __construct()
    {
        $figures = $this->loadFigures();

        $this->unassignedCount = $figures['unassigned_count'];
        $this->unassignedUrgent = $figures['unassigned_urgent'];
        $this->awaitingReplyCount = $figures['awaiting_reply_count'];
        $this->awaitingReplyUrgent = $figures['awaiting_reply_urgent'];
        $this->oldestUnassignedDays = $figures['oldest_unassigned_days'];
        $this->byPriority = $figures['by_priority'];
    }

    /**
     * Every figure the card renders, in one shot.
     *
     * Computed into locals and returned as an array so the constructor never
     * writes a readonly property twice. Pre-initialising a readonly property
     * and then reassigning it inside the try below would throw
     * Error: Cannot modify readonly property, which the surrounding
     * catch(Throwable) would silently swallow into a permanently zeroed
     * widget that always reports a healthy support queue.
     *
     * @return array{
     *     unassigned_count: int,
     *     unassigned_urgent: int,
     *     awaiting_reply_count: int,
     *     awaiting_reply_urgent: int,
     *     oldest_unassigned_days: int,
     *     by_priority: array{urgent: int, high: int, medium: int, low: int},
     * }
     */
    private function loadFigures(): array
    {
        $zeros = [
            'unassigned_count' => 0,
            'unassigned_urgent' => 0,
            'awaiting_reply_count' => 0,
            'awaiting_reply_urgent' => 0,
            'oldest_unassigned_days' => 0,
            'by_priority' => ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
        ];

        try {
            if (! Schema::hasTable((new Ticket)->getTable())) {
                return $zeros;
            }

            $unassigned = Ticket::query()
                ->where('status', '<>', 'closed')
                ->whereNull('assigned_to');

            $unassignedCount = (int) (clone $unassigned)->count();
            $unassignedUrgent = (int) (clone $unassigned)->whereIn('priority', ['urgent', 'high'])->count();
            $awaitingReply = Ticket::query()
                ->where('status', TicketService::STATUS_CUSTOMER_REPLY);
            $awaitingReplyCount = (int) (clone $awaitingReply)->count();
            $awaitingReplyUrgent = (int) (clone $awaitingReply)->whereIn('priority', ['urgent', 'high'])->count();

            $counts = (clone $unassigned)
                ->selectRaw('priority, COUNT(*) as c')
                ->groupBy('priority')
                ->pluck('c', 'priority');

            $byPriority = ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
            foreach ($counts as $priority => $count) {
                $key = strtolower((string) $priority);
                if (array_key_exists($key, $byPriority)) {
                    $byPriority[$key] = (int) $count;
                }
            }

            $oldestUnassignedDays = 0;
            $oldest = $unassigned->min('created_at');
            if ($oldest !== null) {
                $oldestUnassignedDays = max(0, (int) Carbon::parse($oldest)->diffInDays(now()));
            }

            return [
                'unassigned_count' => $unassignedCount,
                'unassigned_urgent' => $unassignedUrgent,
                'awaiting_reply_count' => $awaitingReplyCount,
                'awaiting_reply_urgent' => $awaitingReplyUrgent,
                'oldest_unassigned_days' => $oldestUnassignedDays,
                'by_priority' => $byPriority,
            ];
        } catch (Throwable) {
            // Keep the zeroed defaults: a fresh install or partial migration
            // renders an empty widget instead of a broken dashboard.
            return $zeros;
        }
    }
}
