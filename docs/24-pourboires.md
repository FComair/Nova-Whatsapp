# 24 — Practitioner tips

Tips paid to practitioners are recorded as a **separate, auditable amount** next to the existing payment of an appointment. They never
change the service price, the amount collected for the service, VAT, or any figure that existed before this feature: every existing
financial calculation is unchanged (a test deletes all tips and checks that each pre-existing metric is identical).

## 1. What was inspected (and the model it led to)

| Existing | Consequence |
|---|---|
| A payment is **fields on the appointment** (`price_cents`, `payment_status`, `amount_collected_cents`, `payment_method`), saved by `BookingCore::recordPayment` and logged as a `payment_updated` event. There is no separate payment-transaction table, no payment date column, no receipts. | The "payment transaction" a tip belongs to **is the appointment's payment record**. A tip is attached to the appointment (`booking_id`) and therefore to that payment. |
| One practitioner per appointment (`bookings.practitioner_id`); no multi-practitioner appointments and no allocation rules. | The **default rule is the assigned practitioner**. "Several practitioners" is supported as an explicit **split of the tip between practitioners** (e.g. the stylist and the assistant), with shares that must add up. |
| One payment record per appointment. | **One tip per appointment** (database unique key). Saving again edits it; a second tip transaction is not supported by the existing payment model, so duplicates are impossible rather than merely discouraged. |
| Payment permissions: whoever can manage the appointment (owner, site manager and receptionist on their sites, practitioner on their own appointments). Financial reports: owner, site manager if the owner enabled it. | Recording/editing a tip uses **exactly the payment permissions**; reading tips in reports/exports uses the **financial-report permissions**. |
| `audit_logs` (who/when/what, before/after) and the append-only `booking_events`. | Every tip change writes **both**. |
| Reports/exports: `Reports` module (docs/23), CSV/XLSX through shared tables, print view. | Tips are added as **their own metrics, columns and tables** in the same mechanisms. |

## 2. Data model (migration `2026_11_06_000001`)

- `booking_tips` — `org_id`, `booking_id` (**unique**), `customer_id`, `amount_cents` (> 0), `currency`, `paid_at`, `recorded_by`, timestamps.
  A zero or blank tip is not stored: it means "no tip" and removes an existing one.
- `booking_tip_allocations` — `tip_id`, `booking_id`, `practitioner_id`, `amount_cents` (> 0), unique per (tip, practitioner).
- **The shares always add up to the tip, guaranteed by the database** (deferred constraint triggers), not only by the code.
- Both tables are tenant tables under PostgreSQL RLS. Practitioners are never cascade-deleted: a recipient stays in the history.
- Status and date of the payment: the **payment status is the appointment's live status** (it is never duplicated, so it cannot drift);
  `paid_at` is when the tip was first recorded; exports also show the date the payment was last recorded (latest `payment_updated`
  event). The platform does not store a separate payment date.
- Currency: the appointment's currency (the business currency); amounts are integer cents; display uses the business locale.

## 3. Recording a tip

Where: the existing **Payment** form of an appointment (Planning → appointment → Payment). New field **Tip**, under the existing
payment fields, with the hint *"Optional. A tip is recorded separately: it is never added to the service price."*

- Optional: blank or `0` = no tip. Existing tips are pre-filled, so re-saving a payment leaves them unchanged; clearing the field
  removes the tip.
- Valid amount: non-negative, at most two decimals (`5`, `5,5`, `5.50`); anything else is refused on screen and on the server.
- Recipient: by default **the practitioner of the appointment** ("The tip goes to Anna."). A checkbox *Split between several
  practitioners* shows one amount field per active practitioner and a live balance ("Allocated €6.00 of €10.00 — €4.00 left").
  The full tip is first proposed to the appointment's practitioner when switching to split mode.
- **No active practitioner on the appointment** (the assigned one was deactivated or deleted): the form says so and requires choosing
  the recipient(s) before saving; the server refuses a default attribution in that case.
- A tip needs a payment that was received: **paid on site, partially paid or waived** (a free service can still be tipped).
  *Unpaid* and *written off* refuse a tip, with a clear message. The payment and the tip are saved in **one transaction**: if the tip is
  refused, the payment is not saved either.
- Refused with a clear message: invalid amount, unknown / inactive / duplicated recipient, non-positive share, shares not adding up,
  tip on a cancelled appointment.

## 4. Display

Appointment details show four distinct lines — never one combined figure:

```
Payment status        Paid on site
Service price         €50.00
Collected for the service  €50.00
Tip                   €5.00      Recipient(s): Anna (€5.00)
Total paid (service collected + tip)  €55.00   — This total includes the tip.
```

With no tip the line reads "No tip". The combined line is only shown when something was collected or tipped, and always says it
includes the tip; it is never labelled revenue. (The platform has no receipts or invoices for appointment payments, so there is no
receipt to extend; the appointment details are the payment summary.)

