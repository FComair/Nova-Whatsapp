# Rendivoo — UI aesthetic upgrade plan ("Calm Premium")

> **Palette, typography and shapes in this document are superseded by `docs/20-alignement-visuel-site-et-application.md`** (the app now follows the website identity). The structure, rollout, motion and dark-mode mechanics below still apply.

Status: steps 0 and 1 of section 6.2 are built (tokens, base components, top bar, card/border/radius codemod); steps 2 to 5 remain. Scope: look and feel only. No change to features, page structure, labels or flows.
Written against the code as it is today (Tailwind v4 tokens in `resources/css/app.css`, Vue components in `resources/js/Components`, pages in `resources/js/Pages`). Every contrast figure below was computed (WCAG 2.x relative luminance), not estimated.

---

## 1. Visual direction

### 1.1 Style: "Calm Premium"

A quiet, confident product for people who open it dozens of times a day (hairdressers, clinics, garages). Premium here means restraint, not decoration:

- **Few colours, used with intent.** One brand blue for actions and selection, navy for structure and headings, everything else neutral. Colour carries meaning (action, status), never decoration.
- **Depth from tone and hairlines, not from heavy shadows.** Surfaces separate by a 1 px border and a barely visible shadow.
- **Generous, regular spacing.** A 4 px grid, bigger gutters, calmer forms.
- **Typographic hierarchy does the work** (weight, size, tabular numerals), instead of boxes inside boxes.
- **Motion is a whisper**: 120–220 ms, ease-out, never blocks, off for people who prefer reduced motion (already honoured in `app.css`).

What stays: the Rendivoo navy and blue, Inter, the teal accent for WhatsApp/bot moments, the three-language labels.

### 1.2 Colour palette (light)

| Token | Hex | Use | Contrast |
|---|---|---|---|
| `canvas` | `#F6F8FB` | Page background | text on it 16.8:1 |
| `surface` | `#FFFFFF` | Cards, tables, inputs, modals | text on it 17.9:1 |
| `surface-2` | `#F1F5F9` | Table headers, hover rows, subtle wells | — |
| `line` | `#E6EAF0` | Card and row hairlines (decorative) | — |
| `line-strong` | `#7F8EA3` | **Input, checkbox, select borders** | 3.3:1 on white, 3.1:1 on the page background (WCAG 1.4.11) |
| `text` | `#0F172A` | Body, values | 17.9:1 |
| `text-muted` | `#566175` | Secondary text, labels, hints | 6.2:1 on surface, 5.9:1 on canvas |
| `navy` | `#14213D` | Headings, top bar, brand | — |
| `primary` | `#2A5BD7` | Primary buttons, links, active nav | white on it 5.9:1 |
| `primary-hover` | `#2249B0` | Hover/pressed, link text | 7.9:1 on white |
| `primary-tint` | `#EAF0FD` | Selected rows, active nav background | `#2249B0` on it 6.9:1 |
| `accent` | `#0B7A6D` | WhatsApp / bot moments only | white on it 5.2:1 |

Usage rules:
- **Primary blue**: the single main action per screen, links, the active navigation item, focus rings. Never for decoration, never for status.
- **Navy**: titles and the logo. Not for buttons.
- **Accent teal**: reserved for WhatsApp-related surfaces (connection state, templates, QR code). If it appears elsewhere it stops meaning anything.
- **Neutrals** carry everything else. If a screen feels dull, add whitespace, not colour.

Status colours (text on tinted background, all AA):

| Status | Text | Background | Icon | Contrast |
|---|---|---|---|---|
| Success | `#166534` | `#E8F7EE` | check-circle | 6.4:1 |
| Warning | `#92400E` | `#FEF3C7` | clock / alert-triangle | 6.4:1 |
| Danger | `#B42318` | `#FEECEA` | x-circle / alert-circle | 5.8:1 |
| Info | `#1D4ED8` | `#E8F0FE` | info | 5.8:1 |
| Neutral | `#475569` | `#F1F5F9` | circle / minus | 6.9:1 |

### 1.3 Typography

