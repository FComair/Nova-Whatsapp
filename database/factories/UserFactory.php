<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password-de-test'),
            'phone_e164' => '+3247'.fake()->numerify('#######'),
            'locale' => 'fr',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null, 'phone_verified_at' => null]);
    }
}
