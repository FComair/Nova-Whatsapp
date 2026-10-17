# Rendivoo — Identité visuelle et structure du projet (proposition v1, à valider)

## Partie A — Identité visuelle

**Principes** : sobre, rassurant, lisible pour des utilisateurs non techniques (coiffeurs, kinés, garagistes). Continuité avec le bleu marine des flowcharts Rendivoo. Aucune police ni ressource chargée depuis un CDN externe (RGPD : pas de transfert d'adresse IP à un tiers) — polices **auto-hébergées**.

### A.1 Palette (jetons de design)

| Jeton | Valeur | Usage |
|---|---|---|
| `rendivoo-900` | `#14213D` | Texte principal, sidebar client |
| `rendivoo-700` | `#1F3A6B` | Titres, éléments actifs |
| `primary-600` | `#2A5BD7` | Boutons primaires, liens |
| `primary-50` | `#EEF3FE` | Fonds de sélection |
| `accent-500` | `#12A594` | Accent secondaire, succès WhatsApp/bot |
| `success-600` | `#15803D` | Confirmations |
| `warning-600` | `#B45309` | Avertissements |
| `danger-600` | `#DC2626` | Erreurs, actions destructrices |
| `neutral-50 … 900` | Gris ardoise | Fonds, bordures, texte secondaire |

Contrastes visés : **WCAG 2.2 niveau AA** (4,5:1 texte, 3:1 composants). Les couleurs ne sont jamais le seul vecteur d'information (icône + texte).

**Super Admin** : même système, mais sidebar sombre, **bandeau permanent « ADMIN »** et accent ambre, pour qu'aucune confusion avec l'espace client ne soit possible.

### A.2 Typographie et mise en page
- **Inter** (texte et titres), auto-hébergée, avec repli système.
- Échelle : 12 / 14 / 16 (base) / 20 / 24 / 32 px. Interligne 1,5.
- Grille : mobile (< 640 px), tablette (640–1024 px), bureau (> 1024 px). Navigation : sidebar sur bureau, barre inférieure + menu sur mobile.
- Rayons 8 px, ombres légères, icônes Lucide.

### A.3 Composants à construire (bibliothèque partagée)
Boutons, champs avec validation sous le champ, sélecteur de langue (FR/NL/EN, mémorisé), tableaux avec recherche/filtres/pagination, **planning** (colonne par praticien, glisser-déposer, vue mobile en liste), modales de confirmation (saisie de « SUPPRIMER » pour les actions critiques), toasts de succès/échec, **états vides** avec action suggérée, **squelettes de chargement**, assistant multi-étapes avec barre de progression et reprise, bandeaux d'état de compte (essai, impayé, suspension, pause).

### A.4 Écrans clés à maquetter en premier
1. Inscription + vérification. 2. Assistant d'onboarding (étapes 1 à 9). 3. Tableau de bord. 4. Planning. 5. Paramètres > Compte (pause, suppression). 6. Super Admin : liste des clients et fiche client.

### A.5 Internationalisation
- Langues **fr, nl, en**, clés de traduction centralisées ; aucun texte en dur dans le code ni dans les modèles de vues.
- Ordre de détection : préférence du compte > `Accept-Language` > langue de l'organisation.
- Dates, nombres et devises formatés par la locale (`Intl`). Textes d'erreur du catalogue E1–E14 (+E15) traduits ; **le ton de marque reste à valider** (le catalogue est marqué « textes proposés »).
- Tests : chaque parcours vérifié dans les 3 langues, y compris les emails et les messages système ; contrôle automatique des clés manquantes.

## Partie B — Structure du projet

### B.1 Choix techniques

| Domaine | Choix | Justification |
|---|---|---|
| Backend | **Laravel** (dernière version stable à l'ouverture du projet) | Décision du fondateur ; monolithe modulaire. |
| Base | PostgreSQL 16 + Redis | RLS, `btree_gist`, `tstzrange` ; sessions, files, cache de disponibilités [Spec]. |
| Client | **Inertia + Vue 3 + TypeScript + Tailwind** | Un seul déploiement, interface réactive, typage. |
| Super Admin | **Filament** (panel séparé) — *reco* | Tableaux, filtres et formulaires prêts : gain de temps ; évite un second design system à construire. À valider. |
| Files et jobs | Laravel Queues + Horizon (Redis), Scheduler | Rappels, synchronisations de calendrier, agrégations, pipeline de suppression [Spec §2]. |
| Tests | Pest (PHP), Vitest (front), Playwright (parcours + responsive) | Couvre permissions, isolation, 3 langues, flowcharts. |
| Qualité | Laravel Pint, Larastan (PHPStan), ESLint + Prettier, vue-tsc | Lint, format, types, build en CI. |
| Local | Docker Compose (Postgres, Redis, Mailpit) | Indépendant de l'hébergeur ; cible : VPS UE [Décision]. |

### B.2 Découpage en modules (monolithe modulaire)

```
app/Modules/
  Identity/     authentification, sessions, 2FA, vérifications, invitations
  Tenancy/      organisations, sites, membres, RLS, statuts
  Onboarding/   assistant en 9 étapes, modèles sectoriels, scan de carte
  Catalog/      services, praticiens, horaires, politiques
  Booking/      disponibilités, cœur de réservation (seul écrivain), événements
  Calendars/    import ICS/CalDAV, blocs occupés, flux ICS sortant
  Customers/    clients finaux, consentements, demandes d'effacement (E14)
  Messaging/    WhatsApp (passerelle Meta Cloud API), templates, outbox, staff
  Campaigns/    campagnes, liste d'attente, avis, parrainage
  Billing/      plans, abonnements, factures, limites, PaymentGateway
  Reporting/    daily_stats, rapports, exports
  Privacy/      exports, suppression J+30, deletion_jobs, sous-traitants
  Audit/        journal d'audit, accès sensibles
  Support/      tickets, FAQ
  Admin/        Super Admin (Filament) — dépend des autres modules, jamais l'inverse
  InternalApi/  API pour n8n (scopes, HMAC, idempotence)
```

Règle : les modules communiquent par **services et événements de domaine** ; seul `Booking` écrit l'état des réservations [Spec].

### B.3 Séparation client / Super Admin
- Sous-domaines distincts (`app.…` et `admin.…`), **guards** `web` et `platform` séparés, cookies de session distincts, middleware qui refuse toute session croisée.
- Super Admin : 2FA obligatoire, limitation de débit renforcée, en-têtes de sécurité stricts (CSP, HSTS).

### B.4 Sécurité applicative (base)
Mots de passe Argon2id ; limitation de tentatives (5 puis 15 min) ; CSRF, validation stricte des entrées (Form Requests), échappement systématique ; en-têtes CSP/HSTS ; secrets uniquement en variables d'environnement ; chiffrement applicatif des champs sensibles ; journal d'audit sur toute action sensible ; dépendances auditées en CI.

### B.5 Qualité et livraison
Chaque étape du plan livre : tests unitaires + fonctionnels + isolation entre clients + rôles + 3 langues ; lint, formatage et build verts ; liste des fichiers modifiés ; points restants à valider. **Aucune écriture en production sans votre autorisation explicite.**

### B.6 Ce qui n'est **pas** fait à ce stade
Aucun code, aucun `git init`, aucune installation. Le dossier `Code` ne contient que `docs/`.

## Partie C — Décisions à valider pour lancer l'étape 1

1. **Filament** pour le Super Admin (reco) ou interface entièrement sur mesure ?
2. **Identité visuelle** A.1–A.3 : validée, ou ajustements (couleurs, ton) ?
3. **Niveaux d'accès aux données** du Super Admin (`02` §3.2) : validés ?
4. **Noms et prix des plans** : Starter/Basic et 29,99/34,99 € ; 49,99/69,99 € (les prix seront en base, mais les noms d'affichage et le seed initial doivent être tranchés).
5. `git init` du dossier `Code` : autorisé ?
