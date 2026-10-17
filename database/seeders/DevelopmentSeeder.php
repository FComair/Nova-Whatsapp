<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Onboarding\Support\OnboardingSteps;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Données de DÉVELOPPEMENT uniquement (Codespaces, Docker local) : un établissement de démonstration avec son propriétaire, un service, un
 * praticien, des horaires et un client, pour parcourir l'application sans passer par l'inscription.
 *
 *   php artisan db:seed --class=DevelopmentSeeder --database=pgsql_owner
 *
 * - Refuse de s'exécuter hors environnement local : jamais de compte de démonstration en production.
 * - Idempotent : sans effet si le compte de démonstration existe déjà.
 * - Le mot de passe est une valeur de développement jetable, documentée dans le README ; aucun secret réel.
 */
class DevelopmentSeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@rendivoo.test';

    public const OWNER_PASSWORD = 'nova-dev-only';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevelopmentSeeder ne s\'exécute qu\'en environnement local (APP_ENV=local).');
        }

        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_owner'); // le propriétaire de la base écrit sans contexte d'organisation (la RLS ne s'applique pas à lui)

        try {
            if (User::query()->where('email', self::OWNER_EMAIL)->exists()) {
                $this->command->info('Compte de démonstration déjà présent : rien à faire.');

                return;
            }

            $this->seedDemoAccount();
            $this->command->info('Compte de démonstration créé : '.self::OWNER_EMAIL.' / '.self::OWNER_PASSWORD);
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function seedDemoAccount(): void
    {
        DB::transaction(function (): void {
            $org = Organization::query()->create([
                'name' => 'Salon Démo',
                'slug' => 'salon-demo-'.Str::lower(Str::random(6)),
                'sector' => 'hairdressing',
                'country' => 'BE',
                'timezone' => 'Europe/Brussels',
                'locale' => 'fr',
                'status' => Organization::STATUS_TRIAL,
                'signup_plan' => 'business',
                'trial_ends_at' => now()->addDays(14),
            ]);

            $location = Location::query()->create(['org_id' => $org->id, 'name' => 'Site principal', 'is_default' => true, 'timezone' => 'Europe/Brussels']);

            $owner = User::query()->create([
                'email' => self::OWNER_EMAIL,
                'password' => self::OWNER_PASSWORD,
                'phone_e164' => '+32470000001',
                'locale' => 'fr',
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]);

            OrgMember::query()->create(['org_id' => $org->id, 'user_id' => $owner->id, 'role' => OrgMember::ROLE_OWNER, 'status' => 'active']);
            OnboardingProgress::query()->create(['org_id' => $org->id, 'steps' => array_fill_keys(OnboardingSteps::ALL, 'done'), 'completed_at' => now()]);

            $service = Service::query()->create(['org_id' => $org->id, 'name' => 'Coupe', 'duration_minutes' => 30, 'price_cents' => 2500]);
            $practitioner = Practitioner::query()->create(['org_id' => $org->id, 'name' => 'Léa', 'color' => '#12A594']);
            DB::table('practitioner_services')->insert(['org_id' => $org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $service->id]);

            foreach (range(1, 5) as $weekday) {
                BusinessHour::query()->create(['org_id' => $org->id, 'location_id' => $location->id, 'weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '17:00']);
                PractitionerSchedule::query()->create(['org_id' => $org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $location->id, 'weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '17:00']);
            }

            Customer::query()->create(['org_id' => $org->id, 'phone_e164' => '+32470000002', 'name' => 'Camille Client', 'language' => 'fr']);

            // Acceptations des documents juridiques en vigueur, comme après une inscription (aucun avis ni blocage à l'ouverture).
            foreach (['terms', 'privacy', 'dpa'] as $type) {
                $version = DB::table('legal_document_versions')->where('document', $type)->where('status', 'published')->orderByDesc('effective_on')->value('version');

                if ($version !== null) {
                    DB::table('user_consents')->insert([
                        'id' => (string) Str::uuid7(), 'user_id' => $owner->id, 'type' => $type, 'document_version' => $version,
                        'granted_at' => now(), 'source' => 'signup', 'locale' => 'fr', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }
}
