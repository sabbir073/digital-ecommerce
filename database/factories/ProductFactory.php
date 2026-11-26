<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'active',
            'price_cents' => $this->faker->numberBetween(5, 120) * 100,
            'track_stock' => false,
            'stock' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function stock(int $units): static
    {
        return $this->state(['track_stock' => true, 'stock' => $units]);
    }
}
