# Messages WhatsApp aux clients lors des changements faits par l'établissement (flowcharts 2B, 2D, 4B)

## Quand
Depuis le planning, quand le personnel **crée**, **déplace** ou **annule** un rendez-vous, une case « Prévenir le client par WhatsApp » (cochée par défaut) envoie un message au client. Le même mécanisme (`BookingNotifier`) sert à l'annulation en masse lors d'une pause d'établissement (docs/13).

## Règles
- Message **modèle** approuvé par Meta (seul autorisé hors fenêtre de service de 24 h), dans la **langue du client** (français par défaut), avec quatre paramètres : établissement, service, jour, heure (le **nouveau** créneau pour un déplacement).
- **Au mieux** : un client désinscrit (STOP), sans compte WhatsApp joignable ou un échec de Meta n'annule jamais l'action ; l'utilisateur voit « le client n'a pas pu être prévenu, contactez-le directement ». L'envoi a lieu après l'écriture, jamais dans la transaction.
- Jamais après un STOP. Chaque envoi est journalisé (`whatsapp_messages`, texte chiffré).
- Sans la case cochée (ou sans le champ `notify`), aucun message n'est envoyé.

## Modèles à soumettre à Meta (textes proposés, **à valider par le fondateur**)
Noms configurables : `WHATSAPP_TEMPLATE_CONFIRMATION`, `WHATSAPP_TEMPLATE_RESCHEDULED`, `WHATSAPP_TEMPLATE_CANCELLATION` ; rappel existant : `WHATSAPP_TEMPLATE_REMINDER` (`appointment_reminder`). Paramètres : `{{1}}` établissement, `{{2}}` service, `{{3}}` jour, `{{4}}` heure.

### `appointment_confirmation`
- fr : « Bonjour, {{1}} a bien enregistré votre rendez-vous ({{2}}) le {{3}} à {{4}}. À bientôt ! »
- nl : « Hallo, {{1}} heeft uw afspraak ({{2}}) op {{3}} om {{4}} geregistreerd. Tot binnenkort! »
- en : « Hello, {{1}} has booked your appointment ({{2}}) on {{3}} at {{4}}. See you soon! »

### `appointment_rescheduled`
- fr : « Bonjour, {{1}} a déplacé votre rendez-vous ({{2}}) : il a désormais lieu le {{3}} à {{4}}. Merci de votre compréhension. »
- nl : « Hallo, {{1}} heeft uw afspraak ({{2}}) verplaatst: die vindt nu plaats op {{3}} om {{4}}. Bedankt voor uw begrip. »
- en : « Hello, {{1}} has moved your appointment ({{2}}): it now takes place on {{3}} at {{4}}. Thank you for your understanding. »

### `appointment_cancelled`
- fr : « Bonjour, {{1}} doit annuler votre rendez-vous ({{2}}) du {{3}} à {{4}}. Nous sommes désolés pour ce désagrément et vous recontacterons pour en fixer un nouveau. »
- nl : « Hallo, {{1}} moet uw afspraak ({{2}}) op {{3}} om {{4}} annuleren. Onze excuses voor het ongemak; wij nemen contact met u op voor een nieuwe afspraak. »
- en : « Hello, {{1}} has to cancel your appointment ({{2}}) on {{3}} at {{4}}. We apologise for the inconvenience and will contact you to arrange a new one. »

## Pas encore fait
Réponse du client à ces messages (relève du bot et de n8n), notification aux clients lors de la suppression du compte (docs/13), préférences de notification par établissement, soumission et suivi d'approbation des modèles dans Rendivoo (étape E6), message de confirmation lors d'une réservation faite par le client lui-même via le bot (géré côté n8n).
