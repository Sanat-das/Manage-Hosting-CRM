<?php

namespace Database\Factories;

use App\Models\DeviceType;
use App\Models\Manufacturer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceType>
 */
class DeviceTypeFactory extends Factory
{
    protected $model = DeviceType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'manufacturer_id' => Manufacturer::factory(),
            'model' => fake()->unique()->bothify('Model-####??'),
            'slug' => fake()->unique()->slug(3),
            'category' => 'server',
            'part_number' => strtoupper(fake()->bothify('PN-####??')),
            'u_height' => fake()->numberBetween(1, 4),
            'is_full_depth' => fake()->boolean(),
            'power_draw_watts' => fake()->numberBetween(50, 1200),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
