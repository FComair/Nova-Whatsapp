# 32 : Suppression de tickets et équipe de support

Complète le socle de tickets (docs/29). Navigation, rôles, design et flux de traitement inchangés. Deux ajouts : **suppression de tickets** (unitaire et en lot) et **gestion des agents de support** (création par invitation, statuts, permissions, périmètre, réaffectation).

## 1. Décision de rétention (à valider par le fondateur)

La suppression est **définitive** (pas d'archivage ni d'anonymisation) : ticket, réponses, notes internes, pièces jointes (fichiers compris), événements, étiquettes et liens sont effacés. Seule la **trace d'audit** est conservée, avec : qui, quand, référence, **objet du ticket**, client, état et assigné au moment de la suppression, nombre de messages / notes / pièces jointes / événements effacés, motif (facultatif). Conséquence à connaître : l'objet du ticket (texte libre du client) reste dans le journal d'audit, ce que la demande impose ; si l'objet contient une donnée personnelle, il suit la durée de conservation du journal d'audit. L'effacement RGPD d'une organisation reste inchangé.

Le client ne peut jamais supprimer un ticket (aucun droit en base pour `nova_app`). Aucune suppression automatique n'existe.

## 2. Modèle de données

Migration `2026_11_14_000001_support_deletion_and_team`.

| Objet | Rôle |
|---|---|
| `support_delete_ticket(uuid)` | Fonction `SECURITY DEFINER` (propriétaire), exécutable par `nova_platform` seulement. Efface un ticket et ses dépendances, renvoie ce qui a été effacé (dont les chemins de fichiers). |
| `forbid_modification()` | Les messages, pièces jointes et événements restent en ajout seul : la seule exception nouvelle est cette fonction (réglage local `nova.ticket_purge` + contrôle du propriétaire), comme pour l'effacement RGPD. |
| `platform_admins` | + `name`, `phone`, `status` (`active` / `invited` / `suspended` / `removed`), `internal_notes`, `permissions` (jsonb), `invitation_token` (haché), `invitation_expires_at`, `invited_at`, `suspended_at`, `removed_at`. |
| `platform_admin_client_scopes` | Périmètre client optionnel d'un agent (aucun enregistrement = tous les clients). |

Retirer un agent est une **suppression douce** (`removed`) : l'historique reste attribué. Un compte non « actif » n'ouvre plus le panel (`canAccessPanel`), session ouverte comprise.

## 3. Rôles et permissions

**Super Admin** (sans rôle restreint) : tout, dont l'équipe de support. **Agent de support** : toujours, dans son périmètre : voir ses tickets et les non assignés, chercher / filtrer, répondre au client, ajouter des notes internes, joindre des fichiers, changer l'état, résoudre. Permissions configurables par agent (`SupportPermissions`, vérifiées côté serveur dans `SupportConsole`) :

| Permission | Défaut | Effet si refusée |
|---|---|---|
| `change_priority` | oui | priorité réservée au Super Admin |
| `manage_tags` | oui | étiquettes en lecture seule |
| `reopen_tickets` | oui | pas de réouverture |
| `assign_others` | non | l'agent prend un ticket pour lui ou remet le sien dans la file |
| `delete_tickets` | **non** | ni suppression unitaire, ni en lot |

Un agent n'a jamais : gestion de l'équipe ou des Super Admin, facturation, formules, fiches clients, réglages. **Périmètre client** : un agent limité ne voit (ni n'a assigné) que les tickets de ses clients ; la contrainte s'applique à la liste, au détail, aux actions en lot et à l'assignation. L'écran ne montre pas de rendez-vous du client : le contexte client reste celui du ticket. Photo de profil : non retenue (aucun stockage d'images de profil dans le panel).

## 4. Écrans

- **Support > Équipe de support** (Super Admin seulement) : liste (nom, e-mail, rôle, statut, assignés, ouverts, résolus, dernière activité), filtre par statut (les retirés sont masqués par défaut), fiche avec charge de travail, permissions et périmètre. Actions : ajouter, modifier, voir, renvoyer l'invitation, envoyer une réinitialisation, suspendre, réactiver, retirer.
- **Création** : nom, e-mail, téléphone, notes internes, permissions, périmètre ; le compte est créé « invité » avec un mot de passe aléatoire inutilisable et une invitation par e-mail (jeton aléatoire haché, 7 jours, usage unique, page publique limitée en fréquence). L'agent choisit un mot de passe (16 caractères minimum), devient « actif », puis configure son 2FA (obligatoire) à la première connexion. La saisie directe d'identifiants n'est pas proposée.
- **Suspension / retrait** : si l'agent a des tickets ouverts, le Super Admin doit les réaffecter à un agent actif (dans le périmètre du client) ou les remettre dans la file ; sinon l'action est refusée et rien ne change.
- **Détail d'un ticket** : action rouge « Supprimer le ticket » (visible avec la permission). Fenêtre : conséquences (réponses, notes internes, pièces jointes, historique effacés ; audit conservé), saisie de la référence du ticket pour confirmer, motif facultatif. Après suppression : message de succès et retour à la boîte de réception.
- **Boîte de réception** : vues existantes (Tous, Mes tickets, Non assignés, Urgents, En attente du client…), filtres assigné / état / priorité / non assignés ; sélection par cases et barre d'actions groupées. **Supprimer** en lot (visible avec la permission) : nombre de tickets affiché, case « je comprends que c'est irréversible », saisie du nombre exact, motif facultatif ; chaque ticket est contrôlé (droit, périmètre) et audité, les refus sont comptés « ignorés ». Les actions de priorité et d'étiquette en lot suivent les permissions.
- États vides, d'erreur et de validation : messages traduits fr / nl / en (`lang/*/team.php`, `lang/*/tickets.php`).

## 5. Audit

Toutes les actions passent par `PlatformAudit` (acteur, horodatage, adresse IP, action, élément, avant / après, motif facultatif) : `support.ticket.deleted` (un par ticket), `support.tickets.bulk` (opération `delete`, faits / ignorés), `support.agent.created`, `support.agent.invited`, `support.agent.invitation_resent`, `support.agent.password_reset_sent`, `support.agent.activated`, `support.agent.password_changed`, `support.agent.updated` (ancien / nouveau), `support.agent.suspended`, `support.agent.reactivated`, `support.agent.removed` (avec nombre de tickets réaffectés), `support.ticket.assigned`, `.status_changed`, `.priority_changed`, `.replied`, `.note_added`. Le journal est en ajout seul.

## 6. Notifications (inchangées, docs/29 §8)

Assignation : l'agent reçoit « assigné » (cloche et / ou e-mail selon ses préférences), y compris lors d'une réaffectation à sa suspension ; réponse du client, changement d'état et de priorité : l'assigné (ou la file si non assigné). Invitation et réinitialisation : e-mail de sécurité non désactivable. Les notifications de cloche qui pointent vers un ticket supprimé sont supprimées avec lui.

## 7. Critères d'acceptation

Voir `tests/Feature/Admin/SupportTeamTest.php` : suppression complète avec audit conservé (et fichiers effacés) ; refus du client, de l'agent sans permission, hors périmètre ; ajout seul toujours imposé hors de la fonction ; confirmation par référence ; suppression en lot avec ignorés, case et nombre exacts ; création / invitation / activation à usage unique / lien expiré ou renvoyé ; accès à l'équipe réservé au Super Admin ; suspension qui exige la réaffectation et coupe la session ; assignation refusée vers un compte inactif ou hors périmètre ; permissions appliquées côté serveur ; périmètre client.