Keep **Inter Variable** (already self-hosted, GDPR-safe). Turn on `font-feature-settings: "cv11", "ss03"` for a more open "a" and rounder shapes, and **`font-variant-numeric: tabular-nums`** on every number in tables, KPIs, times and prices so columns align.

| Role | Size / line | Weight | Letter-spacing | Notes |
|---|---|---|---|---|
| Display (KPI value) | 30 / 36 | 650 | −0.02em | tabular numerals |
| H1 (page title) | 26 / 32 | 650 | −0.02em | navy |
| H2 (card/section title) | 18 / 26 | 600 | −0.01em | |
| H3 (sub-section) | 15 / 22 | 600 | 0 | |
| Body | 15 / 24 | 400 | 0 | 16 px in forms on phones (avoids iOS zoom) |
| Small | 13 / 20 | 400–500 | 0 | hints, meta |
| Caption / group label | 12 / 16 | 600 | +0.06em, uppercase | menu group titles, table headers |
| Button | 14.5 / 20 | 600 | 0 | |
| Mono (links, IDs) | 13 / 20 | 500 | 0 | `ui-monospace` |

Before → after: today titles are `text-2xl font-semibold` and body is `16 px`; proposed is a slightly tighter title (negative tracking), 15 px body in dense screens, and tabular numerals everywhere numbers sit side by side.

### 1.4 Iconography

- **Lucide**, outline, **1.75 px stroke**, round caps and joins, 20 px default, 16 px in dense rows and badges, 24 px in empty states.
- Today icons are hand-pasted inline SVGs with stroke 1.8–1.9 and slightly different sizes. Replace with `lucide-vue-next` (tree-shaken, one source of truth) behind a small `<Icon name="calendar" />` wrapper so size and stroke are set once.
- Icons always sit with a text label, except in icon-only buttons, which get an `aria-label` and a tooltip.
- Status icons are filled-circle variants only inside badges (a 14 px glyph), so status reads without colour.

### 1.5 Elevation and surfaces

| Layer | Treatment |
|---|---|
| Page | `canvas` |
| Card / table | `surface`, 1 px `line`, radius 14, shadow `0 1px 2px rgba(16,24,40,.04)` |
| Hover card (interactive) | border `#CBD5E1`, shadow `0 4px 12px -2px rgba(16,24,40,.08)` |
| Dropdown / popover | `surface`, 1 px `line`, radius 12, shadow `0 8px 24px -4px rgba(16,24,40,.14)` |
| Modal / side panel | `surface`, radius 16 (panel: 16 on the free corners), shadow `0 24px 48px -12px rgba(16,24,40,.28)` |
| Overlay | `rgba(15,23,42,.45)`, optional `backdrop-filter: blur(2px)` (off on low-end devices) |

Before → after: today cards are `rounded-card` (8 px) with a border and no shadow; proposed is 14 px radius, a hairline plus a near-invisible shadow, so cards feel lifted without looking boxed.

Radius scale: 6 (badges, small chips) · 10 (buttons, inputs, selects) · 14 (cards, tables) · 16 (modals, panels) · full (pills, avatars).
Spacing: 4 px base. Page gutter 24 (32 on wide screens). Card padding 20 (24 for forms). Gap between sections 24–32. Gap between related fields 16.

---

## 2. Component upgrade

### 2.1 Buttons

Today: `rounded-lg`, `px-4 py-2.5`, 16 px semibold, flat blue, white secondary with a pale grey border, red danger, `transition-colors` only.

