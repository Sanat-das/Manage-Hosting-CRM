<?php

/**
 * Built-in server integrations — always available, no activation step.
 *
 * Add a class under app/Modules/<Name>/ implementing
 * App\Contracts\Integrations\Capabilities\ProvisioningModule (plus
 * TestableServerModule when it can act as a server type), then add an entry
 * here. `group` must be one of panel|virtualization|compute.
 */
return [
    'builtins' => [
        'cpanel' => [
            'class' => \App\Modules\Cpanel\Cpanel::class,
            'name' => 'cPanel/WHM Provisioning',
            'group' => 'panel',
            'description' => '',
        ],
        'plesk' => [
            'class' => \App\Modules\Plesk\Plesk::class,
            'name' => 'Plesk Provisioning',
            'group' => 'panel',
            'description' => '',
        ],
        'directadmin' => [
            'class' => \App\Modules\DirectAdmin\DirectAdmin::class,
            'name' => 'DirectAdmin Provisioning',
            'group' => 'panel',
            'description' => '',
        ],
        'virtualizor' => [
            'class' => \App\Modules\Virtualizor\Virtualizor::class,
            'name' => 'Virtualizor Provisioning',
            'group' => 'virtualization',
            'description' => '',
        ],
        'hyperv' => [
            'class' => \App\Modules\HyperV\HyperV::class,
            'name' => 'Hyper-V Compute',
            'group' => 'virtualization',
            'description' => '',
        ],
        'proxmox' => [
            'class' => \App\Modules\Proxmox\Proxmox::class,
            'name' => 'Proxmox VE',
            'group' => 'virtualization',
            'description' => 'Proxmox VE stub — coming soon.',
        ],
    ],
];
