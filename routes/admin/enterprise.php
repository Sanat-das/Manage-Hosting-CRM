<?php

use App\Http\Controllers\Admin\AssetRelationshipController;
use App\Http\Controllers\Admin\CatalogProductController;
use App\Http\Controllers\Admin\DatacenterController;
use App\Http\Controllers\Admin\IpAddressController;
use App\Http\Controllers\Admin\IpSubnetController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\ProductHostedOnController;
use App\Http\Controllers\Admin\RackController;
use App\Http\Controllers\Admin\ResourcePoolController;
use App\Http\Controllers\Admin\ResourceTypeController;
use App\Http\Controllers\Admin\ServerHostingTreeController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\UsageRecordController;
use App\Http\Controllers\Admin\VlanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Enterprise modules — admin web routes (Session 3B.2)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role) so these
| resources are NOT publicly reachable. All routes live under /admin with
| the admin. name prefix, matching the sidebar contract.
|
| Permission gates: granular per sub-resource (e.g. datacenters.view,
| ip-subnets.manage) as defined in config/permissions.php and seeded by
| AdminLteRbacSeeder. Fallback to hosting.view/manage is handled in
| PermissionMiddleware for backward compatibility.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Datacenters
    Route::get('datacenters', [DatacenterController::class, 'index'])
        ->middleware('permission:datacenters.view')->name('datacenters.index');
    Route::get('datacenters/create', [DatacenterController::class, 'create'])
        ->middleware('permission:datacenters.manage')->name('datacenters.create');
    Route::post('datacenters', [DatacenterController::class, 'store'])
        ->middleware('permission:datacenters.manage')->name('datacenters.store');
    Route::get('datacenters/{datacenter}', [DatacenterController::class, 'show'])
        ->middleware('permission:datacenters.view')->name('datacenters.show');
    Route::get('datacenters/{datacenter}/edit', [DatacenterController::class, 'edit'])
        ->middleware('permission:datacenters.manage')->name('datacenters.edit');
    Route::put('datacenters/{datacenter}', [DatacenterController::class, 'update'])
        ->middleware('permission:datacenters.manage')->name('datacenters.update');
    Route::delete('datacenters/{datacenter}', [DatacenterController::class, 'destroy'])
        ->middleware('permission:datacenters.manage')->name('datacenters.destroy');

    // Racks
    Route::get('racks', [RackController::class, 'index'])
        ->middleware('permission:racks.view')->name('racks.index');
    Route::get('racks/create', [RackController::class, 'create'])
        ->middleware('permission:racks.manage')->name('racks.create');
    Route::post('racks', [RackController::class, 'store'])
        ->middleware('permission:racks.manage')->name('racks.store');
    Route::get('racks/{rack}', [RackController::class, 'show'])
        ->middleware('permission:racks.view')->name('racks.show');
    Route::get('racks/{rack}/edit', [RackController::class, 'edit'])
        ->middleware('permission:racks.manage')->name('racks.edit');
    Route::put('racks/{rack}', [RackController::class, 'update'])
        ->middleware('permission:racks.manage')->name('racks.update');
    Route::delete('racks/{rack}', [RackController::class, 'destroy'])
        ->middleware('permission:racks.manage')->name('racks.destroy');

    // IP subnets
    Route::get('ip-subnets', [IpSubnetController::class, 'index'])
        ->middleware('permission:ip-subnets.view')->name('ip-subnets.index');
    Route::get('ip-subnets/create', [IpSubnetController::class, 'create'])
        ->middleware('permission:ip-subnets.manage')->name('ip-subnets.create');
    Route::post('ip-subnets', [IpSubnetController::class, 'store'])
        ->middleware('permission:ip-subnets.manage')->name('ip-subnets.store');
    Route::get('ip-subnets/{ipSubnet}', [IpSubnetController::class, 'show'])
        ->middleware('permission:ip-subnets.view')->name('ip-subnets.show');
    Route::get('ip-subnets/{ipSubnet}/edit', [IpSubnetController::class, 'edit'])
        ->middleware('permission:ip-subnets.manage')->name('ip-subnets.edit');
    Route::put('ip-subnets/{ipSubnet}', [IpSubnetController::class, 'update'])
        ->middleware('permission:ip-subnets.manage')->name('ip-subnets.update');
    Route::delete('ip-subnets/{ipSubnet}', [IpSubnetController::class, 'destroy'])
        ->middleware('permission:ip-subnets.manage')->name('ip-subnets.destroy');
    Route::post('ip-subnets/{ipSubnet}/generate-ips', [IpSubnetController::class, 'generateIps'])
        ->middleware('permission:ip-subnets.manage')->name('ip-subnets.generate-ips');

    // IP addresses
    Route::get('ip-addresses', [IpAddressController::class, 'index'])
        ->middleware('permission:ip-addresses.view')->name('ip-addresses.index');
    Route::get('ip-addresses/create', [IpAddressController::class, 'create'])
        ->middleware('permission:ip-addresses.manage')->name('ip-addresses.create');
    Route::post('ip-addresses', [IpAddressController::class, 'store'])
        ->middleware('permission:ip-addresses.manage')->name('ip-addresses.store');
    Route::get('ip-addresses/{ip_address}', [IpAddressController::class, 'show'])
        ->middleware('permission:ip-addresses.view')->name('ip-addresses.show');
    Route::get('ip-addresses/{ip_address}/edit', [IpAddressController::class, 'edit'])
        ->middleware('permission:ip-addresses.manage')->name('ip-addresses.edit');
    Route::put('ip-addresses/{ip_address}', [IpAddressController::class, 'update'])
        ->middleware('permission:ip-addresses.manage')->name('ip-addresses.update');
    Route::delete('ip-addresses/{ip_address}', [IpAddressController::class, 'destroy'])
        ->middleware('permission:ip-addresses.manage')->name('ip-addresses.destroy');
    Route::post('ip-addresses/{ip_address}/assign', [IpAddressController::class, 'assign'])
        ->middleware('permission:ip-addresses.manage')->name('ip-addresses.assign');
    Route::post('ip-addresses/{ip_address}/release', [IpAddressController::class, 'release'])
        ->middleware('permission:ip-addresses.manage')->name('ip-addresses.release');

    // VLANs
    Route::get('vlans', [VlanController::class, 'index'])
        ->middleware('permission:vlans.view')->name('vlans.index');
    Route::get('vlans/create', [VlanController::class, 'create'])
        ->middleware('permission:vlans.manage')->name('vlans.create');
    Route::post('vlans', [VlanController::class, 'store'])
        ->middleware('permission:vlans.manage')->name('vlans.store');
    Route::get('vlans/{vlan}', [VlanController::class, 'show'])
        ->middleware('permission:vlans.view')->name('vlans.show');
    Route::get('vlans/{vlan}/edit', [VlanController::class, 'edit'])
        ->middleware('permission:vlans.manage')->name('vlans.edit');
    Route::put('vlans/{vlan}', [VlanController::class, 'update'])
        ->middleware('permission:vlans.manage')->name('vlans.update');
    Route::delete('vlans/{vlan}', [VlanController::class, 'destroy'])
        ->middleware('permission:vlans.manage')->name('vlans.destroy');

    // Licenses
    Route::get('licenses', [LicenseController::class, 'index'])
        ->middleware('permission:licenses.view')->name('licenses.index');
    Route::get('licenses/create', [LicenseController::class, 'create'])
        ->middleware('permission:licenses.manage')->name('licenses.create');
    Route::post('licenses', [LicenseController::class, 'store'])
        ->middleware('permission:licenses.manage')->name('licenses.store');
    Route::get('licenses/{license}', [LicenseController::class, 'show'])
        ->middleware('permission:licenses.view')->name('licenses.show');
    Route::get('licenses/{license}/edit', [LicenseController::class, 'edit'])
        ->middleware('permission:licenses.manage')->name('licenses.edit');
    Route::put('licenses/{license}', [LicenseController::class, 'update'])
        ->middleware('permission:licenses.manage')->name('licenses.update');
    Route::delete('licenses/{license}', [LicenseController::class, 'destroy'])
        ->middleware('permission:licenses.manage')->name('licenses.destroy');

    // Catalog products
    Route::get('catalog-products', [CatalogProductController::class, 'index'])
        ->middleware('permission:catalog-products.view')->name('catalog-products.index');
    Route::get('catalog-products/create', [CatalogProductController::class, 'create'])
        ->middleware('permission:catalog-products.manage')->name('catalog-products.create');
    Route::post('catalog-products', [CatalogProductController::class, 'store'])
        ->middleware('permission:catalog-products.manage')->name('catalog-products.store');
    Route::get('catalog-products/{catalogProduct}', [CatalogProductController::class, 'show'])
        ->middleware('permission:catalog-products.view')->name('catalog-products.show');
    Route::get('catalog-products/{catalogProduct}/edit', [CatalogProductController::class, 'edit'])
        ->middleware('permission:catalog-products.manage')->name('catalog-products.edit');
    Route::put('catalog-products/{catalogProduct}', [CatalogProductController::class, 'update'])
        ->middleware('permission:catalog-products.manage')->name('catalog-products.update');
    Route::delete('catalog-products/{catalogProduct}', [CatalogProductController::class, 'destroy'])
        ->middleware('permission:catalog-products.manage')->name('catalog-products.destroy');

    // Subscriptions
    Route::get('subscriptions', [SubscriptionController::class, 'index'])
        ->middleware('permission:subscriptions.view')->name('subscriptions.index');
    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])
        ->middleware('permission:subscriptions.view')->name('subscriptions.show');
    Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update'])
        ->middleware('permission:subscriptions.manage')->name('subscriptions.update');

    // Usage records
    Route::get('usage-records', [UsageRecordController::class, 'index'])
        ->middleware('permission:usage-records.view')->name('usage-records.index');
    Route::get('usage-records/{usage_record}', [UsageRecordController::class, 'show'])
        ->middleware('permission:usage-records.view')->name('usage-records.show');

    // Resource types
    Route::get('resource-types', [ResourceTypeController::class, 'index'])
        ->middleware('permission:resource-types.view')->name('resource-types.index');
    Route::get('resource-types/create', [ResourceTypeController::class, 'create'])
        ->middleware('permission:resource-types.manage')->name('resource-types.create');
    Route::post('resource-types', [ResourceTypeController::class, 'store'])
        ->middleware('permission:resource-types.manage')->name('resource-types.store');
    Route::get('resource-types/{resourceType}', [ResourceTypeController::class, 'show'])
        ->middleware('permission:resource-types.view')->name('resource-types.show');
    Route::get('resource-types/{resourceType}/edit', [ResourceTypeController::class, 'edit'])
        ->middleware('permission:resource-types.manage')->name('resource-types.edit');
    Route::put('resource-types/{resourceType}', [ResourceTypeController::class, 'update'])
        ->middleware('permission:resource-types.manage')->name('resource-types.update');
    Route::delete('resource-types/{resourceType}', [ResourceTypeController::class, 'destroy'])
        ->middleware('permission:resource-types.manage')->name('resource-types.destroy');

    // Resource pools
    Route::get('resource-pools', [ResourcePoolController::class, 'index'])
        ->middleware('permission:resource-pools.view')->name('resource-pools.index');
    Route::get('resource-pools/create', [ResourcePoolController::class, 'create'])
        ->middleware('permission:resource-pools.manage')->name('resource-pools.create');
    Route::post('resource-pools', [ResourcePoolController::class, 'store'])
        ->middleware('permission:resource-pools.manage')->name('resource-pools.store');
    Route::get('resource-pools/{resourcePool}', [ResourcePoolController::class, 'show'])
        ->middleware('permission:resource-pools.view')->name('resource-pools.show');
    Route::get('resource-pools/{resourcePool}/edit', [ResourcePoolController::class, 'edit'])
        ->middleware('permission:resource-pools.manage')->name('resource-pools.edit');
    Route::put('resource-pools/{resourcePool}', [ResourcePoolController::class, 'update'])
        ->middleware('permission:resource-pools.manage')->name('resource-pools.update');
    Route::delete('resource-pools/{resourcePool}', [ResourcePoolController::class, 'destroy'])
        ->middleware('permission:resource-pools.manage')->name('resource-pools.destroy');

    // Asset relationships (reporting links)
    Route::get('asset-relationships', [AssetRelationshipController::class, 'index'])
        ->middleware('permission:asset-relationships.view')->name('asset-relationships.index');

    // Server hosting tree (read-only report)
    Route::get('hosting-tree', [ServerHostingTreeController::class, 'index'])
        ->middleware('permission:asset-relationships.view')->name('hosting-tree.index');
    Route::get('asset-relationships/create', [AssetRelationshipController::class, 'create'])
        ->middleware('permission:asset-relationships.manage')->name('asset-relationships.create');
    Route::post('asset-relationships', [AssetRelationshipController::class, 'store'])
        ->middleware('permission:asset-relationships.manage')->name('asset-relationships.store');
    Route::get('asset-relationships/{assetRelationship}/edit', [AssetRelationshipController::class, 'edit'])
        ->middleware('permission:asset-relationships.manage')->name('asset-relationships.edit');
    Route::put('asset-relationships/{assetRelationship}', [AssetRelationshipController::class, 'update'])
        ->middleware('permission:asset-relationships.manage')->name('asset-relationships.update');
    Route::delete('asset-relationships/{assetRelationship}', [AssetRelationshipController::class, 'destroy'])
        ->middleware('permission:asset-relationships.manage')->name('asset-relationships.destroy');

    // Product hosted-on report (read-only)
    Route::get('product-hosting-tree', [ProductHostedOnController::class, 'index'])
        ->middleware('permission:asset-relationships.view')->name('product-hosting-tree.index');
});
