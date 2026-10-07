<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Models;

use App\Modules\Admin\Models\PlatformOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Suivi de la suppression d'un compte (rôle plateforme uniquement). Le contact du propriétaire ne sert qu'au message de confirmation.
 *
 * @property string $org_id
 * @property string $kind standard | immediate
 * @property string|null $owner_email_enc
 * @property string|null $owner_locale
 * @property string|null $org_name_enc
 * @property Carbon $scheduled_for
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $alerted_at
 */
class DeletionRequest extends Model
{
    protected $connection = 'pgsql_platform';

    protected $table = 'deletion_requests';

    protected $primaryKey = 'org_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['owner_email_enc', 'org_name_enc'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['owner_email_enc' => 'encrypted', 'org_name_enc' => 'encrypted', 'scheduled_for' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'alerted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<PlatformOrganization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(PlatformOrganization::class, 'org_id');
    }

    /**
     * @return HasMany<DeletionJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(DeletionJob::class, 'org_id', 'org_id');
    }
}
