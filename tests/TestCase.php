<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Tenancy\Support\DefaultSectors;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Base des tests sur PostgreSQL réel.
 *
 * - Le schéma est migré UNE fois par exécution, avec le rôle propriétaire (`pgsql_owner`).
 * - Les tests interrogent la base avec le rôle applicatif (`pgsql`), donc SOUMIS à la RLS : c'est le comportement de production.
 * - Les données de départ sont créées via `asOwner()` (le propriétaire contourne la RLS).
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Le client de test de Symfony envoie « Accept-Language: en-us » : on fixe le français, langue par défaut de Nova.
        $this->withHeader('Accept-Language', 'fr');

        if (! self::$schemaReady) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
            self::$schemaReady = true;
        }

        $this->resetDatabase();
        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        // Ne jamais laisser un contexte d'organisation sur une connexion réutilisée.
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Exécute un bloc avec le rôle propriétaire (contourne la RLS) pour préparer des données.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function asOwner(callable $callback): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_owner');

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function resetDatabase(): void
    {
        $owner = DB::connection('pgsql_owner');

        // session_replication_role = replica désactive les triggers « ajout seul » le temps de vider les tables.
        $owner->unprepared('SET session_replication_role = replica');
        $owner->unprepared('TRUNCATE invoice_sequences, audit_logs, user_consents, password_reset_tokens, org_member_locations, org_members, locations, organizations, platform_admins, platform_notifications, support_saved_views, faq_categories, users, message_template_events RESTART IDENTITY CASCADE');
        $owner->unprepared('SET session_replication_role = DEFAULT');

        // Les secteurs sont des données de référence que certains tests modifient : retour aux secteurs de départ à chaque test.
        $owner->unprepared('DELETE FROM sectors');
        DefaultSectors::insert('pgsql_owner');
    }
}
