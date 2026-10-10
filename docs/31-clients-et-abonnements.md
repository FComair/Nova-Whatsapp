# 31 : Super Admin > Clients et abonnements

Section **Clients** du Super Admin (panneau `/admin`) : liste avec indicateurs, recherche, filtres, vues enregistrées, actions groupées et export ; fiche client en cinq onglets ; rapports. Aucun autre module n'est modifié. Réservé au Super Admin : les rôles de support restreints (`agent`, `editor`) n'y ont pas accès (`SuperAdminOnly`).

## 1. Modèle de données

Migration `2026_11_13_000001_client_management_foundation`.

| Objet | Rôle |
|---|---|
| `client_notes` (`org_id`, `author_id`, `body`, `created_at`) | Notes internes, jamais visibles du client. Table de plateforme : RLS active, aucun droit pour `nova_app`. |
| `client_tags` (`org_id`, `tag`, `created_by`) | Étiquettes internes (30 caractères, minuscules). Unique par client. |
| `client_saved_views` | Vues de la liste (nom + filtres), privées à chaque administrateur. |
| `org_user_summary()` | Fonction `SECURITY DEFINER` : e-mail et téléphone du propriétaire, nombre d'utilisateurs actifs, dernière connexion. `nova_platform` ne lit pas `users`. |
| `org_member_directory(uuid)` | Idem pour la liste des membres d'un client (lecture seule). |
| `gdpr_purge_org()` | Étendue : efface aussi `client_notes` et `client_tags` (`ErasureSteps`). |

## 2. États d'abonnement (définitions)

Un seul état dérivé par client (`ClientDirectory::STATE_SQL`), évalué dans cet ordre :

1. **En pause** : compte en pause.
2. **Impayé** : abonnement `past_due` ou compte `past_due`.
3. **Résilié** : abonnement terminé (`canceled`) ou suspension `system.subscription_ended`.
4. **Actif** : abonnement `active` (même si une résiliation est programmée en fin de période).
5. **Expiré** : suspendu à la fin de l'essai (`system.trial_ended`).
6. **Essai** : compte en essai.
7. **Aucun** : autre cas (par exemple suspendu ou en suppression programmée par Nova).

Le **statut du compte** (`organizations.status`) reste affiché à part : l'état d'abonnement et le statut sont deux notions distinctes.
**MRR** = prix mensuel de la formule des clients actifs ou impayés. **Renouvellement** = fin d'essai (essai) ou fin de période / résiliation programmée (abonnement).
**Activité** = la plus récente entre connexion d'un membre, rendez-vous créé et message WhatsApp.

## 3. Liste

- Indicateurs : clients, actifs, en essai, impayés, résiliés, essai expiré, nouveaux du mois, renouvellements sous 7 jours, MRR. Les onglets de la liste correspondent aux états.
- Colonnes : client, contact principal, formule, abonnement, renouvellement, MRR, utilisateurs, création, dernière activité, statut ; masquables.
- Recherche : nom, adresse, identifiant, nom de facturation, e-mail ou téléphone du propriétaire, identifiant d'abonnement.
- Filtres : état, formule, essai (en cours / fin sous 7 jours), période de renouvellement, paiement (impayé, échec récent, sans moyen de paiement), statut, pays, date de création, activité, nombre d'utilisateurs, étiquette.
- Vues enregistrées : enregistrer, rappliquer et supprimer ses filtres.
- Actions groupées : prolonger l'essai, suspendre, réactiver, ajouter / retirer une étiquette, exporter la sélection. Les clients pour lesquels l'action est impossible sont **ignorés et comptés**, sans bloquer les autres.
- États vides, d'erreur et de validation : messages traduits (fr / nl / en).

## 4. Fiche client

