# 37 · Audit RGPD (2026-10-10)

Audit complet de la plateforme (code, base de développement, configuration, documents). Le **rapport détaillé** (Word, confidentiel : il décrit des failles) est généré hors dépôt dans `output/Nova_SaaS_GDPR_Compliance_Audit_Report.docx` (dossier ignoré par Git). Ce document résume ce qui concerne le code.

## Résultat

Conformité **partielle**, pas encore prête pour une mise en production avec des clients européens. Base technique solide (RLS forcée, rôles séparés, 2FA obligatoire, chiffrement des conversations et secrets, journaux en ajout seul, effacement testé). Écarts surtout de **transparence envers les clients (sous-traitants), de gouvernance et de cycle de vie des données**. 29 constats : 0 critique, 6 élevés, 13 moyens, 10 faibles.

## Corrigé dans le code

| Constat | Correction |
|---|---|
| Connexions du Super Admin non tracées, `last_login_at` jamais écrit | `RecordAdminAuthentication` : `platform.login`, `platform.login_failed` (compte inconnu : empreinte SHA-256 seulement), dernière connexion enregistrée |
| Session Super Admin valable 120 min sans activité | `EnforceAdminIdleTimeout` : déconnexion après `NOVA_ADMIN_IDLE_MINUTES` (60 par défaut, 0 = coupé), entrée `platform.session_expired`, Livewire répond 419 |
| Ports de développement ouverts sur toutes les interfaces (PostgreSQL, Redis sans mot de passe, Mailpit) | `docker-compose.yml` : tous les ports liés à 127.0.0.1 |
| Charges utiles des tâches échouées et jetons de réinitialisation conservés | Planifié chaque nuit : `queue:prune-failed --hours=168`, `auth:clear-resets` |
| Preflight ne contrôlait ni HTTPS ni TLS ni l'IA | `rendivoo:preflight` : erreurs si `APP_URL`, n8n ou l'IA ne sont pas en HTTPS ; avertissements si l'IA est active (sous-traitant à déclarer), si `DB_SSLMODE` n'impose pas TLS, si le délai d'inactivité admin est coupé |
| Dépendances npm vulnérables, aucun contrôle automatique | `npm audit fix` (0 vulnérabilité), `.github/workflows/security.yml` (composer et npm audit à chaque envoi et chaque semaine), `.github/dependabot.yml` |
| PDF joints aux demandes d'aide acceptés sans contrôle du contenu actif | `App\Support\Files\PdfInspector` (partagé avec le dépôt de PDF juridiques) : `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile`, `/RichMedia` refusés |
| Rapport d'audit risquant d'être versionné | `/output/` ajouté à `.gitignore` |

Tests : `tests/Feature/Security/GdprAuditTest.php` (connexions, expiration, planification, preflight, PDF), `HardeningTest` mis à jour.

## À décider (non traité dans le code)

Sous-traitants IA et n8n à déclarer dans le DPA et la politique de confidentialité ; textes juridiques à compléter (versions publiées figées, brouillon dans le rapport) ; durées de conservation (proposition dans le rapport, purge à écrire ensuite) ; sauvegardes ; données de santé et AIPD ; limitation du rôle de base `nova_platform` à des fonctions d'agrégat (il lit aujourd'hui toutes les tables des clients) ; plan de réponse aux incidents, registre des violations et DPO ; outils de droits des personnes (export par client, restriction, demandes initiées par le client final) ; tunnel public de développement ; fournisseurs de production (hébergement, e-mail, SMS, paiement).

## Variables ajoutées

`NOVA_ADMIN_IDLE_MINUTES` (défaut 60, `0` = désactivé).
