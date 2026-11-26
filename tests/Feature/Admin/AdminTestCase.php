<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\AuthenticateAdmin;
use App\Models\AdminUser;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    protected function makeAdmin(string $roleSlug, array $attributes = []): AdminUser
    {
        return AdminUser::factory()->role($roleSlug)->create($attributes);
    }

    protected function signIn(AdminUser $user): static
    {
        return $this->actingAs($user, 'admin')
            ->withSession([AuthenticateAdmin::SESSION_KEY => $user->session_version]);
    }
}
