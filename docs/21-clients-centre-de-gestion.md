# Nova — Customers hub (spec)

Status: design to validate. Visual reference: the canvas "Nova Customers hub" (https://claude.ai/artifact/J7JQpkYgrcd2CehUjfazGY), light and dark, using the website identity (docs/20). No change to navigation or unrelated features.

## 1. Starting point (what exists)

- List (`/clients`): name, phone, blocked badge, last visit; search; paging.
- Profile (`/clients/{id}`): name, email, phone, language, block switch, booking history, erasure.
- Database: `customers` has `tags` (unused on screen), `is_blocked`, `anonymized_at`; WhatsApp STOP is stored as `whatsapp_opted_out_at`. `bookings` carries `price_cents`, `payment_status`, `amount_collected_cents`, `source`, `status`, so visits, no-shows, cancellations and value are computable. No marketing campaigns and no payment processing exist.
- Roles already restrict a practitioner to their own customers (docs/02).

## 2. Views

**Customers list.** Header (title, subtitle, count, Add customer, Export). One search box (name without accents and with typos; phone by digits in any format; email and reference by prefix; tags and searchable custom fields). Table, 60 px rows, sortable, configurable columns: Customer (initials, name, reference), Contact, Last appointment (date, service, status badge), Next appointment (date, service, practitioner), Visits, Value, WhatsApp, Tags, Status, actions. Filters in a slide-out panel: status, WhatsApp consent, appointment state, last and next appointment dates, practitioner, service, location, tags, value segment, number of appointments, no-shows, custom fields; five starter saved views. Selecting rows shows a bulk bar: add tag, assign practitioner, archive, export selected, send WhatsApp message (only to opted-in customers, others skipped with a note), delete (owner and managers, confirmed).

**Profile.** Header with initials, name, reference, status, consent, tags and actions (Create appointment, Send WhatsApp message, Edit, more). Six figures: appointments, completed, cancellations, no-shows, customer value, next appointment. Tabs: Overview (insights, recent activity, contact, pinned note), Appointments (table with filters and rates), WhatsApp and communications (message timeline with sent, delivered, read, failed; consent badges), Notes (internal only, pinned, author and time), Preferences and consent (language, channel, practitioner, location, days, reminders, consent with date and source), Payments (value, average, outstanding, per-booking amounts; only what bookings already hold), Data and privacy (export this customer, consent history, important changes, archive, block, delete).

**Add and edit.** One column, progressive sections: basic information, contact, preferences, consent, business fields; address, date of birth, source, tags and default practitioner under "More details". Required: a name or a usable phone. Inline checks: invalid email, phone without country code, duplicate phone or email (warning with View existing / Create anyway), unusual dates, marketing consent without date and source. Save customer, Save and create appointment, Cancel.

**Export.** From the list (all, search results, filtered list, selected rows) and from a profile. A dialog asks for scope, data categories, format (CSV default, XLSX, JSON for a single customer or technical use), period for history-type data, shows the privacy reminder and "You are exporting N customers and M appointments", then downloads. History page lists every export. Owner only by default; site managers when granted.

**States.** No customers yet, no results, loading skeleton, error with retry, no appointment history, no WhatsApp marketing consent (action visible but disabled with the reason), practitioner scope banner, role without access.

## 3. Data model note

- `customers` (extended): `first_name`, `last_name`, `display_name`, `reference` (per-business sequence), `date_of_birth`, `whatsapp_phone`, `address` (jsonb), `acquisition_source`, `status` (active, archived, blocked; replaces `is_blocked`), `assigned_practitioner_id`, `default_location_id`, `preferred_channel`, `custom` (jsonb keyed by field id). Unchanged: E.164 phone unique per business, language, tags, `anonymized_at`.
- `customer_consents` (append-only): customer, purpose (appointment, marketing), channel, status (granted, withdrawn), source, evidence reference, recorded by, recorded at. Current state is the latest row per purpose; `whatsapp_opted_out_at` is migrated as a withdrawn marketing row.
- `customer_fields`: key, label, type (text, textarea, number, currency, date, boolean, select, multiselect, url), helper text, required, visibility (staff or admin), show in list, searchable, exportable, active, position, options.
- `customer_notes`: customer, author, body (encrypted), pinned, created and edited times.
- `saved_views`: name, filters, columns, owner or shared.
- `export_logs` (append-only): user, scope, filters, categories, format, period, counts, status, file expiry, created at.
- Computed and cached per customer: visits, last and next appointment, cancellations, no-shows, spend, average value, preferred services and practitioner.
- Every new table carries `org_id`, goes through `Rls::enableTenantTable()` and is added to the GDPR erasure steps (`ErasureSteps`, `gdpr_purge_org`); a test already fails when one is missed. Consent and export logs follow the rule of append-only journals (docs/CLAUDE.md rule 4); the export file is deleted after 7 days.

## 4. Permissions and privacy

Owner: everything. Site manager: customers of their locations, edit, archive, notes; export on grant. Receptionist: view and edit operational data, appointments, notes, tags; no export or delete. Practitioner: only customers they have appointments with, no export, no bulk actions. All enforced server-side. Admin-only custom fields and sensitive fields are hidden in the screen, the search and the export. Notes are never visible to customers. Deleting anonymises personal data and keeps appointment records for reporting. Marketing actions require a granted marketing consent.

## 5. Delivery in phases

1. **Foundation** (no visible change): new customer columns, status, reference, consent records, computed summary, search index (pg_trgm and digit-normalised phone), tests, erasure steps.
2. **List**: table, columns, search, filters, saved views, bulk tags and archive, empty and error states.
3. **Profile**: header, figures, tabs (overview, appointments, communications, notes, preferences and consent, data and privacy).
4. **Add and edit**: split name fields, duplicate detection, validation, consent capture.
5. **Export**: server-side CSV, XLSX, JSON, audit log and history, individual export.
6. **Custom fields and payments tab**: field definitions in Settings, values in forms, list and export; value figures from bookings.

## 6. Decisions to take

1. Split the single name into first and last (existing names split on the last space; a few will need correcting) or keep one name plus a display name.
2. Date of birth and address off by default, enabled per business.
3. Marketing consent switch in the form only once campaigns exist; until then show the STOP status.
4. No file attachments in custom fields in the first version.
5. Customer value is computed from booking prices and amounts collected and labelled as such.
6. Site managers export by default, or only on the owner's grant (proposed: only on grant).
