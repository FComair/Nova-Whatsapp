<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'name' => fake()->city(),
            'timezone' => 'Europe/Brussels',
            'is_default' => false,
        ];
    }
}
