<?php

namespace App\Models;

use App\Support\Notifications\EventCatalogue;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Role extends Model
{
    use HasFactory;

    protected $table = 'adminlte_roles';

    protected $fillable = [
        'name',
        'label',
    ];

    protected static function booted(): void
    {
        static::updating(static function (Role $role): void {
            $new = (string) $role->name;
            $old = (string) $role->getOriginal('name');

            if ($old === '' || $old === $new || ! Schema::hasTable('notification_event_settings')) {
                return;
            }

            // Defensive: never leave two audiences claiming the same name.
            DB::table('notification_event_settings')->where('audience', $new)->delete();

            DB::table('notification_event_settings')
                ->where('audience', $old)
                ->update([
                    'audience' => $new,
                    'updated_at' => now(),
                ]);
        });

        static::saved(static function (): void {
            EventCatalogue::flushAudiences();
            NotificationMatrix::flush();
        });

        static::deleted(static function (Role $role): void {
            if (Schema::hasTable('notification_event_settings')) {
                DB::table('notification_event_settings')
                    ->where('audience', (string) $role->name)
                    ->delete();
            }

            EventCatalogue::flushAudiences();
            NotificationMatrix::flush();
        });
    }

    /**
     * Users that are assigned this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'adminlte_role_user');
    }

    /**
     * Permissions granted to this role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'adminlte_permission_role');
    }

    /**
     * Determine whether the role has the given permission.
     */
    public function hasPermission(string $name): bool
    {
        return $this->permissions()->where('name', $name)->exists();
    }
}