Proposed:
- **Shape**: radius 10, min-height 44 (touch) / 40 in dense desktop toolbars, padding 0 16, icon 18 px with 8 px gap, icon placed left of the label (right only for "next"/external).
- **Primary**: `primary` fill, white text, 1 px inner border `rgba(255,255,255,.12)` for a crisp edge, shadow `0 1px 2px rgba(16,24,40,.12)`. Hover: `primary-hover`, shadow-sm. Active: `translateY(0.5px)`, shadow none.
- **Secondary**: `surface`, border `line-strong`, text `text`. Hover: `surface-2`, border `#64748B`.
- **Tertiary (ghost)**: transparent, text `primary-hover`. Hover: `primary-tint`.
- **Danger**: `#B42318` fill, white text (6.8:1). Hover `#912018`. Used only for the confirm step of a destructive action.
- **Focus**: 2 px `primary` ring with 2 px offset (today 3 px solid outline; thinner reads as more refined, still ≥3:1).
- **Disabled**: 45 % opacity, `cursor-not-allowed`, no hover. Loading: spinner replaces the icon, width stays fixed so the layout doesn't jump.
- **Transition**: `background-color, border-color, box-shadow, transform` 150 ms `cubic-bezier(.2,.8,.2,1)`.

### 2.2 Inputs and forms

Today: `rounded-lg border-neutral-300 px-3 py-2.5`, 16 px, border changes colour on focus plus a 3 px outline. **The border (`#CBD5E1`) is 1.5:1 on white, below the 3:1 that WCAG 1.4.11 asks for a control boundary.** This is the one accessibility gap the upgrade fixes outright.

Proposed:
- **Text fields / selects**: height 44, radius 10, border `line-strong` (`#7F8EA3`, 3.3:1), background `surface`, padding 0 14. Hover: border `#64748B`. Focus: border `primary` + 3 px `rgba(42,91,215,.22)` halo (animated 150 ms). Error: border `#B42318` + halo `rgba(180,35,24,.18)` + icon and message below (message in `#B42318`, 13 px).
- **Labels**: above the field, 13.5 px / 600, `text`; required marker is an asterisk in `#B42318` plus the word "required" for screen readers.
- **Helper text**: 13 px `text-muted`, 6 px under the field. Error replaces helper, never both.
- **Checkbox / radio**: 20 px, border `line-strong`, checked = `primary` fill with a white 2 px check that draws in over 120 ms. 12 px between control and label, whole row clickable.
- **Toggle**: 40 × 24, thumb 18, `line-strong` track off / `primary` on, 150 ms slide.
- **Date and time**: date keeps the native picker (reliable on desktop and mobile); time stays the two closed lists already built (24 h and 60 min), styled like selects so they look like one family.
- **Rhythm**: 20 px between fields, 32 px between groups, group title as H3 with a one-line description. Forms are capped at 640 px wide so lines don't stretch.

### 2.3 Tables and lists

Today: `text-sm`, plain borders, little hierarchy (WhatsApp templates table, calendars, history lists).

Proposed:
- **Row height** 52 (comfortable) / 44 (compact toggle), cell padding 0 16.
- **Header**: `surface-2` background, 12 px caption style (uppercase, +0.06em, `text-muted`), sticky with a soft shadow once scrolled.
- **Rows**: hairline dividers only (no zebra); hover `surface-2` (120 ms); selected `primary-tint` with a 2 px `primary` bar on the left edge; focus-visible shows the ring on the row.
- **Primary vs secondary data**: first column in `text` 500 weight (name, time), the rest in `text-muted` 400; numbers right-aligned and tabular; status as a badge, never as coloured text alone.
- **Row actions**: ghost icon buttons, visible on hover and always on touch; destructive actions in an overflow menu.
- **Empty state**: 24 px icon in a `surface-2` circle, one sentence, one primary action (the app already has the copy).
- **Mobile**: tables collapse to stacked rows with the first column as the title.

### 2.4 Cards and panels

- Padding 20 (24 when it holds a form), radius 14, border `line`, shadow-xs.
- Header: H2 left, optional action (ghost button or link) right, 16 px below, no divider unless the card has several sections.
- **KPI card**: label (caption), value (Display, tabular), optional delta chip and a 24 px sparkline in `primary` at 60 % opacity. Whole card is a link when it filters something.
- **Settings group cards**: one card per setting group, group title + description on the left, controls on the right on wide screens (the layout used in the Settings redesign).

### 2.5 Badges and status

Pill, height 24, padding 0 10, 12.5 px / 600, 14 px icon + label (icon first). Variants: **subtle** (tint background, default, in tables) and **solid** (status colour fill, white text, only for the one thing that needs attention on a page).

