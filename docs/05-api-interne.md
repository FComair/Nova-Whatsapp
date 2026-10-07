# API interne v1 (n8n, passerelle WhatsApp)

Base : `/api/v1`. JSON uniquement, sans session. Toutes les écritures de réservation passent par le cœur de réservation (règle n° 2) : n8n n'accède jamais à la base.

## Authentification

Chaque requête porte trois en-têtes :

| En-tête | Contenu |
|---|---|
| `X-Nova-Key` | Clé publique du client d'API (`nk_…`) |
| `X-Nova-Timestamp` | Secondes Unix ; tolérance de 5 minutes |
| `X-Nova-Signature` | `hex(HMAC-SHA256(secret, "{timestamp}\n{MÉTHODE}\n{chemin?requête}\n{sha256(corps)}"))` |

Le chemin est celui de la requête, requête comprise (`/api/v1/availability?from=…`). Corps vide : `sha256("")`. Clé inconnue, révoquée ou signature fausse : même réponse `401 invalid_credentials` (pas d'oracle). Plafond : 300 requêtes/minute par clé, 600 par adresse IP.

Le secret est chiffré au repos, affiché **une seule fois** à l'émission :

```bash
docker compose run --rm --no-deps app php artisan api-clients:create <id-organisation> n8n --scope=availability:read --scope=bookings:write
```

## Périmètres (scopes)

`config:read`, `availability:read`, `bookings:read`, `bookings:write`, `customers:write`, `conversations:write`. Un périmètre manquant : `403 insufficient_scope`.

## État du compte

Suspendu : lecture seule (`403 read_only` en écriture). En pause, en suppression ou supprimé : `503 account_unavailable`.

## Points d'accès

| Méthode et chemin | Périmètre | Rôle |
|---|---|---|
| `GET /config` | config:read | Sites et horaires, services réservables, praticiens actifs, politiques (aucune donnée personnelle) |
| `GET /availability?location_id&service_id&from&to[&practitioner_id]` | availability:read | Créneaux réservables (UTC), 31 jours maximum, 500 créneaux maximum (`truncated`) |
| `POST /customers` | customers:write | Retrouve ou crée un client par numéro E.164 (201 création, 200 existant) |
| `GET /bookings?customer_phone` | bookings:read | Prochains rendez-vous d'un client |
| `POST /messages` | conversations:write | Envoie une réponse WhatsApp (`customer_phone`, `text`) : refus `409` (`window_closed`, `opted_out`, `no_account`), `502 delivery_failed` |
| `POST /bookings` | bookings:write | Crée une réservation (en-tête `Idempotency-Key` obligatoire, 60 caractères max) |
| `POST /bookings/{id}/confirm` | bookings:write | Confirme un blocage en attente |
| `POST /bookings/{id}/cancel` | bookings:write | Annule (motif optionnel) |
| `PUT /bookings/{id}/move` | bookings:write | Déplace (`starts_at`, `practitioner_id` optionnel) |

Dates et heures : ISO 8601 **avec fuseau** (`2026-06-02T10:00:00+02:00`) ; les réponses sont en UTC.

`POST /bookings` : `location_id`, `service_id`, `practitioner_id`, `starts_at`, `customer_phone` (E.164), `customer_name?`, `customer_language?` (fr/nl/en), `hold?` (blocage de quelques minutes en attente de confirmation), `source?` (`whatsapp` par défaut `api`), `notes?`. Les politiques de réservation des clients s'appliquent (délai minimum, horizon).

Agir « pour le client » (`acting_as` = `customer`, par défaut) exige `customer_phone`, comparé à celui de la réservation (`403 customer_mismatch`), et soumet à la fenêtre d'annulation. `acting_as: business` agit comme l'établissement.

## Idempotence

Même `Idempotency-Key` et même demande : la réservation existante est renvoyée (`200`). Même clé avec une demande différente : `409 idempotency_conflict`. Les clés sont propres à chaque client d'API.

## Erreurs

`{ "error": { "code": "…", "message": "…" } }`, codes stables. Créneau indisponible : `409 slot_unavailable` avec `reason` (`taken`, `outside_hours`, `policy`) et `suggestions` (3 prochains créneaux). Règles métier : `422` avec le code de la règle (`cancellation_too_late`, `hold_expired`, `invalid_transition`, `service_unavailable`, `practitioner_cannot_perform`) ; `403 customer_blocked`. Validation : `422` au format Laravel.

## Hors périmètre de cette étape

Commandes du personnel (`staff_whatsapp`), écran propriétaire de gestion des clés (pour l'instant : commande en ligne), rotation automatique des secrets, liste blanche d'adresses IP.
