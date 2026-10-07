# Nova Chatbot : règles du projet

Documents de référence : `docs/01-schema-base-de-donnees.md`, `docs/02-roles-et-permissions.md`, `docs/03-identite-visuelle-et-structure-projet.md`. Sources fonctionnelles : spec backend FR et flowcharts « Parcours utilisateur PDF » (dossier parent).

## Environnement
Tout tourne dans Docker (PHP, Postgres 16, Redis, Mailpit). Ne pas installer PHP/Composer sur la machine.
- `docker compose up -d postgres redis mailpit`
- Migrations : `docker compose run --rm --no-deps app php artisan migrate --database=pgsql_owner`
- Qualité : `docker compose run --rm --no-deps app composer check` (Pint + Larastan + Pest)

## Règles non négociables
1. **Isolation entre clients par RLS PostgreSQL.** Toute table métier porte `org_id` et passe par `Rls::enableTenantTable()`. L'application utilise le rôle `nova_app` (jamais `nova_owner`). Le contexte se pose avec `TenantContext` et se vide en fin de requête/job.
2. **Seul le cœur de réservation écrit les réservations** (les workflows n8n passent par l'API interne, jamais par la base).
3. **Super Admin** : table, guard et rôle de base séparés (`platform_admins`, `pgsql_platform`), 2FA obligatoire, sans impersonation. Toute action sensible : re-authentification, motif, entrée `Audit::record()`.
4. **Journaux en ajout seul** (`audit_logs`, `booking_events`) : jamais d'UPDATE/DELETE.
5. **Aucun texte en dur** : tout passe par les traductions fr/nl/en. Aucun prix ou durée en dur : `plans` et `platform_settings`.
6. **Aucun secret dans le code.** Les valeurs de `.env.example` sont des valeurs de dev jetables.
7. Aucune écriture en production sans autorisation explicite du fondateur. Toute décision métier importante est signalée avant d'agir.

## Conventions
- `declare(strict_types=1)`, Pint (style Laravel), Larastan niveau 6, tests Pest sur PostgreSQL réel.
- Code par modules : `app/Modules/{Identity,Tenancy,Audit,...}`.
- Fichiers en UTF-8 **sans BOM** (attention à `Set-Content -Encoding utf8` sous PowerShell 5.1).
