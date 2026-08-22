# Handoff: Kurash Manager Redesign — v2 "Soft SaaS" (IKA green/blue)

## Overview
Visual redesign of the Kurash tournament manager (repo `hadi165/kurash-tournament-manager`, Laravel 12 + Livewire + Flux, views under `kurash-manager/resources/views/`).

**Implement v2 (the `* v2.dc.html` files).** Direction: soft, modern SaaS — rounded cards, soft shadows, airy spacing, pill buttons, quiet borders. Brand: IKA **green** primary, IKA **blue** secondary, red for destructive only. Font: Segoe UI with self-hosted **Source Sans 3** fallback.

(The v1 files without `v2` in the name are an earlier flat "Modernist" direction — kept for reference only, do not implement.)

## About the Design Files
The `.dc.html` files are **design references created in HTML** — prototypes showing intended look and behavior, not production code. Recreate them in the existing Laravel + Livewire + Flux/Tailwind codebase by restyling the mapped Blade views; keep all existing Livewire wiring (`wire:model`, `wire:click`, `wire:confirm`, `wire:navigate`).

## Fidelity
**High-fidelity.** Colors, radii, shadows, spacing and states below are final.

## Design Tokens (do this first — most of the redesign is tokens)
Define as CSS variables and map into the Tailwind 4 `@theme`. Exact values are in `kurash-soft.css`.

**Light**
- bg `#f6f8f7` · surface `#ffffff` · text `#16211d` · muted `#6b7a74`
- line `#e4eae7` (borders) · line-soft `#eef2f0` (row separators, hover fill)

**Dark**
- bg `#101614` · surface `#18211e` · text `#eaf2ee` · muted `#90a099`
- line `#263230` · line-soft `#1f2a27`
- green-soft `#12291d` · blue-soft `#0f2733` · red-soft `#2a1418`

**Brand**
- green `#019a44` (primary buttons, active nav, pass states, progress, gold column) · green-soft `#e9f7ee` · green-deep `#046830`
- blue `#1a9fd8` (info pills, "blue corner", secondary emphasis) · blue-soft `#e8f6fd` · blue-deep `#086690`
- red `#d7263d` (destructive, fail states) · red-soft `#fdeaee`

**Shape & depth**
- radius: sm `8px` (inputs, small chips) · md `12px` (inner cells, callouts) · lg `18px` (cards) · pill `999px` (buttons, badges, tabs)
- shadow-sm `0 1px 2px rgba(16,33,29,.06), 0 1px 3px rgba(16,33,29,.04)`
- shadow-md `0 4px 16px rgba(16,33,29,.07), 0 1px 3px rgba(16,33,29,.05)` — the default card shadow
- shadow-lg `0 12px 32px rgba(16,33,29,.10)` — popovers
- Dark theme shadows: black at .4 / .45 / .55
- **Cards have no border — shadow only.** Inner cells/inputs use a 1px `line` border on the `bg` color.

**Typography**
- Stack: `"Segoe UI", "Segoe UI Variable", "Source Sans 3", system-ui, sans-serif` (self-host Source Sans 3, weights 400/500/600/700)
- H1 page title 30px/700, letter-spacing -0.02em · card title 17-20px/700 · body 13.5-14.5px · meta 12.5px muted
- Table headers: 11.5px/600 uppercase, letter-spacing 0.05em, muted
- No all-caps display type anywhere else.

**Spacing:** 4/6/8/10/12/16/18/20/24/28px. Content column max-width 1180px, cards 24px 28px padding, 16px between cards.

