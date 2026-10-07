<?php

use App\Services\Search\Providers\CatalogProductSearchProvider;
use App\Services\Search\Providers\ContactSearchProvider;
use App\Services\Search\Providers\CustomerSearchProvider;
use App\Services\Search\Providers\DomainSearchProvider;
use App\Services\Search\Providers\HostingAccountSearchProvider;
use App\Services\Search\Providers\InventoryAssetSearchProvider;
use App\Services\Search\Providers\InvoiceSearchProvider;
use App\Services\Search\Providers\IpAddressSearchProvider;
use App\Services\Search\Providers\KnowledgeBaseSearchProvider;
use App\Services\Search\Providers\OrderSearchProvider;
use App\Services\Search\Providers\PaymentSearchProvider;
use App\Services\Search\Providers\ProductSearchProvider;
use App\Services\Search\Providers\QuoteSearchProvider;
use App\Services\Search\Providers\ServerSearchProvider;
use App\Services\Search\Providers\ServiceInstanceSearchProvider;
use App\Services\Search\Providers\SslCertificateSearchProvider;
use App\Services\Search\Providers\StaffUserSearchProvider;
use App\Services\Search\Providers\TicketSearchProvider;
use App\Services\Search\Providers\TransactionSearchProvider;

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
        CustomerSearchProvider::class,
        ContactSearchProvider::class,
        StaffUserSearchProvider::class,
        OrderSearchProvider::class,
        InvoiceSearchProvider::class,
        PaymentSearchProvider::class,
        TransactionSearchProvider::class,
        QuoteSearchProvider::class,
        ServiceInstanceSearchProvider::class,
        HostingAccountSearchProvider::class,
        ServerSearchProvider::class,
        DomainSearchProvider::class,
        SslCertificateSearchProvider::class,
        TicketSearchProvider::class,
        KnowledgeBaseSearchProvider::class,
        CatalogProductSearchProvider::class,
        ProductSearchProvider::class,
        InventoryAssetSearchProvider::class,
        IpAddressSearchProvider::class,
    ],
];
