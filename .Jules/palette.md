## 2026-02-12 - Icon-Only Table Actions Need Explicit Names
**Learning:** Portfolio table actions can be represented with emoji-only buttons, which are visually clear but silent/ambiguous for screen readers without an explicit accessible name.
**Action:** For any icon-only action in data tables, always add a localized `aria-label` (and optional `title`) that includes record context like currency or item name.

## 2026-02-12 - Separate Link Color from Filled Button Color
**Learning:** A single primary token used for both link text and filled buttons can create conflicting contrast needs on dark UI; white-on-primary and primary-on-dark often require different shades.
**Action:** Keep a readable link color token and introduce a stronger companion token for filled interactive surfaces (active nav, primary button) to satisfy contrast in both contexts.
