# Nova — FAQ / Help centre management in the Super Admin (spec)

Status: built. Extends the Help centre of docs/22 (articles, search, feedback — unchanged for clients) with content managed by the team. Same conventions as docs/29 and CLAUDE.md. UI text in fr / nl / en (`lang/*/faq.php`), dates DD/MM/YYYY. No existing Super Admin screen was redesigned: the changes are a new menu group, the `editor` role, and the access trait renamed `SuperAdminOnly` (was `HiddenFromSupportAgents`).

## 1. How the content is organised

Two sources feed the client Help centre, merged by key `category/article` (`HelpCatalog`):

1. the **Markdown files** delivered with the application (`resources/help/{fr,nl,en}/…`), unchanged;
2. the **FAQ articles managed here** (database). As soon as a key exists in the database — published, draft or archived — the file version of that key is ignored: the team stays in control (unpublishing an article really removes it).

`php artisan help:import-files` copies the files into the database (8 categories, 24 articles, 72 translations, related links), published and ready, without overwriting anything (safe to re-run). After the import, the team edits everything from the Super Admin. Before it, the screens work and the Help centre shows the files as before. The command is **not** run automatically: run it once per environment (dev: done).

## 2. Data model and publishing workflow

### 2.1 Tables (global content, no `org_id`)

| Table | Content |
|---|---|
| `faq_categories` | `slug` (the address, immutable), `icon` (rocket, calendar, chat, users, clock, card, shield, wrench), `position`, `is_active`, `names` and `descriptions` per language (jsonb) |
| `faq_articles` | `category_id`, `slug` (unique per category), `status` (draft, published, archived), `publication` (`available` or `all`), `position`, `is_popular`, `tags` (jsonb, up to 12), `author_id`, `last_editor_id`, `published_at`, `archived_at`, created / updated |
| `faq_translations` | one row per language (`fr`, `nl`, `en`): `question` (160), `summary` (300), `body` (Markdown), `is_ready`, `updated_by`. A ready translation must have all three texts (database CHECK) |
| `faq_article_related` | related articles, ordered |
| `faq_translation_versions` | every saved change of a language: question, summary, body, ready flag, editor, date. **Append-only** (trigger), team only |

Answer formatting is **Markdown**, the same pipeline as the files: headings, lists, links, bold / italic, code, quotes, tables; raw HTML is stripped and unsafe links (`javascript:`) refused. Images: typed as `![description](address)` with an https address or an application file (`/help/…`). Uploading images from the editor is **not built** (the Help centre serves its screenshots from `public/help/`).

Views / helpfulness: the client Help centre already stores "Was this article helpful?" (`help_article_feedback`, keyed by `category/slug`); the list shows "N helpful · M not helpful" and sorts by number of answers. **Views are not tracked** by the client Help centre, so "most viewed" is not offered (no invented number).

### 2.2 States

| State | Visible to clients | Notes |
|---|---|---|
| Draft | No | Default of a new or duplicated FAQ. Visible in the Super Admin only |
| Published | Yes, in each language where its translation is ready (rule below) | `published_at` = date of the last publication |
| Archived | No (not in lists, not in search) | Kept for history; can be restored as a draft |

Transitions: create → draft; draft / archived → published (needs the languages below ready); published → draft (**Unpublish**); any → archived; archived → draft (**Restore**); draft or archived → deleted. A **published FAQ cannot be deleted**: unpublish or archive it first.

### 2.3 Publication rule and translation completeness

Each language is **ready** (reviewed, publishable), **draft** (started, not ready) or **missing** (no text). Shown everywhere as icon + word: "French ready · Dutch draft · English missing".

- `publication = available` (**Available languages only**, default): French and English (`config help.required_locales`) must be ready to publish; Dutch is optional and appears for Dutch clients as soon as its translation is ready.
- `publication = all` (**All languages**): French, Dutch and English must all be ready.
- A published FAQ must stay publishable: editing it so that a required language is no longer ready is refused ("unpublish first").
- Languages are never mixed: a client sees an article only in a language whose translation is ready. A client whose language is missing does not see the FAQ at all (no fallback to another language).

The database enforces the client-side rule too: the client role cannot read a translation that is not ready, nor any translation of a draft / archived article, nor — in "all" mode — any translation until three are ready (tested by bypassing the application).

## 3. Navigation and pages

Menu group **Help centre**: **FAQ articles** (badge = number of drafts) and **Categories**.