## App shell (every screen)
- **Sidebar** 264px, *no border and no background* — it floats on the page ground; 16px 12px padding. Logo in a white 12px-radius chip with shadow-sm, then "IKA Manager / Kurash Association". Group labels 11px/600 uppercase muted. Nav items 13.5px/500, 9px 12px padding, 8px radius; **active = green-soft background + green-deep text + weight 600**; hover = line-soft. Bottom: user card (surface, 12px radius, shadow-sm) with a round green avatar.
- **Main column:** `padding: 16px 20px 40px 4px`. Top row = breadcrumbs (muted, `›` separators) left / theme toggle + page actions right. Then page title block. Then content cards.
- **Buttons** (all pill, 13.5px/600, `padding: 8px 16px`, `transition: all .15s`): primary = solid green, white text, shadow-sm · soft = surface + 1px line border · ghost = transparent, hover line-soft · danger = transparent with red text, hover red-soft · chip/small = 12.5px, `padding: 6px 14px`.
- **Badges/pills**: `padding: 4px 12px`, 12px/600, pill radius. Green-soft/green-deep = success. Blue-soft/blue-deep = info. Red-soft/red = problem. bg + 1px line + muted = neutral.
- **Tables** live in a card with `overflow: hidden` and no padding; header row has a 1px `line` bottom border, body rows a 1px `line-soft` bottom border; row hover = line-soft. IDs and URLs in monospace 12px muted.
- **Inputs:** `bg` fill, 1px line border, 8px radius, `padding: 10px 14px`, 14px. Search fields use pill radius. Labels 12.5px/600 muted above the field.

## Screens (→ Blade views to restyle)
1. **Categories** (`livewire/competition/categories.blade.php`) — signature screen. Header **card** (not a colored band): a green-soft status pill ("● Live · 12 Sep 2026 · Tashkent"), 34px title, subtitle; right side three stat tiles (bg fill, 12px radius) for categories / athletes / weight classes. Below: a **pill tab bar** (surface card, pill radius, 5px padding) — active tab = solid green pill with white text. "Export ▾" popover top-right (surface, 12px radius, shadow-lg). "New age category" collapsed behind a green `+ New age category` button; when open, a card with Name / Weight classes / Gender — **gender is a segmented pill control** (bg track, active segment = white surface + shadow-sm). Each category card: title + gender pill (Men = blue, Women = green), "N athletes registered · M weight classes", actions right (Registration/Weigh-in soft, Edit ghost, Delete red). Then a **weight-class grid** (auto-fit minmax 158px, 10px gap): each tile = bg fill, 1px line, 12px radius, 18px/700 label, `count/cap` muted right, and a 6px rounded **capacity bar** — grey under 50%, blue 50-99%, green at 100%. Tile hover lifts (`translateY(-1px)` + shadow-md).
2. **Dashboard** (`dashboard.blade.php`) — championship card: title + green "● 2 bouts on mats" pill, stat tiles row, 8px rounded green progress bar ("Bouts decided 98/156"), and a **blue-soft "Next up" panel** (12px radius) listing next actions with small soft buttons.
3. **Championships** (`championships.blade.php`) — `+ New championship` button in the header; form card appears on demand; table of championships with Edit/Delete row actions.
4. **Archive** (`archive.blade.php`) — "Ready to close" card with an inset bg row + green "All decided" pill + green Archive button; archived cards with a neutral "Archived <date>" pill, top-3 NOC medal **pill chips**, export chips, muted event log under a hairline, Reopen ghost.
5. **Registration** (`registration.blade.php`) — blue "Men Senior" pill above the title; 3-column register form; athlete table with a **pill search field**, monospace IKA IDs, NOC chips (8px radius, bg, 1px line), weigh-in pills (green = "65.8 kg", red = fail, neutral = "Not weighed").
6. **Weigh-in** (`weigh-in.blade.php`) — three stat cards on top (Weighed / Passed in green / Outside class in red), class filter select, per-row number input + Save, result pill. Rule: pass if kg ≤ limit + 0.5; `+` classes always pass.
7. **Entries** (`entries.blade.php`) — three stat cards (ready-to-draw in green); by-weight table with draw status pills — "Done" green + Open button, "Not started" neutral + green "Start draw", "Needs 2 cleared" red; by-NOC table below.
8. **Fight order** (`fight-order.blade.php`) — controls card (min-rest input, Build running order primary, Clear ghost, Hide finished checkbox); a **red-soft callout** for unscheduled bouts; bouts table where Blue and Green athletes are prefixed with 8px round **blue / green dots**; decided rows at 50% opacity with the winner bold; row actions ↑ ↓ and Mat 1 / Mat 2 chips.
9. **Mats** (`courts.blade.php`) — **card grid instead of a table** (auto-fit minmax 320px): each mat card = square number badge + name + monospace URL + Active/Inactive pill, bouts count under a hairline, then Open mat (green) / Scoreboard / Test / Activate / Delete. Add-mat form collapsed behind `+ Add mat`.
10. **Medals** (`medals.blade.php`) — export chips in the header; standings table with muted rank, NOC chip + name, **Gold column in green 700**, Total bold; per-event podium cards where each place is an inset bg tile with a Gold (green) / Silver (blue) / Bronze (neutral) pill.

