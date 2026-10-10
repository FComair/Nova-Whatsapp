<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Identity\Models\User;
use Database\Factories\OrgMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rattachement utilisateur ↔ organisation, avec son rôle (docs/02-roles-et-permissions.md §1).
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $role
 * @property string $status
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $preset
 * @property array<string, bool>|null $permissions
 * @property string|null $notes
 */
class OrgMember extends Model
{
    /** @use HasFactory<OrgMemberFactory> */
    use HasFactory, HasUuids;

    public const ROLE_OWNER = 'owner';

    public const ROLE_SITE_MANAGER = 'site_manager';

    public const ROLE_PRACTITIONER = 'practitioner';

    public const ROLE_RECEPTIONIST = 'receptionist';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_SITE_MANAGER, self::ROLE_PRACTITIONER, self::ROLE_RECEPTIONIST];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['permissions' => 'array', 'invited_at' => 'datetime', 'suspended_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    protected static function newFactory(): OrgMemberFactory
    {
        return OrgMemberFactory::new();
    }
}