## 5. Audit trail

Each change writes `audit_logs` (`booking.tip.added | changed | reallocated | removed`: user, timestamp, IP, appointment reference,
**before and after** amounts and allocations, currency, payment status) and a `tip_updated` entry in the appointment's append-only
`booking_events`. Saving without any real change writes nothing.

## 6. Reports (Financial, Practitioners, Overview)

Definitions are in docs/23 §3. In short, and **always as separate figures**:

| Figure | Meaning |
|---|---|
| Service amounts | Appointment value, collected, outstanding… exactly as before — **no tip inside**. |
| **Tips** | Sum of the tips of the period's appointments; **Number of tips**; **Average tip** = tips ÷ number of tips. |
| **Total paid (service collected + tips)** | `collected + tips`, labelled with its calculation and "includes tips". Never "service revenue". |
| Tips by practitioner | Tips *received*, from the allocations (the recipient of a split tip may differ from the appointment's practitioner), with count and share. |
| Tips by service / location | A **Tips** column next to value and collected. |
| Tips over time | A third series next to value and collected, attributed to the **appointment day** (like collected amounts). |
| Split tips | How many tips were split between several practitioners. |

Filters: date range, practitioner (the appointment's practitioner), location, service, **payment status** (new, Financial report only),
appointment status. The previous-period comparison follows the usual neutral rules.
Per-practitioner service revenue is unchanged; the practitioner comparison table gets a separate **Tips** column.

## 7. Exports and print

- **CSV / XLSX** (and the print view, which reuses the screens): the Financial summary gains Tips, Number of tips, Average tip and
  Total paid; the over-time, service, location and practitioner tables gain a Tips column; a *Tips by practitioner* table is added.
- **Detail rows (one appointment per row)** gain: *Appointment reference* (the appointment id), *Service amount*, *Service amount
  collected*, *Tip*, *Total paid (service collected + tip)*, *Currency*, *Payment status*, *Payment date*, and — for roles that may see
  practitioner-level detail — *Tip recipient* and *Tip allocation* ("Anna: 6.00; Ben: 4.00").
- A **Tip details** table lists **one row per recipient** (a split tip makes several rows) with the share, the tip total, currency,
  payment status and date.
- Everything respects the selected period and filters, and the permissions: without financial access no tip column is written at all
  (the figures are not even computed); without practitioner-level access the recipient/allocation columns and the tip details are omitted.

## 8. Permissions summary

| Action | Who |
|---|---|
| Record / edit / remove a tip | Same as recording the payment (owner, site manager, receptionist on their sites, practitioner on own appointments) |
| See a tip on an appointment | Whoever can open the appointment |
| Tips in reports, exports and print | Financial permissions (owner; site manager if enabled) — never practitioner or receptionist |
| Recipient columns / tips by practitioner | Roles that may compare staff |
| Export | Owner, site manager |

## 9. Assumptions and financial treatment (to confirm)

1. **No tax, VAT, payroll or accounting treatment is applied or implied.** Tips are neither part of the VAT base nor of "service
   revenue"; they are stored exactly as entered. Whether tips are subject to payroll, social contributions or tax distribution is a
   business/legal decision that the platform does not make; the existing financial model records no such treatment, so nothing is
   configurable yet. A setting can be added once the rule is decided.
2. A tip implies money was received: statuses *paid on site*, *partially paid*, *waived*. *Unpaid* / *written off* refuse a tip.
3. One tip per appointment (the payment itself is unique); several tip *transactions* would require a payment-transaction model first.
4. "Several practitioners" = a split of the tip between practitioners (shares visible, summed, audited), because appointments have a single practitioner.
5. Tips are attributed to the **appointment day** in reports, consistent with collected amounts, because the payment date is not stored.
6. A tip stays on an appointment cancelled afterwards, like a collected amount (refunds are not recorded).
7. Currency: one currency per appointment; the Financial report totals the period's main currency and says how many appointments it left out.
   The practitioner report's tips column assumes a single currency.
8. WhatsApp staff commands do not record tips (the payment form is the only place payments with tips are entered today).

## 10. Implementation notes

`BookingTips` (rules + audit), `BookingTip` / `BookingTipAllocation`, `PlanningController::payment` (one transaction with the payment),
`FinancialReport`, `PractitionersReport`, `DetailRows`, `ReportTables`, `ReportExporter`; front end `TipFields`, `BookingDetails`,
`FinancialView`, `PractitionersView`, `OverviewView`, `ReportToolbar`, `composables/tips.ts`.
Tests: `tests/Feature/Booking/TipsTest.php` (zero/non-zero, attribution, splits, validation, duplicates, database guarantees, audit,
permissions), `tests/Feature/Reports/TipReportsTest.php` (totals, unchanged metrics, filters, comparison, exports, permissions, print)
and Vitest suites for the helpers, `TipFields`, the payment form/summary and the report views.
