<?php

/**
 * Global search provider registry.
 *
 * Every entry is a class implementing App\Services\Search\SearchProvider.
 * Providers whose class or destination route is not installed yet are skipped
 * at runtime (logged once, never thrown), so this list may name the full
 * curated set before each provider class lands.
 */
return [
    'providers' => [
        \App\Services\Search\Providers\CustomerSearchProvider::class,
        \App\Services\Search\Providers\ContactSearchProvider::class,
        \App\Services\Search\Providers\StaffUserSearchProvider::class,
        \App\Services\Search\Providers\OrderSearchProvider::class,
        \App\Services\Search\Providers\InvoiceSearchProvider::class,
        \App\Services\Search\Providers\PaymentSearchProvider::class,
        \App\Services\Search\Providers\TransactionSearchProvider::class,
        \App\Services\Search\Providers\QuoteSearchProvider::class,
        \App\Services\Search\Providers\ServiceInstanceSearchProvider::class,
        \App\Services\Search\Providers\HostingAccountSearchProvider::class,
        \App\Services\Search\Providers\ServerSearchProvider::class,
        \App\Services\Search\Providers\DomainSearchProvider::class,
        \App\Services\Search\Providers\SslCertificateSearchProvider::class,
        \App\Services\Search\Providers\TicketSearchProvider::class,
        \App\Services\Search\Providers\KnowledgeBaseSearchProvider::class,
        \App\Services\Search\Providers\CatalogProductSearchProvider::class,
        \App\Services\Search\Providers\ProductSearchProvider::class,
    ],
];
