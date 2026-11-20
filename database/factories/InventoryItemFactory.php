<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => fn () => Device::create([
                'asset_tag' => 'DEV-'.fake()->unique()->numerify('#####'),
                'device_type_id' => DeviceType::factory()->create()->id,
            ])->id,
            'name' => fake()->words(2, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'part_number' => strtoupper(fake()->bothify('PN-####??')),
            'serial_number' => strtoupper(fake()->unique()->bothify('SN-########')),
            'status' => 'installed',
            'discovered' => false,
        ];
    }
}
