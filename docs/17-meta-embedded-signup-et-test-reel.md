# Meta : inscription intégrée (Embedded Signup) et test réel de la Graph API

Ce document sépare ce que **vous** devez faire chez Meta (je n'ai accès à aucun compte Meta) de ce que **Nova** fait déjà. Les valeurs entre crochets sont à placer dans l'environnement du serveur (jamais dans le code ni dans le dépôt).

## A. Démarches du fondateur chez Meta

Objectif : qu'un établissement connecte son numéro en quelques clics (fenêtre Meta), sans manipuler d'identifiant ni de jeton.

1. **Compte Meta Business** de la société Nova, avec **vérification de l'entreprise** (documents officiels ; quelques jours à quelques semaines). Prérequis de tout le reste.
2. **Application Meta** de type « Entreprise » (developers.facebook.com), produit **WhatsApp** ajouté.
3. Devenir **fournisseur de technologie (Tech Provider)** : accepter les conditions WhatsApp Business Solution et, si Meta le demande, passer l'**examen d'application** (permissions `whatsapp_business_management` et `whatsapp_business_messaging`, vidéo de démonstration).
4. Créer la **configuration « Embedded Signup »** (Facebook Login for Business) : vous obtenez un **identifiant de configuration**.
5. Dans l'application Meta : **domaines autorisés** (celui de Nova), **URL de rappel du webhook** (celle de la passerelle, docs/06) et **jeton de vérification** (`WHATSAPP_VERIFY_TOKEN`), puis abonnement aux champs `messages` et `message_template_status_update`.
6. Relever l'**identifiant** et le **secret** de l'application.

### Variables d'environnement à renseigner sur le serveur
| Variable | Valeur |
|---|---|
| `META_APP_ID` | identifiant de l'application Meta (public) |
| `META_EMBEDDED_SIGNUP_CONFIG_ID` | identifiant de la configuration Embedded Signup (public) |
| `WHATSAPP_APP_SECRET` | **secret** de l'application Meta (déjà utilisé pour signer les webhooks ; ne quitte jamais le serveur) |
| `WHATSAPP_DRIVER` | `meta` |

Tant que `META_APP_ID` et `META_EMBEDDED_SIGNUP_CONFIG_ID` sont vides, le bouton « Se connecter avec Meta » n'apparaît pas et seule la connexion manuelle est proposée. `php artisan nova:preflight` signale cet état.

## B. Ce que Nova fait déjà (décision du fondateur)
- Page WhatsApp et étape 7 de l'assistant : bouton **« Se connecter avec Meta »** (recommandé) au-dessus de la connexion manuelle, conservée en secours.
- **RGPD** : le module JavaScript de Meta n'est **chargé qu'au clic**, après un avertissement (Meta reçoit alors l'adresse IP de l'utilisateur). La politique de contenu n'autorise `connect.facebook.net`, `facebook.com` (fenêtre) et `graph.facebook.com` **que sur les pages de connexion WhatsApp** et seulement si l'application est configurée. Les messages renvoyés par la fenêtre ne sont acceptés que s'ils viennent de `www.facebook.com` ou `web.facebook.com`.
- **Échange du code côté serveur** : le navigateur ne transmet que le code à usage unique et les identifiants du numéro et du compte ; le serveur l'échange contre un jeton avec le secret de l'application (qui n'est jamais envoyé à la page), vérifie le numéro auprès de Meta, enregistre le compte (jeton chiffré, comme pour la connexion manuelle) et **abonne le compte aux webhooks** de Nova. Un échec d'abonnement n'annule pas la connexion.
- Erreurs (E5) : code refusé, numéro déjà connecté à un autre établissement, Meta injoignable, fonction non configurée.
- **À mettre à jour par le juriste** : la politique de confidentialité et le DPA doivent mentionner Meta comme sous-traitant ultérieur pour WhatsApp et le transfert d'adresse IP au clic.

### Non couvert, à confirmer sur un compte de test
- Le déroulement réel de la fenêtre Meta (étapes, messages `WA_EMBEDDED_SIGNUP`, versions de format), l'**enregistrement du numéro** auprès de l'API Cloud (certains parcours l'exigent avec un code PIN) et les cas d'erreur propres à Meta (numéro non éligible, vérification en deux étapes). Ces points ne se vérifient qu'avec les identifiants réels.

## C. Test réel de la Graph API (avant toute mise en production)

Les appels à Meta n'ont été testés qu'avec des réponses simulées. Protocole sur un **compte WhatsApp Business de test** (Meta fournit un numéro de test gratuit dans l'application, rubrique WhatsApp > Démarrage) :

1. Dans l'environnement d'essai : `WHATSAPP_DRIVER=meta`, `WHATSAPP_GRAPH_VERSION` à la version courante de la Graph API.
2. Contrôle sans écriture (jeton saisi de façon masquée) :
   ```bash
   docker compose run --rm app php artisan whatsapp:check <phone_number_id> <waba_id>
   ```
   Attendu : « Numéro valide », puis la liste des 12 modèles de Nova avec leur statut (`absent` au départ).
3. Dans Nova (compte de test) : connecter le numéro (`/whatsapp`, avec « Se connecter avec Meta » une fois l'application configurée), **soumettre** les messages manquants, vérifier dans WhatsApp Manager que les 12 modèles apparaissent, puis **actualiser** les statuts après approbation.
4. Envoyer un rendez-vous de test depuis le planning avec « Prévenir le client » (le numéro de test ne peut écrire qu'à des numéros ajoutés à sa liste de destinataires dans la console Meta).
5. Noter pour chaque appel le **code d'erreur** renvoyé par Meta : il sert à affiner les messages d'erreur (E5, E6).

À vérifier en particulier : format des **exemples de variables** à la création, **délai d'approbation**, comportement d'un **modèle refusé**, **limites de débit**, **version** de la Graph API.

## D. Facturation des conversations WhatsApp (décision : Nova refacture)
Chaque message envoyé est compté par établissement, par mois, par catégorie (`utility` pour un modèle, `service` pour une réponse libre) et par pays du destinataire (`whatsapp_usage`), car les tarifs de Meta dépendent de ces critères. Aucune donnée personnelle n'est conservée dans ces compteurs ; ils sont conservés à la suppression du compte comme les données de facturation (docs/09).

**Reste à décider** : le prix refacturé (par catégorie et pays, ou forfait inclus par formule avec dépassement : lien avec la décision n° 9 « dépassement de volume »), la marge de Nova, et le moment de facturation (ligne ajoutée à la facture mensuelle). Les prix iront dans `platform_settings`, pas dans le code. Conséquence côté Meta : les conversations sont alors facturées à **Nova** (ligne de crédit de Nova sur l'application Meta), à prévoir dans la trésorerie.
