# 34 : Équipe et accès (Réglages > Équipe et accès)

Le propriétaire invite des **praticiens** et des **assistants**, choisit **précisément ce que chacun peut voir et faire**, puis suspend, réactive ou retire un accès. Navigation, authentification, design et gestion des utilisateurs existants sont conservés ; les rôles historiques (`owner`, `site_manager`, `practitioner`, `receptionist`) restent la base, et **rien ne change tant qu'aucune permission n'est réglée**.

Code : `app/Modules/Team/` (`Support/Capabilities`, `Services/TeamManager`, `Http/Controllers/TeamController`), `CurrentTenant::can()`, middleware `permission:`, `InvitationService` (extension), page `Account/Team.vue`, composants `PermissionPicker`, `TeamMemberDialog`, textes `lang/*/ui.php` (groupe `team`) et `lang/*/mail.php`.

## 1. Modèle de rôles et de permissions

- **Rôle de base** (`org_members.role`) : donne les capacités PAR DÉFAUT, identiques à la matrice de docs/02 §1. Un **Assistant** est le rôle historique « réceptionniste » (même code, nouveau nom d'affichage).
- **Écarts** (`org_members.permissions`, jsonb) : ne garde que ce qui diffère des défauts (`capacité => vrai / faux`). Un membre sans écart suit son rôle ; un changement de défaut futur ne casse donc pas les réglages.
- **Capacités effectives** = défauts du rôle + écarts + implications (voir tous les clients ⇒ voir les siens ; voir toute l'activité des rapports ⇒ voir les rapports). Le propriétaire a toujours tout (ses écarts sont ignorés).
- **Source de vérité unique** : `CurrentTenant::can('capacité')`, posé à chaque requête depuis la base (`auth_memberships()` ne renvoie que les membres « actifs »). Tout contrôle serveur passe par là : middleware `permission:`, `PlanningAccess`, `ReportAccess`, `LoyaltyAccess`, contrôleurs.
- **Capacités sensibles** (`settings.team`, `settings.billing`) : seul le propriétaire peut les accorder ou les retirer.
- **Réservé au propriétaire, non délégable** : données du compte (export, pause, suppression), assistant de démarrage, import de liste de prix.

## 2. Capacités par groupe

| Groupe | Capacité | Effet |
|---|---|---|
| Tableau de bord | `dashboard.view` | Voir le résumé du jour |
| Planning et rendez-vous | `calendar.view` | Ouvrir le planning |
| | `appointments.view_all` | Voir tous les rendez-vous (sinon seulement les siens) |
| | `appointments.create` / `.edit` / `.reschedule` / `.cancel` | Créer, changer le praticien, déplacer, annuler |
| | `appointments.complete` / `.no_show` | Marquer terminé / absent (deux permissions distinctes) |
| | `appointments.view_notes` | Lire les notes privées des rendez-vous |
| Prestations et prix | `services.create` / `.edit` / `.delete` | Créer, modifier, archiver |
| | `services.prices` | Modifier le prix et la TVA (contrôlé séparément de `services.edit`) |
| Horaires et calendriers | `schedules.edit_own` | Gérer ses horaires, ses prestations (« Mon profil ») |
| | `calendars.own` | Connecter son propre calendrier externe |
| Clients | `customers.view_own` / `.view_all` | Voir les clients dont on s'est occupé / tous |
| | `customers.edit` / `.organize` / `.archive` / `.erase` | Modifier ou bloquer ; étiqueter et partager des vues ; archiver ; effacer à la demande du client |
| WhatsApp | `whatsapp.connect` / `.templates` | Connecter ou déconnecter et gérer les QR codes ; gérer les modèles |
| Fidélité | `loyalty.view` / `.redeem` / `.adjust` / `.configure` | Voir, utiliser une récompense, corriger avec motif, configurer |
| Rapports | `reports.view` / `.all_data` / `.financial` / `.staff` / `.export` / `.configure` | Voir ; toute l'activité (sinon la sienne) ; montants ; comparer les praticiens ; exporter ; réglages |
| Paiements | `payments.record` | Enregistrer paiements et pourboires |
| Réglages | `settings.business` / `.practitioners` / `.integrations` / `.billing` / `.team` | Profil de l'entreprise ; praticiens ; tous les calendriers ; facturation et abonnement ; équipe |
| Support | `support.use` | Contacter le support Rendivoo, suivre ses tickets |

**Non proposé, faute de fonction** (ne protégerait rien) : conversations WhatsApp, envoi manuel de messages, analyses WhatsApp, liste d'attente, remboursements, journal d'activité du compte, gestion des sites après l'assistant, indicateurs du tableau de bord (un seul résumé du jour), export des clients, transfert de propriété, photo d'un assistant (la photo existe pour les praticiens). À ajouter avec la fonction correspondante.

## 3. Préréglages et matrice par défaut

**Praticien** : tableau de bord, planning, créer / modifier / déplacer / annuler / terminer / marquer absent **ses** rendez-vous, notes, ses horaires et son calendrier, **ses** clients (modifier), ses rapports (sans montants ni comparaison), paiements, support. Jamais : facturation, équipe, réglages de l'entreprise, WhatsApp, fidélité, export.
**Assistant** : tableau de bord, planning et **tous** les rendez-vous (créer, modifier, déplacer, annuler, terminer, absent), tous les clients (modifier, étiqueter), fidélité (voir, utiliser), paiements, support. Jamais : notes de rendez-vous, rapports, facturation, réglages. (Écart avec la demande : les « rapports de base » ne font pas partie du préréglage car aucun rapport restreint n'existe pour un assistant ; le propriétaire peut les ajouter.)
**Accès personnalisé** : on part du préréglage et on active ou désactive chaque élément ; l'écran affiche « Accès personnalisé · N modifications », avec « Revenir au préréglage ».

| | Propriétaire | Responsable de site | Praticien | Assistant |
|---|---|---|---|---|
| Équipe, facturation | ✅ | ❌ | ❌ | ❌ |
| WhatsApp (connexion, modèles, QR) | ✅ | ❌ | ❌ | ❌ |
| Prestations (créer, modifier, prix) | ✅ | ✅ | ❌ | ❌ |
| Praticiens : gérer | ✅ | ❌ | ❌ | ❌ |
| Planning | tout | tout | le sien | tout |
| Clients | tous | tous | les siens | tous |
| Fidélité | configurer | voir, corriger | ❌ | voir, utiliser |
| Rapports | tout | activité, export | les siens | ❌ |
| Calendriers externes | ✅ | ✅ | le sien | ❌ |
| Support | ✅ | ✅ | ✅ | ✅ |

Chaque case peut être modifiée membre par membre (sauf propriétaire et capacités sensibles réservées au propriétaire).

## 4. Navigation et pages

Réglages > groupe **Compte** > **Équipe et accès** (`/reglages/equipe`), visible seulement avec `settings.team` (menu, recherche des réglages et route). La recherche des réglages connaît la page (« équipe », « inviter », « permissions »…).

**Liste** : avatar (photo du praticien, sinon initiales), nom, badge « Propriétaire », « vous », e-mail, rôle, niveau d'accès (Complet / Étendu / Limité / Minimal + nombre de permissions), statut (Actif, Invité, Invitation expirée, Suspendu, Retiré), dernière connexion ou date d'invitation, boutons « Voir le profil » et « Modifier l'accès ». Recherche (nom, e-mail, rôle, sans accents), filtres (rôle, statut, niveau), tri (nom, rôle, statut, activité, croissant ou décroissant), compteur, état vide (propriétaire seul : explication et bouton), « personne ne correspond » avec « Effacer les filtres ». Le propriétaire reste en tête.

**Ajouter** (fenêtre en 3 étapes, accessible au clavier) : 1 coordonnées (nom, e-mail, téléphone facultatif, rôle Praticien / Assistant, notes) ; 2 accès (sélecteur de permissions par groupe : interrupteurs, libellé simple et explication, « Tout activer / désactiver » par groupe, compteur) ; 3 résumé (identité, rôle, préréglage ou personnalisé, permissions accordées, liste repliable de ce qui est exclu, avertissements) puis « Envoyer l'invitation ». Une adresse déjà utilisée renvoie à l'étape 1 avec un message précis ; si la personne est déjà dans l'équipe, « Ouvrir cette personne ».

**Modifier l'accès** : mêmes interrupteurs, rôle modifiable, résumé « Pourra désormais / Ne pourra plus » avec les libellés des accès, avertissements, confirmation explicite pour accorder la facturation ou l'équipe, enregistrement, e-mail à la personne.

**Profil** : statut, rôle, e-mail, téléphone, dernière activité, dates (ajouté, invité), notes, permissions accordées, actions de statut. Le propriétaire et soi-même sont expliqués (« ne peut pas être suspendu, retiré ou restreint »).

## 5. Cycle de vie

Invité (lien de 3 jours, renvoi = nouveau lien et ancien invalidé, annulation) → Actif (acceptation : compte créé avec rôle, nom, permissions de l'invitation) → Suspendu (« disabled » : connexion refusée dès la requête suivante, réactivable avec les mêmes accès) → Retiré (suppression douce : accès coupé, historique conservé et attribué ; réactivable). Réinitialisation du mot de passe : lien envoyé à la personne (son mot de passe actuel n'est jamais vu ni modifié). **Retrait** : confirmation, explication (historique conservé, nombre de rendez-vous à venir restant sur sa fiche praticien, à réaffecter depuis le planning), motif facultatif. Le propriétaire est unique et ne peut être ni suspendu ni retiré ; le transfert de propriété n'existe pas encore.

## 6. Sécurité, audit, isolation

- **Côté serveur d'abord** : middleware `permission:` sur chaque route, contrôles dans `PlanningAccess`, `ReportAccess`, `LoyaltyAccess`, contrôleurs (prix d'une prestation, résultat « terminé / absent », actions groupées clients). L'interface (menus, boutons, interrupteurs) ne fait que masquer.
- **Pas d'auto-promotion** : on ne modifie jamais son propre accès ; on n'accorde que ce que l'on a soi-même ; les capacités sensibles ne se donnent ni ne se retirent que par le propriétaire ; seul le propriétaire modifie une personne qui gère l'équipe ; le propriétaire est intouchable.
- **Isolation** : tout passe par l'organisation courante (RLS) ; un identifiant d'un autre établissement répond « introuvable ». Une adresse qui a un compte ailleurs reçoit un message neutre (on ne révèle pas son existence).
- **Journal** (`audit_logs`, ajout seul), avec auteur, date, IP, éléments et valeurs avant / après : `team.invited`, `team.invitation_cancelled`, `team.invitation_updated`, `invitation.sent`, `invitation.accepted`, `team.access_updated` (rôle avant / après, ajouts, retraits, permissions avant / après), `team.suspended`, `team.reactivated`, `team.removed` (rendez-vous à venir), `team.password_reset_sent`. Motif facultatif pour suspendre et retirer.
- **Notifications** à la personne (e-mail, sécurité, non désactivable, dans sa langue) : accès modifié, suspendu, réactivé, retiré ; invitation et réinitialisation.

## 7. États

Chargement (navigation Inertia, boutons « en cours »), vide (équipe réduite au propriétaire ; aucun résultat), validation (champs obligatoires, e-mail, longueurs, rôle), erreur (messages clairs `ui.team.errors.*` : droit manquant, propriétaire protégé, doublon, accès essentiel retiré…), succès (messages `ui.team.flash.*`). Mobile : liste en cartes empilées, fenêtres plein écran, aucun défilement horizontal ; clavier, focus visible, libellés lisibles par lecteur d'écran (fenêtres `<dialog>` natives, groupes `fieldset/legend`, états annoncés).

## 8. Critères d'acceptation et tests

`tests/Feature/Team/TeamAccessTest.php` : matrice par défaut identique à docs/02 ; écarts minimaux et relecture exacte ; implications ; page réservée à `settings.team` ; invitation d'un assistant (sans fiche praticien) et d'un praticien ; doublons ; acceptation (rôle, nom, permissions) ; renvoi et annulation ; modification avec audit avant / après et e-mail ; avertissement d'accès essentiel ; propriétaire protégé et pas d'auto-promotion ; octroi limité à ce que l'on a, sensibles au propriétaire ; pairs ; isolation entre établissements ; suspension (accès coupé), réactivation, retrait (historique conservé), réinitialisation ; matrice d'accès par rôle sur les routes ; écarts appliqués côté serveur. Front : `team.test.ts` (recherche, filtres, tri, diff, avertissements, implications), `TeamMemberDialog.test.ts` (étapes, résumé, confirmation, accès verrouillés), `settingsNav.test.ts`.
