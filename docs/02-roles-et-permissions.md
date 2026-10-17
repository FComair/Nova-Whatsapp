# Rendivoo — Rôles, permissions et règles d'accès (proposition v1, à valider)

Légende : ✅ autorisé · 👁 lecture seule · 🔸 limité (voir note) · ❌ interdit.
Sources : spec §4.1, §9, §10 ; flowcharts 2B, 3A–3C, 4A–4D ; décisions du fondateur.

## 1. Espace client — rôles

> **Depuis docs/34 (Équipe et accès)**, le tableau ci-dessous donne les droits PAR DÉFAUT de chaque rôle. Le propriétaire peut les ajuster personne par personne (capacité par capacité) ; le serveur applique les droits effectifs (`CurrentTenant::can()`), le propriétaire et les droits sensibles restant protégés.

Rôles de la spec : **propriétaire**, **responsable de site**, **praticien**, **réceptionniste**. Un seul propriétaire par organisation (le flowchart 4C : « seul le propriétaire peut demander la suppression »).

| Fonction | Propriétaire | Resp. de site | Praticien | Réceptionniste |
|---|---|---|---|---|
| Tableau de bord (résumé du jour) | ✅ | ✅ (ses sites) | 🔸 son agenda | ✅ (ses sites) |
| Planning : voir | ✅ | ✅ (ses sites) | 🔸 le sien | ✅ (ses sites) |
| Planning : créer / déplacer / annuler un RDV | ✅ | ✅ | 🔸 les siens | ✅ |
| Clients : voir, modifier, notes, bloquer | ✅ | ✅ | 🔸 ses clients | ✅ (sans notes santé) |
| Notes et réponses d'accueil sensibles | ✅ | ✅ | 🔸 ses clients | ❌ |
| Services et catégories | ✅ | ✅ (son site) | 🔸 ses services (durée/prix perso) | 👁 |
| Praticiens : inviter, désactiver | ✅ | 🔸 son site | ❌ | ❌ |
| Ses propres horaires et indisponibilités | ✅ | ✅ | ✅ | ❌ |
| Horaires d'ouverture, jours fériés | ✅ | ✅ (son site) | 👁 | 👁 |
| Sites : créer / modifier | ✅ | 🔸 modifier le sien | ❌ | ❌ |
| Politiques de réservation | ✅ | 🔸 son site | ❌ | ❌ |
| Calendriers externes (import) | ✅ | ✅ (son site) | 🔸 le sien | ❌ |
| Flux ICS sortant (jeton) | ✅ | ✅ | 🔸 le sien | ❌ |
| WhatsApp, bot, modèles de messages | ✅ | 👁 | ❌ | ❌ |
| FAQ du bot | ✅ | ✅ | ❌ | 👁 |
| Retours clients : liste d'attente, avis, parrainage | ✅ | ✅ | 🔸 ses avis | ✅ |
| Campagnes WhatsApp | ✅ | ❌ | ❌ | ❌ |
| Rapports (activité, clients, bot) | ✅ | ✅ (ses sites) | 🔸 les siens | ❌ |
| Rapports financiers | ✅ | 🔸 selon réglage propriétaire | ❌ | ❌ |
| Export CSV / PDF des rapports | ✅ | ✅ | ❌ | ❌ |
| Équipe : rôles, invitations | ✅ | ❌ | ❌ | ❌ |
| Abonnement, factures, moyen de paiement | ✅ | ❌ | ❌ | ❌ |
| Notifications, consentements de Rendivoo (son compte) | ✅ | ✅ | ✅ | ✅ |
| Consentements des clients finaux (opt-in, STOP) | ✅ | 👁 | ❌ | 👁 |
| Mode « données sensibles » | ✅ | ❌ | ❌ | ❌ |
| Mettre en pause le compte | ✅ | ❌ | ❌ | ❌ |
| Export de toutes les données | ✅ | ❌ | ❌ | ❌ |
| Demande de suppression / annulation | ✅ | ❌ | ❌ | ❌ |
| Demandes d'effacement des clients finaux (E14) | ✅ | ✅ | ❌ | ❌ |
| Aide : FAQ, tickets support | ✅ | ✅ | ✅ | ✅ |
| Sécurité de son propre compte (mot de passe, e-mail, tél.) | ✅ | ✅ | ✅ | ✅ |

**Règles transverses**
- Toute requête est filtrée par `(org_id, sites autorisés)` [Spec §9]. L'accès aux données d'un autre `org_id` est impossible côté base (RLS).
- Les changements d'e-mail, de téléphone, de mot de passe et la suppression exigent le **mot de passe actuel** (5 essais max) [Flow 3C, 4C].
- Toute modification touchant des rendez-vous à venir liste les rendez-vous concernés, demande confirmation, puis prévient les clients par WhatsApp [Flow 3A].
- Les fonctions absentes du plan sont bloquées par **feature flag** avec message d'erreur E8 (« votre plan inclut N praticiens… ») [Flow 3A].
- Les notifications de sécurité, de facturation et légales ne peuvent pas être coupées [Flow 3B].
- Commandes WhatsApp du personnel : mêmes règles que le dashboard, via le cœur de réservation, journalisées avec `actor_type = staff_whatsapp` [Spec §10].

## 2. Commandes WhatsApp du personnel (par rôle)

