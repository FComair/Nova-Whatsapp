<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrgMember>
 */
class OrgMemberFactory extends Factory
{
    protected $model = OrgMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrgMember::ROLE_RECEPTIONIST,
            'status' => 'active',
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => OrgMember::ROLE_OWNER]);
    }
}
