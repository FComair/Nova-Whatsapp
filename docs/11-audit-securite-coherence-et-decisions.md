# Relecture de sécurité, cohérence avec les flowcharts et décisions en attente

Document établi après l'étape 8. Trois parties : (1) sécurité, (2) écarts avec les flowcharts « Parcours utilisateur PDF », (3) liste consolidée des décisions que seul le fondateur peut prendre.

## 1. Relecture de sécurité

### Contrôlé et conforme
- Requêtes SQL brutes toutes paramétrées ; aucun `v-html`, `eval`, `unserialize` sur des données externes ; aucune affectation de masse depuis la requête.
- RLS activée et forcée sur toutes les tables portant `org_id` ; le rôle `nova_platform` (Super Admin) ne peut écrire ni dans les données clients ni dans les journaux autrement qu'en ajout.
- Protection SSRF (calendriers externes), signatures HMAC et webhooks comparées par `hash_equals`, CSRF actif, session régénérée à la connexion.
- Journaux en ajout seul (`audit_logs`, `booking_events`) protégés par déclencheur ; la seule exception (purge RGPD) passe par une fonction dédiée du rôle propriétaire.

### Corrigé pendant la relecture
| Constat | Correction |
|---|---|
| Aucun en-tête de sécurité | Middleware `SecurityHeaders` : nosniff, anti-cadre, référent strict, permissions, COOP, HSTS (production + HTTPS), CSP (`NOVA_CSP` : `report-only` par défaut, `enforce`, `off`; panel Super Admin exclu de la CSP) |
| Adresses IP vues par l'application = celles du proxy | `TRUSTED_PROXIES` (liste ou `*`) dans `bootstrap/app.php` |
| Colonne `platform_admins.ip_allowlist` inutilisée | `EnforceAdminIpAllowlist` (IPv4/IPv6, CIDR) : refus 403, déconnexion, entrée d'audit `platform.ip_denied` ; commande `rendivoo:admin-allowlist` |
| `nova_app` pouvait supprimer des organisations et écrire `migrations` | Migration `2026_10_15_000001_harden_application_role` (REVOKE) |
| Risque de mise en production avec des réglages de développement | Commande `rendivoo:preflight` (11 erreurs bloquantes, 7 avertissements) |

Tests : `tests/Feature/Security/HardeningTest.php`.

### Risques acceptés ou à traiter avant la production
- **Table `users`** : le rôle applicatif lit et écrit tous les utilisateurs (dont les empreintes de mots de passe). À terme, authentification via fonction `SECURITY DEFINER`. Risque accepté pour l'instant.
- **Journaux** : les traces d'erreur SQL peuvent contenir des données personnelles → `LOG_LEVEL=warning` en production, accès restreint aux journaux.
- **Audit des dépendances** (`composer audit`, `npm audit`) : impossible dans cet environnement (interception TLS) → à exécuter en CI.
- **Infrastructure** : TLS vers PostgreSQL et Redis, mot de passe Redis, rotation de `APP_KEY` (`APP_PREVIOUS_KEYS`), chiffrement des sauvegardes, `SESSION_SECURE_COOKIE`, sous-domaine dédié pour le Super Admin.
- **Page de connexion du Super Admin** non restreinte par IP (seules les pages authentifiées le sont) : protégée par mot de passe + 2FA + limitation de tentatives.
- **Rôle `nova_owner`** : doit disposer de `BYPASSRLS` (et non être superutilisateur) en production.

## 2. Cohérence avec les flowcharts

### Conforme
Inscription et vérifications (1A), invitations (1C/E13), connexion et erreurs (2A, E3), suppression de compte et export RGPD (5A, E12 partiellement), calendriers externes, Super Admin (sensibles : réauthentification + motif + audit), facturation (essai, relances, suspension).

