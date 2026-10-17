# Rendivoo — Support tickets in the Super Admin (spec, phase 3 of docs/22)

Status: built. Same conventions as docs/22 (client side of the same tickets), docs/02 (Super Admin: separate guard, 2FA, no impersonation) and CLAUDE.md. Dates DD/MM/YYYY. UI text in fr / nl / en (`lang/*/tickets.php`). No existing Super Admin screen was redesigned: the only changes to them are a menu group, one extra block of global settings and the hiding of screens from support agents (§5).

## 1. What already existed, and what this adds

Already built in phase 2 (docs/22): the client contact form, the tables `support_tickets`, `support_messages`, `support_attachments`, `support_ticket_events` (RLS, append-only, internal notes invisible to the client **in the database**), the client pages (`/aide/tickets`) and the e-mails created / reply / resolved.

Added here:

| Area | What |
|---|---|
| Data | 3 more statuses, `urgent` priority, source, first-reply deadline (SLA), version for edit conflicts, internal-only events, tags, links, saved views, agent notifications, support role |
| Console | Inbox (`/admin/support-tickets`), ticket page, reports (`/admin/support-reports`), notification preferences |
| Rules | `SupportConsole` (single entry point: rights, audit, conflict check), `TicketDesk` (domain), `SupportNotifier`, `SupportSla`, `TicketMetrics` |
| Client side | Two new e-mails (status change, closure); the client keeps seeing the 5 statuses of docs/22 |

## 2. Data model and status workflow

### 2.1 Ticket (`support_tickets`)

| Field | Meaning |
|---|---|
| `reference_number` → `NWS-1042` | Global sequence, shown to client and staff |
| `subject`, first message (`support_messages`) | Subject and description |
| `status` | See 2.2 |
| `urgency` / `staff_priority` | Priority chosen by the client (low, medium, high) / by the team (low, medium, high, **urgent**). **Effective priority** = team priority, otherwise client urgency |
| `category` | technical, feature, billing, access, other |
| `org_id`, `user_id`, `reply_email`, `locale` | Client account, requesting user, contact e-mail, language of the e-mails |
| `assigned_admin_id` | Agent (no foreign key: platform accounts live in another role) |
| `source` | `contact_form` (default), `email`, `in_app`, `manual` (created by the team). Only `contact_form` and `manual` are produced today |
| `created_at`, `updated_at`, `last_message_at` | Dates; `last_message_at` = last activity |
| `last_client_message_at`, `last_staff_message_at` | Drive "needs a reply" and waiting time |
| `first_response_at`, `first_response_due_at`, `sla_alert` | First public reply, its deadline, last warning sent (`at_risk` / `overdue`) |
| `resolved_at`, `closed_at`, `closed_reason` | Resolution and closure (client, auto_resolved, auto_closed, staff) |
| `related_to` | Area chosen by the client (appointments, WhatsApp…) |
| `version` | Incremented by every change (edit-conflict detection, §6) |

Team-only tables (no right at all for the client role, enforced by PostgreSQL, tested): `support_ticket_tags` (internal tags), `support_ticket_links` (links to another ticket or to a record: appointment, customer, service, WhatsApp conversation, other), `support_saved_views`, `platform_notifications`. Internal events carry `visibility = internal` and are invisible to the client role like internal notes. Attachments follow the visibility of their message.

Related appointment / customer / service / conversation: stored as **links** (kind + label, with the id when it is a ticket). They are references typed by the agent, not foreign keys: the Super Admin role cannot read customers or bookings (RLS), and must not.

### 2.2 Statuses

| Status | Label (team) | Meaning | Client sees |
|---|---|---|---|
| `new` | New | Created, nobody has touched it | Open |
| `open` | Open | Triaged or assigned, not yet being worked | Open |
| `in_progress` | In progress | The team is working on it | In progress |
| `waiting_on_you` | **Waiting on client** | Rendivoo needs information or an action **from the client** | Waiting on you |
| `waiting_internal` | **Waiting on internal team** | Rendivoo waits for another team (billing, engineering…). Nothing to do for the client | In progress |
| `resolved` | Resolved | The team considers the issue solved; the ticket is not closed yet and can be reopened (client within 14 days, team any time) | Resolved |
| `closed` | Closed | Finished. The client can no longer reply (new request needed); the team can reopen | Closed |
| `reopened` | Reopened | Reopened after resolution or closure | Open |

