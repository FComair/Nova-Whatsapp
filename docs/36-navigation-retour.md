# 36 · Navigation « Retour » et modifications non enregistrées

Un bouton « Retour » clair et identique partout où l'on entre dans un écran plus profond, côté client et côté Super Admin, et une confirmation avant de perdre un formulaire modifié.

## Principes

- **En haut à gauche**, au-dessus du contenu, toute la largeur sur téléphone, 44 px de haut au moins, flèche + libellé (« Retour aux clients »). Focus visible, états survol/actif, `<nav aria-label>` + lien : fonctionne au clavier, au lecteur d'écran, en clic droit / nouvel onglet.
- **Le libellé et la destination dépendent de l'écran, pas de la page d'où l'on vient.** Premier niveau (menu principal, listes) : « Retour au tableau de bord ». Écran plus profond : retour à son parent. Le tableau de bord n'a pas de retour.
- **Contexte conservé.** Quand l'écran précédent est bien le parent (liste ouverte → fiche), « Retour » rejoue l'historique du navigateur : mêmes filtres, recherche, page, onglet et défilement. Sinon il va à l'adresse du parent. Le bouton Précédent du navigateur donne donc le même résultat.
- **Permissions.** Le retour est calculé côté serveur/route à partir d'adresses fixes de chaque espace, jamais d'une adresse fournie par la requête (`Referer` ignoré côté Super Admin). Un parent auquel la personne n'a plus accès passe par le contrôle d'accès habituel de la page (redirection vers son tableau de bord).

## Client (`resources/js`)

- `composables/backNav.ts` : table écran → { libellé, parent } (`backTarget`) et pile de navigation (`trackNavigation`, `canReturnInHistory`). Posée une fois dans `app.ts`.
- `Components/BackLink.vue`, affiché par `Layouts/AppLayout.vue` : toutes les pages de l'espace client en profitent sans modification page par page. Les anciens liens « retour » en doublon (fiche client, facture, demande d'aide) ont été retirés.
- `Components/ModalDialog.vue` : bouton « Retour » en haut à gauche de chaque fenêtre (fiche rendez-vous, nouveau rendez-vous, profil d'un membre, export, import de tarifs, nouveau compte WhatsApp) ; Échap fait la même chose.
- `Components/ConfirmDialog.vue` garde « Annuler » ; `TeamMemberDialog` a son propre « Annuler »/« Retour » d'étape.

## Super Admin (`resources/views/admin/back.blade.php`)

- `Admin/Support/AdminBack.php` : table route → { libellé, parent }. Branché sur tous les écrans du panel par un crochet de rendu (`PanelsRenderHook::CONTENT_START`). Les écrans « Documents juridiques » ont leur propre retour (docs/35) ; la connexion et le tableau de bord n'en ont pas.
- Le même fichier porte la protection des formulaires et sa boîte de confirmation.

## Modifications non enregistrées

Message : « Vous avez des modifications non enregistrées. Voulez-vous vraiment quitter sans enregistrer ? » ; choix **Rester et enregistrer** (envoie le formulaire modifié), **Quitter sans enregistrer**, **Annuler** (Échap = Annuler). Aucun avertissement sans modification.

- Un formulaire est « modifié » quand ses champs diffèrent de ce qu'ils valaient à la première interaction, jusqu'à un enregistrement réussi. Sont ignorés : recherche (`role="search"`), `method="get"`, filtres et outils (`data-unsaved-ignore`), tables et fenêtres d'action Filament.
- Bloqués : liens et visites vers une autre page (menu, Retour, listes), fermeture d'une fenêtre, fermeture/rechargement de l'onglet (confirmation du navigateur). Une visite non GET (enregistrement) ou vers la même page (filtre, pagination) n'est jamais bloquée.
- Côté client : `composables/unsaved.ts` + `UnsavedChangesDialog`. Côté Super Admin : script de `back.blade.php`. La page de dépôt juridique garde sa propre protection (message propre au fichier).

## Limites connues

- Le bouton **Précédent du navigateur** n'est pas intercepté quand un formulaire est modifié (le navigateur n'offre pas d'interception fiable du retour dans l'historique) ; la fermeture de l'onglet et les liens le sont.
- « Rester et enregistrer » envoie le premier formulaire modifié de la page ; dans l'assistant d'ajout d'un membre, il n'enregistre qu'à la dernière étape.
- Les écrans hors espace principal (connexion, inscription, assistant de configuration, documents publics `/legal`) gardent leurs liens de retour propres.

Tests : `resources/js/composables/backNav.test.ts`, `unsaved.test.ts`, `Components/BackLink.test.ts`, `tests/Feature/Admin/AdminBackTest.php`.
