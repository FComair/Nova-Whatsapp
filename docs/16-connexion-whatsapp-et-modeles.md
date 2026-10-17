# Connexion WhatsApp et modèles de messages (étapes 7 et 8 de l'onboarding, erreurs E5 et E6)

Page `/whatsapp` (propriétaire uniquement) et étapes 7 « WhatsApp » et 8 « Messages types » de l'assistant ; les mêmes composants servent aux deux. Aucune écriture dans la console Meta n'est faite sans la saisie de l'établissement.

## Connexion du numéro (étape 7, E5)
- L'établissement saisit l'**identifiant du numéro**, l'**identifiant du compte WhatsApp Business (WABA)** et un **jeton d'accès** Meta, plus son mot de passe (5 essais par 15 minutes).
- Rien n'est enregistré tant que **Meta n'a pas confirmé** que le numéro et le jeton sont valides (`WhatsappTransport::verifyNumber`). Refus : « identifiants non acceptés » (E5) ; Meta injoignable : message distinct.
- Le jeton est **chiffré en base** et **jamais renvoyé** à la page ni écrit dans le journal. Un numéro déjà rattaché à un autre établissement est refusé. Un seul numéro actif par établissement (le nouveau remplace l'ancien).
- **Déconnexion** (mot de passe exigé) : le numéro, le jeton et le suivi des modèles sont supprimés ; les conversations restent. Traçabilité : `whatsapp.connected` / `whatsapp.disconnected`.
- On peut **continuer l'assistant sans connecter** (« Je le ferai plus tard ») : l'approbation par Meta prend des heures.

## Messages types (étape 8, E6)
- Quatre messages (rappel, confirmation, déplacement, annulation) × trois langues (fr, nl, en) = **12 modèles**, catégorie « utilitaire ». Noms réglables (`WHATSAPP_TEMPLATE_*`), textes dans `lang/{fr,nl,en}/whatsapp.php` (docs/15 : textes à valider ; Meta les approuve tels quels).
- **Soumettre les messages manquants** : chaque modèle jamais soumis est envoyé à Meta (un échec n'arrête pas les autres) ; statut « en attente ». **Actualiser les statuts** à la demande, et automatiquement **toutes les heures** (`whatsapp:sync-templates`, comptes essai/actif/impayé uniquement). Statuts : non soumis, en attente de Meta, approuvé, refusé (avec le motif), suspendu par Meta.
- Seuls les modèles de Rendivoo sont suivis ; les autres modèles du compte Meta sont ignorés.

## Hypothèses et limites (à valider)
- **Connexion manuelle** : l'établissement fournit des identifiants Meta. L'inscription intégrée de Meta (**Embedded Signup**, sans jeton à manipuler) exige une **application Meta enregistrée au nom de Rendivoo** : le fondateur a décidé de la lancer, démarches et suite dans docs/17.
- Un modèle **refusé** n'est pas resoumis automatiquement (Meta interdit de réutiliser le même nom tel quel) : corriger le texte et changer le nom, ou passer par le support.
- Les appels réels à la Graph API (vérification, création et liste de modèles) ne sont **pas testés contre Meta** : seuls le canal simulé et les contrats le sont. Protocole de test réel et commande whatsapp:check : docs/17.
- Étape 9 (réservation de test) : pas encore construite ; l'assistant ne peut donc pas encore se terminer.
- Mode « Premiers pas » du tableau de bord : « Connecter votre numéro WhatsApp » en première ligne.