Transitions:

- Created by the client → `new`. Created by the team → `open`.
- Assigning a `new` ticket → `open` (triage done).
- First public team reply: `new` / `open` / `reopened` → `in_progress` (unless the agent picks another status in the reply box); sets `first_response_at`.
- Client reply: `waiting_on_you` → `in_progress`; `resolved` → `reopened`; others unchanged. Not allowed on `closed`.
- Team: any status among open, in progress, waiting on client, waiting on internal team, resolved can be set directly; **Close** and **Reopen** are separate actions. "Request help" sets `waiting_internal`.
- `resolved`/`closed` → `reopened`: by the team (action **Reopen**), by the client on a resolved ticket (button or reply).

A status change is a **client-visible** event (and a client e-mail) only when the status the client sees changes ("new" → "open" or "in progress" → "waiting on internal team" are internal).

### 2.3 Priorities and response targets

Priorities: Low, Medium, High, Urgent. The first-reply target depends on the effective priority and comes from **Global settings** (`platform_settings`, never in code): `support_sla_urgent_hours` (2), `support_sla_high_hours` (8), `support_sla_medium_hours` (24), `support_sla_low_hours` (48) and `support_at_risk_percent` (75). The clock is **elapsed hours (24/7)**: business-hours calendars are not modelled (decision to confirm, docs/22 §12.2). A priority change before the first reply recalculates the deadline from the creation date. A ticket is *at risk* once the "at risk" share of its target has elapsed, *overdue* after the deadline; the hourly job `support:watch-sla` warns the agents once per state.

## 3. Navigation and pages

