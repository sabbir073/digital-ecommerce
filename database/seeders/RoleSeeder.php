<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/** Creates the four built-in roles from config/permissions.php. Safe to run again. */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('permissions.default_roles') as $slug => $definition) {
            $role = Role::query()->updateOrCreate(['slug' => $slug], [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_system' => true,
                'is_super' => $definition['is_super'],
            ]);
            $role->syncPermissions($definition['permissions']);
        }
    }
}
