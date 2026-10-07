<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inscription (flowchart 1A) : crée l'organisation d'essai, son site par défaut, le compte propriétaire
 * et les consentements (CGU, confidentialité, DPA) avec leur version, dans une seule transaction.
 *
 * Le nom de l'entreprise n'est pas demandé à l'inscription : il est renseigné à l'étape 1 de l'assistant d'onboarding.
 */
final class RegisterOrganization
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{email: string, password: string, phone_e164: string, sector: string, plan?: string|null}  $data
     */
    public function handle(array $data, string $locale, ?string $ip): User
    {
        $orgId = (string) Str::uuid7();

        // La RLS impose que le contexte soit l'organisation à créer avant toute écriture.
        $this->tenant->set($orgId);

        try {
            return DB::transaction(function () use ($data, $locale, $ip, $orgId): User {
                $user = User::query()->create([
                    'email' => mb_strtolower(trim($data['email'])),
                    'password' => $data['password'],
                    'phone_e164' => $data['phone_e164'],
                    'locale' => $locale,
                ]);

                $placeholderName = Str::before($user->email, '@');

                Organization::query()->create([
                    'id' => $orgId,
                    'name' => $placeholderName,
                    'slug' => Str::slug($placeholderName).'-'.Str::lower(Str::random(6)),
                    'sector' => $data['sector'],
                    'locale' => $locale,
                    'status' => Organization::STATUS_TRIAL,
                    'signup_plan' => $data['plan'] ?? 'trial',
                    // Durée de l'essai : paramètre global (hypothèse : 14 jours, à valider).
                    'trial_ends_at' => now()->addDays(app(PlatformSettings::class)->integer('trial_days')),
                ]);

                Location::query()->create([
                    'org_id' => $orgId,
                    'name' => __('ui.onboarding.default_location', [], $locale),
                    'is_default' => true,
                ]);

                OnboardingProgress::query()->create(['org_id' => $orgId, 'steps' => []]);

                OrgMember::query()->create([
                    'org_id' => $orgId,
                    'user_id' => $user->id,
                    'role' => OrgMember::ROLE_OWNER,
                    'status' => 'active',
                ]);

                $this->recordConsents($user, $ip);

                Audit::record(
                    'account.registered',
                    'user',
                    actorId: $user->id,
                    orgId: $orgId,
                    entityType: 'organization',
                    entityId: $orgId,
                    diff: ['sector' => $data['sector'], 'plan' => $data['plan'] ?? 'trial'],
                );

                return $user;
            });
        } finally {
            $this->tenant->clear();
        }
    }

    private function recordConsents(User $user, ?string $ip): void
    {
        $versions = [
            'terms' => config('nova.legal.terms_version'),
            'privacy' => config('nova.legal.privacy_version'),
            'dpa' => config('nova.legal.dpa_version'),
        ];

        foreach ($versions as $type => $version) {
            DB::table('user_consents')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'type' => $type,
                'document_version' => $version,
                'granted_at' => now(),
                'source' => 'signup',
                'ip' => $ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
