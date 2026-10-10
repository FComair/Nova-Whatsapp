# Nova — Help centre, contact form and support tickets (spec)

Status: phases 1 (Help centre), 2 (tickets for clients) and 3 (support console in the Super Admin, see docs/29) built; phases 4 and 5 to come. Statuses are now eight on the team side (new, waiting on internal team and reopened were added; the client still sees five): see docs/29 §2. Same visual language as the app (docs/20: teal tokens, Bricolage Grotesque and Instrument Sans, pill buttons, status badge pairs). No change to existing screens except one new top-level menu entry. UI text in fr / nl / en, dates DD/MM/YYYY (CLAUDE.md).

## 1. What this adds, and what it reuses

- **New top-level menu item "Help"** (question-mark icon, after Settings) with a small badge: the number of tickets that need the client's attention (a support reply not yet read, or status "Waiting on you"). Inside the area, three tabs: *Help centre*, *Contact support*, *Your tickets*.
- **Help centre content lives in the repository**, not in the database: Markdown files in `resources/help/{fr,nl,en}/{category}/{slug}.md` with front matter (`title`, `summary`, `tags`, `related`, `popular`, `updated`). Screenshots are static images under `public/help/`. A test fails if a slug exists in one language and not in the other two, or if a `related` link points nowhere. Rendering uses the Markdown library already shipped with Laravel; no raw HTML.
- **Search** is built like Settings search: a per-language index (title, summary, tags, plain text of the body), accent- and case-insensitive, matched in the browser, with suggestions after 2 characters.
- **Tickets are tenant data**: tables carry `org_id`, go through `Rls::enableTenantTable()` and the GDPR erasure steps (CLAUDE.md rule 1). Nova's support staff work in the **Super Admin console** (Filament, separate guard, 2FA, no impersonation) through read/write policies for the `nova_platform` role limited to these tables, with `Audit::record()` on every staff action.
- **Access**: signed-in users only in version 1 (Help centre included). Only the ticket's author and the business owner can see a ticket; everybody else in the same business cannot.

## 2. Help centre landing (`/aide`)

- **Hero**: title "How can we help?", subtitle one line, and a large search field (placeholder: "Search for help articles, guides, or topics…"). Suggestions drop down under the field after 2 characters: up to 6 matches with title, category chip and a highlighted summary excerpt; arrow keys, Enter and Escape work; "See all results for …" at the bottom opens the results page.
- **Categories grid** (2 columns on phone, 4 on desktop, each card: mint icon tile, name, one-line description, article count): Getting started · Appointments and calendar · WhatsApp connection · Customers · Services, schedules and rules · Billing and subscription · Account and security · Troubleshooting.
- **Popular articles**: 6 articles flagged `popular: true`, as a plain list with category chip.
- **Contact strip** at the bottom: "Can't find what you're looking for?" + primary button "Contact support" + secondary "Your tickets (n open)". Response-time sentence under it (see 7).
- **Search results page** (`/aide/recherche?q=`): same field, results grouped by category, no results state: "No article matches “xyz”. Try fewer words, or contact support." with the contact button.

## 3. Article page (`/aide/{category}/{slug}`)

- **Breadcrumb**: Help › Category › Article (last item not a link, `aria-current="page"`).
- **Layout**: single readable column (max 72 characters per line), article title (Bricolage), summary, "Updated on DD/MM/YYYY", then the body: H2 sections, numbered steps, callouts (tip, warning) with icon and text, screenshots with alt text and a caption. On desktop a sticky "In this article" list (H2 links) sits on the right; on phone it collapses above the body.
- **Feedback widget** at the end: "Was this article helpful?" with *Yes* / *No*. After *No*: optional short field "What was missing?" (500 characters) and Send; after any answer: "Thank you for your feedback." One answer per user and article per day. Feedback is stored without the text being shown to anyone but Nova staff.
- **Related articles** (up to 3, from front matter) and, whatever the answer, a quiet line: "Still stuck? Contact support" with the article title pre-filled as the ticket's subject context.

## 4. Contact support (`/aide/contact`)

One column, same card style as the customer form (docs/21).

