# Nova — Schéma de base de données (proposition v1, à valider)

Statut : **proposition**. Sources : spec backend FR, flowcharts 1–5, plan de tarification, décisions du fondateur (17 réponses).
Légende : **[Spec]** repris de la spec · **[Flow]** exigé par un flowchart · **[Décision]** décidé par le fondateur · **[Reco]** recommandation à valider.

## 0. Conventions

- PostgreSQL 16, extensions `btree_gist`, `pgcrypto`, `citext`.
- Clés primaires `uuid` (UUIDv7, triables par date). Horodatages `timestamptz` en UTC ; fuseau IANA stocké sur `organizations` et `locations`.
- Montants en **centimes** (`bigint`) + `currency char(3)`. Jamais de flottants.
- **Isolation** [Spec] : toute table métier porte `org_id` et une politique **RLS** `org_id = current_setting('app.current_org')::uuid`. L'application se connecte avec un rôle **non propriétaire** (sans `BYPASSRLS`). Le Super Admin utilise un rôle distinct, journalisé.
- Tables **append-only** (`audit_logs`, `booking_events`, `sensitive_access_logs`) : `INSERT` seul accordé au rôle applicatif, `UPDATE/DELETE` révoqués + trigger de garde.
- Suppression : soft delete (`deleted_at`) uniquement là où l'historique est utile ; le pipeline RGPD (`deletion_jobs`) fait la suppression/anonymisation réelle.
- Champs chiffrés (`_enc`) : chiffrement applicatif. Si `organizations.data_sensitivity = true`, clé séparée par organisation (chiffrement d'enveloppe) [Spec §9].

## 1. Identité, tenancy et cycle de vie

| Table | Colonnes principales | Remarques |
|---|---|---|
| `platform_admins` | id, email, password_hash, totp_secret_enc, totp_confirmed_at, recovery_codes_enc, ip_allowlist, last_login_at | **Table et guard séparés** des clients [Décision : fondateur seul]. 2FA obligatoire. |
| `users` | id, email (citext, unique), password_hash, phone_e164, locale (`fr/nl/en`), email_verified_at, phone_verified_at, failed_login_count, locked_until, last_login_at, status | Comptes de connexion client [Spec]. `locale` mémorisée [Flow]. |
| `organizations` | id, name, slug, sector, country, timezone, locale, **status**, plan_id, terminology jsonb, data_sensitivity bool, trial_ends_at, paused_at, pause_until, suspended_at, suspension_reason, suspension_ends_at, unavailable_message jsonb (par langue), deletion_requested_at, deletion_scheduled_for, deleted_at, billing_* (raison sociale, TVA, adresse) | Statuts : `trial, active, past_due, suspended, paused, pending_deletion, deleted` (**`paused` ajouté** [Flow 4B]). |
| `locations` | id, org_id, name, address, timezone, is_default | Site par défaut créé à l'inscription, masqué si count = 1 [Spec]. |
| `org_members` | id, org_id, user_id, role (`owner, site_manager, practitioner, receptionist`), status | [Spec]. Un seul `owner` actif par organisation. |
| `org_member_locations` | member_id, location_id | Sites autorisés (rôle site_manager, réceptionniste). |
| `invitations` | id, org_id, email, role, practitioner_id?, token_hash, expires_at, used_at, revoked_at | [Flow 1C, E13] : nouvelle invitation invalide l'ancienne. |
| `onboarding_progress` | org_id, current_step (1–9), steps jsonb (statut par étape), started_at, completed_at, went_live_at | [Spec, Flow 1B–1D] : reprenable à chaque étape. |
| `verification_codes` | id, subject_type/id, channel (`email_link, sms_code`), code_hash, expires_at, attempts, sent_count, window_started_at, blocked_until | [Flow 1A, E2] : 6 chiffres, 5 essais, blocage 15 min, 3 envois/heure (**valeurs à valider**). |
| `password_resets` | id, user_id, token_hash, expires_at (60 min), used_at | [Flow 2A]. |
| `login_attempts` | id, identifier_hash, ip, succeeded, created_at | Verrouillage 15 min après 5 échecs [Flow E3]. |
| `sessions` | Table Laravel standard + user_id, ip, user_agent | Fermeture des autres sessions après changement de mot de passe/e-mail [Flow]. |
| `legal_documents` | id, type (`cgu, privacy, dpa`), version, locale, published_at | Versionnage des documents acceptés. |
| `user_consents` | id, user_id, type (`cgu, privacy, dpa, nova_news, product_improvement`), document_version, granted_at, withdrawn_at, source, ip | [Flow 1A, 3B] : date et source à chaque choix. |
| `user_notification_prefs` | user_id, event, channel (`email, browser`), enabled, locked bool | `locked = true` pour les notifications obligatoires (sécurité, facturation, légal) [Flow 3B]. |
| `push_subscriptions` | id, user_id, endpoint, keys_enc | Notifications navigateur [Flow 2A]. |

## 2. WhatsApp et personnel

| Table | Colonnes principales |
|---|---|
| `whatsapp_channels` | id, org_id, location_id, phone_number_id, waba_id, display_name, token_ref, verification_status, meta_tier, quality_rating, mode (`live, test_limited`) [Spec, Flow E5] |
| `template_definitions` | id, org_id, key, language, category, body, variables jsonb, meta_template_id, status (`draft, submitted, approved, rejected`), rejection_reason, accepted_example [Spec, Flow E6] |
| `staff_identities` | id, org_id, member_id/practitioner_id, phone_e164, pairing_code_hash, verified_at, revoked_at [Spec §10] |
| `staff_notification_prefs` | id, staff_identity_id, event, muted, quiet_hours jsonb [Spec §10] |

## 3. Catalogue, horaires, politiques

`practitioners`, `practitioner_services`, `practitioner_schedules`, `practitioner_time_off`, `service_categories`, `services`, `service_fields`, `resources`, `service_resources`, `business_hours`, `business_hours_exceptions`, `booking_policies` : **repris tels que dans la spec §4.2–4.5**, plus :

| Ajout | Détail |
|---|---|
| `practitioners.status` | `draft` quand la limite du plan est atteinte [Flow E8 : « l'élément reste en brouillon »]. |
| `sector_templates` | id, sector, locale, services jsonb, policies jsonb — modèles d'onboarding [Spec §9, Flow 1B]. |
| `service_import_jobs` | id, org_id, file_ref, mime, status (`pending, read, failed, confirmed`), extracted jsonb, error, confirmed_at — scan de carte tarifaire par **LLM vision** [Décision, Flow 1B/E4]. Fichier supprimé après confirmation. |

## 4. Réservations et retours clients

| Table | Colonnes / remarques |
|---|---|
| `bookings` | Spec §4.5 + colonne générée `during tstzrange(starts_at, ends_at)`, `expires_at` (blocage 5–10 min), `idempotency_key`, `source` (`dashboard, whatsapp, staff_whatsapp, api`). Contrainte : `EXCLUDE USING gist (practitioner_id WITH =, during WITH &&) WHERE (status IN ('pending','confirmed'))` [Spec §6]. `notes_enc` chiffrées. |
| `booking_events` | Append-only : acteur (`user, staff_whatsapp, customer, system, api`), action, diff [Spec]. |
| `booking_field_answers` | Chiffrées si `data_sensitivity` [Spec §9]. |
| `waitlist_entries` | id, org_id, customer_id, service_id, practitioner_id?, desired_range, status (`waiting, offered, accepted, expired`), offered_at [Flow 2B]. |
| `reviews` | id, org_id, booking_id, rating, comment, replied_at, reply_text [Flow 2B]. |
| `referrals` | id, org_id, code, referred_org_or_customer, status, rewarded_at [Flow 2B]. **Mécanisme de récompense à définir.** |

## 5. Clients finaux et consentements

| Table | Détail |
|---|---|
| `customers` | org_id, phone_e164 (unique par org), name, language, tags, blocked, anonymized_at [Spec]. |
| `customer_consents` | customer_id, type (`marketing_optin, stop, privacy_notice_ack`), granted_at, withdrawn_at, source [Spec, Flow 2D, E14]. |
| `customer_notes` | Chiffrées ; lecture journalisée si `data_sensitivity` [Spec §9]. |
| `data_subject_requests` | id, org_id, customer_id, type (`erasure, access, rectification`), status, decision (`accepted, refused`), refusal_reason, decided_by, decided_at [Flow E14]. Le **commerce** décide (responsable du traitement — **à confirmer par un juriste**). |

## 6. Conversations

`conversations`, `messages`, `nlu_interpretations` [Spec §4.7] + `outbox_messages` (pattern Outbox [Spec §12]) + `handoff_requests` (id, org_id, conversation_id, reason `not_understood, llm_down, customer_asked`, status, resolved_at) [Flow E11].

## 7. Calendriers externes (import en lecture seule) [Décision]

| Table | Colonnes principales |
|---|---|
| `external_calendar_links` | id, org_id, practitioner_id, provider (`ics_url, ics_file, caldav` — `google, microsoft, calendly` réservés), account_ref, credentials_enc, feed_url_enc, sync_token, direction = `read`, status (`active, error, revoked`), last_synced_at, last_error, **`import_customer_details bool default false`** (opt-in par calendrier, avertissement RGPD) |
| `external_busy_blocks` | id, org_id, link_id, external_uid, starts_at, ends_at, all_day, status, content_hash. **Aucun titre ni participant.** Ces blocs réduisent les disponibilités du praticien mais **ne sont pas des réservations** : la contrainte d'exclusion reste intacte. |
| `external_event_details` | block_id, title_enc, attendees_enc — **créée uniquement si l'opt-in est actif** ; purgée à la désactivation. |
| `sync_runs` | id, link_id, started_at, finished_at, status, blocks_added/updated/removed, error. Alimente l'erreur E15 (« synchronisation échouée », **à ajouter au catalogue**). |
| `calendar_feed_tokens` | practitioner_id, token_hash, revoked_at — flux ICS **sortant** en lecture [Spec §11]. |

Notes : Doctena et Rosa = import de fichier ou lien ICS uniquement (aucun accès API confirmé). Feature flag `external_calendars` activé sur Basic et Business.

## 8. Campagnes, support, FAQ

| Table | Détail |
|---|---|
| `campaigns` | id, org_id, name, audience_filter jsonb, template_id, status (`draft, scheduled, sending, sent, blocked`), blocked_reason (`meta_tier, quality`), scheduled_at [Flow 2B, E9] |
| `campaign_recipients` | campaign_id, customer_id, status, excluded_reason (`no_optin, stop`) — exclusion automatique sans opt-in ; STOP respecté. |
| `faq_entries` | id, org_id, locale, question, answer, position [Business plan, spec §7]. |
| `support_tickets`, `ticket_messages` | id, org_id, user_id, subject, status, priority, context jsonb (journal d'échec E5/E12), assigned_to = fondateur [Flow 2B, E5, E12]. |

## 9. Facturation Nova → client

| Table | Détail |
|---|---|
| `plans` | id, code, name jsonb, price_monthly_cents, price_yearly_cents, currency, limits jsonb (praticiens, sites, conversations, rendez-vous), is_active. **Prix en base, jamais en dur** (contradiction Starter/Basic non tranchée). |
| `plan_features` | plan_id, feature_key, enabled, limit_value. |
| `org_feature_overrides` | org_id, feature_key, enabled, reason, set_by (admin), expires_at. |
| `subscriptions` | id, org_id, plan_id, interval, status, provider, provider_ref, current_period_start/end, trial_end, pending_plan_id (**descente appliquée à l'échéance** [Flow 3C]), cancel_at. |
| `subscription_items` | subscription_id, kind (`location_addon, extra_user`), quantity, unit_price_cents. |
| `invoices`, `invoice_lines` | numérotation séquentielle sans trou, snapshot de l'identité de facturation, HT/TVA/TTC, statut, `pdf_ref`, `pseudonymised_at` (conservation légale 7 ans). |
| `payments`, `payment_methods` | provider_ref, statut, failure_code. **Aucune donnée de carte** : jetons du prestataire uniquement. |
| `dunning_events` | org_id, attempt, sent_at, channel [Flow E7]. |
| `usage_counters`, `usage_alerts` | org_id, period, metric (`practitioners, locations, conversations, appointments`), value ; alerte avant seuil, palier supérieur proposé, **aucune coupure** [Décision]. |

**Couche d'abstraction de paiement** [Décision : prestataire non choisi] : interface `PaymentGateway` (créer client, abonnement, prorata, moyen de paiement, webhooks idempotents). Le prestataire est branché plus tard.

## 10. RGPD, suppression, export

| Table | Détail |
|---|---|
| `data_exports` | id, org_id, requested_by, formats (`json, csv`), status, file_ref, expires_at, attempts, error [Flow 4C, E12] |
| `deletion_requests` | id, org_id, requested_by, kind (`owner, admin`), reason_code (`standard, fraud, abuse`), immediate bool, confirmed_at, scheduled_for (J+30), cancelled_at, reminders_sent jsonb (J-7, J-1) [Flow 4C/4D, Décision] |
| `deletion_jobs` | org_id, table_name, status, scheduled_for, completed_at, attempts (3 relances puis alerte fondateur) [Spec, Flow E12] |
| `subprocessors` | id, name, purpose, country, dpa_ref, active — liste des sous-traitants (Meta, hébergeur, fournisseur LLM, Google/Microsoft si connecteurs, prestataire de paiement, e-mail transactionnel). |

## 11. Audit, exploitation, API

| Table | Détail |
|---|---|
| `audit_logs` | id, org_id?, actor_type (`user, platform_admin, system, staff_whatsapp, api`), actor_id, action, entity_type/id, diff jsonb, reason, ip, user_agent, created_at. Append-only. |
| `sensitive_access_logs` | Lecture de notes/réponses d'accueil (mode données sensibles) [Spec §9]. |
| `system_alerts` | id, severity, source, org_id?, message, resolved_at — erreurs et alertes du Super Admin. |
| `platform_settings` | key, value jsonb — durées (pause 90 j, suspension 60 j, verrouillage, etc.) **paramétrables** [Décision]. |
| `system_message_templates` | key, locale, subject, body — e-mails et messages système FR/NL/EN. |
| `api_clients` | id, name, secret_hash, scopes, active — clients de l'API interne pour **n8n** [Décision]. |
| `webhook_events` | provider, event_id (unique), payload_hash, processed_at — idempotence [Spec §12]. |
| `daily_stats` | Agrégats nocturnes [Spec §8]. Les rapports lisent ces agrégats, jamais les réservations brutes. |

## 12. Machine à états de `organizations.status`

| De | Vers | Déclencheur |
|---|---|---|
| `trial` | `active` | Abonnement payé |
| `trial` | `suspended` | Essai terminé sans abonnement [Flow 4A] |
| `active` | `past_due` | Paiement échoué [Flow E7] |
| `past_due` | `active` / `suspended` | Régularisation / délai dépassé |
| `active`, `trial` | `paused` | Demande du propriétaire ; max 90 j, non facturée [Décision] |
| `paused` | `active` | Réactivation |
| `paused` | `pending_deletion` | Durée max dépassée, si suppression choisie |
| `suspended` | `active` | Régularisation ou levée par le Super Admin |
| `suspended` | `pending_deletion` | Au-delà de 60 j : avertissement, export, suppression proposée [Décision] |
| tout statut sauf `deleted` | `pending_deletion` | Confirmation propriétaire (e-mail 24 h) ou décision Super Admin |
| `pending_deletion` | `active` | Annulation avant J+30 avec confirmation d'identité |
| `pending_deletion` | `deleted` | J+30 (ou immédiat si fraude/abus, motif journalisé) |

Effets par statut : voir `02-roles-et-permissions.md` §4.

## 13. Points ouverts sur le schéma

1. Récompense du **parrainage** (crédit ? remise ?) — non défini dans les documents.
2. Périmètre exact des **données financières pseudonymisées** conservées 7 ans : point de **validation juridique**.
3. Chiffrement d'enveloppe par organisation : coût/complexité à confirmer (**Reco** : oui pour `data_sensitivity`).
4. Stockage des fichiers (cartes de tarifs, exports) : disque chiffré sur VPS ou stockage objet UE.