Onglets : **Vue d'ensemble** (résumé, santé du compte, tickets), **Informations et équipe** (identité, adresse de facturation, membres en lecture seule), **Abonnement** (formule, période, prochaine facturation, tentatives, limites, historique, factures avec détail et tentatives), **Utilisation** (face aux limites de la formule, activité, WhatsApp), **Notes et étiquettes**. L'activité (journal d'audit du client) et les tickets sont en relation sous la fiche.

Santé du compte : suspendu, suppression programmée, en pause, fin d'essai proche (≤ 3 jours), essai expiré, impayé, paiement récemment échoué, sans moyen de paiement, carte qui expire (< 60 jours) ou expirée, limite de formule dépassée ou proche (≥ 80 %), inactif (≥ 30 jours), sans praticien, sans WhatsApp, tickets urgents.

Actions : modifier les informations, prolonger l'essai, suspendre / réactiver, programmer / annuler la suppression, déblocage d'accès, ajouter une note, étiqueter, exporter ce client. Les actions de cycle de vie existantes (`OrganizationLifecycle`) sont inchangées.

## 5. Règles

- **Prolonger l'essai** : de 1 à 60 jours, à partir de la fin d'essai (ou de maintenant si elle est passée). Permis pour un compte en essai ou suspendu à la fin de son essai (il redevient en essai). Le propriétaire est prévenu par e-mail.
- **Modifier les informations** : nom, raison sociale, TVA, adresse, pays, fuseau, langue, secteur. Le journal garde l'ancienne et la nouvelle valeur.
- **Notes** : le journal d'audit garde la longueur, pas le texte.
- **Export CSV** : par groupes de colonnes (identité, contact, abonnement, utilisation, étiquettes) ; le contact est une donnée personnelle, avertissement affiché, 10 000 lignes maximum, formules de tableur neutralisées, export tracé (`client.exported`).
- **Audit** : `client.viewed`, `client.updated`, `client.trial_extended`, `client.tag_added|removed`, `client.note_added`, `client.bulk`, `client.exported`. Motif toujours facultatif.

## 6. Rapports (page « Rapports clients »)

Clients par formule et par état, nouveaux clients par mois, essais commencés et convertis, abonnements terminés, paiements refusés, renouvellements 7 / 30 jours avec MRR, activité 7 / 30 jours, dormants, tickets par client. Filtres : période, formule, état, pays. Chaque indicateur est défini sur la page. L'historique du MRR n'est pas conservé : seule la valeur actuelle est donnée.

## 7. Ce qui n'existe pas encore (décisions à prendre)

À signaler, non inventé ici car cela suppose des décisions de facturation ou de produit :

- **Changement de formule par l'équipe, remises / coupons, remboursements, paiements manuels, relance de paiement manuelle** : le moteur de facturation (docs/10) n'a ni coupons ni remboursements ; le client gère sa formule depuis sa page Facturation.
- **Facturation annuelle, pause d'abonnement par l'équipe** : non offertes.
- **Imitation de connexion (impersonation)** : exclue par la règle 3 du projet.
- **Création manuelle d'un client, archivage** : le client s'inscrit lui-même ; la suppression passe par le circuit existant (programmée, annulable).
- **Gestion des utilisateurs du client** (rôles, invitations) : lecture seule ; le propriétaire gère son équipe ; l'équipe Nova dispose du déblocage d'accès.
- **Export XLSX / PDF** : seul le CSV existe.
- **Limites de rendez-vous et de conversations** : mesurées et affichées, pas encore appliquées. Stockage et appels d'API ne sont pas suivis.

## 8. Critères d'acceptation et tests

`tests/Feature/Admin/ClientManagementTest.php` : états d'abonnement dérivés, indicateurs, liste (recherche, filtre), fiche et trace de consultation, santé, prolongation d'essai (calcul, réactivation, refus), informations (audit avant / après), étiquettes, notes (non lisibles par `nova_app`, texte absent du journal), action groupée avec ignorés, export (contact opt-in, neutralisation des formules, audit), rapports, accès refusé aux rôles restreints. Les tests Admin existants (`AdminPanelTest`, `AccessRecoveryTest`, `SensitiveActionsTest`, `DeletionPipelineTest`) restent verts.
