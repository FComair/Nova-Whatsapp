# 23 — Reports & analytics

Scope: the **Reports** area of the business dashboard (`/rapports`). It gives owners, site managers and practitioners a clear view of
appointments, customers, finances and team workload, with comparisons, export and print. Nothing here redesigns another area, and
nothing is estimated: a figure the platform does not record is omitted or labelled unavailable.

Roles and visibility follow `docs/02`. Everything is computed on the server (`app/Modules/Reports`), always inside the tenant context
(PostgreSQL RLS), and always from what the signed-in role may see.

---

## 1. Information architecture and navigation

**Entry point:** a **Reports** link in the top navigation, after *Customers*. Shown to owner, site manager and practitioner; hidden for
receptionist (who gets a 403 if they type the address).

**Page: "Reports & analytics"** (`GET /rapports`)

```
Reports & analytics
[ Overview | Appointments | Customers | Financial* | Practitioners ]      * only if the role may see amounts
[ Today | This week | This month | Last month | This quarter | Custom range ]        [ Export ] [ Print report ]
Filters: Location · Practitioner · Service · Booking source · Appointment status
Summary: "01/10/2026 to 31/10/2026 (31 days) · Site time: Europe/Brussels — compared with 01/09/2026 to 30/09/2026 — No filters"
-----------------------------------------------------------------------------------------------------------------
(category content)
```

- **Switching categories keeps the date range and every applicable filter** (they live in the URL: `?category=…&preset=…&location=…`).
  The URL can be bookmarked, shared with a colleague who has the same rights, or printed.
- **Overview** (default): the headline figures of each category the role may see (4 each), an "Worth noting" box and the appointments
  chart. Each block links to its full category.
- A **Print** page (`/rapports/imprimer`) and an **Export** (`/rapports/export`) reuse the same period, filters and rights.
- Owner only: a small setting at the bottom of the page — *Allow site managers to see financial reports* (off by default).

**Date range.** Presets are evaluated in the **site's timezone**, never the browser's. "This week" is Monday to Sunday; "This quarter"
is the calendar quarter; "Custom range" takes two dates (swapped if reversed, capped at 366 days). Each appointment belongs to the
local day of its site, so a 01:30 appointment is counted on the right day even when it is still the previous day in UTC.