| Status | Colour | Icon | Label |
|---|---|---|---|
| Confirmed | Success | check-circle | Confirmed |
| Pending | Warning | clock | Pending |
| Completed | Neutral | circle-check | Completed |
| Cancelled | Neutral (strikethrough on the item, not the badge) | x-circle | Cancelled |
| No-show | Danger | user-x | No-show |
| Active / Connected / Approved | Success | dot | label |
| Inactive / Paused | Neutral | pause | label |
| Waiting for Meta | Info | loader (static) | label |
| Rejected / Error | Danger | alert-circle | label |

### 2.6 Modals and side panels

- Overlay `rgba(15,23,42,.45)`; container radius 16, shadow-xl, max-width 520 (forms 640), padding 24.
- Header: title H2 + a ghost close icon (40 × 40 target); footer: right-aligned actions on a `surface-2` strip with a hairline above (secondary left of primary; destructive confirm uses the danger button).
- Side panel (new appointment, details): 440 wide on desktop, full screen on phones, slides from the right, content scrolls and the footer stays pinned.
- Open: 200 ms (scale .98 → 1, fade); close: 140 ms. Focus is trapped and returned (already true with the native `<dialog>`).

### 2.7 Navigation

Today: white top bar, text links with `rounded-lg px-3 py-1.5`, active = pale blue; Settings has a grouped left menu.

Proposed:
- **Top bar**: height 64, `surface`, hairline bottom, no shadow until the page scrolls (then shadow-xs). Logo + shop avatar left, nav centre-left, tools right.
- **Item**: 36 high, radius 10, 14.5 px / 550, icon 18 px + 8 px gap. Idle `text-muted`; hover `surface-2` + `text`; active `primary-tint` background with `primary-hover` text, `aria-current="page"`.
- **Settings menu**: group captions (12 px uppercase), items 42 high, status chips (Connected, 2-step on) right-aligned; search field on top (built).
- **Mobile**: bottom bar with 4–5 icons + label (11.5 px), 56 high, safe-area padding.

---

## 3. Page-level improvements

### Dashboard
- KPI row: today the six numbers are boxed `text-2xl`. Make the first two ("Today", "Remaining") visually larger (Display size, 2 columns wide on desktop) and the other four compact; add a one-line delta ("+2 vs last Monday") in `text-muted`. Tabular numerals, label above the number.
- "Next appointment" becomes a prominent card: time in Display, customer and service on two lines, practitioner avatar, a ghost "Open" action.
- Today's agenda: timeline list (time column, a 3 px bar in the practitioner colour, customer + service, status badge right). Past items at 55 % opacity, the current/next one with a `primary-tint` background.
- Banners (trial, profile incomplete) become slim, dismissible strips at the top with an icon, not full-width boxes.
- Whitespace: 24 px between blocks, content max-width 1120 centred.

### Calendar (planning)
- **Grid**: hour lines `line`, half-hour lines at 40 % of that, no vertical clutter. Hour labels 12 px `text-muted`, right-aligned, tabular.
- **Non-working hours / closed days**: instead of solid grey, a fine diagonal hatch (`repeating-linear-gradient(135deg, transparent 0 6px, rgba(100,116,139,.10) 6px 7px)`) so open time reads as clean white; closed days additionally get a "Closed" caption.
- **Today**: column header in `primary-tint` with a filled day number circle; a 2 px `#DC2626` now-line with a dot (4.8:1 on white).
- **Appointment card**: background = practitioner colour at 12 % (light) / 24 % (dark), 3 px solid left bar in the full colour, text always `text` (never coloured text, so contrast holds whatever colour is chosen), time 12.5 px / 600 tabular, customer 12.5 / 500, service 12 muted. Radius 8, hover: shadow-sm + outline 1 px of the practitioner colour, 120 ms. Status: a 14 px icon top-right (clock = pending) rather than recolouring the card.
- **Dense days**: overlapping bookings sit side by side at equal width; below 28 px height only the time and customer show, with the full detail in a popover on hover/focus.
- **Weekends**: no special treatment unless the shop is closed (then hatched), so a Saturday-open salon doesn't look "off".
- **Views**: week/day switch as a segmented control; the date switcher keeps the arrows aligned with the date label.