| Page | URL |
|---|---|
| List | `/admin/faq` |
| New / edit | `/admin/faq/create`, `/admin/faq/{id}/edit` |
| Preview | `/admin/faq/{id}/preview` |
| Categories | `/admin/faq-categories` |

## 4. UI specification

### 4.1 FAQ list

- **Views** (tabs with counter): In progress (draft + published, default), Published, Drafts, Archived, All.
- **Columns**: Question (with `/category/address` underneath), Category, Status, **three language badges** (FR · ready, NL · missing, EN · draft: icon + text + tooltip), Last editor, Created (toggle), Updated, Order, Feedback ("N helpful · M not helpful").
- **Search** (one box): question, summary and text of every language, tags, category name and address.
- **Filters**: status (multi), category, **language** (has a ready translation in…), "some language missing or not ready", author or editor, updated between two dates.
- **Sorting**: Question, Category, Status, Updated (newest first by default), Created, Order, Number of feedback answers.
- **Row actions**: Edit, Preview, Publish / Unpublish (confirmation says in which languages clients will see it, or which languages block it), Duplicate, Archive / Restore, Delete (not shown for published FAQs).
- **Bulk actions**: Publish, Unpublish, Archive, Change category…, Delete (confirmations). Each FAQ goes through the same rules as a single action; those that refuse (incomplete translations, published, …) are skipped and counted ("N FAQs were skipped"); one audit line per operation.
- Empty state: "No FAQ here — create a FAQ, or change the view and filters."

### 4.2 Editor

- **General** card: category, address (empty = built from the first question; unique in the category), display order, publication (available languages / all languages), tags, related articles (search by question), "Show among the popular articles".
- **One tab per language** (French, Dutch, English), each with a badge ready / draft / missing: question, short summary, answer (Markdown editor: bold, italic, link, heading, lists, quote, code, undo / redo), and **"Ready to publish in this language"** (requires question, summary and answer: the form shows the missing fields).
- Subheading: "Draft · French ready · Dutch missing · English ready".
- Header: **Preview**, **Publish** / **Unpublish**, **More** (Version history, Duplicate, Archive / Restore, Delete); form buttons **Save** and **Cancel**. Saving never publishes.
- A refused action (missing language, address taken, …) shows a persistent, plain message and keeps what was typed.
- **Version history**: pick a saved version (date · language · editor · state · question) and restore it; it becomes a new version (history is never rewritten).

### 4.3 Preview and publish flow

The preview shows the article as the client sees it (same Markdown rendering, summary, "Updated on", "In this article" list, related articles, the "Was this article helpful?" line), **one language at a time** (language buttons with their state). A banner states clearly **why clients can or cannot see it** in that language (published and ready / not published / translation missing or not ready / "all languages" not complete). Header: Edit, Publish (confirmation lists the languages that will go live) or Unpublish.

### 4.4 Categories

Table: position, name (French) with address and which languages have a name, icon, number of articles (and published ones), state. Actions: **Edit** (icon, order, active, name and description in fr / nl / en; the French name is required), **Activate / Deactivate** (deactivating a category with published articles asks for confirmation: they disappear for clients), **Delete**:

- an empty category is deleted after confirmation;
- a category that still contains articles asks to **reassign them explicitly** to another category (refused, nothing changed, if an article address already exists there), then it is deleted;
- the 8 categories delivered with the application cannot be deleted (they would come back with their original texts): deactivate them;
- addresses used by other Help pages (`recherche`, `retour`, `avis`, `contact`, `tickets`) are refused.

### 4.5 Accessibility, responsive, states

Statuses and language states always carry an icon **and** a word; language buttons of the preview are a labelled tab list; the preview article has a `lang` attribute; forms use labelled Filament fields with visible focus; tables scroll horizontally on tablet / phone and the editor stacks in one column. Empty state (list), loading indicators (tables, buttons, modals), validation (required fields when "ready", slug format, 160 / 300 character limits, order 0–10 000, 12 tags) and error messages (`faq.errors.*`, persistent, translated) are all covered.

## 5. Client-facing integration rules

- A published FAQ appears **in its category**, in the lists, the "popular" list (when flagged) and the search index of the client's language; the search covers question, summary, **text**, tags and category name.
- Language: the client's interface language decides which translation is read; only ready translations; no mixing.
- Never exposed to clients (guaranteed by the database): drafts, archived articles, unready translations, inactive categories (and their articles), version history, internal data.
- **Related articles**: only articles that are published and available in the client's language are listed.
- **"Was this article helpful?"** works on managed articles (same endpoint; the key is `category/slug`). Changing an article's category or address starts a new feedback key; old answers stay in the history under the old key.
- Category names, descriptions and icons come from the database in the client's language (fallback: the delivered texts). Category addresses are validated by the controller (unknown or inactive → 404).

