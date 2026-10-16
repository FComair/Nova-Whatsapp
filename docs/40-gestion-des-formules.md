# Gestion des formules (Super Admin)

Création, configuration, publication, duplication, archivage, suppression et migration des formules d'abonnement, avec affichage automatique côté client. Écran : Configuration › Formules. Page publique : `/tarifs` (et `/tarifs.json`).

## 1. État avant ce chantier (audit)

| Élément | Constat |
|---|---|
| Table `plans` | Globale, lecture seule pour l'application, **aucune écriture du Super Admin**. `code`, `name`, `price_monthly_cents`, `price_yearly_cents` (inutilisé), `currency`, `limits` (praticiens, sites, conversations, rendez-vous), `is_active`, `position`, `is_recommended`. Deux formules seedées : Basic, Business. |
| Abonnements | `subscriptions.plan_id` et `pending_plan_id` (descente programmée à l'échéance) ; `organizations.plan_id` et `signup_plan`. |
| Facturation | `BillingService` facturait **toujours un mois** et relisait le prix **courant** de la formule à chaque renouvellement : modifier un prix aurait changé les abonnés en cours. |
| Écrans clients | Choix de formule à l'inscription (`SignupPlans`, `PlanPicker`) et page Facturation. Pas de page tarifs publique. |

## 2. Structure de données

`plans` est **étendue**, pas remplacée (les abonnements y pointent) :

- Identité : `name`, `code` (technique, ne change plus), `internal_description`, `public_description` (fr/nl/en), `status` (`draft`, `active`, `inactive`, `archived`).
- Prix : `plan_type` (`free_trial`, `monthly`, `annual`, `custom`, `one_time`), `billing_frequency` (`monthly`, `quarterly`, `yearly`, `custom`) et `billing_interval_months` (1 à 60), `price_cents` (prix d'**une période**), `price_monthly_cents` (équivalent mensuel, tenu à jour), `currency`, `tax_mode` (`excluded` / `included`), `setup_fee_cents`, `trial_days`.
- Visibilité : `visibility` (`public`, `private`, `invite_only`, `segments`), `invite_code`, `segments` (secteurs et pays), `available_from` / `available_until` (UTC), `position`, `is_recommended` (une seule, garanti par la base).
- Présentation : `features` (liste ordonnée : libellé fr/nl/en, état `included` / `limited` / `unavailable`, valeur facultative), `limits` (les 4 limites **appliquées** par la plateforme), `cta_type` (`choose_plan`, `start_trial`, `contact_sales`) et `cta_url`.
- Versions : `family_id`, `version`, `superseded_by`.

`is_active` et `price_monthly_cents` restent utilisables par l'existant : un déclencheur SQL (`plans_sync_legacy`) garde `status` et `price_cents` d'accord avec eux.

## 3. Règles métier

- **Brouillon** : jamais visible d'un client. **Inactive** : retirée de la vente. **Archivée** : plus proposée aux nouveaux abonnements, consultable dans le Super Admin.
- **Les abonnés en cours ne changent jamais par surprise.** Modifier le prix, la devise, la période, la TVA, les frais ou les limites d'une formule qui a des abonnés crée une **nouvelle version** : l'ancienne (code `xxx~v1`, archivée, `superseded_by`) garde son prix et ses limites pour eux ; les nouveaux abonnés et les essais prennent la version en vigueur. Le reste (textes, fonctionnalités affichées, ordre, visibilité, dates, bouton) se modifie sur place.
- **Suppression** : refusée tant qu'un abonnement ou un compte référence la formule. **Migration explicite** : « Migrer les abonnés » programme le passage à leur **prochaine échéance** (`pending_plan_id`, mécanisme existant), au prix de la formule de destination, sans prélèvement immédiat ; il faut prévenir les clients (pas de message automatique).
- **Publier** exige une formule cohérente : un paiement unique ne se publie pas (pas de moteur d'encaissement), une formule mensuelle ou annuelle a un prix > 0, « Choisir cette formule » n'existe que pour une formule achetable, « Contacter l'équipe commerciale » a une adresse.
- Une formule se **souscrit en ligne** seulement si elle est mensuelle ou annuelle et payante. Essai gratuit, sur mesure et paiement unique sont affichés (page tarifs) mais pas encaissés en ligne.
- **Visibilité** : `public` listée pour tous ; `private` jamais listée, accessible par `/inscription?plan=code` ; `invite_only` listée avec `/inscription?invite=CODE` ; `segments` listée pour les secteurs / pays choisis (page Facturation du compte concerné, ou `?sector=` à l'inscription).
- Une seule formule **recommandée** : en désigner une retire la précédente.

## 4. Facturation (modifiée)

`BillingService` utilise la période de la formule (`addMonths(interval)`), le prix hors TVA de la période (`Plan::netCents` : un prix « TVA comprise » est ramené hors taxe au taux belge), les frais de mise en service (deuxième ligne de la **première** facture seulement) et compare les formules par équivalent mensuel hors TVA pour monter / descendre. Pour les formules mensuelles existantes le comportement et les montants sont inchangés.

## 5. Parcours Super Admin

Liste (statut, prix, cycle, visibilité, abonnés ; recherche, filtres, tri, ordre par glisser-déposer) → création / modification sur une page avec **aperçu en direct** de la carte client (mêmes données que la page publique, avec la raison si elle n'y figure pas) → publier, retirer, archiver (confirmation avec le nombre d'abonnés), dupliquer, migrer les abonnés, supprimer (confirmation, refus clair). Toutes les opérations passent par `PlanManager` (permission `settings.edit`, transaction, journal d'audit `plan.*`).

## 6. À valider

- La **TVA comprise** est ramenée hors taxe au taux belge (21 %) ; pour un client en autoliquidation, il paie le hors-taxe correspondant.
- Les **fonctionnalités affichées** (hors les 4 limites appliquées) sont descriptives : elles ne bloquent rien dans l'application. Les modifier ne crée pas de version.
- Les **dates de disponibilité** sont saisies et affichées en UTC.
- Le **paiement unique** est gérable mais non publiable tant que la facturation ne l'encaisse pas.
- Les **messages WhatsApp / e-mails** aux abonnés migrés ne sont pas automatiques.
