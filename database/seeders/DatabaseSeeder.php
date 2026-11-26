<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        // Placeholder store details until the owner supplies the real ones.
        if (Setting::get('store.name') === null) {
            Setting::put('store.name', 'Northlane Digital');
        }
    }
}