### Écarts à combler
| Flowchart | Écart |
|---|---|
| 2B/2D, tableau de bord | Pas de « résumé du jour », ni « Premiers pas », ni étape d'autorisation des notifications |
| 2B/2D | Création/déplacement/annulation depuis le tableau de bord : aucun message WhatsApp de confirmation ou d'information au client |
| 3A | Modification touchant des rendez-vous à venir : pas de liste, de confirmation ni de notification des clients |
| 3C, docs/02 | Pas de « Paramètres > Sécurité » : changement d'e-mail (lien au nouveau + avis à l'ancien), de téléphone (code SMS), de mot de passe (mot de passe actuel), fermeture des autres sessions |
| 4B | Pas de **pause** (effets expliqués, rendez-vous à venir, message d'absence, 90 jours max, non facturée) |
| 4A | Pas de règle de « suspension prolongée » ; message configurable `unavailable_message` inutilisé |
| Suppression de compte | Nous : nom de l'établissement + effet immédiat. Flowchart : saisie de « SUPPRIMER » + lien e-mail valable 24 h, suggestion de pause, avertissement des clients ayant des rendez-vous, rappels J-7/J-1, annulation avec reconfirmation d'identité, déconnexion du numéro Meta |
| Notifications | Préférences par événement, notifications navigateur, consentements Rendivoo (actualités, amélioration produit : date + source), « mode données sensibles », vue des consentements clients (opt-in/STOP) absents |
| Rapports | Activité, clients, bot, financier + export CSV/PDF absents |
| Retours clients | Liste d'attente, avis, parrainage absents |
| Campagnes WhatsApp | Opt-in marketing, paliers Meta (E9) absents |
| Aide | FAQ, aide, tickets absents |
| E11 | Passage à un humain et boîte de réception absents |
| E14 | Seul l'effacement **à l'initiative de l'établissement** existe ; la demande du client via WhatsApp (notification, accepter/refuser, confirmation) n'existe pas |
| E4, E5/E6, onboarding 7-9 | Lecture de la grille tarifaire (vision), connexion WhatsApp, gestion des modèles Meta : non construits |
| E12 | Ticket en cas d'échec d'export : non construit |
| Super Admin | Action de support « déblocage d'accès » absente ; décision #19 (accès exceptionnel niveau 3) non construite |
| Décisions #14, #15 | Enregistrements clients issus des calendriers (désactivés par défaut, activation par calendrier avec avertissement RGPD) et limitation de l'import aux formules Basic/Business : **non implémentés** |
| Décision #9 | Dépassement de volume (alerte + proposition de formule, sans coupure) : quotas non appliqués |
| Nommage | « Basic » (documents) et « Starter » (code) : à unifier |

## 3. Décisions en attente du fondateur

**Prestataires et identité**
1. Prestataire de paiement (aujourd'hui simulé) — et son adaptateur, webhooks, remboursements.
2. Fournisseur SMS pour le code téléphonique (décision #21).
3. Identité légale du vendeur (`BILLING_SELLER_*`) ; règles de TVA (comptable, vérification VIES).
4. Adresse d'alerte `NOVA_FOUNDER_EMAIL`.

**Abonnement et facturation**
5. Essai : 14 jours ? Relances : 3 tentatives espacées de 3 jours ?
6. Prorata en cas de montée de formule ; comportement en fin d'abonnement (suspension ou suppression programmée).
7. Règles de fin de pause (90 jours) et de suspension (60 jours) ; différence « bloquer » / « suspendre » ; motif visible ou non du propriétaire dans l'e-mail de suspension.
8. Nom des formules (Basic ou Starter).

**RGPD et juridique (validation par un juriste)**
9. Durées de conservation : conversations, preuves d'audit, factures (7 ans), clients inactifs.
10. Effacement d'un client bloqué (intérêt légitime) ; effacement du journal d'audit côté client.
11. Rôle de Rendivoo (sous-traitant ou responsable) selon les traitements ; textes CGU, confidentialité, DPA.
12. Mode de confirmation de la suppression de compte (« SUPPRIMER » + lien e-mail, comme le flowchart, ou nom de l'établissement).

**WhatsApp**
13. Textes et mots-clés STOP/START ; conduite si un client désinscrit écrit sans START.
14. Modèles Meta (fr/nl/en) et déconnexion du numéro à la suppression.

**Calendriers et réservation**
15. Synchronisation 15 min, 5 calendriers par praticien, traitement des journées entières et de `TRANSP`.
16. Pas de créneau 15 min ; surréservation / capacité > 1 ; affectation de site par défaut (un responsable sans site voit tout).
17. Règle minimale de 24 h pour les rappels ; 3 tentatives d'envoi.
18. Politique de mot de passe et de 2FA pour les clients.

## Prochaines étapes recommandées
1. Paramètres > Sécurité du compte. 2. Pause d'établissement. 3. Notifications WhatsApp aux clients lors des modifications faites au tableau de bord. 4. Tableau de bord (résumé, premiers pas). 5. Onboarding 7-9 (WhatsApp, modèles). 6. Rapports, campagnes, boîte de réception.
