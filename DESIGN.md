# Hive design system

Style: **Achromatic ledger**, dealt by the `design-taste-frontend` skill for an app screen, adapted to the spec's fixed left sidebar. Structural reference: the type scale and spacing rhythm of Linear's DESIGN.md from VoltAgent's awesome-design-md, used for structure only, not its colours or identity.

## The idea

Ink on a pale blue-grey ledger ground. The chrome holds no colour. One data hue, a deep ledger blue, marks the thing each screen is about: booked days on the rota, the forecast bar, the current month. Status colours appear only where a decision is needed, and always with words.

The face of the product is the **rota timeline**: people down the side, days across, every kind of booking with its own mark, and clashes outlined in red.

## Tokens

| Token | Light | Dark | Use |
|---|---|---|---|
| `--hive-ground` | `#f2f4f7` | `#0f1216` | Page ground (blue-grey ledger) |
| `--hive-surface` | `#ffffff` | `#171b21` | Cards, tables, panels |
| `--hive-raised` | `#eceff3` | `#20252d` | Hover, secondary buttons, weekends |
| `--hive-border` | `#e1e5ea` | `#2a3038` | Card and control borders |
| `--hive-rule` | `#eef1f4` | `#22272e` | Table rules, day lines |
| `--hive-ink` | `#0b0d10` | `#eef1f4` | Text, primary button |
| `--hive-muted` | `#5f6b7a` | `#98a3b1` | Secondary text |
| `--hive-data` | `#1d5fbf` | `#6ea0ec` | The one data hue |
| `--hive-positive` | `#2e7d32` | `#6cc070` | On track, approved |
| `--hive-warning` | `#92400e` | `#e0a160` | Watch, pending |
| `--hive-negative` | `#c4242a` | `#f07a7e` | At risk, clash, returned |

Radii are bimodal: 8px for cards, 6px for controls, 999px only for pills. No shadows at rest; the only shadow is on overlays (modals, offcanvas, toasts).

## Type

Atkinson Hyperlegible Next (self-hosted, OFL) at 400, 500 and 700. 700 is for money only. Atkinson Hyperlegible Mono only for codes: `EV-26114`, `PO-26-0033`, invoice numbers.

Why this face: the taste draw dealt Geist, and Impeccable's detector flagged Geist as an overused face. Atkinson Hyperlegible was drawn by the Braille Institute so that characters cannot be mistaken for each other (0 and O, 1, l and I). Crew read event codes, call times and PO numbers on a phone in a dark venue; the face is chosen for that job, and its figures are tabular.

| Role | Size / line height | Weight |
|---|---|---|
| Page title | 24 / 32 | 500 |
| Section title | 16 / 24 | 500 |
| Key figure | 30 / 36 | 400, or 700 for money |
| Body | 14 / 20 | 400 |
| Small, labels, table headers | 12 / 16 | 500 |

Tabular figures on every table, figure and anything that updates. Sentence case everywhere; no tracked capitals.

## Layout

- Sidebar 248px, grouped as in the spec, collapsing to an offcanvas below 992px. Active item: a neutral raised fill and ink text.
- Top bar 52px on the ground: search, Ask Hive, notifications, persona and user menu.
- Page: a title row with a one-sentence answer and the primary action, then the content. Forms cap at 720px; tables and the rota run full width.
- One gap value: 16px between blocks, 24px page padding (16px on phones).

## Components

- **Status pill**: pale tint with dark ink and the status text. Tone comes from the enum's `tone()`.
- **Figure**: label (12/500 muted), value (30/400), one plain caption. No icons, no sparklines.
- **Table**: 1px rules, no zebra, 12px header in muted ink, numbers right-aligned in tabular figures, 44px rows.
- **Empty state**: says what will appear here and how to add the first one, with the action.
- **Rota**: 200px name column grouped by department; 1px day lines; weekends one tone darker; today a 1px data-hue line. Bars 22px at radius 4. Confirmed: solid data hue. First hold: data-hue outline. Second hold: dashed outline. Leave: pale fill. Sick: diagonal hatch (only to those allowed to know). Unavailable: grey step. Clash: red 2px outline and a "Clash" label.

## Motion

Only in answer to an action: a toast slides in, a modal fades, a resolved clash row collapses. `prefers-reduced-motion` removes all of it.
