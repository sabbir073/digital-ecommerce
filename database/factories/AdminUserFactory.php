<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminUser> */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password-123',
            'status' => AdminUser::STATUS_ACTIVE,
        ];
    }

    public function role(string $slug): static
    {
        return $this->state(fn () => ['role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }
}
