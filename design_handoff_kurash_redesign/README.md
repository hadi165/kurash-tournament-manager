# Handoff: Kurash Manager Redesign (IKA green/blue)

## Overview
A full visual redesign of the Kurash tournament manager (repo: `hadi165/kurash-tournament-manager`, Laravel 12 + Livewire + Flux, views under `kurash-manager/resources/views/`). The redesign replaces the neutral zinc look with the IKA brand: **logo green** as the primary accent, **logo blue** as the secondary, **logo red** reserved for destructive/danger only. Style direction is "Modernist": flat, architectural, set entirely in **Archivo**, zero corner radius, strong 2px dividers, flush-left labels, visible modular grid.

## About the Design Files
The `.dc.html` files in this bundle are **design references created in HTML** — prototypes showing intended look and behavior, not production code. The task is to **recreate these designs in the existing Laravel + Livewire + Flux/Tailwind codebase**, restyling the existing Blade views (mapped below) rather than rebuilding them. Open each `.dc.html` in a browser to inspect it (they reference `kurash-theme.css` and a design-system stylesheet; the visual specs below are self-sufficient).

## Fidelity
**High-fidelity.** Colors, typography, spacing and interaction states are final. Recreate pixel-perfectly using Tailwind/Flux theming (CSS variables + Tailwind config), keeping all existing Livewire behavior.

## Design Tokens (implement first — most of the redesign is this)
Suggested: define as CSS variables on `:root` / `.dark` and map into Tailwind theme colors.

Light theme:
- `--color-bg: #f3f2f2` (page ground) · `--color-surface: #eae9e9` (cards) · `--color-text: #201e1d`
- `--color-divider: rgba(32,30,29,0.4)` — used as **2px** rules, never 1px hairlines for major sections
- Neutral ramp: 100 `#f8f4f4` · 200 `#eae7e7` · 300 `#d7d3d3` · 400 `#bab6b6` · 500 `#9b9797` · 600 `#7d7979` · 700 `#605d5d` · 800 `#444141` · 900 `#2d2b2b`

Dark theme (default in the mocks):
- `--color-bg: #141817` · `--color-surface: #1e2423` · `--color-text: #eef3f0`
- `--color-divider: rgba(238,243,240,0.4)`
- Neutral ramp (inverted, cool-tinted): 100 `#242a28` · 200 `#313835` · 300 `#465049` · 400 `#5f6a64` · 500 `#8a948e` · 600 `#aab3ae` · 700 `#c8d0cb` · 800 `#e0e6e2` · 900 `#f2f6f3`

Accent — IKA green (primary actions, active nav, pass states, progress fills, gold-count emphasis):
- 100 `#e8f9ee` · 200 `#c8f1d6` · 300 `#8fdfae` · 400 `#3dbd6f` · **500 `#019a44` (base)** · 600 `#01813a` · 700 `#046830` · 800 `#084d26` · 900 `#0b371d`

Accent 2 — IKA blue (info badges, "blue corner", secondary emphasis):
- 100 `#e8f6fd` · 200 `#c8eafa` · 300 `#93d7f2` · 400 `#4fbce8` · **500 `#1a9fd8` (base)** · 600 `#0c81b4` · 700 `#086690` · 800 `#0c4c69` · 900 `#0f3749`

