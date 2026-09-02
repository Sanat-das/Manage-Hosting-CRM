<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $new = [
            'hosting.create'  => 'Create Hosting Services',
            'hosting.edit'    => 'Edit Hosting Services',
            'hosting.suspend' => 'Suspend / Unsuspend Hosting Services',
            'hosting.delete'  => 'Delete Hosting Services',
        ];

        foreach ($new as $name => $label) {
            Permission::firstOrCreate(['name' => $name], ['label' => $label]);
        }

        // Narrow the hosting.manage label — it now covers servers only, not all hosting.
        Permission::where('name', 'hosting.manage')->update(['label' => 'Manage Servers']);
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'hosting.create',
            'hosting.edit',
            'hosting.suspend',
            'hosting.delete',
        ])->delete();
    }
};