### Services and schedules
- Service list: one row per service, name (500) with category chip beneath in `text-muted`, then duration and price right-aligned in tabular numerals; duration as "45 min", price as "€38.00". Inactive services at 60 % opacity with a neutral badge.
- Practitioner schedules: day rows with the day name left, ranges as readable chips ("09:00 – 12:30"), "Closed" as a neutral chip; editing opens in place with the hour lists already built.

### Booking rules, templates, reports, settings
- **Booking rules**: sections with an H3 and a one-line helper, controls in a 640 px column; related values inline ("Minimum notice [ 2 ] hours"); a sticky save bar at the bottom appears only when something changed.
- **WhatsApp templates**: table with message name, language chips (FR/NL/EN) and a status badge per language; "Submit missing" as the single primary button above the table; rejected rows show the reason on a second line in `text-muted`.
- **Reports** (dashboard counters today, reports as they arrive): one idea per card, chart on the left, the number and its comment on the right; charts in two neutral tints plus `primary`, direct labels instead of legends, gridlines at 8 % opacity, tabular axis labels. Never more than 4 colours per chart; colour-blind-safe pairing (blue + amber).
- **Settings**: keep the grouped menu; each page opens with an H1 and a one-line purpose, content in cards; the profile page starts with the shop identity block (logo, name, plan chip).

---

## 4. Micro-interactions and motion

Principle: **fast, subtle, never distracting**. Anything under 200 ms for small feedback, under 300 ms for panels. Everything respects `prefers-reduced-motion` (already global).

| Interaction | Spec |
|---|---|
| Button hover | colour + shadow, 150 ms `cubic-bezier(.2,.8,.2,1)` |
| Button press | `translateY(.5px)`, shadow removed, 80 ms |
| Input focus | border + halo fade, 150 ms |
| Checkbox / toggle | 120 ms check draw / 150 ms slide |
| Table row hover and select | background 120 ms; selected bar scales in from 0 height, 150 ms |
| Link hover | underline offset 2 → 3 px, 120 ms |
| Modal open / close | 200 ms (fade + scale .98 → 1) / 140 ms fade |
| Side panel | translateX(24 px → 0) + fade, 240 ms ease-out; close 160 ms |
| Toast | slide up 8 px + fade 180 ms, auto-dismiss 5 s, pause on hover |
| Skeletons | static `surface-2` blocks with a 1.4 s shimmer at 40 % opacity (replaces spinners for page loads) |
| Calendar slot hover | hovered half-hour gets `primary-tint` at 60 %, 100 ms, with a "+" appearing at its start |
| Appointment hover | shadow-sm and outline, 120 ms; others in the same practitioner colour dim to 70 % |
| Drag and drop | picked card lifts (shadow-md, scale 1.01), a dashed ghost stays at the origin, the target slot shows the new time in a small tooltip; snap animation 120 ms; invalid targets show a `not-allowed` cursor and a red tint |
| View change (day/week/month) | cross-fade 160 ms, the grid itself does not slide |
| Page navigation | no page animation; only a 2 px top progress bar for slow requests |

---

## 5. Light and dark mode

Today the client app has **no dark theme** (only the redesign mockups did). Build it from semantic tokens so one set of components serves both.

| Token | Light | Dark |
|---|---|---|
| `canvas` | `#F6F8FB` | `#0B1220` |
| `surface` | `#FFFFFF` | `#111A2E` |
| `surface-2` | `#F1F5F9` | `#16213D` |
| `line` | `#E6EAF0` | `#24304A` |
| `line-strong` (controls) | `#7F8EA3` | `#5A6A88` (3.2:1) |
| `text` | `#0F172A` | `#E8EDF7` (14.8:1 on surface) |
| `text-muted` | `#566175` | `#A3AEC4` (7.8:1) |
| `primary` (fill) | `#2A5BD7` | `#6B96FF` with `#0B1220` text (6.6:1) |
| `primary` (link/text) | `#2249B0` | `#8CB0FF` (8.1:1) |
| `primary-tint` | `#EAF0FD` | `#1B2748` |
| `accent` | `#0B7A6D` | `#2FA38F` |

