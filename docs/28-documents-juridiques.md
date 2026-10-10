# 28. Documents juridiques et acceptations

Conditions d'utilisation, politique de confidentialité et accord de sous-traitance (DPA) : pages publiques stables, acceptation à l'inscription, version et historique d'acceptation, section **Réglages › Juridique**, nouvelle acceptation quand une version significative est publiée, publication par le Super Admin. Module `app/Modules/Legal`.

> **Les textes fournis sont des modèles à faire valider par un juriste avant la mise en production** (`resources/legal/{document}/{fr,en}.md`, version 2026-10-01). Ils n'inventent aucune identité : `{{company}}` est remplacé par la raison sociale du vendeur (`BILLING_SELLER_NAME`, à renseigner : tant qu'elle ne l'est pas, les pages affichent « Nova (raison sociale à renseigner) »). Le néerlandais n'est pas encore rédigé : la page s'affiche en français avec un avis (puis en anglais si le français manquait).

## 1. Adresses stables (publiques, sans connexion)

| Adresse | Contenu |
|---|---|
| `/legal/terms-of-use` | Conditions d'utilisation en vigueur |
| `/legal/privacy-policy` | Politique de confidentialité en vigueur |
| `/legal/dpa` | DPA en vigueur |
| `/legal/{document}/versions/{version}` | Une version publiée précise (on peut toujours relire le texte accepté) |
| `/legal/{document}/download` | Version en vigueur en Markdown (pièce jointe) |

Ces adresses ne changent jamais : elles peuvent figurer dans des e-mails, des factures et des messages du support. Chaque page montre titre, **version**, **date d'effet**, un **sommaire** (ancres `#section-N`), l'historique des versions, l'impression (feuille de style dédiée) et le téléchargement ; langue de l'interface (fr, en) avec repli annoncé. Le texte est du Markdown rendu **sans HTML brut** (`html_input: strip`, liens non sûrs refusés). Une version brouillon ou dont la date d'effet n'est pas atteinte répond 404.

## 2. Inscription

- Trois cases **distinctes et jamais précochées**, avec le titre de chaque document en **lien vers sa page, dans un nouvel onglet** (`noopener`, annoncé aux lecteurs d'écran) et la version qu'elle accepte :
  « J'accepte les [Conditions d'utilisation]. » · « Je reconnais avoir lu la [Politique de confidentialité]. » · « J'accepte l'[Accord de sous-traitance des données (DPA)]. »