11. **Venue scoreboard** (`livewire/competition/scoreboard.blade.php` + `components/layouts/scoreboard.blade.php`) — **implement `Scoreboard v2.dc.html`** (`Scoreboard A/B.dc.html` are earlier drafts, reference only). It carries BOTH a dark and a light theme — dark is the venue default, light is for bright halls and daylight-lit projections; wire it to the same appearance toggle as the rest of the app, or a per-mat setting. Panels are rounded cards (22px radius, 2px border) rather than full-bleed bands; each athlete pane carries a 20px full-height corner bar in blue `#1a9fd8` / green `#019a44`; score cells are 164px rounded tiles (16px radius); the clock sits above three **period dots** (18px, filled green for completed/current) with a "Period N" label. Light theme: ground `#eef1f0`, chrome/panes `#ffffff`, text `#0d1613`, muted `#5d6d67`, lines `#dbe2df`, cell fill `#f2f5f4`, dim `#a8b4af`, winner tints `#eaf5fc` (blue) / `#eaf7ef` (green). **The clock stays light-on-dark in both themes** (`#0d1613` plate, `#ff5b3c` digits, `#ff2a17`/`#d7263d` in the final 20s) so it never loses contrast at distance. Dark theme values are in the file.
    - **Contest states** (the `state` prop in the mock — drive these from bout data): `live` (normal), `golden`, `halal`, `winner`, `idle`.
    - **Golden score**: when a contest goes to extra time the clock **counts up** instead of down, digits turn gold `#e0a83c` with a gold border, the period dots are replaced by a bold gold "GOLDEN SCORE" label. A clock frozen at 00:00 reads as a broken board.
    - **Halal takeover**: a halal ends the contest, so before settling into the winner state the board goes **full-screen green `#019a44`** for a few seconds — kicker line (mat/bout/phase) at 30px/700 letter-spaced, "HALAL" at 188px/900, the winner's name at 84px/900 uppercase, and country + division at 32px/600. Same treatment is appropriate for the final bell. Give the hall the moment.
    - **Passivity / shido indicator** (`passivity` prop: none/blue/green): the flagged athlete's pane border turns gold `#e0a83c` and a gold "PASSIVITY 0:08" pill (tabular-nums, counting down) appears next to their corner tag. Tanbeh counts say *that* something was called; this says *what is happening now*.
    - **Feed health dot** (`link` prop: ok/stale): a 20px dot at the top right with a soft ring — green when the poll is fresh, **red and pulsing once no poll has landed for ~6 seconds**. Critical: the board runs its clock locally between the 2 s polls, so a dead feed produces a plausible but wrong board that nobody notices. Compare `Date.now()` against the last successful poll client-side.
    - **Next bout strip** (62px, above the footer): "NEXT" kicker at 20px/900 letter-spaced muted, then "No.44 · -81 kg Men · MALIKOV (UZB) v ZHAKSYLYK (KAZ)" at 28px/700. Athletes and coaches at that mat should not have to find another screen to know they are up.. This screen does NOT follow the soft-SaaS tokens: it is a venue big-screen board on near-black (`#05070a`), designed to read at thirty metres. Authored at 1920x1080 — keep the existing `vh`-based sizing approach so it scales to any projector; the px values below are at 1080p, divide by 10.8 for vh.
    - **Header** (152px): left = IKA logo on a white 10px-radius chip + "Mat 1 / No.43" at 44px/900 with the phase under it at 22px/600 uppercase muted (`#8a99ab`); centre = the clock — monospace, 108px/700, tabular-nums, `#ff5b3c` on black with a 3px `#1c2330` border and 14px radius, switching to `#ff2a17` with a matching border **in the final 20 seconds**; right = weight class 44px/900 over division 22px/600 uppercase muted. Header background `#0b0f15`, 2px `#1b222c` bottom border.
    - **Athlete rows** (one flex row each, equal height): an 18px full-height **corner bar** in blue `#1a9fd8` (top) or green `#019a44` (bottom); then the **NOC flag** in a 150x100 rounded tile (10px radius, 2px `#232c38` border, `object-fit: cover`), loaded from `asset("flags/{iso}.svg")` exactly as the current view does — the flag is a primary athlete identifier at thirty metres, so it is the normal case, not decoration. **Note: `kurash-manager/public/flags/` is not committed to the repo — the flag SVGs must be added there** (lowercase ISO-3 filenames, e.g. `uzb.svg`, `kaz.svg`); until they are, the tile falls back to the NOC code at 40px/900 white over a subtle diagonal-stripe fill, which is what the mock shows. Keep both paths. then the name at 76px/900 uppercase with a pill corner tag ("BLUE CORNER" / "GREEN CORNER") in the corner colour under it. Row background `#080b10`; the blue row has a 2px `#1b222c` bottom border.
    - **Score cells**: four 170px columns (Y / C / D / T) at the right of each row, value 106px/900 tabular-nums over the letter at 22px/700 letter-spaced. Cells are `#0e131a` with a 2px `#161d26` left border; **a zero D or T cell dims to `#0b0f15` with `#54606f` text** so the referee's eye goes to what actually scored.
    - **Winner state**: the winning row tints (blue `#0c1c28` / green `#0b1f14`), the C and D cells are replaced by a **WINNER block** in the corner colour (54px/900, 14px radius, 16px 40px padding), and only Y and T counts remain.
    - **Idle state**: "No contest on this mat", 64px/700 in `#3a4552`, centred.
    - **Footer** (74px): the Y/C/D/T key spelled out (Yonbosh / Chala / Dakki / Tanbeh) at 20px/600 muted with the letters in white 900, and the championship name + venue right-aligned. No halal column — a halal ends the contest and the board shows WINNER.
    - Keep the existing polling + local clock behavior exactly as it is (`wire:poll.2s`, Alpine clock ticking between polls, re-anchored on every poll).