| Field | Rules |
|---|---|
| Subject* | 5–120 characters. Placeholder "Briefly describe your issue or question". |
| Category* | Technical issue · Feature request · Billing · Account access · Other. |
| Related to | Optional: Appointments · WhatsApp · Customers · Services · Planning · Other. |
| Description* | 20–5000 characters. Placeholder "Explain your issue in detail, what you expected, and what actually happened." Live counter. |
| Attachments | Optional, up to 3 files, 5 MB each, PNG / JPG / PDF. The limits are written under the field. Files are checked on the server by content, not by name. |
| Email* | Pre-filled with the account email, shown as text with a "Use another address" link that unlocks the field (reply address only; the account email does not change). |
| Urgency | Low (a question or an idea) · Medium (something does not work as expected) · High (my business is blocked: customers cannot book). Short guidance under each choice. It is advisory: staff may re-rank. |

- **Before sending**: when the subject has 3+ words, up to 3 matching articles appear in a "These may answer your question" panel (does not block sending).
- **Page notice** (top, info style): "We aim to respond within 1 business day. Complex issues may take longer. For urgent issues, describe the impact on your business in the description."
- **Validation**: inline, on blur and on submit, messages next to the field; focus goes to the first error. Server enforces everything again. At most 5 new tickets per user per hour (message: "You have sent several requests recently. Please wait a little or reply on an existing ticket.").
- **After sending**: confirmation page "Your request has been sent." with the reference (**#NWS-1248**), a summary card (subject, category, urgency, date), "You'll receive a confirmation email shortly at name@company.be", and two buttons: *View your tickets* and *Back to Help*. Reloading never creates a second ticket.

## 5. Your tickets (`/aide/tickets`)

- **Header**: "Your tickets", subtitle "Track and follow up on your requests to Nova support.", primary button "New ticket", the response-time sentence beneath.
- **Toolbar**: search (reference, subject, words in messages), filters in one row: Status (Open · In progress · Waiting on you · Resolved or closed), Category, Urgency; a "Needs your attention" shortcut chip (replies not read + Waiting on you). Active filters are listed with "Clear filters".
- **Table** (60 px rows, same pattern as Customers): Reference (link, monospace-free, Bricolage numerals) · Subject (bold if unread support reply, with a small dot and sr-only "new reply") · Status badge · Category · Urgency · Created (DD/MM/YYYY) · Last update (DD/MM/YYYY HH:MM). Sortable by Created and Last update (default: Last update, newest first). 25 per page. On phone each row becomes a card: reference + status on top, subject, last update.
- **Empty state**: "You have no support tickets yet." + "Create your first ticket". **No result state** with "Clear filters". **Error state** with "Try again".
- Owner sees a switch "My tickets / All tickets of my business" (other roles see only theirs).

## 6. Ticket detail (`/aide/tickets/{reference}`)

- **Header card**: reference and subject, status badge with an info tooltip (definitions in 7), category, urgency, "Created on …", "Last update …", and for the owner "Opened by Name". Actions: **Reply** (focuses the reply box), **This solved it** (visible when Resolved: closes the ticket), **Reopen** (visible when Resolved and within 14 days), **Close ticket** (visible while Open, In progress or Waiting on you, with a confirmation dialog "Close this ticket? You can still open a new one later."). There is no separate "Mark as resolved" for clients: "Resolved" is set by support; the client closes with *This solved it* or *Close ticket*.
- **Thread** (chronological, oldest first, scrolled to the latest on open): each message is a bubble block with sender ("You" / "Nova support" with the agent's first name when present), time (DD/MM/YYYY HH:MM), body (plain text with line breaks and clickable links, never HTML), and attachments as file chips (name, size, download). Client messages: mint background on the right-aligned side; support messages: white with teal left border. System events ("Status changed to Waiting on you", "Ticket closed by you") are centred small grey lines. **Internal notes written by staff never reach the client** (they are rows the tenant role cannot read; see 9).
- **Reply box** (sticky at the bottom on phone): label "Write your reply…", textarea (up to 5000 characters), attachments (same limits as the form), buttons *Send reply* and *Cancel* (clears after confirming if text was typed). Disabled with a reason on a Closed ticket: "This ticket is closed. Open a new ticket to continue." (link, with the old reference pre-filled in the description).
- **After sending**: the message appears at once, "Last update" changes, a toast "Your reply has been sent." and the status moves according to 8.

## 7. Statuses, expectations, notifications

| Status | Badge | Meaning shown to the client |
|---|---|---|
| Open | info | "We received your request. It has not been picked up yet." |
| In progress | warning-style neutral | "Our team is working on it." |
| Waiting on you | warning | "We need more information or an action from you. Please reply." |
| Resolved | success | "We believe this is fixed. Reply within 14 days if it is not; otherwise it closes by itself." |
| Closed | neutral | "This ticket is finished. Open a new ticket if you need more help." |

- **Response-time copy** (form, tickets page, Help landing): "We aim to respond within 1 business day. Complex issues may take longer." Business days and hours of the team are stated in a "How support works" line (Mon–Fri, 09:00–17:00 Brussels time) — to confirm (decision 2).
- **Emails** (in the user's language, from the platform sender, via the existing mail setup): ticket created (confirmation with reference and summary and a link), new support reply (excerpt of 300 characters and a link; no attachments), status Resolved, and automatic closure notice. The email never contains the full conversation; it links to the signed-in page. No reply-by-email in version 1 (the email says "Reply from your tickets page").
- **In-app**: menu badge (number of tickets needing attention; hidden at 0), bold subject and dot in the list, a one-line banner on the dashboard is **not** added.

## 8. Workflow

1. **Create** → *Open*. Confirmation email. Staff are notified in the Super Admin console (new ticket list, oldest first, High first).
2. **Staff picks it up** (assigns themselves) → *In progress*.
3. **Staff needs details** → public reply + *Waiting on you*. Client gets the email and the badge.
4. **Client replies** (Waiting on you → *In progress*; Open or In progress → unchanged; Resolved → *Open*, counted as a reopening). Reply on a Closed ticket is refused (new ticket).
5. **Staff fixes it** → public reply + *Resolved* (email).
6. **Close**: the client clicks *This solved it* or *Close ticket*, or it closes by itself 14 days after Resolved with no reply. A *Waiting on you* ticket with no reply for 14 days is set to *Resolved* with a system line, then follows the same rule. A reminder email is sent after 3 days of waiting.
7. Every status change and message is appended to the ticket's event log (append-only, like `booking_events`) so the history can be reconstructed.

## 9. Data model, permissions, security

- `support_tickets`: id, org_id, reference_number (global sequence, shown `NWS-1001…`, written by the database), user_id (author), reply_email, subject, category, related_to, urgency (low, medium, high), staff_priority, status, assigned_admin_id (platform admin, no foreign key across roles), last_message_at, last_staff_message_at, client_read_at, resolved_at, closed_at, closed_reason (client, auto_resolved, auto_closed), timestamps.
- `support_messages` (append-only): id, org_id, ticket_id, author_type (client, staff, system), author_id, visibility (public, internal), body, created_at. **The tenant policy only lets the application read `visibility = 'public'`**, so internal notes cannot leak even through a bug in the application. The platform role sees both.
- `support_attachments`: id, org_id, message_id, original_name, mime, size, path on the private disk. Downloaded only through an authenticated route that checks the ticket first; never a public URL. Files are deleted with the ticket or the account.
- `support_ticket_events` (append-only): status changes, assignment, reopen, close, reason, actor.
- `help_article_feedback` (append-only): org_id, user_id, locale, slug, helpful, comment, created_at.
- All five tables are added to `ErasureSteps` and `gdpr_purge_org`, and to the organisation data export. Rule 4 (append-only journals) applies to messages, events and feedback.
- **Who sees what**: author and owner see a ticket; nobody else of the business; Nova staff see all through the console. Practitioners and receptionists can create and follow their own tickets. Sending is rate limited (5 new tickets and 30 replies per user per hour). Attachment names are escaped, content types checked from the file bytes, images re-encoded to drop metadata. No ticket text is written to the application log.

## 10. Visual and responsive details

- Components reused: `Badge`, `BaseButton`, `TextField`, `TextareaField`, `SelectField`, `ConfirmDialog`, the table pattern of the Customers list, toast and alert patterns. New: article renderer styles, file chip, message bubble, search suggestions list.
- Tabs inside Help follow the Settings sub-navigation pattern (keyboard reachable, `aria-current`).
- Mobile: category grid 2 columns; article column full width with the contents list collapsed; ticket list as cards; reply box sticky; every control at least 44 px.
- Accessibility: landmarks per page, one H1, suggestions list as a proper combobox, status always icon + text, focus moved to the first error or to the new message after sending.

## 11. Delivery in phases

1. **Help centre**: menu entry, landing, categories, article rendering, search with suggestions, feedback, the first articles (about 24 at launch, 3 languages), tests (content parity, search, feedback).
2. **Tickets for clients**: tables and policies, contact form, confirmation page, ticket list, detail and thread, replies, attachments, confirmation and reply emails, menu badge, tests (isolation between businesses, internal notes invisible, status transitions, rate limits, attachments).
3. **Support console in Super Admin**: ticket queue, assignment, public reply, internal note, status and priority, canned replies, audit entries, tests.
4. **Automations**: reminder after 3 days waiting, auto-resolve and auto-close jobs, resolved and closure emails.
5. **Polish**: feedback overview for the team, "articles that fail" report, optional public Help centre for visitors.

## 12. Decisions to take

1. **Who answers and where**: staff use the Nova Super Admin console (proposed, keeps data in one place), or tickets are forwarded to an external help desk (Zendesk, Freshdesk…) and this module only mirrors them.
2. **Response promise and hours**: "within 1 business day", Monday to Friday 09:00–17:00 Brussels time (proposed) — confirm what the team can really hold.
3. **Languages of the answers**: the team answers in the client's language, or only in fr and en.
4. **Article authoring**: I draft the ~24 launch articles in fr and en from the real app screens, and nl from fr; the team reviews the wording before release.
5. **Visibility inside a business**: author and owner only (proposed), or owner and site managers.
6. **Public Help centre** for visitors (good for search engines and pre-sales) now or later (proposed: later, phase 5).
7. **Attachment limits**: 3 files, 5 MB, PNG / JPG / PDF (proposed).
8. **Timings**: reminder after 3 days waiting, auto-resolve after 14 days, reopen window 14 days (proposed).
9. **Reference format**: global sequence `NWS-1001…` (reveals the total volume of tickets to clients; use a per-business sequence if you prefer).

## 13. Phase 2 as built

- **Routes** (signed-in, all roles): `/aide/contact`, `/aide/contact/envoye/{NWS-n}`, `/aide/tickets`, `/aide/tickets/{NWS-n}` (reply, close, solved, reopen, attachments). Tabs *Help centre · Contact support · Your tickets* on every Help page, a call-to-action strip on the landing page, "Still stuck?" on articles, and a count badge on the menu entry and the tab.
- **Database** (migration 2026_11_01): `support_tickets`, `support_messages`, `support_attachments`, `support_ticket_events`, all with RLS and in the GDPR erasure steps and the organisation export (internal notes are never exported). The application role cannot read internal notes (policy on `visibility`), cannot write staff messages, cannot change staff priority or assignment, cannot delete tickets; messages, attachments and events are append-only. The reference comes from the sequence `support_ticket_reference_seq` (global, from 1001).
- **Rules as specified**: 5 new tickets per user per hour, 30 replies per hour, one ticket per form token, 3 attachments of 5 MB (PNG, JPG, PDF read from the file content, images re-encoded, files on the private disk and served only through a checked route), reply on a closed ticket refused, reopen within 14 days, "Waiting on you" → "In progress" on a client reply, "Resolved" → "Open". Author and business owner only.
- **Emails** (fr/nl/en, best effort, after the write): confirmation on creation; reply and resolution emails are sent by `TicketDesk` when the team answers (used by the console in phase 3).
- **Team side already in `TicketDesk`** (no screen yet): public reply with optional new status, internal note, status change, automatic closure. The console (phase 3) will call these through the `nova_platform` role.
