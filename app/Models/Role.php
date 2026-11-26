<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_system', 'is_super'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_super' => 'boolean'];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function adminUsers(): HasMany
    {
        return $this->hasMany(AdminUser::class);
    }

    /** @return array<string, list<string>> module => actions */
    public function permissionMap(): array
    {
        $map = [];
        foreach ($this->permissions as $permission) {
            $map[$permission->module][] = $permission->action;
        }

        return $map;
    }

    public function allows(string $module, string $action): bool
    {
        if ($this->is_super) {
            return true;
        }

        return $this->permissions->contains(
            fn (RolePermission $p) => $p->module === $module && $p->action === $action
        );
    }

    /** @param array<string, list<string>> $map */
    public function syncPermissions(array $map): void
    {
        $modules = array_keys(config('permissions.modules'));
        $actions = config('permissions.actions');

        $this->permissions()->delete();
        $rows = [];
        foreach ($map as $module => $moduleActions) {
            if (! in_array($module, $modules, true)) {
                continue;
            }
            foreach (array_unique((array) $moduleActions) as $action) {
                if (in_array($action, $actions, true)) {
                    $rows[] = ['module' => $module, 'action' => $action];
                }
            }
        }
        $this->permissions()->createMany($rows);
        $this->unsetRelation('permissions');
    }
}
