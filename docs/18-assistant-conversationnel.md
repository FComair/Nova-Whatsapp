# Assistant de conversation WhatsApp natif (reprise du flux n8n « MASTER ROUTER V2 »)

> **Statut : proposition, en attente de validation du fondateur.** Elle déplace une décision (docs/04 n° 1 : la conversation est portée par n8n). Par défaut **rien ne change** : `CONVERSATION_DRIVER=n8n`. Mettre `CONVERSATION_DRIVER=native` fait répondre Nova elle-même.

## Pourquoi
Le client final doit retrouver dans Nova **exactement** la conversation du flux n8n (menus, textes, étapes), sans dépendre de n8n, de Google Calendar ni de Google Sheets : Nova est le calendrier de référence.

## Fonctionnement
- `InboundProcessor` : si le pilote est `native`, il met `HandleInboundMessage` en file au lieu de `ForwardInboundMessage` (n8n). STOP/START, désabonnement et doublons de message sont traités avant, comme aujourd'hui.
- `ConversationEngine` (module `app/Modules/Conversation`) : une machine à états par client, mêmes états que n8n (`waiting_service_choice`, `waiting_booking_day`, …). Message libre : **règles** (mots-clés fr/nl/en), puis **modèle de langage** en repli (seuil 0,70), puis **menu de clarification**.
- Parcours : réservation, modification, annulation, questions (FAQ), « Parler à quelqu'un », réponse à un rappel. Pagination 5 par page, praticien choisi automatiquement s'il n'y en a qu'un, nom → e-mail facultatif (0 = continuer) → récapitulatif 1 Confirmer / 2 Modifier / 3 Annuler.
- **Une seule porte vers les réservations** : `BookingGateway` → `AvailabilityService` (créneaux) et `BookingCore` (création, déplacement, annulation avec règles, fenêtre d'annulation, anti double réservation, idempotence `bot-…`). Aucun paiement : paiement sur place.
- Textes : `lang/{fr,nl,en}/conversation.php` (le français reprend le wording de n8n). Aucun texte en dur.
- État de la conversation : cache chiffré, verrou par client, expiration `conversation.session_ttl_minutes` (120 min). Pas de table → aucun impact RLS ni effacement RGPD (le cache expire seul).
- Modèle de langage : API compatible OpenAI (`CONVERSATION_AI_API_KEY`, vide = menus seuls, aucun appel externe). Pour la FAQ, il ne reçoit que les données de l'établissement ; le texte du client est envoyé au prestataire (**point RGPD à valider**, sous-traitant à déclarer).

## Écarts et ajouts par rapport à n8n (à valider)
1. Textes **nouveaux** (absents de n8n) : jour/nom/e-mail incompris, demande abandonnée, rendez-vous refusé, trop tard pour annuler/modifier, transfert à l'équipe, aucune disponibilité, réponse de rappel invalide, FAQ sans information, erreur technique.
2. « Prendre un nouveau rendez-vous » depuis un rendez-vous existant va directement au choix du service.
3. Le menu des praticiens ne propose que ceux qui ont un créneau ce jour-là ; « Aucune préférence » est résolu à la confirmation.
4. L'e-mail est demandé comme dans n8n mais **aucune invitation n'est envoyée** (pas de synchro calendrier) : il est seulement mémorisé sur la fiche client s'il était vide.
5. Réponse à un rappel (1 confirmer / 2 annuler) : implémentée, mais `ReminderService` ne l'active pas encore (`ConversationEngine::expectReminderReply`) et le modèle Meta de rappel n'affiche pas ces options. La présence confirmée n'est pas enregistrée.
6. « Parler à quelqu'un » : l’assistant se tait `conversation.handoff_hours` (12 h). Comportement inventé.
7. FAQ : horaires, adresse, prix, durées, politique d'annulation. Téléphone, e-mail, site et FAQ personnalisée suivront (table `faq_entries` pas encore créée).
8. Traductions nl/en à faire valider par un natif.

## Tests
`tests/Feature/Conversation/ConversationTest.php` (Pest, Postgres réel) : parcours complet, rejeu du même message, créneau pris, annulation (délai), modification, FAQ avec/sans modèle, seuil de confiance, silence après transfert, langue, pause, analyseurs.