12. **PDF exports** (`resources/views/exports/table.blade.php` — every tabular PDF renders through this one template, so restyling it restyles all of them) — implement `Export PDF.dc.html`. **Every rule below is expressible in Dompdf**: no flexbox, no external stylesheets, no web fonts (keep `DejaVu Sans`, which covers Cyrillic), PNG logo read from disk via `public_path()`, `thead { display: table-header-group }` for repeated headings. Where the mock uses flex, use a two-cell layout table in the Blade template.
    - **Header band**: full-bleed green `#019a44`, white text, 20px 40px padding. Left = the logo on a white 5px-padded chip (3px radius) + "International Kurash Association" at 14px/900 over "OFFICIAL COMPETITION DOCUMENT" at 8.5px/700 letter-spacing 0.14em in white at 80%. Right = the document type ("ENTRIES REPORT") at 10px/700 uppercase letter-spaced, over a monospace document reference (`IKA-ENT-2026-014`) at 9px — federation paperwork gets filed, so give it a citable reference. A `header: rule` variant keeps the band white with a 3px green bottom rule, for printers where a full-bleed fill is wasteful; pick one and use it everywhere.
    - **Title block**: report title at 23px/900, letter-spacing -0.015em. Then the meta lines as a **horizontal row of label/value pairs** (label 8.5px/700 uppercase letter-spaced `#7d8b85`, value 12.5px/700), not the current stacked "Label: value" list — the same information in a third of the height. Closed with a 1px `#e0e5e3` rule.
    - **Table**: header row solid green, white 9px/700 uppercase letter-spaced text, 1px white-22% column dividers. Body cells 11px, 8px 10px padding, **only a 1px `#eceeed` bottom rule** — drop the current full 0.5pt grid; zebra rows `#f7f9f8`. Numeric columns right-aligned with `font-variant-numeric: tabular-nums`; first and last columns bold.
    - **Status values** get a small filled chip (2px radius, 9px/700 uppercase): Done = `#e2f4e9` on `#046830`, Not started = `#eef1f0` on `#5d6d67`, a short/blocked field = `#fbe9ec` on `#8f1626`. Reuses the vocabulary the spec fixes.
    - **Total row** under the table: `#f2f5f4` fill with a 2px green top rule, label left and value right at 11px/700 uppercase. Add it wherever the report has a meaningful sum.
    - **Footer**: 2px green top rule, then championship + venue at 8.5px/700 with the generation timestamp under it, and "PAGE 1 OF 2" right-aligned. Keep the two separately anchored `position: fixed` blocks the current template uses — the existing comment explains why floats leak in Dompdf.
    - Empty state: keep the "Nothing to report yet." row, restyled to 11px `#7d8b85` italic.

