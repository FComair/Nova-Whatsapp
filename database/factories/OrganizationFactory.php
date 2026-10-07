<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'sector' => 'beauty',
            'country' => 'BE',
            'timezone' => 'Europe/Brussels',
            'locale' => 'fr',
            'status' => Organization::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(14),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
