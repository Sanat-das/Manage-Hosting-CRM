<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\CustomerContact;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer contacts.
 *
 * A contact has no screen of its own: the destination is the parent customer's
 * page, so `toResult()` builds the URL from the customer relation — passing the
 * contact's own key into `admin.customers.show` would open the wrong customer
 * (or 404). The relation is eager loaded in `baseQuery()` for that reason.
 *
 * The email is the most identifier-like column on a contact, so it decides
 * closeness ranking rather than the default first column (`first_name`).
 */
class ContactSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return 'Contacts';
    }

    public function icon(): string
    {
        return 'bi bi-person-lines-fill';
    }

    public function permission(): string
    {
        return 'customers.view';
    }

    public function showRoute(): string
    {
        return 'admin.customers.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.customers.index';
    }

    protected function baseQuery(): Builder
    {
        return CustomerContact::query()->with('customer');
    }

    protected function searchableColumns(): array
    {
        return ['first_name', 'last_name', 'email'];
    }

    protected function rankColumn(): string
    {
        return 'email';
    }

    public function toResult(Model $model): array
    {
        /** @var CustomerContact $model */
        $customer = $model->customer;
        $label = trim($model->first_name.' '.$model->last_name);

        return [
            'id' => $model->getKey(),
            'label' => $label !== '' ? $label : '#'.$model->getKey(),
            'subtitle' => $model->email,
            // A contact without its parent cannot deep-link to a customer
            // page; fall back to the customer list rather than a wrong URL.
            'url' => $customer !== null
                ? route($this->showRoute(), $customer)
                : route('admin.customers.index'),
        ];
    }
}
