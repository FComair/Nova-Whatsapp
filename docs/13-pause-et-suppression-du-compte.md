# Pause d'établissement et suppression du compte (flowcharts 4B et 4C)

Page `/compte/pause` (propriétaire uniquement, accessible aussi quand le compte est en pause ou suspendu à la fin de la pause, pour le réactiver) et `/compte/donnees` (« Mes données »).

## Pause (4B)

| Sujet | Règle |
|---|---|
| Qui | Le propriétaire, depuis un compte en essai, actif ou impayé (pas depuis un compte suspendu, déjà en pause ou en suppression). Mot de passe exigé, case « je comprends » cochée. |
| Durée | De 1 jour à `pause_max_days` (90 par défaut, `platform_settings`, bornes 1 à 365). |
| Rendez-vous | La pause est **refusée** tant qu'un rendez-vous (en attente ou confirmé) est prévu pendant sa durée, **sauf** si le propriétaire coche « Annuler ces rendez-vous et prévenir mes clients par WhatsApp » : ils sont alors annulés (acteur : le propriétaire) et chaque client reçoit le modèle WhatsApp `appointment_cancelled` (dans sa langue). Envoi au mieux : un client désinscrit (STOP), sans WhatsApp joignable ou un échec de Meta ne bloque pas la pause ; le propriétaire voit combien de clients n'ont pas pu être prévenus et doit les contacter. |
| Pendant la pause | Dashboard limité à l'écran d'état, au compte (export, suppression) et à la pause ; l'API répond `503 account_unavailable` ; rappels et synchronisations arrêtés ; aucune facturation (`billing:run` ignore les comptes en pause). |
| Message d'absence | Facultatif, dans la langue de l'établissement (`organizations.unavailable_message`). Fourni au bot dans la réponse `503` de l'API (`error.unavailable_message` + `error.locale`) pour qu'il le renvoie aux clients. |
| Non facturée | À la reprise (ou à l'échéance), l'essai (`trial_ends_at`) et la période payée (`current_period_start/end`, `cancel_at`) sont **décalés de la durée de la pause**, une seule fois. |
| Fin | Rappel par e-mail 7 jours avant l'échéance (une fois), puis, à l'échéance, **suspension du compte** (raison `system.pause_ended`, données conservées ; commande `accounts:end-pauses`, toutes les heures) avec un e-mail proposant de **réactiver ou supprimer**. Le propriétaire peut réactiver à tout moment, pendant ou après la pause. |
| Traçabilité | `organization.paused`, `organization.pause_expired` (système) et `organization.pause_ended` (réactivation par le propriétaire). |

**Décisions du fondateur** : à la fin de la pause, suspension puis proposition de suppression (pas de réactivation automatique) ; un compte resté suspendu **60 jours** (suspension_max_days) voit sa **suppression programmée automatiquement** (rappel par e-mail 7 jours avant, puis e-mail de suppression programmée, délai habituel de 30 jours, annulable par le propriétaire tant que l'effacement n'a pas commencé ; la suspension d'avant est alors rétablie). Ne concerne que la suspension qui suit une pause (system.pause_ended), pas les autres suspensions.

## Suppression du compte (4C) : confirmation en deux étapes (décision 12)

1. « Mes données » > Supprimer : le propriétaire saisit le **mot de confirmation** (« SUPPRIMER » en français, « DELETE » en anglais, « VERWIJDEREN » en néerlandais), son mot de passe et coche la case. Rien n'est programmé : un **lien signé de 24 h, à usage unique** est envoyé à son adresse (seule l'empreinte du jeton est conservée ; une nouvelle demande remplace l'ancien lien).
2. Le clic sur le lien (propriétaire connecté) programme la suppression au délai des paramètres (30 jours par défaut), mémorise le statut d'avant, trace `organization.deletion_requested` et envoie l'e-mail de suppression programmée. L'annulation reste possible tant que l'effacement n'a pas commencé.

Avant la suppression, la page **suggère la pause** (non facturée, données conservées).

### Pas encore fait (écarts avec le flowchart 4C)
Avertissement des clients qui ont des rendez-vous à venir (WhatsApp), rappels J-7 et J-1, reconfirmation d'identité à l'annulation, déconnexion du numéro WhatsApp Meta, purge des sauvegardes (docs/09).

## Modèle WhatsApp d'annulation
Textes proposés et règles d'envoi : docs/15.