13. **Spreadsheet exports — action needed.** `CsvWriter` emits **CSV**, which is plain text: it cannot carry a logo, colors, fonts, column widths or frozen headers, so the "Excel" buttons can never be branded as-is. To match the PDFs, replace `CsvWriter` with a PhpSpreadsheet (`maatwebsite/excel` or `phpoffice/phpspreadsheet`) **XLSX** writer that reuses the same `Report` interface — nothing about the report classes needs to change. Target formatting: logo image anchored at A1 with the org name beside it, the meta pairs as label/value rows above the table, a green `#019a44` header row in white bold, a frozen pane under the headings, auto-fitted column widths, right-aligned numeric columns, and an autofilter on the heading row. Keep the UTF-8 handling intent — XLSX is Unicode natively, so the BOM workaround becomes unnecessary. Keep a CSV option alongside it for data interchange, unstyled and clearly labelled "CSV" rather than "Excel".

14. **Draw sheet — bracket export (PDF + XLSX)** — implement `Draw Sheet.dc.html`. This replaces the flat row-per-bout table `DrawSheetReport` currently produces: after a draw, officials need the **tree**, with the fight number in every square. Seeding comes from `App\Support\BracketSeeding::order()` — the mock reimplements the same recursive construction, so pairings match exactly. Landscape; page width scales with bracket size (seed column 232px + 148px per round + 196px champion column + 88px padding), so a bracket of 8 prints on A4 landscape and a bracket of 32 on A3.
    - **Header**: "INTERNATIONAL KURASH ASSOCIATION" at 12px/700 letter-spacing 0.16em in green `#019a44`, championship title at 27px/900 under it, then a chip row — category (blue `#e8f6fd`/`#086690`), "Bracket of 32" (green `#eaf7ef`/`#046830`), and an athletes/byes count (neutral). Right side: "DRAW SHEET" kicker, date + venue, monospace document reference, and **the IKA logo top right** in an 84px box with a 12px radius and 2px `#e2e8e5` border. Closed with a 3px green rule.
    - **Column headings** above the tree, one per round, named by phase from `BracketSeeding::phaseName()` (1/16 final → … → Final), plus "Champion".
    - **Seed column**: one row per bracket seat. Each seat = a **seed chip** (30px, 4px radius, white 700 numerals) coloured by corner — **blue `#1a9fd8` for the upper seat of each pair, green `#019a44` for the lower** — then the athlete name at 11.5px/600 on a ruled `#b9c4bf` baseline with the NOC right-aligned at 10px/700 muted. Byes read BYE, as the current report already does.
    - **The tree**: round *r* spans 2^r rows. Each match cell draws a 1px `#b9c4bf` connector — a vertical bar from 25% to 75% at the cell's left edge, a horizontal feed into the box, and a horizontal feed out of the right edge — with a **fight-number box** centred on it: 62×20px, 5px radius, 1px border, "No. 12" at 10.5px/700 tabular-nums. The final's box is filled green-tint `#eaf7ef` with a green border. Above each outgoing feed sits a short ruled line for the winner's name. Fight numbers run in published running-order sequence, round by round.
    - **Champion**: a green "CHAMPION" label at 10px/700 letter-spaced over a full-width ruled line, vertically centred in the sheet.
    - **Footer**: blue/green corner legend, a note that fight numbers follow the published running order, and the generation timestamp.
    - Row height 26px at bracket 32, 44px at 16 and below — keeps a small draw legible and a large one on one sheet.
    - **PDF**: Dompdf cannot position absolute connectors reliably, so build the tree as a **layout table** — one `<tr>` per bracket seat, one `<td>` per round with `rowspan = 2^r`, and draw the connectors as cell `border-left` / `border-bottom` edges rather than absolute divs. The visual result is identical; the geometry is what `rowspan` was made for.
    - **XLSX**: the same structure maps directly onto a worksheet, which is why this export belongs in the spreadsheet writer as well as the PDF — **one worksheet row per bracket seat, one column group per round, and the fight number written into a merged cell spanning that match's rows** (exactly the "fight no in every square" requirement). Draw the tree with cell borders on the merged ranges; fill seed cells with the blue `#1A9FD8` / green `#019A44` theme colours and white bold numerals; anchor the IKA logo image over the top-right cells; freeze the pane below the heading rows; set explicit column widths so the tree keeps its proportions. Requires the PhpSpreadsheet writer from item 13 — `CsvWriter` cannot express a bracket at all.

