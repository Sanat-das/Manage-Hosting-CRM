<?php

/*
|--------------------------------------------------------------------------
| Granular Permission Registry
|--------------------------------------------------------------------------
| Central inventory of granular infrastructure permissions that replace the
| coarse hosting.view/manage gates. Seeded by AdminLteRbacSeeder and
| enforced via PermissionMiddleware (with fallback to hosting.* for
| backward compatibility).
|
| Naming: <resource>.view / <resource>.manage where resource matches the
| route slug (e.g. datacenters, ip-subnets). Hyphens preserved from slugs.
*/

return [
    'infrastructure' => [
        'datacenters' => ['view' => 'datacenters.view', 'manage' => 'datacenters.manage'],
        'racks' => ['view' => 'racks.view', 'manage' => 'racks.manage'],
        'ip-subnets' => ['view' => 'ip-subnets.view', 'manage' => 'ip-subnets.manage'],
        'ip-addresses' => ['view' => 'ip-addresses.view', 'manage' => 'ip-addresses.manage'],
        'vlans' => ['view' => 'vlans.view', 'manage' => 'vlans.manage'],
        'dns-zones' => ['view' => 'dns-zones.view', 'manage' => 'dns-zones.manage'],
        'dns-records' => ['view' => 'dns-records.view', 'manage' => 'dns-records.manage'],
        'licenses' => ['view' => 'licenses.view', 'manage' => 'licenses.manage'],
        'catalog-products' => ['view' => 'catalog-products.view', 'manage' => 'catalog-products.manage'],
        'subscriptions' => ['view' => 'subscriptions.view', 'manage' => 'subscriptions.manage'],
        'usage-records' => ['view' => 'usage-records.view', 'manage' => 'usage-records.manage'],
        'resource-types' => ['view' => 'resource-types.view', 'manage' => 'resource-types.manage'],
        'resource-pools' => ['view' => 'resource-pools.view', 'manage' => 'resource-pools.manage'],
        'asset-relationships' => ['view' => 'asset-relationships.view', 'manage' => 'asset-relationships.manage'],
        'inventory' => ['view' => 'inventory.view', 'manage' => 'inventory.manage'],
        'tax-rates' => ['view' => 'tax-rates.view', 'manage' => 'tax-rates.manage'],
        'product-bundles' => ['view' => 'product-bundles.view', 'manage' => 'product-bundles.manage'],
        'product-upgrades' => ['view' => 'product-upgrades.view', 'manage' => 'product-upgrades.manage'],
        'service-instances' => ['view' => 'service-instances.view', 'manage' => 'service-instances.manage'],
        'provisioning-events' => ['view' => 'provisioning-events.view', 'manage' => 'provisioning-events.manage'],
    ],

    // Non-infrastructure granular permissions added in this fix
    'notifications' => [
        'view' => 'notifications.view',
        'manage' => 'notifications.manage',
    ],

    'users' => [
        'manage' => 'manage-users',
    ],
];
