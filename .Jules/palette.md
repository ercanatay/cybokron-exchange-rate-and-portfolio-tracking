## 2026-02-12 - Icon-Only Table Actions Need Explicit Names
**Learning:** Portfolio table actions can be represented with emoji-only buttons, which are visually clear but silent/ambiguous for screen readers without an explicit accessible name.
**Action:** For any icon-only action in data tables, always add a localized `aria-label` (and optional `title`) that includes record context like currency or item name.