Menu group **Support** (first group of the menu): **Tickets** (badge = tickets waiting for a team reply, in the agent's scope) and **Support reports**. The five global settings appear in the existing "Global settings" screen.

## 4. UI specification

### 4.1 Inbox (`/admin/support-tickets`)

- **Views** (tabs with a counter): Open (default), My tickets, Unassigned, Urgent, Waiting on client, Due for first response (overdue or at risk), Resolved today, All tickets. Counters count within the agent's scope. The list refreshes itself every 60 s.
- **Columns**: ID (`NWS-n`), Attention, Subject, Client account, Requester (toggle), Status, Priority, Category (toggle), Assigned to, Created (toggle), Last update (+ "Waiting for the team" / "Team replied"), Last client message (toggle, hidden), Source (toggle, hidden), Tags (toggle).
- **Attention badge** (icon + text): *First reply overdue* (red), *First reply at risk* (amber), *Needs a reply* (blue, the client spoke last), none otherwise. Status and priority badges always show an icon **and** a word.
- **Search** (one box): ticket number (`NWS-1042` or `1042`), subject, requester e-mail, client account, tags, **text of any message including internal notes**.
- **Filters** (collapsible panel): status (multi), priority (multi), category (multi), assigned agent (or unassigned), client account (search), source (multi), creation date range, "needs a reply from the team", first-reply deadline (overdue / at risk).
- **Sorting**: click a column heading: ID, Status, Priority (urgent first), Created (newest / oldest), Last update, Last client message (waiting time).
- **Saved views**: menu *Saved views*: apply a view, *Save the current filters as a view*, *Delete a saved view*. Personal to the agent.
- **Bulk actions** (select rows): Assign…, Change status…, Change priority…, Add a tag…, Close tickets (confirmation). Every ticket goes through the same rules as a single action; tickets that are closed, out of scope or not allowed in their state are skipped and counted ("N tickets were skipped"); one audit line per operation.
- **Header actions**: *New ticket* (create on behalf of a client), *Saved views*, *Export CSV* (Super Admin only), *My notifications*.
- Empty state: "No ticket here — try another view or clear the filters."

### 4.2 Ticket page (`/admin/support-tickets/{id}`)

1. **Summary card**: status, priority, SLA and "needs a reply" badges, tags (removable ×), client account (link to the account for Super Admins), requester and language, assigned agent, category and area, source, created / last update / first reply (or deadline) / resolved / closed, related items (removable ×).
2. **Conversation** (chronological): client messages (grey, left), **replies sent to the client** (green, right, label "Reply sent to the client"), **internal notes** (amber, dashed border, lock icon, label "Internal note: not visible to the client"), system lines (centered: status change, assignment, priority, tag, link, reopening, closure; internal ones marked "internal"). Attachments listed under each message, downloaded through a checked route.
3. **Reply box**: two-position switch **Reply to client** / **Internal note**. The mode changes the box's colour and border, its heading and a banner: *PUBLIC: this reply is emailed to … and shown in their ticket* (green) vs *PRIVATE: only Rendivoo staff can see this note* (amber), and the submit button (**Send reply** / **Save internal note**). Fields: text (required), status after sending (reply only; default automatic), attachments (3 files, 5 MB, PNG / JPG / PDF). Plain text, like the rest of the platform's messages. On a closed ticket the box is replaced by "This ticket is closed — reopen it to continue".
4. **Header actions**: Assign, Change status, Priority, Mark as resolved, Reopen (resolved / closed only), and a **More** menu: Add a tag, Link a ticket or record, Request help from another team, Close ticket. Resolve, reopen and close ask for confirmation and say who will be emailed.
5. **Internal help request**: choose a team (Billing, Engineering, Product, Management, Other) and describe the need. The ticket moves to *Waiting on internal team*, an internal note records the request, nothing reaches the client.

### 4.3 Reports (`/admin/support-reports`)

Filters: period, agent, category, priority, client account. Cards and tables, each with its definition printed on the page:

| Figure | Scope | Definition |
|---|---|---|
| Open tickets | now | Statuses new, open, in progress, waiting on client, waiting on internal team, reopened |
| Unassigned | now | Open tickets without agent |
| Waiting on client | now | Status *waiting on client* |
| Overdue / at-risk first reply | now | Open tickets without public reply whose deadline passed / whose "at risk" share elapsed |
| Resolved today / week / month | now | Tickets whose current resolution date falls in the day / week (from Monday) / month. A ticket reopened after resolution is no longer counted |
| Tickets created | period | Created in the period |
| Average first response time | period | Creation → first public reply, tickets created in the period with a reply. Internal notes do not count |
| First replies on time | period | Share of those replies given before the deadline that applied |
| Average resolution time | period | Creation → resolution (or closure when never marked resolved) |
| Reopened tickets | period | Reopenings (client or team) that happened in the period |
| By status / priority / category / agent | period | Tickets created in the period (priority = effective priority) |

The period filters apply to "period" figures; the other filters apply to everything.

### 4.4 Accessibility and responsive

Every status and priority has an icon and a word (no colour-only meaning). The reply switch, banners and buttons have text labels and visible focus (Filament components); the thread is an ordered list with `<time>` elements; each report block is a labelled `section` with real tables (`th scope`); attachment links name the file and size. Layouts reflow to one column on tablet and phone (the inbox table scrolls horizontally with its toggled columns; the ticket page stacks the summary, thread and composer).

### 4.5 Empty, loading, validation and error states

| State | Behaviour |
|---|---|
| Empty list | Message above; filters can be cleared in one click |
| Loading | Filament loading indicators on the table, the buttons (disabled while sending) and the modals |
| Validation | Reply and note: text required (10 000 characters), attachments limited to 3 × 5 MB, PNG / JPG / PDF checked from the file content; tag 30 characters; link label 120; manual ticket: client account, requester (member of the account), subject, category, priority, message required |
| Action refused | "This action is not possible in the ticket's current state" (closed ticket, unknown status…) |
| Changed elsewhere | Banner "This ticket was updated by someone else" + **Refresh**; actions are refused until refreshed; the draft is kept |
| No permission | 403 page: an agent opening a colleague's ticket, attachment or any other area |

## 5. Permission model

| Who | Rights |
|---|---|
| **Super Admin** (every account without support role; separate guard, 2FA) | All tickets of all accounts, all actions, assign to anyone, reports, CSV export, global settings, all other Super Admin screens |
| **Support agent** (`platform_admins.support_role = 'agent'`; created with `php artisan rendivoo:create-admin <email> --support-agent`) | Tickets assigned to them **and unassigned tickets**; can reply, note, set status / priority, tag, link, assign (including to themselves), request help, bulk-act within that scope. Reports limited to that scope. **No** export. **No** access to accounts, invoices, audit log, sectors, legal versions, settings, feedback (menu hidden, URL refused). Opens on the ticket list after login |
| **Client** | Only their own tickets (the owner also sees the business's); the 5 client statuses; never internal notes, events, tags, links, assignee, team priority |

Enforcement (never only in the interface):

- PostgreSQL: the client role has no policy for internal messages / events / attachments and no privilege at all on tags, links, saved views and notifications; it cannot update team fields (`staff_priority`, assignee, SLA) nor delete; messages, events and attachments are append-only.
- `SupportConsole::find()` applies the agent's scope to **every** read and write (ticket page, actions, bulk, attachment download, reports, the list query).
- Filament `canAccess` on all non-support screens for agents.

## 6. Workflow and automation

Lifecycle: (1) client submits → *New* + confirmation e-mail + agents notified; (2) triage: category, priority, assignment → *Open*; (3) reply → *In progress*; (4) needs info → *Waiting on client* (client e-mail); (5) solved → *Resolved* (client e-mail, 14 days to reply); (6) close — by the client (button / "this solved it"), by the team (client e-mail), or by the automatic closure planned in docs/22 phase 4; (7) reopening: client reply or button on a resolved ticket; team *Reopen* on resolved or closed.

Built: automatic ticket creation from the contact form, status change on client reply, first-reply tracking, SLA at-risk / overdue warnings (`support:watch-sla`, hourly), agent notifications.

**Not built** (each needs a decision, kept out to stay reliable): automatic assignment rules (round-robin, by category…); inbound e-mail as a ticket source (the `email` value is reserved); the 3-day reminder and the 14-day auto-resolve / auto-close jobs (docs/22 phase 4; `TicketDesk::closeTicket()` already supports the automatic reason); business-hours SLA; a client reply on a *closed* ticket still opens nothing (docs/22 decision: new request) — the team can reopen.

**Simultaneous edits**: each ticket has a `version` incremented by every change. The page remembers the version it showed; every change action sends it back and is refused (with the banner above) if it moved. The page checks every 30 s and shows the banner at once. Replies and notes are not blocked (they only add lines). This detects conflicts; it does not lock tickets.

## 7. Notifications

### 7.1 Agents (bell in the panel and/or e-mail)

| Event | Who | Default |
|---|---|---|
| New ticket | The assignee if any, otherwise every account that can see it | Bell + e-mail |
| Client reply (also a client reopening) | The assignee, otherwise everyone who can see it | Bell |
| Assigned / reassigned to me | The new assignee (never for self-assignment) | Bell + e-mail |
| Priority change | The assignee | Bell |
| First reply at risk / overdue | The assignee, otherwise everyone who can see it | Bell + e-mail |
| Closed or confirmed solved by the client | The assignee | Bell |

Each agent chooses for each event: bell, e-mail, both or none (**My notifications**, stored in `platform_admins.support_notifications`). Nobody is notified of their own action. Text contains the reference, subject and client name — never the message. Delivery is best effort and never blocks the action (errors are logged without ticket text).

### 7.2 Clients (e-mail, language of the ticket)

| Event | E-mail |
|---|---|
| Ticket created (by the client or by the team) | Confirmation with reference |
| Team reply | Excerpt (300 characters) + link; no attachment |
| Status the client sees changes without a message (waiting on you, in progress, reopened) | "Your request was updated" |
| Resolved | Resolution + 14-day reopening window |
| Closed by the team | Closure + "open a new request" |

No e-mail for internal changes (assignment, priority, tags, links, waiting on internal team, notes). The client has no in-app notification beyond the menu badge of docs/22.

## 8. Audit requirements

Every action by a platform account writes one `audit_logs` line (append-only, with actor, IP, user agent, the client account and the ticket id; no message text): `support.ticket.viewed`, `.replied`, `.note_added`, `.status_changed`, `.priority_changed`, `.assigned`, `.tag_added` / `.tag_removed`, `.link_added` / `.link_removed`, `.help_requested`, `.closed`, `.reopened`, `.created`, `support.attachment.downloaded`, `support.tickets.bulk` (operation, done, skipped), `support.tickets.exported` (rows, filters), `support.notifications.updated`, and system lines `support.sla.at_risk` / `.overdue`. The ticket history (`support_ticket_events`, append-only) records who did what to the ticket itself; the client sees only its public part. Visibility rules apply to messages, attachments, events.

## 9. Integration with the client contact form

```
Client (Help › Contact support)
  → POST /aide/contact (StoreTicketRequest: validation, 5 tickets / hour, form token = no duplicate)
  → TicketDesk::create()  [client database role, RLS]
       ticket(status new, source contact_form, deadline) + first message + event "created"
       → confirmation e-mail to the client
       → SupportNotifier: bell / e-mail to the agents   [platform role, best effort]
Agent (Super Admin)
  → SupportConsole (rights, version check, audit) → TicketDesk (platform role)
       reply / note / status / assignment … → events, e-mails to the client
Client reply → TicketDesk::clientReply() → status rule, last_client_message_at, notify assignee
```

Manual creation (*New ticket*): pick the account, the requester among its **active members** (a narrow database function lists them; the Super Admin role still cannot read users), subject, category, priority, source (manual / e-mail), optional assignee, message. The ticket appears in the requester's list like any other. External channels (e-mail, WhatsApp, API) would call `TicketDesk` through an internal service the same way; nothing writes to the tables from outside.

## 10. Acceptance criteria and tests

Automated (PostgreSQL, Pest): `tests/Feature/Support/SupportConsoleTest.php` (34 tests) and `tests/Feature/Admin/SupportInboxTest.php` (28 tests), plus the updated `SupportTicketsTest` / `FeedbackTest`.

| Area | Criteria (all tested) |
|---|---|
| Creation | Client ticket is *New*, source `contact_form`, deadline = creation + target of its priority, agents notified with their preferences; manual ticket needs a member of the account, is *Open*, visible to the requester, audited |
| Assignment | Assigning a *New* ticket opens it; reassign / unassign; internal event with from / to; assignee notified except on self-assignment; unknown account or closed ticket refused |
| Replies | Public reply emailed with attachment, sets first response once, status *In progress*; note never emailed, never visible to the client (messages, attachments, events), does not change status or first response |
| Statuses | Client e-mail only when the client-visible status changes; resolve → e-mail; client reply on resolved → *Reopened* (event); close → e-mail, client reply refused, team reopen allowed; invalid transitions refused |
| Priority / SLA | Urgent accepted; deadline recalculated before first reply; at risk then overdue; one warning per state; priority change notifies the assignee |
| Tags, links, help | Normalised, deduplicated, removable, audited, invisible to the client role; help request → *Waiting on internal team* without client e-mail |
| Conflicts | Stale version refused (service and page), accepted after refresh; version moves on client and team changes |
| Permissions | Agent sees own + unassigned only (list, page, actions, bulk, reports, attachment, 403); agent blocked from other screens and redirected to tickets; export Super Admin only; clients isolated by account; client role cannot write team fields or tables |
| Audit | One line per view and per action, with account, ticket and actor |
| Inbox | Views and counters, search (number, subject, e-mail, account, tag, message and note text), every filter, sorting, bulk actions with skipped count, saved views, CSV export (formula-safe, audited), notification preferences |
| Reports | Every figure, all filters, agent scope |
| Data | Invalid values refused by the database, events append-only, erasure of tags / links with the account (RGPD) |

Manual check before release: send a test ticket from Help › Contact, open it in the console as an agent and as a Super Admin, reply with and without a note, resolve, reopen as the client, close, and confirm the e-mails in Mailpit and the bell.

## 11. Operations

- Create an agent: `php artisan rendivoo:create-admin agent@example.com --support-agent` (2FA configured at first login).
- Hourly job: `support:watch-sla` (scheduled in `routes/console.php`).
- Tunable without a deployment: Global settings → five support targets.
- Existing tickets from before this version keep no first-reply data (no backfill: row-level security hides them from migrations); new rules apply from their next change.
