# Passerelle WhatsApp (Meta Cloud API)

Décision du fondateur : Meta Cloud API en direct. Chaque établissement a son propre numéro WhatsApp Business, rattaché à son organisation.

## Flux

1. Meta appelle `POST /api/v1/webhooks/whatsapp` (signature `X-Hub-Signature-256` = HMAC-SHA256 du corps brut avec le secret de l'application Meta ; sans secret configuré, tout est refusé). L'URL est vérifiée par `GET` (`hub.verify_token`).
2. L'établissement est retrouvé par l'identifiant du numéro Meta (fonction SQL étroite `whatsapp_account_lookup`), puis tout s'exécute sous son contexte (RLS).
3. Le message est enregistré (texte **chiffré au repos**), le client retrouvé ou créé par son numéro. Un même identifiant WhatsApp n'est traité qu'une fois.
4. Le message est transmis à n8n (`N8N_INBOUND_URL`), signé (`X-Nova-Signature` = HMAC-SHA256 de `horodatage.corps`). Seuls des identifiants passent par la file Redis ; 5 essais avec délais croissants.
5. n8n répond par `POST /api/v1/messages` (périmètre `conversations:write`) et réserve par le reste de l'API.

## Garde-fous du canal

- **STOP / START** (mots exacts, sans casse ni ponctuation finale, en français, néerlandais et anglais) : désabonnement et réabonnement tracés dans le journal d'audit, confirmation envoyée dans la langue du client. Un client désabonné qui écrit sans dire START ne déclenche aucune réponse automatique.
- **Rappels de rendez-vous** : commande `bookings:send-reminders` toutes les 5 minutes. Délai réglé dans les politiques (`reminder_hours_before`, 0 = aucun). Message **modèle** Meta (`WHATSAPP_TEMPLATE_REMINDER`, défaut `appointment_reminder`) à 4 variables : établissement, service, date, heure ; à créer en fr, nl et en dans la console Meta. Un seul rappel par rendez-vous (réservation atomique par le cœur de réservation, événement `reminder_sent`), aucun pour un rendez-vous pris à l'intérieur du délai, jamais après un STOP, comptes suspendus/en pause ignorés, 3 tentatives au plus, nouveau rappel après un déplacement.
- **Fenêtre de service de 24 h** : réponse libre uniquement après un message du client. Au-delà, un modèle approuvé par Meta est obligatoire (rappels : ci-dessus ; campagnes : plus tard).
- Envoi échoué : consigné `failed` avec le code Meta, sans le texte du message.
- Accusés d'état (`sent`, `delivered`, `read`, `failed`) : l'état n'avance que vers l'avant.

## Configuration (environnement, jamais dans le code)

`WHATSAPP_DRIVER` (`fake` par défaut, `meta` en production), `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`, `N8N_INBOUND_URL`, `N8N_SIGNING_SECRET`. Le jeton d'accès de chaque numéro est chiffré en base ; rattachement :

```bash
docker compose run --rm --no-deps app php artisan whatsapp:connect <id-organisation> <phone_number_id> --display=+3225550000
```

Le jeton est saisi de façon masquée (jamais en argument).

## À valider par le fondateur / juridique

- Textes de confirmation STOP/START (`lang/*/whatsapp.php`) et mots-clés reconnus.
- **Durée de conservation** des conversations (aujourd'hui illimitée : la purge automatique reste à faire).
- Rôle de Rendivoo (sous-traitant) et information des clients finaux sur l'usage d'un assistant automatisé.
- Modèles de messages (rappels, confirmations hors fenêtre) : à soumettre à Meta.