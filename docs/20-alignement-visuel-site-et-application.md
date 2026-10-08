# Nova — aligning the app with the website ("Calm SaaS, one deep teal")

Status: steps 1 and 2 are built (website tokens, self-hosted fonts, pill buttons, fields, dialogs, top bar, KPI figures); dark mode, calendar details and the remaining pages are still to do. Scope: look and feel only; no change to features, page structure, labels or flows.
Source of truth: the artifact **"Nova WhatsApp Website"** (https://claude.ai/artifact/7UK5KyqsVSQo5zT1gEJ5J8), mainly its `System` board ("Design system and site map") and the CSS of the `Home` board. Every value below is taken from there, then adapted for a dense product. Contrast figures are computed (WCAG 2.x), not estimated.

This spec **supersedes the palette, type and shape choices of `docs/19`** (navy + blue + Inter). The mechanics of docs/19 stay valid: semantic tokens, rollout in steps, contrast test, motion rules, dark mode built from tokens.

---

## 0. What changes compared with what is built today

Steps 0 and 1 of docs/19 are in the app (commit `0a26940`) with a navy-and-blue identity. The website uses a different one, so the app currently looks like a sibling product, not the same brand.

| | App today (docs/19, built) | Website (source of truth) | App after this spec |
|---|---|---|---|
| Primary | `#2A5BD7` blue | `#0E6B6B` Nova Teal | `#0E6B6B` |
| Structure colour | `#14213D` navy | `#0B2B2E` Deep Ink | `#0B2B2E` |
| Page background | `#F6F8FB` cool grey | `#F6F8F5` Canvas (warm-green) | `#F6F8F5` |
| Accent | `#0B7A6D` teal (WhatsApp only) | `#F0A23A` Spark Amber | amber as accent (see §1) |
| Headings | Inter 650 | Bricolage Grotesque 600–700 | Bricolage Grotesque |
| Body / UI | Inter | Instrument Sans | Instrument Sans |
| Buttons | 10 px radius | pill, 52 px, hover lifts 1 px with teal shadow | pill, 44 px (36 dense) |
| Cards | 14 px radius | 20 px radius, hover lifts 3 px | 16 px (20 for feature/KPI cards) |
| Fields | 10 px radius, 1 px border | 14 px radius, 1.5 px border, teal halo | 12 px radius, 1.5 px border, teal halo |
| Icons | Lucide-like, 1.75 | outline 1.75 in a 46 px mint tile | same, tile only where it helps |

Good news: because docs/19 moved the app to semantic tokens (`bg-surface`, `border-line`, `text-muted`, `rounded-control`…), most of the re-skin is a change of values in `resources/css/app.css` plus the button shape and the fonts; pages do not need to be rewritten.

---

## 1. Colour (hex, light)

| Token | Hex | Website name | Use in the app |
|---|---|---|---|
| `primary` | `#0E6B6B` | Nova Teal | Primary buttons, links, active nav, selection, focus |
| `primary-hover` | `#0A5656` | Teal Dark | Hover/pressed, text on mint |
| `deep` | `#0B2B2E` | Deep Ink | Dark panels (sign-in side, empty-state hero, dark mode base) |
| `ink` | `#10201F` | Ink | Headings, key values |
| `body` | `#3F5150` | Body | Paragraphs, table cells |
| `muted` | `#566968` | Muted | Captions, helper text, table headers |
| `mint` | `#DDF1EA` | Mint | Icon tiles, selected rows, info tint |
| `canvas` | `#F6F8F5` | Canvas | Page background |
| `surface` | `#FFFFFF` | (white cards) | Cards, tables, inputs, modals |
| `surface-2` | `#E8F0EC` | nav hover | Hover rows, table header, wells |
| `line` | `#E1E9E5` | card border | Hairlines (decorative) |
| `accent` | `#F0A23A` | Spark Amber | Badges of attention, highlights, **focus ring in dark mode**; never text on white |
| `sand` | `#FBEBCF` | Sand | Warm tint (warnings, "to finish" strips) |
| `field-border` | `#6F8984` | (new) | Input, select, checkbox borders (3.5:1 on canvas) |

Status (text on tint), all ≥ 5.5:1:

| Status | Text | Background | Icon | Contrast |
|---|---|---|---|---|
| Success | `#14683B` | `#E2F3E8` | check-circle | 5.9:1 |
| Warning | `#7A4A06` | `#FBEBCF` (Sand) | clock | 6.4:1 |
| Danger | `#B3261E` (the site's error red) | `#FDECEA` | alert-circle | 5.7:1 |
| Info | `#0A5656` | `#DDF1EA` (Mint) | info | 7.2:1 |
| Neutral | `#3F5150` | `#E8F0EC` | minus-circle | 7.2:1 |

Verified pairs: ink on canvas 15.8:1 · body on surface 8.4:1 · muted on surface 5.8:1 (on canvas 5.4:1) · white on Nova Teal 6.3:1 · white on Teal Dark 8.5:1 · teal link on white 6.3:1 · Teal Dark on Mint 7.2:1.

Rules (same discipline as the site):
- **Teal does the work**: one primary action per view, links, the active state. Everything else is neutral.
- **Amber is a spark, not a colour of text**: small badges, a highlight stripe, the dark-mode focus ring. On white it is 2.1:1, so it never carries text or a focus indicator in light mode.
- **WhatsApp green is not used**: the site deliberately sits next to WhatsApp without copying it. Chat previews use the site's bubble colours (outgoing `#D6F0E3`, incoming white).
- Practitioner colours (calendar): pick from an 8-colour set tuned to sit beside teal (teal, amber, plum `#7A3E86`, blue `#2F5F9E`, coral `#C4553B`, olive `#6C7F2F`, slate `#51657A`, rose `#B04870`), each used at 14 % as a tint with full colour on the left bar. Text on the card stays `ink`, so contrast never depends on the chosen colour.

### Two accessibility corrections to the website itself
The site is the reference, but two of its values fail WCAG non-text contrast. The app uses corrected values and the same fixes are recommended for the site:
1. **Field border `#C7D6D1` is 1.5:1 on white** (needs 3:1, WCAG 1.4.11). App: `#6F8984` (3.5:1 on canvas, 3.8:1 on white), still a soft green-grey; the site's pale border stays for decorative card lines.
2. **Focus ring `#F0A23A` is 2.1:1 on white** (needs 3:1). App, light mode: 3 px `#0E6B6B` ring with 2 px offset (6.3:1). App, dark mode: the amber ring (7.1:1 on Deep Ink), so the site's signature survives where it works.

---

## 2. Typography

Same two families as the site, **self-hosted** (the site links Google Fonts for the mock-up; in the app that would send visitors' IP addresses to a third party, which the project rules forbid). Use `@fontsource-variable/bricolage-grotesque` and `@fontsource-variable/instrument-sans`, Latin subset, `font-display: swap`.

| Role | Family / weight | Marketing site | App (dense) | Line height | Tracking |
|---|---|---|---|---|---|
| Page title (H1) | Bricolage 700 | 40–68 | **28** | 1.15 | −0.02em |
| Section title (H2) | Bricolage 600 | 30–48 | **20** | 1.3 | −0.015em |
| Card title (H3) | Bricolage 600 | 20–22 | **17** | 1.3 | −0.01em |
| KPI value | Bricolage 700 | n/a | **32** | 1.1 | −0.02em |
| Body | Instrument Sans 400 | 16–17 | **15** (16 in forms on phones) | 1.5 | 0 |
| Table cell | Instrument Sans 400 | n/a | **14** | 1.4 | 0 |
| Small / caption | Instrument Sans 400–500 | 14 | **13** | 1.4 | 0 |
| Eyebrow / table header | Instrument Sans 600 | 13 uppercase | **12** uppercase | 1.3 | +0.08em, `primary` or `muted` |
| Button | Instrument Sans 600 | 16 | **14.5** (14 dense) | 1.2 | 0 |

Notes: Bricolage is a display face, so it stays on titles and big numbers only; tables, forms and menus are Instrument Sans so dense text stays calm. Check that Instrument Sans provides tabular figures (`tnum`); if not, keep prices and times in Instrument Sans with `font-variant-numeric: tabular-nums` where available and verify column alignment in review.

---

## 3. From a marketing page to a dense product

The site breathes (52 px buttons, 26 px card padding, 56 px sections). The app keeps the same shapes and tones at a smaller scale.

| Property | Website | App default | App dense (tables, toolbars, calendar) |
|---|---|---|---|
| Button height | 52 | 44 | 36 |
| Button padding | 0 26 | 0 20 | 0 14 |
| Field height | 52 | 44 | 36 |
| Card radius / padding | 20 / 26 | 16 / 20–24 | 12 / 16 |
| Table row | n/a | 52 | 44 |
| Page gutter | 24–64 | 24–32 | 16 |
| Section gap | 56 | 24–32 | 16 |
| Max content width | 1180 | 1120 (1280 for calendar/tables) | n/a |

Principles for long hours of use: lower contrast of chrome (hairlines `#E1E9E5`, no heavy shadows), higher contrast of data (ink and body text), one accent only, no motion that repeats, numbers in tabular figures, status by badge (icon + text), ≥44 px touch targets on touch devices even in dense layouts.

---

## 4. Component alignment

**Buttons.** Pill shape like the site. Primary `#0E6B6B` fill, white text; hover `#0A5656` with 1 px lift and `0 10px 24px rgba(14,107,107,.28)` (180 ms); active returns to rest. Secondary: white, 1.5 px `#C7D6D1` border (decorative here, text carries the meaning), hover border and text `#0E6B6B`. Ghost: teal text on mint hover. Danger: `#B3261E` fill. Disabled: `#C9D8D3` fill, `#566968` text. In dense tables, buttons are 36 px, **no lift and no shadow** (a column of lifting buttons is noise); the colour change remains.

**Inputs.** 12 px radius, 1.5 px `#6F8984` border, white fill, 44 px high. Focus: border `#0E6B6B` plus `0 0 0 4px rgba(14,107,107,.18)` halo (as on the site). Error: border `#B3261E`, message below in `#B3261E`, icon + text. Labels 14 px/600 `ink` above the field; helper 13 px `muted`. Checkbox/radio 20 px, border `#6F8984`, checked = teal with white tick. Toggle 40×24, teal when on.

**Cards.** White, 1 px `#E1E9E5`, radius 16 (20 for hero KPI and feature cards). Rest: no shadow. Hover **only when the card is clickable**: lift 3 px, `0 18px 40px rgba(16,32,31,.08)`, border `#BFD5CE` (200 ms). Data cards (tables, forms) never lift.

**Icons.** Outline, 24 px grid, 1.75 stroke, round caps (the site's icons). In the app: bare 20 px (16 in rows) in navigation, tables and buttons; inside a **mint tile** (32–40 px, radius 12, icon `#0A5656`) for KPI cards, empty states and settings group headers. Attention items use a **sand** tile with a `#7A4A06` icon (the site's "problem tile").

**Badges / chips.** Pill, 24 px, 12.5 px/600, icon + label. Subtle by default (tints above). The site's "chip" (white, 1 px `#9CCBBE`, `#0A5656` text) is used for filters, language tags and counts. Confirmed = success, Pending = warning, Completed = neutral, No-show = danger, Connected/Approved = success, Waiting for Meta = info.

**Tables.** Header on `surface-2` `#E8F0EC`, 12 px uppercase `muted`; rows 52 (44 compact) with `#E1E9E5` hairlines, no zebra; hover `#E8F0EC`; selected `mint` with a 3 px teal bar on the left; first column `ink` 500, other columns `body`; numbers right-aligned and tabular; actions as ghost icon buttons.

**Modals and side panels.** Radius 20, `0 20px 44px rgba(16,32,31,.12)` (the site's floating card), overlay `rgba(11,43,46,.55)` (Deep Ink, so the dimmed page stays on-brand), padding 24, footer actions right-aligned. Side panel 440 px wide, slides from the right. Open with the site's `pop` (translateY 10 → 0, scale .97 → 1) shortened to 200 ms.

**Navigation.** Same recipe as the site's top bar: sticky, 64–72 px, translucent canvas (`rgba(246,248,245,.85)`) with a 14 px blur and a 1 px `#E1E9E5` bottom line. Links: 15 px/500 `#2E4442`, radius 10, hover `#E8F0EC` + teal text, **active** = mint background + Teal Dark text with `aria-current`. Logo and shop avatar left; Settings, language switch and Sign out right. Phone: bottom bar (56 px, five icons + label) instead of the site's full-screen overlay, since the app has daily actions to reach in one tap. Settings keeps its grouped side menu (group labels in the eyebrow style, teal).

---

## 5. Light and dark

The site is light with **Deep Ink sections** (`#0B2B2E`, text `#D5E6E2`, accents `#9FE0CB`/`#7BC4B0`, amber). Dark mode is those sections extended to the whole app, not a generic grey theme.

| Token | Light | Dark |
|---|---|---|
| `canvas` | `#F6F8F5` | `#081F21` |
| `surface` | `#FFFFFF` | `#0B2B2E` (Deep Ink) |
| `surface-2` | `#E8F0EC` | `#11383B` |
| `line` | `#E1E9E5` | `#1E4448` |
| `field-border` | `#6F8984` | `#5B8A86` (3.9:1) |
| `ink` / headings | `#10201F` | `#F2F7F5` (13.9:1 on surface) |
| `body` | `#3F5150` | `#D5E6E2` (11.6:1) |
| `muted` | `#566968` | `#9DB8B4` (7.1:1) |
| `primary` fill / text on it | `#0E6B6B` / white | `#9FE0CB` / `#0B2B2E` (10.0:1) |
| `primary` as link | `#0E6B6B` | `#9FE0CB` (10.0:1) |
| `mint` tile | `#DDF1EA` | `#12383B` with `#9FE0CB` icon |
| `accent` | `#F0A23A` | `#F0A23A` |
| focus ring | 3 px `#0E6B6B` | 3 px `#F0A23A` (7.1:1) |

Dark status (text on tint): success `#8FE0AE` on `#0F3322` (8.9:1), warning `#F6C879` on `#3A2A0C` (8.9:1), danger `#FFA39B` on `#3D1714` (8.2:1), info `#9FE0CB` on `#12383B` (8.5:1), neutral `#D5E6E2` on `#1E4448` (8.2:1).

Rules: depth by tone and 1 px `#1E4448` lines, not shadows; appointment tints rise from 14 % to 26 %; the QR code and the shop logo sit on a white tile; follow the system setting by default with a manual light/dark/system choice in the profile.

---

## 6. Motion

Taken from the site's own timings, calmer for a tool used all day:

| Interaction | Website | App |
|---|---|---|
| Button hover | 180 ms, lift 1 px + teal shadow | same (not in dense tables) |
| Nav link hover | 150 ms colour | same |
| Card hover | 200 ms, lift 3 px | only on clickable cards |
| Field focus | halo | 150 ms halo |
| Entry animation | `pop` 500–600 ms, `rise` 800 ms | modals/panels/popovers only, 200 ms; **no scroll reveals, no looping animation** |
| Parallax | hero blobs ≤ 40 px | none |

Additions for the product, same easing and subtlety: row hover and selection (120 ms background, selection bar grows in 150 ms), calendar slot hover (mint at 60 %, 100 ms, "+" at the start), appointment hover (shadow and a 1 px outline in its colour), drag feedback (lift with the site's soft shadow, dashed ghost at the origin, time tooltip on the target, 120 ms snap), toasts (slide up 8 px + fade, 180 ms), skeletons instead of spinners for page loads. Everything animates only `transform` and `opacity` where possible and is off under `prefers-reduced-motion` (already global in `app.css`).

---

## 7. Applying it screen by screen

- **Dashboard**: page title in Bricolage; the first two KPIs ("Today", "Still to come") as 20 px cards with a mint icon tile and the value at 32 px, the others compact; "Next appointment" as a prominent card with the time in Bricolage 28; today's agenda as a timeline (time, 3 px practitioner bar, customer + service, status badge); banners (setup, trial) as slim sand/mint strips with an icon.
- **Calendar**: warm canvas grid with `#E1E9E5` lines (hour lines stronger than half-hours); non-working hours as a fine diagonal hatch in `#C9D8D3`; closed days hatched with a "Closed" caption; today's header in mint with a filled teal day number; now-line 2 px `#B3261E` with a dot; appointment cards = practitioner tint + left bar, `ink` text, status icon; segmented control for day/week; the new-appointment form as a side panel on desktop.
- **Services**: rows with the name (500) and category chip beneath, duration and price right-aligned and tabular; inactive services at 60 % with a neutral badge; import and "add" actions as one primary + one secondary pill.
- **Schedules**: day rows with ranges as readable chips ("09:00 – 12:30"), "Closed" as a neutral chip; the hour lists keep the field style.
- **Booking rules**: a calm single column (max 640 px), H3 + one-line helper per group, related values inline, a sticky save bar that appears only when something changed.
- **Templates (WhatsApp)**: table with a status badge per language; **a message preview using the site's chat bubbles** (outgoing `#D6F0E3`, 14 px radius, 4 px corner, soft shadow) so the app and the site show WhatsApp the same way; the QR code page uses a white tile on a mint panel.
- **Reports**: one idea per card; teal as the single series colour, amber as the second, direct labels instead of legends, grid at 8 % opacity, tabular numbers; never more than four series.
- **Settings**: grouped side menu with eyebrow-style group labels, cards with a mint icon tile per group, the profile page opening with the shop identity block (logo, name, plan chip) on a Deep Ink band that echoes the site's dark sections.

---

## 8. Implementation

1. **Re-point the tokens** in `resources/css/app.css`: `primary-*` → teal scale (`600 #0E6B6B`, `700 #0A5656`, `50 #E8F0EC`/`100 #DDF1EA`), `nova-900` → `#0B2B2E`, `canvas`/`line`/`muted`/`field-border`/status pairs as above, radii (`control 12`, `card 16`, `modal 20`, `pill 999`), shadows and easing from the site; add the dark overrides. The contrast test (`resources/js/design/tokens.test.ts`) is extended with every pair in this document and must pass before merge.
2. **Fonts**: add the two `@fontsource-variable` packages, set `--font-sans` (Instrument Sans) and `--font-display` (Bricolage Grotesque); apply `font-display` to `h1`–`h3` and the KPI value through a single `.font-display` utility.
3. **Components**: `BaseButton` becomes pill-shaped with a `size` prop (`md` 44 / `sm` 36); fields get the 12 px radius and 1.5 px border; `Badge`, cards, dialogs and the top bar take their values from the tokens. Pages are untouched except where a title or KPI needs the display font.
4. **Rollout** (each step shippable): tokens and fonts → base components → layouts (top bar, settings menu, bottom bar) → pages in order of daily use (Calendar, Dashboard, Services, Settings, Templates, Reports) → dark mode and theme switch → motion polish.
5. **Checks**: contrast test, axe on the main pages in both themes, screenshot comparison at three widths, and a side-by-side review against the website's Home and System boards.
6. **Website follow-up** (outside the app): fix the two accessibility values (field border, light-mode focus ring) so site and product stay identical.

Out of scope here: page structure, labels and translations, form fields and validation, permissions, booking logic.
