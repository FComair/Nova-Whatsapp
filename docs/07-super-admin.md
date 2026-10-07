# Super Admin : actions sensibles (étape 5a)

Panel Filament séparé (`/admin`), guard `platform`, rôle PostgreSQL `nova_platform`, 2FA TOTP obligatoire, sans impersonation.

## Garde-fous de toute action sensible

Motif facultatif (décision du fondateur, 2026-10-07). Plus de saisie du nom de l'organisation. Le mot de passe et le code TOTP ne sont demandés qu'à la connexion (décision du fondateur, 2026-10-07 : plus de re-authentification par action). Chaque action réussie écrit dans `audit_logs` (acteur, motif, ancien et nouveau statut, IP, agent) et prévient le propriétaire par e-mail dans sa langue.

## Actions disponibles

| Action | Depuis | Effet |
|---|---|---|
| Suspendre | essai, actif, impayé, en pause | `suspended` : dashboard en lecture seule, bot indisponible, rappels arrêtés ; le statut d'avant est mémorisé |
| Lever la suspension | suspendu | retour au statut d'avant (un essai échu devient « actif ») |
| Programmer la suppression | tout sauf déjà en suppression ou supprimé | `pending_deletion`, date = maintenant + `deletion_delay_days` (30 par défaut, modifiable) |
| Annuler la suppression | en suppression, effacement pas commencé | retour au statut d'avant (y compris « suspendu ») |
| Supprimer immédiatement | tout sauf supprimé | fraude ou abus : échéance = maintenant, nom saisi + case « irréversible » ; effacement dans les 5 minutes |
| Suppressions (suivi) | — | avancement par table, étape bloquée (E12), relance avec motif |
| Paramètres globaux | — | modifier `deletion_delay_days`, `pause_max_days`, `suspension_max_days` (avec bornes 1 à 365) |
| Journal d'audit | — | consultation en lecture seule |

## Ce que le rôle plateforme ne peut pas faire

Lire la table des utilisateurs (l'e-mail du propriétaire n'est lu que par la fonction étroite `org_owner_contact`), écrire dans les réservations, les clients ou les conversations, modifier ou supprimer le journal d'audit. Le panel n'expose que les niveaux 1 et 2 de données (compte, statut, configuration) ; les niveaux 3 et 4 de docs/02 §3.2 restent hors panel.

## Pas encore fait (étape suivante)

Exécution réelle de la suppression à l'échéance (pipeline RGPD `deletion_jobs`, relances, E12), suppression immédiate pour fraude, export avant suppression, accès exceptionnel niveau 3, plans et fonctions par client, facturation, supervision.