**Comparison period** (always shown in words and in each figure's tooltip/footnote):

| Selected | Compared with |
|---|---|
| This week | the previous week |
| This month | the previous calendar month |
| Last month | the month before it |
| This quarter | the previous quarter |
| Today / Custom | the period of the same length immediately before |

When the selected period is not over, the page says so: comparing a partial period with a full one only gives a partial change.

**Filters** (all optional, combinable, kept when switching category)

| Filter | Appointments | Customers | Financial | Practitioners |
|---|---|---|---|---|
| Location (hidden if a single location) | ✔ | ✔ | ✔ | ✔ |
| Practitioner (hidden for the practitioner role) | ✔ | ✔ | ✔ | ✔ |
| Service | ✔ | ✔ | ✔ | ✔ |
| Booking source | ✔ | ✔ | ✔ | ✔ |
| Appointment status (confirmed, completed, no-show, cancelled) | ✔ | — (explained on screen) | ✔ | ✔ |

Useful combinations: *one practitioner × one service* (is this offer popular?), *location × source* (where do WhatsApp bookings land?),
*status = no-show × last month* (when do no-shows happen?), *service × completed* (what really earns).

---

## 2. Screen specifications

All blocks use the existing design system: cards (`rounded-card border bg-surface shadow-xs`), Bricolage/Instrument fonts, the teal
palette, `BaseButton`, `SelectField`, `CheckboxField`, `ModalDialog`, `AlertBox`.

### 2.1 Common building blocks

- **KPI card:** label, large value in its unit, an `i` button with the definition (hover, focus, tap; Esc closes), and a
  comparison line: arrow + absolute change + percentage, then "Previous period: value".
  Comparisons are **neutral**: arrow/sign/words only — never green or red. A rise is not "good" (more cancellations) and a fall is not
  "bad" (fewer no-shows).
  - baseline = 0 → absolute change only + "Nothing in the previous period: no percentage";
  - baseline too small (< 5 appointments, < 50 in money, < 2 h) → absolute change only + "Baseline too small for a reliable percentage";
  - rates are compared in **percentage points** (`+3 pt`), and only when both periods have at least 10 cases;
  - forward-looking figures (upcoming appointments, customers with an upcoming appointment) are not compared.
- **Chart card:** title, **Chart / Table** switch (the table holds the same numbers), screen-reader description, empty state.
  Charts are SVG: every bar group is focusable (Tab) with a text label, tooltips appear on hover and on keyboard focus, series differ
  by **colour and pattern** (solid, stripes, dots, cross-hatch) so they stay readable in grayscale and for colour-blind users.
- **Insights ("Worth noting"):** one-sentence statements produced by the server only when the data supports them (minimum sample sizes).
  Examples: busiest weekday/hour, a service above 30 % of appointments, no-show rate moved by ≥ 3 points, volume moved by ≥ 10 %.
- **Heatmap:** a real table (weekday × hour), darker = more appointments, the number written in each cell.
- **Ranked bars:** label and value always written out; the bar is only a visual aid.

### 2.2 Overview
Four KPI rows (appointments, customers, financial*, practitioners*), insights, stacked-by-status appointments chart (+ table).

### 2.3 Appointments
KPIs: booked, completed, upcoming, awaiting outcome, cancelled, no-shows, rescheduled, cancellation rate, no-show rate, completion
rate, average per day. Charts: appointments over time (stacked by status, day/week/month buckets by period length), outcomes (one
stacked bar with counts and %), booking source, weekday × hour heatmap, most requested services (with appointment value when allowed),
services × source (stacked).
Table columns: *Period, Completed, Upcoming, Awaiting outcome, No-show, Cancelled, Total* · *Status, Appointments, Share* · *Source,
Appointments, Share* · *Service, Appointments, Cancelled[, Value]*.

### 2.4 Customers
Aggregates only. KPIs: customers, new, active, returning, with an upcoming appointment, with no recent appointment (90 days),
appointments per active customer, returning rate, retention. Charts: new vs active over time, active customers by preferred service,
by source of their first appointment, by main location (if several). A link opens the Customers list (with its own permissions);
no name, phone number or address appears in any report, export or print.

### 2.5 Financial *(owner; site manager if enabled; never practitioner/receptionist)*
Notes first: currency, "collected amounts are attributed to the appointment day", and what is **unavailable**.
KPIs: appointment value, collected, outstanding, average value, value of completed, share collected on completed, waived/written off,
value of no-shows, value of cancellations, plus **Refunds** and **Discounts** shown as "—, not recorded".
Charts: value vs collected over time, by service, by practitioner (not for a role that may not compare staff), by location (if
several), collected by payment method, payment status of completed appointments.
Table columns: *Period, Value (EUR), Collected (EUR)* · *Name, Appointments, Value (EUR), Collected (EUR)* · *Method, Collected (EUR)* ·
*Payment, Appointments, Price (EUR), Collected (EUR)*.

### 2.6 Practitioners
Banner: *actual hours worked are not recorded; the figures compare scheduled time with time booked by appointments.*
KPIs: assigned, completed, scheduled working time, booked time, unbooked time, utilization, cancellation rate, no-show rate.
Sortable **comparison table** (practitioner, assigned, completed, cancelled, no-show, scheduled, booked, unbooked, utilization,
days off[, value, collected]), booked vs unbooked hours chart, completed by practitioner, service mix per practitioner, workload
heatmap, and a time-off summary (dates and number of days — the reason is never shown).
A practitioner sees only their own row, without peer comparisons or amounts.

### 2.7 Export configuration (modal)
1. **Format:** Excel (.xlsx, one sheet per table) · CSV (sections separated by blank lines; a single table is a plain CSV) · PDF
   (opens the print layout; choose "Save as PDF").
2. **Reports to include:** the current report (pre-ticked) or any combination of permitted categories.
3. **Content:** key figures with comparison · tables behind the charts · detailed rows (one appointment per row).
4. **Practitioner-level detail** (checkbox, only for roles that may compare staff).
5. **"What will be exported" summary**, computed by the server before anything is produced: file name, each table and its row count.
6. States: preparing, failed (clear message, nothing is downloaded), preview unavailable.

File names: `Appointments_Report_2026-10-01_to_2026-10-31.xlsx`, `Reports_2026-10-01_to_2026-10-31.csv` (several reports),
`Financial_Report_…`. The first sheet/section, "Report information", records business, reports, period, comparison period, filters,
content, time zone, generation date and the data notes. Exported text cells starting with `=`, `+`, `-`, `@` are neutralised in CSV
(spreadsheet formula injection). CSV is UTF-8 with a BOM and `;` separators so Excel opens it with accents intact.
Exporting is audited (`reports.exported`).

### 2.8 Print layout (`/rapports/imprimer`)
No navigation, buttons or controls. Header with the business logo and name, report title, categories, date range, comparison period,
filters and time zone; key figures; each chart **followed by its table**; tables repeat their header row on every page and avoid
splitting rows; running footer with business name, generation date and "confidential"; page numbers (`1 / 5`) through the `@page`
margin box (supported by current Chromium browsers; others still print the footer). A4, 13–16 mm margins. The document title is the
suggested file name when saving as PDF. Series patterns keep charts legible in grayscale.

---

## 3. Metric definitions

| Metric | Definition |
|---|---|
| Appointments booked | Real appointments whose start falls in the period (local day of the site), **cancelled ones included**. Pending or expired holds are not appointments and are never counted. |
| Completed / No-show / Cancelled | Status `completed` / `no_show` / cancelled by the customer or by the business. |
| Upcoming | Confirmed appointments whose time has not passed. |
| Awaiting outcome | Confirmed appointments whose time has passed but that nobody marked completed or no-show. They are **neither attended nor no-show**: the platform does not guess. |
| Due appointments | Completed + no-show + awaiting outcome (they took place, or should have). |
| **Cancellation rate** | Cancelled ÷ appointments booked. |
| **No-show rate** | No-shows ÷ due appointments. |
| Completion rate | Completed ÷ due appointments. |
| Average per day | Non-cancelled appointments ÷ number of days in the period. |
| Rescheduled | Appointments whose date or time changed at least once (a practitioner-only change does not count). |
| Customers | Customers of the business, excluding archived and erased ones (limited to the sites/practitioners the role sees). |
| New customers | Customers created during the period. |
| Active customers | At least one non-cancelled appointment in the period. |
| Returning customers | Active customers who already had a non-cancelled appointment before the period. Returning rate = returning ÷ active. |
| Retention | Share of customers active in the previous period who are also active in this one. |
| No recent appointment | Had an appointment before, none in the last 90 days, none upcoming. |
| **Appointment value** | Price (as recorded at booking) of non-cancelled appointments — upcoming, completed and no-shows. **Not money received.** |
| Completed value | Price of completed appointments. |
| **Collected (revenue)** | Amounts recorded as collected, attributed to the **appointment day** (the payment date is not stored). Refunds are not recorded. |
| Outstanding | Price − collected, on completed appointments whose payment is *unpaid* or *partially paid*. Upcoming appointments are never "outstanding". |
| Waived or written off | Price − collected, on completed appointments marked *waived* or *written off*. |
| Average value | Appointment value ÷ non-cancelled appointments. |
| Share collected on completed | Collected on completed appointments ÷ their price. |
| Scheduled working time | Practitioner schedule slots within the site's opening hours, with time off and exceptional closures removed and exceptional availability added. **Not hours worked.** |
| Booked time | Duration of non-cancelled appointments (upcoming, completed, no-show). **Not hours worked.** |
| Unbooked time | Scheduled − booked, never negative. |
| **Utilization rate** | Booked time ÷ scheduled working time. Can exceed 100 % if appointments were placed outside the schedule (a note says so). Empty when nothing is scheduled. |
| Days off | Days where the schedule plans work but no slot remains (time off or closed site). |
| Non-working days | Days the usual schedule does not include. |

---

## 4. What the platform does and does not collect

| Needed for | Collected? | Treatment |
|---|---|---|
| Status, source, price, duration, practitioner, service, site | Yes (bookings) | Used |
| Reschedules | Yes (`booking_events` "moved") | Used |
| Amount collected, payment status/method | Yes, on the appointment | Used; attributed to the appointment day |
| Payment date | No | Stated; no cash-flow-by-payment-date |
| Refunds, discounts, VAT breakdown | No (only waived/written-off status) | Shown as unavailable, never estimated |
| Several currencies | One per appointment | Only the main currency of the period is totalled; a notice counts the rest |
| Actual hours worked / attendance | No (only schedule and bookings) | Never shown; scheduled ≠ booked ≠ worked is spelled out |
| Customer source ("how did they find us") | Only the channel of their first appointment | Used under that name |
| Cost, margin, commissions | No | Not offered |
| Website bookings | Only as `api` | Labelled "Website / API" |

---

## 5. Permissions and privacy

| | Owner | Site manager | Practitioner | Receptionist |
|---|---|---|---|---|
| Appointments, customers | all sites | **their sites** | **own appointments only** | — |
| Financial | ✔ | only if the owner enabled it | — | — |
| Practitioner comparison, per-practitioner amounts | ✔ | ✔ (their sites) | own row only | — |
| Export (xlsx/csv) | ✔ | ✔ | — | — |
| Print | ✔ | ✔ | ✔ (what they see) | — |
| Reports setting | ✔ | — | — | — |

- One implementation (`ReportAccess` + `ReportQuery`) feeds the screen, the print view and exports: a refused figure is **never
  computed**, so it cannot leak through a chart, a table, a download or the print route. Asking for a refused category redirects to
  the overview.
- Business isolation is enforced by PostgreSQL RLS, independently of the report code.
- **No personal customer data** (name, phone, e-mail, address, notes, reasons for time off) in any report, export or print. Detailed
  export rows contain no customer column. The customer list remains the only place to open a person, under its own rights.
- Limits: exports are throttled (20/min), detail rows are capped at 50 000, periods at 366 days.

---

## 6. Responsive behaviour, states, accessibility

- **Small screens:** KPI grid 2 → 3 → 4 columns; charts stack (2 columns only ≥ lg); filters collapse behind a "Filters (n)" button;
  tables scroll horizontally in a focusable region; tabs scroll horizontally; the export dialog is a full-width modal.
- **Loading:** an overlay "Generating the report…" over the previous result while a new period/filter is computed (`aria-busy`).
- **Empty:** *No data for this period* (suggests another period); *No results with these filters* (with a **Reset filters** button);
  an empty chart shows a short message instead of a blank axis.
- **Error:** a report that fails to compute shows "This report could not be displayed" with **Try again**; a failed export shows an
  error and downloads nothing; a blocked print window shows how to allow pop-ups.
- **Accessibility:** one `<h1>`, tabs as navigation links with `aria-current`, definitions reachable by keyboard, every chart has a
  text description and an equivalent table, focus rings everywhere, no information carried by colour alone, contrast from the existing
  tokens, numbers/dates/currency formatted with the business language (`DD/MM/YYYY` dates).

---

## 7. Implementation notes

- Backend: `app/Modules/Reports` — `Support/{ReportFilters, ReportAccess, Comparison, Buckets, Xlsx}`,
  `Services/{ReportQuery, AppointmentsReport, CustomersReport, FinancialReport, PractitionersReport, ReportInsights, ReportService,
  ReportTables, DetailRows, ReportExporter}`, `Http/Controllers/ReportController`.
  `AvailabilityService::workingDays` exposes the real scheduling rules for scheduled time.
- Front end: `Pages/Reports/{Index,Print}.vue`, `Components/Reports/*`, `composables/reports.ts`, `types/reports.ts`.
- Migration `2026_11_05_000001`: `organizations.reports_financial_managers` (default false).
- No new dependency: charts are SVG, `.xlsx` is written directly, "PDF" uses the browser's print-to-PDF on the print layout (a
  server-side PDF renderer would add a dependency and was deliberately not introduced).
- Tests: `tests/Feature/Reports/ReportsTest.php` (metrics, rates, comparisons, timezone day boundaries, filters, financial
  definitions, customers aggregates without PII, scheduled vs booked time, permissions per role, isolation, export contents,
  formula neutralisation, print rights, presets) and Vitest suites for the composables, widgets, toolbar, export dialog and page states.