Danger — IKA red (Delete buttons' text, fail/"outside class" badges, warning counts):
- base `#d7263d` · 100 `#fdeaee` · 200 `#f9cdd4` · 700 `#8f1626`

Other:
- **Border radius: 0 everywhere** (override Flux defaults)
- Spacing scale: 4 / 8 / 12 / 16 / 24 / 32 px
- Shadows: sm `0 1px 2px rgba(45,43,43,.14)` · md `0 3px 10px rgba(45,43,43,.16)` (dark theme: black at .5/.55)
- Links: green 700 on light, green 400 on dark
- Focus: `outline: 2px solid #019a44; outline-offset: 2px`

## Typography
- One family: **Archivo** (Google Fonts, weights 400/600/800) for everything.
- Headings: weight 800, line-height ~1.1, letter-spacing -0.015em. H1 pages 36px; hero title 52px; card titles 24-25px.
- Body/UI: 13-15px, weight 400-600.
- "Kicker" label style (used everywhere for section labels, table-adjacent labels, stat captions): 10-11px, weight 800, uppercase, letter-spacing 0.12em.
- Numbers in tables: tabular/monospace for IDs (IKA IDs, fight numbers, URLs).

## Layout system (shared shell, every screen)
- Left sidebar 252px, `border-right: 2px solid divider`. Top: white chip (4px padding) holding the IKA logo + org name in 12.5px/800 uppercase. Nav groups titled with kickers ("PLATFORM", championship name). Items 13.5px/600, 7px 17px padding; **active item = 3px green left border + 14% green tinted background + weight 800**. Bottom: Repository/Documentation links, 2px rule, user row (30px green square with initial + name/role).
- Main column: top utility bar (breadcrumbs left, actions/theme toggle right, 2px bottom rule) → page header (green kicker, big H1, muted subtitle) → content column, max-width 1240px, 32px side padding, 20px gaps.
- Cards: `background: surface; border: 1px solid neutral-300; box-shadow: sm; radius 0`. Internal major sections separated by 2px divider rules.
- Stat strips: CSS grid of equal cells with `gap: 1px; background: neutral-300` (the 1px gaps read as a modular grid). Stat = 30-34px/800 number over kicker caption.

## Screens (→ Blade views to restyle)
1. **Categories** → `livewire/competition/categories.blade.php`. Signature screen. Full-width **green hero band** (`#019a44`, white text): logo chip, kicker "INTERNATIONAL KURASH ASSOCIATION · OFFICIAL CHAMPIONSHIP CONSOLE", 52px title, right-aligned big stats (age categories / athletes / weight classes). Below: tab row (Categories active with 3px green underline; Fight order; Mats & scoreboards; Medals) + "Export ▾" popover (Entries by weight / by NOC, PDF + Excel). "New age category" form is **collapsed behind a green "+ New age category" primary button**. Each age-category card: 25px title + gender tag (MEN = blue tag, WOMEN = green tag), muted "N athletes registered", actions right (Registration, Weigh-in secondary; Edit ghost; Delete ghost in red text); 2px rule; then a **weight-class grid** (auto-fit minmax 150px, 1px gaps): each cell = 22px/800 label, blue count right, and a 4px **capacity bar** (green fill, width = count/16).
2. **Dashboard** → `dashboard.blade.php`. One card per championship: title links in, "2 BOUTS ON MATS" green tag, 5-cell stat grid, "Bouts decided 98/156" 6px green progress bar, "NEXT" section (blue kicker) listing next steps with secondary buttons.
3. **Championships** → `championships.blade.php`. New-championship form card (Title/Location/Start date) + table (Title links, Location, Starts, Categories, Athletes, Edit/Delete ghost — Delete text in red).
4. **Archive** → `archive.blade.php`. "Ready to close" card (row with ALL DECIDED green tag + Archive primary); archived card with ARCHIVED neutral tag, top-3 NOC medal line, export ghost buttons, mono event log, Reopen ghost.
5. **Registration** → `registration.blade.php`. Accreditation ghost links; 6-field register form; searchable athlete table — weigh-in badges: green tint = passed weight shown ("65.8 KG"), red tint = fail, outline = NOT WEIGHED. NOC shown as bordered mono chip (e.g. UZB) + country.
6. **Weigh-in** → `weigh-in.blade.php`. Class filter select; per-row number input + Save; result badge PASSED (green) / OUTSIDE CLASS (red) / NOT WEIGHED (outline). Rule: pass if kg ≤ limit + 0.5; open (+) classes always pass.
7. **Entries** → `entries.blade.php`. 3 stat cells (ready-to-draw count in green); by-weight table with draw status: DONE green tag + Open ghost; NOT STARTED outline tag + green Start primary; NEEDS 2 CLEARED red tag. By-NOC table below.
8. **Fight order** → `fight-order.blade.php`. Controls card (min rest input, Build running order primary, Clear ghost, Hide finished checkbox, Print); unscheduled warning as red tag + text; bouts table: Blue athlete prefixed with 10px **blue square**, Green athlete with 10px **green square**; decided rows at 55% opacity with bold winner; per-row Mat 1/Mat 2 secondary buttons.
9. **Mats** → `courts.blade.php`. Add-mat form; table with ACTIVE (green) / INACTIVE (outline) badges; actions: Open mat (primary), Scoreboard (secondary), Test/Toggle/Edit ghost, Delete red.
10. **Medals** → `medals.blade.php`. Export rows; standings table (rank mono, NOC chip + name, **Gold column in green 800 weight**, Total bold); per-event podium cards: 4-column grid, each medal under a 2px top rule with GOLD kicker in green.

## Interactions & Behavior
- Buttons: primary = solid green, white text (hover green 600); secondary = neutral outline; ghost = borderless; destructive = ghost with red text + confirm dialog. Radius 0, weight 800 labels.
- Tags/badges: uppercase 10-11px/800, tinted fills (accent-200 bg + accent-800 text pattern), outline variant for neutral states.
- Light/dark toggle: mocks include a "Light mode / Dark mode" toggle in the utility bar — maps to the existing Flux appearance setting. **Dark is the default.**
- Hovers: nav/table rows tint with neutral-200; weight cells tint on hover; all links green.
- Keep all existing Livewire wiring (wire:model, wire:click, wire:confirm) — this is a reskin plus the Categories-page layout changes (hero band, collapsed form, weight-class capacity grid, export popover).

## Assets
- `IKA.png` — official logo (green/blue globes, red ring). Place at `kurash-manager/public/images/logo.png` (the app's `config/branding.php` already points there). Always show it on a white chip; never recolor.
- Archivo via Google Fonts (or self-host).

## Files in this bundle
- `README.md` — this spec
- `kurash-theme.css` — the exact token sheet (light + dark) used by the mocks
- `modernist-base.css` — the base design-system stylesheet (buttons, tags, inputs, cards, table styles the mocks build on)
- `IKA.png` — logo
- `*.dc.html` — the ten screen mocks (Categories, Dashboard, Championships, Archive, Registration, Weigh-in, Entries, Fight-Order, Mats, Medals, Sidebar)