| Commande | Propriétaire | Resp. de site | Praticien | Réceptionniste |
|---|---|---|---|---|
| Agenda du jour / du lendemain, client suivant | ✅ | ✅ | ✅ (le sien) | ✅ |
| Marquer arrivé / terminé / no-show | ✅ | ✅ | ✅ (les siens) | ✅ |
| Annuler avec motif | ✅ | ✅ | ✅ (les siens) | ✅ |
| Bloquer un créneau, indisponibilité personnelle | ✅ | ✅ | ✅ (le sien) | ❌ |
| Ajouter un client sans rendez-vous | ✅ | ✅ | ✅ | ✅ |
| Vérifier un créneau | ✅ | ✅ | ✅ | ✅ |
| Suspendre les réservations en ligne | ✅ | ✅ (son site) | ❌ | ❌ |

Liste d'actions **fermée** : le modèle de langage peut analyser un message mais n'exécute jamais une action du personnel [Spec §7].

## 3. Super Admin

**Décisions** : fondateur seul, **sans impersonation**. Table, guard, sous-domaine et sessions séparés. 2FA TOTP obligatoire, liste d'IP autorisées possible. Rôle de base de données dédié.

### 3.1 Capacités

| Domaine | Actions | Motif (facultatif) |
|---|---|---|
| Clients | Lister, filtrer, consulter la fiche (statut, plan, usage, config) | Non |
| Comptes | Créer, modifier, **suspendre** (motif enregistré), **bloquer**, lever | Oui |
| Suppression | Standard J+30 (même pipeline que le client) ; **immédiate uniquement pour fraude ou abus** | Oui + double confirmation |
| Plans et features | Créer/modifier plans, prix, limites ; activer/désactiver une fonction pour un client (`org_feature_overrides`) | Oui |
| Facturation | Factures, paiements, impayés, relances, remboursements | Oui pour remboursements/annulations |
| Revenus | MRR, ARPU, churn, cohortes | Non |
| RGPD | Suivre `deletion_requests`, `deletion_jobs`, exports ; relancer une table en échec (E12) | Oui |
| Support | Tickets, déblocage d'accès après vérification d'identité [Flow 5A] | Oui pour déblocage |
| Messages | Modèles e-mail et messages système FR/NL/EN, message « service indisponible » par défaut | Non |
| Paramètres globaux | `platform_settings` (pause 90 j, suspension 60 j, seuils de sécurité) | Oui |
| Supervision | Erreurs, alertes, santé des synchronisations calendrier, statut Meta | Non |
| Export | Exports structurés (CSV/JSON) des données de plateforme | Oui |
| Journal | Consulter `audit_logs` (lecture seule) | Non |
| Administrateurs | Gérer son propre compte, 2FA, codes de secours | Oui |

### 3.2 Niveaux d'accès aux données d'un client (**proposition à valider**)

| Niveau | Contenu | Accès |
|---|---|---|
| 1 | Compte, statut, plan, usage, configuration, facturation | Toujours visible |
| 2 | Statistiques agrégées, journaux d'erreurs | Toujours visible |
| 3 | Données personnelles des clients finaux, contenu des rendez-vous | **Accès exceptionnel** : motif obligatoire, durée limitée, journalisé, propriétaire notifié |
| 4 | Notes et réponses d'accueil en mode données sensibles (RGPD Art. 9) | **Jamais** : chiffrées avec une clé propre à l'organisation |

Aucune impersonation : pour aider un client, le fondateur utilise les niveaux 1 et 2, ou un accès de niveau 3 exceptionnel. **À confirmer par le fondateur.**

### 3.3 Actions sensibles : garde-fous
- Pas de re-authentification par action (mot de passe + TOTP demandés à la connexion seulement, décision du fondateur, 2026-10-07) ; motif facultatif ; écriture dans `audit_logs` avec IP et agent.
- Double confirmation par saisie du nom de l'organisation pour blocage ou suppression.
- Notification e-mail au propriétaire du compte (suspension, blocage, suppression).
- Aucune modification directe des données de production hors flux applicatifs.

## 4. Effets du statut de l'organisation

| Statut | Dashboard client | Bot WhatsApp | Rappels / campagnes | Facturation |
|---|---|---|---|---|
| `trial`, `active` | Complet | Actif | Actifs | Selon plan |
| `past_due` | Complet + bandeau de régularisation | Actif | Actifs | Relances (E7) |
| `suspended` | **Lecture seule** | « Service indisponible » (message configurable) | **Arrêtés** | Gelée ; export possible |
| `paused` | Accès limité (réactivation, export, suppression) | Message d'absence | Arrêtés | **Non facturée** [Décision] |
| `pending_deletion` | Écran dédié : date limite, export, annulation | Coupé | Arrêtés | Abonnement arrêté |
| `deleted` | Aucun | Numéro déconnecté | — | Données financières pseudonymisées 7 ans |

## 5. API interne pour n8n

- Authentification : clé d'API + signature HMAC des requêtes, `api_clients` avec **scopes** (`availability:read`, `bookings:write`, `customers:write`, `conversations:write`, `config:read`).
- Le tenant est déterminé par le numéro WhatsApp (`phone_number_id`), jamais fourni librement par l'appelant.
- `Idempotency-Key` obligatoire sur toute écriture. Limitation de débit par numéro de téléphone [Spec §12].
- Les écritures passent par le **cœur de réservation** : n8n n'accède jamais directement à la base [Décision].

## 6. Tests d'accès prévus
- Un utilisateur du client A ne lit ni n'écrit rien du client B (tests automatisés sur chaque endpoint + tests RLS directs en SQL).
- Chaque rôle : cases ❌ de la matrice testées en refus (403), cases ✅ en succès.
- Statuts `suspended`, `paused`, `pending_deletion` : écritures refusées, lecture conforme au tableau §4.
- Super Admin : action sensible toujours tracée dans le journal (motif facultatif) ; journal complet.