Dark mode status badges (text on tint): success `#6EE7A0` on `#0F2A1E` (9.9:1), warning `#FBBF77` on `#2E2010` (9.6:1), danger `#FCA5A5` on `#331517` (8.8:1), info `#9DBBFF` on `#16213D` (8.3:1), neutral `#C2CBDD` on `#1D2740` (9.1:1).

Rules for dark:
- **No shadows for depth**: use the tonal step (`canvas` < `surface` < `surface-2`) and 1 px `line` borders; overlays darken to `rgba(0,0,0,.62)`.
- Practitioner colour on appointment cards rises from 12 % to 24 % opacity; the left bar stays at full colour; text stays `text`.
- Shop logo and QR code sit on a white tile in dark mode (a QR code must remain dark on light to scan).
- Follow the system setting by default (`prefers-color-scheme`), with a manual choice (light / dark / system) in the profile, remembered per user.

---

## 6. Implementation guidance

### 6.1 Design tokens and Tailwind

1. Move literal colours to **semantic tokens** in `@theme` (`--color-surface`, `--color-line`, `--color-text-muted`, …) and override them under `:root[data-theme="dark"]` and `@media (prefers-color-scheme: dark)`. Keep the existing `primary-*`, `nova-*` and `neutral-*` names working during the transition.
2. Add radius (`--radius-control: 10px`, `--radius-card: 14px`, `--radius-modal: 16px`), shadow (`--shadow-xs/sm/md/lg`), motion (`--ease-out`, `--duration-fast/base/panel`) and type (`--text-display` …) tokens.
3. Replace `bg-white`, `border-neutral-200`, `text-neutral-600` etc. with `bg-surface`, `border-line`, `text-muted` via a scripted codemod (grep-based, reviewed by hand). Today these literals appear in nearly every page, which is exactly why a token pass must come first.
4. One `Icon` wrapper over Lucide; one `Badge` component (variants subtle / solid, status map above); one `Card` and one `PageHeader` component so spacing is defined once.

### 6.2 Rollout (each step shippable on its own, nothing breaks)

| Step | Content | Risk |
|---|---|---|
| 0 | Tokens added, no visual change; contrast check script in CI | none |
| 1 | Base components: Button, fields (fixes the 3:1 border), Checkbox/Toggle, Badge, AlertBox, dialogs | low, shared by all pages |
| 2 | Layouts: top bar, Settings menu, page header, mobile bottom bar | low |
| 3 | Pages in order of daily use: Planning (cards, hatch, now-line), Dashboard, Services/Schedules, then Settings pages, Templates, Reports | medium, page by page |
| 4 | Dark mode (tokens already in place) + theme switch in the profile | low |
| 5 | Motion polish and skeletons | low |

Every step keeps all existing translations, routes and tests. The front-end tests that mount pages (`pages.test.ts`, component tests) act as the safety net; add Playwright screenshot comparisons for Planning, Dashboard and Settings in light and dark, 3 widths.

### 6.3 Quality gates

- A small script that checks every token pair listed here (the figures above come from the same calculation) and fails the build under 4.5:1 for text and 3:1 for controls.
- axe-core run on the main pages in light and dark.
- Visual review on a phone-width and a 1440 px screen for each step.

### 6.4 Tools

- **Figma**: one library with the tokens as variables (light/dark modes), the components above with all states, and 6 reference screens (Dashboard, Planning, Services, Settings page, Templates, a modal). Mockups already produced for Settings, logo, search, QR and price import serve as the starting point.
- **Component catalogue**: for a Vue + Vite project, **Histoire** is lighter than Storybook (same idea, zero config). A single `/dev/styleguide` page in development is an even cheaper start: every component with its states, in both themes.

### 6.5 What this plan deliberately does not touch

Page structure, navigation order, labels and translations, form fields and validation, the permissions model, the booking logic. If a change here would need one of those, it is out of scope here and will be raised separately.