## Colour system (applies to every screen)
Cards are white/surface with a **coloured rail** that names what the card is about, plus tinted section headers — the only colour rule beyond the accents already listed. Three hues carry data meaning: **green** = brand, done, passed; **blue** = information, entries, the blue corner; **amber** `#c98a00` (soft `#fbf0d8`, deep `#6f4d00`) = medals, honours, "needs attention". Red stays destructive/failed only.
- **Rails**: 5px on the top edge of a section card, 4px on the left edge of a stat tile. Rails are decorative — no contrast floor.
- **Table cards clip their corners but must not clip their columns**: use `overflow-x: auto; overflow-y: hidden` (or an inner scroll wrapper), never `overflow: hidden` — several of these tables are wider than the content column, and a hidden rightmost column is unreachable data with no scrollbar to reveal it.
- **Text on tints uses the `-deep` role, never the bright hue.** `--s-green-deep`, `--s-blue-deep`, `--s-amber-deep` are the readable end of each hue and **invert with the theme** (light theme: dark inks; dark theme: `#6fd898`, `#7fd0f0`, `#e8bf62`). Bright `--s-green` / `--s-blue` / `--s-amber` are for fills, rails and bars only — `#c98a00` on white is 2.95:1 and fails large-text contrast.
- Per screen: Categories hero = green tint with green/blue/amber stat tiles, category cards railed and header-tinted by the category's hue (men blue, women green); Dashboard championship card green rail, stat tiles railed per metric; Championships form green / table blue; Archive amber; Registration form green / table blue; Weigh-in tiles blue (weighed) / green (passed) / red (outside class); Entries tiles blue / green / amber (ready to draw); Fight order controls green / table blue; **Mats cards rail green when active and neutral when inactive** — the rail is the mat's live state; Medals standings table amber.
- `Categories v2.dc.html` carries a `palette` prop (green lead / blue lead / amber accent / two tone) showing alternative combinations of the same three hues. **Ship "green lead"** unless told otherwise; the others exist so the combination can be changed in one place.

## Interactions & Behavior
- Light/dark toggle in the top bar → maps to the existing Flux appearance setting.
- Hovers: nav + table rows tint `line-soft`; weight tiles lift; buttons tint.
- Focus: `outline: 2px solid #019a44; outline-offset: 2px`.
- Collapsible create-forms on Categories, Championships and Mats (button → card, Cancel closes).
- Everything else keeps current Livewire behavior.

## Assets
- `IKA.png` — official logo. Place at `kurash-manager/public/images/logo.png` (`config/branding.php` already points there). Always on a white chip; never recolor.
- Source Sans 3 (self-host, see Fonts note above).

## Files in this bundle
- `README.md` — this spec
- `kurash-soft.css` — **the v2 token sheet (light + dark). Port this first.**
- `IKA.png` — logo
- `* v2.dc.html` — the eleven v2 screens (implement these)
- `kurash-theme.css`, `modernist-base.css`, non-v2 `*.dc.html` — earlier v1 direction, reference only
