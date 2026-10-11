# 38 · Administrateurs : profils et accès

Section **Configuration › Administrateurs** du Super Admin : créer, voir, modifier, suspendre, réactiver et retirer les administrateurs de la plateforme, avec un rôle et des permissions précises. Navigation, design et fonctions existants inchangés. Les agents de support et les éditeurs de contenu (docs/32) restent gérés dans « Équipe de support » : ils n'apparaissent pas ici.

## 1. Parcours

1. **Liste** (Super Admin seulement) : nom, e-mail, rôle, statut (actif vert, invité bleu, suspendu orange, supprimé rouge), dernière connexion, date d'ajout, créé par, nombre de permissions ; recherche par nom ou e-mail, filtres rôle, statut, date d'ajout, dernière connexion, tri, pagination, export CSV (tracé). Les comptes supprimés sont masqués par défaut.
2. **Ajouter** : nom, e-mail, téléphone, notes, rôle, permissions. Le compte naît « invité » avec un mot de passe aléatoire inutilisable ; un e-mail contient un lien à usage unique (7 jours) pour choisir un mot de passe (16 caractères minimum), puis le 2FA est obligatoire à la première connexion (même page que l'équipe de support).
3. **Profil** : identité, statut, rôle, créé le / par qui, dernière connexion et adresse, permissions accordées par module, tickets traités, clients suivis, actions enregistrées, dernières connexions (réussies et échouées), activité récente, notes. Actions : modifier, renvoyer l'invitation, réinitialiser le mot de passe, déconnecter partout, suspendre, réactiver, supprimer — chacune explique son effet avant la confirmation.
4. **Modifier** : profil, rôle et permissions ; changer les accès demande une confirmation.

Retour en haut à gauche sur chaque écran profond (liste → tableau de bord ; profil et création → liste ; modification → profil).

## 2. Rôles et permissions

| Rôle | Contenu |
|---|---|
| Super Admin | Tout, y compris la gestion des administrateurs |
| Admin opérations | Tableau de bord ; clients (voir, modifier, suspendre, exporter) ; abonnements (voir, prolonger les essais) ; réglages (voir, modifier) ; rapports (voir, exporter) |
| Admin support | Tableau de bord ; tickets (voir, créer, répondre, assigner, traiter) ; équipe de support (voir, inviter, modifier, suspendre) ; avis (voir) ; FAQ (voir, créer, modifier) ; rapports (voir) |
| Admin lecture seule | Toutes les permissions « voir » (aucune écriture) |
| Admin personnalisé | La liste cochée, module par module (au moins une) |

Modules et actions accordables (`AdminPermissions`) : tableau de bord (voir) ; clients (voir, modifier, suspendre, supprimer, exporter) ; abonnements (voir, modifier) ; tickets (voir, créer, répondre, assigner, traiter, supprimer) ; équipe de support (voir, inviter, modifier, suspendre, retirer) ; avis (voir) ; FAQ (voir, créer, modifier, supprimer) ; documents juridiques (voir, rédiger et publier, déposer et remplacer les PDF, télécharger les archives) ; réglages (voir, modifier) ; rapports (voir, exporter) ; audit (voir). La gestion des administrateurs (`admins.*`) n'est jamais déléguée.

**Volontairement absentes** (aucune action réelle ne les porterait) : imiter un client (exclu par décision du fondateur : jamais d'impersonation), rembourser / annuler / changer de formule (facturation simulée), répondre à un avis ou le modérer (liste en lecture seule), exporter l'audit (aucun export).

Un compte historique sans rôle enregistré est Super Admin ; la migration le marque `super_admin`. Un agent garde ses règles (tickets), un éditeur les FAQ.

## 3. Application des droits

- **Écrans** : `SuperAdminOnly` + `$viewPermission` de chaque ressource ou page ; actions masquées sans permission (clients, abonnements, tickets, équipe, réglages, secteurs, juridique, FAQ, suppressions).
- **Services** : chaque écriture appelle `AdminAccess::authorize()` (ClientManager, OrganizationLifecycle, ClientExport, AccessRecovery, SupportConsole, SupportTeam, FaqManager, FaqCategoryManager, LegalVersionManager, LegalDocumentFiles, PlatformSettings, SectorManager) : une action refusée échoue même appelée hors de l'écran. Répondre en changeant l'état d'un ticket exige aussi « traiter » ; le téléchargement d'un PDF juridique archivé exige sa permission dédiée.
- Les droits fins des tickets (`SupportPermissions`) viennent du rôle pour un administrateur et des réglages pour un agent.

## 4. Sécurité

- Seul un Super Admin actif gère les administrateurs : aucun rang inférieur ne peut toucher un Super Admin ni se promouvoir.
- On ne se retire pas son propre accès (rôle, permissions, suspension, retrait, déconnexion, réinitialisation) ; on ne retire, suspend ni supprime **jamais le dernier Super Admin actif**.
- Suspendre ou supprimer coupe l'accès tout de suite : statut non actif (le panel refuse à la requête suivante) et `sessions_revoked_at`.
- **Déconnecter partout** : toute session ouverte avant `sessions_revoked_at` est refusée (heure d'ouverture posée à la connexion ; sans heure connue, la session est refusée). Réinitialiser le mot de passe ferme aussi les autres sessions.
- E-mail unique sans tenir compte de la casse ; rôle obligatoire ; au moins une permission pour un rôle personnalisé ; permissions hors catalogue ignorées.
- Exports CSV sans secret, cellules neutralisées contre les formules.

## 5. Audit (`audit_logs`, ajout seul)

`admin.created`, `admin.invited`, `admin.invitation_resent`, `admin.activated`, `admin.updated` (ancien / nouveau), `admin.access_changed` (rôle ou permissions), `admin.password_reset_sent`, `admin.password_changed`, `admin.sessions_revoked`, `platform.session_revoked`, `admin.suspended` (motif), `admin.reactivated`, `admin.removed` (motif), `admin.exported`.

## 6. Données

Migration `2026_11_17_000001_admin_profiles_and_access` : `platform_admins.admin_role`, `created_by`, `last_login_ip`, `sessions_revoked_at` ; contraintes (rôle valide, `support_role` et `admin_role` ne se cumulent pas) ; comptes existants sans rôle restreint → `super_admin`.

## 7. Limites et décisions

- Pas de photo de profil (décision antérieure, docs/32 : aucun stockage d'images dans le panel).
- Statut « désactivé » et « supprimé » confondus : « Supprimé » (suppression douce, réactivable).
- Aucun rôle ne peut être modifié par un autre que le Super Admin : la délégation de `admins.*` n'existe pas.

Tests : `tests/Feature/Admin/AdminProfilesTest.php`.