## 6. Permissions and audit

| Role | FAQ |
|---|---|
| Super Admin | everything |
| **Content editor** (`platform_admins.support_role = 'editor'`; `php artisan nova:create-admin <email> --content-editor`) | FAQ articles and categories only; no tickets, accounts, billing, settings; opens on the FAQ list after login; never notified about tickets and cannot be assigned tickets |
| Support agent | no FAQ access (tickets only, docs/29) |

Every action by a platform account writes one `audit_logs` line (append-only, actor, IP, user agent) with before / after where practical: `faq.created`, `faq.updated` (field → [before, after]: category, address, order, popular, publication, tags, related), `faq.published`, `faq.unpublished`, `faq.archived`, `faq.restored`, `faq.deleted` (slug, category, questions), `faq.duplicated`, `faq.translation_added`, `faq.translation_updated` (language, fields changed), `faq.order_changed`, `faq.bulk` (operation, done, skipped), `faq_category.created` / `.updated` / `.deleted` / `.order_changed` / `.articles_moved`, and the system line `faq.imported`. Text of translations is not copied into the audit log: the **version history** keeps every saved text.

## 7. Empty, loading, validation and error states

| State | Behaviour |
|---|---|
| Empty list | Message and a one-click way to create |
| Loading | Filament indicators; buttons disabled while saving |
| Validation | Fields required only when a language is "ready"; address format; maximum lengths; one error summary per field |
| Refused by a rule | Persistent notification with the reason in the user's language: missing languages, incomplete translation, address taken or invalid, category not empty, delete a published FAQ, built-in category |
| Partial bulk | "N updated" and "N skipped" |
| No permission | 403 (agent on FAQ, editor on tickets or accounts) |

## 8. Acceptance criteria and tests

Automated (PostgreSQL, Pest): `tests/Feature/Help/FaqManagementTest.php` (33) and `tests/Feature/Admin/FaqAdminTest.php` (28); the existing Help tests (19) and front-end tests (498) still pass.

| Area | Criteria (all tested) |
|---|---|
| Create | Draft with address from the first question, tags normalised, versions and audit; empty content, invalid or taken address refused; related never to itself; ready without summary / text refused (service **and** database) |
| Edit | Form loads each language; save updates, audits before / after, adds a version only when text changes; order change audited; a required language cannot be removed under a published FAQ |
| Publish / unpublish | Needs required languages ready (says which are missing); "all" needs three; unpublish, archive, restore; invalid transitions refused; published FAQ cannot be deleted |
| Translate | Add (audit `translation_added`), update (`translation_updated`), version restore as a new version; versions cannot be rewritten |
| Duplicate / move / bulk | Copy is a draft with unready translations and a free address; move refused on address clash; bulk counts done and skipped |
| Delete | Draft or archived only; audit keeps slug, category and questions |
| Categories | Create / edit / activate / deactivate with audit; reserved, invalid, taken addresses and missing names refused; delete refused with articles, explicit reassignment works and refuses on clashes; built-in cannot be deleted |
| Client | Published FAQ in its category, popular list and search index with text and tags; related only if published and translated; language respected; drafts, archives, unready or missing languages, inactive categories → 404; database refuses to show hidden content even when the application is bypassed; feedback works |
| Import | 8 / 24 imported, idempotent, client sees the same articles; unpublishing an imported article really hides it; an edited text replaces the file |
| Search / filters | Question, text, summary, tags, category, address; status, category, language, incomplete, author, dates; sorting by order, update, feedback |
| Permissions | Super Admin / editor / agent matrix (menus, URLs, redirects); `--content-editor` creates the role; editors never notified of tickets |

Manual check before release: run `php artisan help:import-files`, open the Help centre as a client in fr / nl / en, edit one imported article, preview it, unpublish it and confirm it disappears, publish it again, create a new category and a new FAQ, and confirm the audit log lines.

## 9. Operations and limits

- One-time import per environment: `php artisan help:import-files`.
- Required languages: `config/help.php` → `required_locales` (fr, en).
- Not built: image upload from the editor, view counters ("most viewed"), scheduled publication, review / approval by a second person, translation by machine. Each would be an addition to this model (no rework needed).
