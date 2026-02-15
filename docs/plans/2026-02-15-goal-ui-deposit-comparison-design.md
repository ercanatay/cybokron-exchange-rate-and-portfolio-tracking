# Goal UI Improvement & Deposit Interest Comparison

**Date:** 2026-02-15
**Status:** Approved

## 1. Goal Edit Form UI Improvements

### Problem
Edit form spacing is too tight - elements feel cramped with insufficient padding, gaps, and margins.

### Changes
- Increase `goal-edit-form` padding from 12px to 20px
- Increase `goal-form-grid-4` min column width from 160px to 200px
- Add margin between form grid and sources section
- Increase `goal-edit-actions` spacing
- Improve label-input gap in form fields
- Better overall gap values in edit form

## 2. Deposit Interest Comparison Feature

### Overview
Show a single-line comparison on each goal card: "What if this money was in a deposit account instead?"

### Data Flow
1. Admin sets annual net deposit interest rate in settings (default: 40%)
2. `computeGoalProgress()` calculates deposit equivalent for each goal
3. For each portfolio item in a goal's sources:
   - `days = today - buy_date`
   - `deposit_return = cost_try * (1 + rate/100) ^ (days/365)`
4. Sum all deposit returns per goal
5. Display comparison line below progress stats

### Database
- `settings` table: key `deposit_interest_rate`, value `40`

### Backend
- `Portfolio::computeGoalProgress()` returns additional fields per goal:
  - `deposit_value`: Total value if money was in deposit
  - `deposit_diff`: `deposit_value - current_value`
  - `deposit_rate`: Interest rate used

### Frontend
- Single line below `goal-progress-stats`:
  - If deposit better: `"Mevduatta: 125.000 TL (+15.000 TL fark)"` (red tint)
  - If portfolio better: `"Mevduatta: 95.000 TL (-15.000 TL avantaj)"` (green tint)
- CSS class: `.goal-deposit-comparison`

### Admin Panel
- Add "Deposit Interest Rate (%)" field to settings

### Localization
- All 5 locale files updated (tr, en, de, fr, ar)