- **Décision : le DPA est exigé à l'inscription** (`nova.legal.dpa_required_at_signup = true`). L'établissement est responsable du traitement des données de ses propres clients et Nova est son sous-traitant ; l'article 28 du RGPD impose un accord écrit, et le flux d'inscription (flowchart 1A) le prévoyait déjà. Mettre la valeur à `false` rend la case facultative : elle n'enregistre rien tant qu'elle n'est pas cochée.
- Le compte n'est pas créé tant qu'une case obligatoire manque (serveur et écran) ; les coordonnées saisies sont conservées (le mot de passe est à ressaisir). Message clair sous chaque case.
- **Version vue = version acceptée** : le formulaire envoie les versions affichées ; si un document a été publié entre l'affichage et l'envoi, l'envoi est refusé, la nouvelle version s'affiche et les cases sont décochées (jamais l'ancienne version acceptée en silence).
- **Preuve enregistrée** (`user_consents` + `audit_logs` `legal.accepted`) : utilisateur, document, version, horodatage, contexte (`signup`), adresse IP, agent utilisateur, langue affichée, établissement.

## 3. Versions et historique

- `legal_document_versions` : une ligne par version (`document`, `version`, `effective_on`, `status` brouillon/publiée, `requires_acceptance`, titre et texte par langue, résumé des changements par langue).
- **Une version publiée est figée** : un déclencheur de base interdit toute modification et suppression, même pour le propriétaire de la base ; un nouveau texte est toujours une **nouvelle version**. Les brouillons restent modifiables.
- **Version en vigueur** = dernière version publiée dont la date d'effet est atteinte.
- `user_consents` : une acceptation de conditions, confidentialité ou DPA **ne peut plus être modifiée** (déclencheur) et la même version ne s'enregistre **pas deux fois** (index unique) ; la suppression d'un compte (RGPD) reste possible. L'historique garde chaque version acceptée avec son contexte (`signup`, `reacceptance`, `settings`).

## 4. Réglages › Juridique (tous les rôles)

Pour chaque document : version en vigueur et date d'effet, **version et date acceptées par la personne connectée** (avec le contexte), liens **Lire / Imprimer / Télécharger**, historique de ses acceptations, état :

| État | Sens | Action proposée |
|---|---|---|
| À jour | Version en vigueur acceptée | — |
| À accepter | Aucune acceptation enregistrée (par exemple utilisateur invité avant cette fonction) | « J'accepte la version … » |
| Nouvelle acceptation requise | Version significative publiée depuis celle acceptée | « J'accepte la version … » ; le service attend (voir §5) |
| Nouvelle version disponible | Version sans acceptation requise | Aucune : information seulement |
| Non concerné | DPA pour un rôle autre que propriétaire | — |

**Visiter la page n'engage à rien** (aucune écriture). Le **DPA est un accord de l'établissement** : seul le propriétaire l'accepte.

## 5. Nouvelle version et nouvelle acceptation

- Le Super Admin marque une version **« acceptation requise »** quand elle est juridiquement significative (sinon : simple information dans les Réglages).
- Après connexion, un utilisateur qui avait accepté une version antérieure d'un document dont une version significative est parue **doit l'accepter pour continuer** : toute page est redirigée vers Réglages › Juridique (avec un message), sauf celles qui protègent ses droits : documents, **sécurité du compte, aide, facturation, « Mes données » (export, suppression)**, profil, langue, déconnexion. Le bloc « Nouvelle acceptation requise » explique ce qui a changé (résumé fourni par le Super Admin). Une version significative reste exigée même si une version moins significative paraît ensuite.
- Un utilisateur **sans aucune acceptation enregistrée** n'est **jamais bloqué** : il voit un avis discret et masquable (« Aucune acceptation de nos documents juridiques n'est enregistrée… ») avec un lien vers la page. (Il n'existe pas encore de cases d'acceptation dans l'invitation d'un collaborateur : suite possible.)
- Une version dont la date d'effet est future n'oblige à rien avant cette date.

## 6. Super Admin (seul à écrire)

Ressource Filament **Documents juridiques** (groupe Configuration) : liste des versions, **Nouvelle version** (document, version unique par document, date d'effet, acceptation requise, titre / texte Markdown / résumé en fr, nl, en), **Modifier le brouillon**, **Publier** (confirmation qui dit si les utilisateurs devront réaccepter), **Supprimer le brouillon**. Publier est refusé sans titre ni texte (fr ou en), avec une date passée, ou avec une date d'effet antérieure ou égale à celle de la dernière version publiée (historique ordonné, rien de réécrit). Chaque écriture est tracée dans l'audit de la plateforme (`legal.version_created / updated / published / deleted`). Les clients n'ont qu'un accès en lecture à la table (rôle `nova_app`).

## 7. Accessibilité et mise en page

Cases natives avec libellé lié, liens soulignés avec nouvel onglet annoncé, focus visible, erreurs annoncées (`role="alert"`), page document responsive (sommaire au-dessus du texte sur mobile), typographie lisible (≈ 68 caractères par ligne, interligne 1,75), contrastes du design system, impression propre (menus masqués).

## 8. Données

`legal_document_versions` (globale, lecture seule pour l'application cliente) ; `user_consents` reçoit `user_agent` et `locale` et ses deux garde-fous ; la version des documents n'est plus lue dans `config/nova.php` (supprimée), seulement en base.
