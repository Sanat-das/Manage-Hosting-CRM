<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Who may act on an invoice.
 *
 * The routes already carry the coarse gates (invoices.view / invoices.create
 * / invoices.edit), but a middleware string is not an authority: nothing
 * checks it when an invoice is reached by another path, and nothing says
 * what the decision was. This policy is the single place the answer lives,
 * and the controller asks it on every write.
 */
class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('invoices.create');
    }

    /**
     * Editing an invoice.
     *
     * The permission is the whole decision: an invoice is a document of
     * record for the customer it belongs to, and the customer is not
     * reassignable here (the edit form does not offer it).
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission('invoices.edit');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission('invoices.delete');
    }
}
