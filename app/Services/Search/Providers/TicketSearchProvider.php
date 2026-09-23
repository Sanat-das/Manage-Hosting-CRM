<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Ticket;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Support tickets, matched on their number and subject.
 *
 * Gated by `tickets.view` — the same permission `routes/admin/support.php`
 * puts on the ticket index/show screens. The ticket number is the strongest
 * identifier, so it decides closeness ranking explicitly rather than relying
 * on column order. Chat messages are deliberately not searched here; they have
 * a dedicated endpoint.
 */
class TicketSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'tickets';
    }

    public function label(): string
    {
        return 'Tickets';
    }

    public function icon(): string
    {
        return 'bi bi-life-preserver';
    }

    public function permission(): string
    {
        return 'tickets.view';
    }

    public function showRoute(): string
    {
        return 'admin.tickets.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.tickets.index';
    }

    protected function baseQuery(): Builder
    {
        return Ticket::query();
    }

    protected function searchableColumns(): array
    {
        return ['ticket_no', 'subject'];
    }

    protected function rankColumn(): string
    {
        return 'ticket_no';
    }

    public function toResult(Model $model): array
    {
        /** @var Ticket $model */
        return $this->resultRow($model, (string) $model->ticket_no, $model->subject);
    }
}
