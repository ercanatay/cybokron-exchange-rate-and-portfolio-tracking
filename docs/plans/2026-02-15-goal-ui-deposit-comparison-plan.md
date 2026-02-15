# Goal UI & Deposit Comparison Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Improve goal edit form spacing/padding and add deposit interest comparison to each goal card.

**Architecture:** Server-side calculation in `Portfolio::computeGoalProgress()`. Admin configures deposit rate via settings. Each goal card shows a single comparison line below progress stats.

**Tech Stack:** PHP (backend), CSS (styling), MySQL (settings storage)

---

### Task 1: Goal Edit Form UI Spacing Improvements (CSS)

**Files:**
- Modify: `assets/css/style.css:2941-2949` (goal-edit-form styles)
- Modify: `assets/css/style.css:2497-2615` (goal-form styles)
- Modify: `assets/css/style.css:2742-2754` (goal-form-grid-4, goal-edit-actions)

**Step 1: Update goal-edit-form padding and spacing**

In `assets/css/style.css`, change the `.goal-edit-form` block (line ~2941):

```css
/* FROM: */
.goal-edit-form {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

/* TO: */
.goal-edit-form {
    margin-top: 16px;
    padding: 20px 16px 16px;
    border-top: 1px solid var(--border);
    background: var(--bg);
    border-radius: 0 0 14px 14px;
}
```

**Step 2: Update goal-form-grid-4 minimum column width**

Change `.goal-form-grid-4` (line ~2742):

```css
/* FROM: */
.goal-form-grid-4 {
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
}

/* TO: */
.goal-form-grid-4 {
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
}
```

**Step 3: Improve goal-form-field spacing**

Add after `.goal-form-field label` block (line ~2508):

```css
.goal-form-field {
    margin-bottom: 4px;
}
```

**Step 4: Update goal-edit-actions spacing**

Change `.goal-edit-actions` (line ~2750):

```css
/* FROM: */
.goal-edit-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

/* TO: */
.goal-edit-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border);
}
```

**Step 5: Add sources section spacing in edit form**

Add after `.goal-edit-form .goal-form-grid` block (line ~2947):

```css
.goal-edit-form .goal-sources-section {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px dashed var(--border);
}
```

**Step 6: Update edit form grid**

Change `.goal-edit-form .goal-form-grid` (line ~2947):

```css
/* FROM: */
.goal-edit-form .goal-form-grid {
    grid-template-columns: 2fr 1fr 1fr;
}

/* TO: */
.goal-edit-form .goal-form-grid {
    grid-template-columns: 2fr 1fr 1fr;
    gap: 14px 12px;
}
```

**Step 7: Verify visually**

Open https://servbay.host/cybokron-exchange-rate-and-portfolio-tracking/portfolio.php, click edit on a goal, confirm spacing improvements.

**Step 8: Commit**

```bash
git add assets/css/style.css
git commit -m "style: improve goal edit form spacing and padding"
```

---

### Task 2: Add Deposit Interest Rate Setting to Admin Panel

**Files:**
- Modify: `admin.php` (POST handler + form UI)
- Modify: `locales/tr.php` (admin labels)
- Modify: `locales/en.php` (admin labels)
- Modify: `locales/de.php` (admin labels)
- Modify: `locales/fr.php` (admin labels)
- Modify: `locales/ar.php` (admin labels)

**Step 1: Add POST handler in admin.php**

After the `set_retention_days` handler block (line ~145), add:

```php
    if ($_POST['action'] === 'save_deposit_rate' && isset($_POST['deposit_interest_rate'])) {
        $depositRate = max(0, min(200, (float) $_POST['deposit_interest_rate']));
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['deposit_interest_rate', (string) $depositRate, (string) $depositRate]
        );
        $message = t('admin.deposit_rate_updated');
        $messageType = 'success';
    }
```

**Step 2: Add settings read in admin.php**

After the `$retentionDaysRow` read (line ~218), add:

```php
$depositRateRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['deposit_interest_rate']);
$depositRateValue = $depositRateRow ? (float) $depositRateRow['value'] : 40.0;
```

**Step 3: Add action to allowed list**

In the allowed actions validation (line ~180), add `'save_deposit_rate'` to the array.

**Step 4: Add admin card UI**

After the Data Retention admin-card closing `</div>` (after line ~430), add:

```php
            <!-- Deposit Interest Rate -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #10b98120, #059e6020);">🏦</div>
                        <div>
                            <h2><?= t('admin.deposit_rate_title') ?></h2>
                            <p><?= t('admin.deposit_rate_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <form method="POST" class="settings-form">
                        <input type="hidden" name="action" value="save_deposit_rate">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="form-field">
                            <label for="deposit_interest_rate"><?= t('admin.deposit_rate_label') ?></label>
                            <input type="number" id="deposit_interest_rate" name="deposit_interest_rate"
                                   value="<?= $depositRateValue ?>" step="0.1" min="0" max="200"
                                   style="max-width: 120px">
                        </div>
                        <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                    </form>
                </div>
            </div>
```

**Step 5: Add locale strings**

In `locales/tr.php`, add after existing admin strings:

```php
    'admin.deposit_rate_title' => 'Mevduat Faiz Oranı',
    'admin.deposit_rate_desc' => 'Hedef karşılaştırması için kullanılacak yıllık net mevduat faiz oranı.',
    'admin.deposit_rate_label' => 'Yıllık Net Faiz Oranı (%)',
    'admin.deposit_rate_updated' => 'Mevduat faiz oranı güncellendi.',
```

In `locales/en.php`:

```php
    'admin.deposit_rate_title' => 'Deposit Interest Rate',
    'admin.deposit_rate_desc' => 'Annual net deposit interest rate used for goal comparison.',
    'admin.deposit_rate_label' => 'Annual Net Interest Rate (%)',
    'admin.deposit_rate_updated' => 'Deposit interest rate updated.',
```

In `locales/de.php`:

```php
    'admin.deposit_rate_title' => 'Einlagenzinssatz',
    'admin.deposit_rate_desc' => 'Jährlicher Nettozinssatz für Zielvergleich.',
    'admin.deposit_rate_label' => 'Jährlicher Nettozinssatz (%)',
    'admin.deposit_rate_updated' => 'Einlagenzinssatz aktualisiert.',
```

In `locales/fr.php`:

```php
    'admin.deposit_rate_title' => 'Taux d\'intérêt du dépôt',
    'admin.deposit_rate_desc' => 'Taux d\'intérêt net annuel utilisé pour la comparaison des objectifs.',
    'admin.deposit_rate_label' => 'Taux d\'intérêt net annuel (%)',
    'admin.deposit_rate_updated' => 'Taux d\'intérêt du dépôt mis à jour.',
```

In `locales/ar.php`:

```php
    'admin.deposit_rate_title' => 'سعر فائدة الودائع',
    'admin.deposit_rate_desc' => 'سعر الفائدة الصافي السنوي المستخدم لمقارنة الأهداف.',
    'admin.deposit_rate_label' => 'سعر الفائدة الصافي السنوي (%)',
    'admin.deposit_rate_updated' => 'تم تحديث سعر فائدة الودائع.',
```

**Step 6: Verify**

Navigate to admin panel, confirm the deposit rate card appears and saves correctly.

**Step 7: Commit**

```bash
git add admin.php locales/tr.php locales/en.php locales/de.php locales/fr.php locales/ar.php
git commit -m "feat: add deposit interest rate setting to admin panel"
```

---

### Task 3: Backend Deposit Comparison Calculation

**Files:**
- Modify: `includes/Portfolio.php:1151-1505` (computeGoalProgress method)

**Step 1: Read deposit rate from settings at the start of computeGoalProgress**

After the pre-indexing blocks (after line ~1170), add:

```php
        // Deposit comparison: read annual net interest rate from settings
        $depositRateRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['deposit_interest_rate']);
        $depositAnnualRate = $depositRateRow ? (float) $depositRateRow['value'] : 40.0;
        $today = new DateTime();
```

**Step 2: Add deposit calculation to the value/cost/amount/currency_value branch**

In the main foreach loop where items are accumulated (the block starting at line ~1436), we need to accumulate deposit values. Before that loop, add:

```php
            $depositTotal = 0.0;
```

Inside the loop, after `$countedItems++` in each branch (value, cost, amount, currency_value), add deposit calculation:

```php
                // Deposit comparison: compound interest from buy_date to today
                $buyDateStr = $item['buy_date'] ?? '';
                if ($buyDateStr && $depositAnnualRate > 0) {
                    $buyDateObj = DateTime::createFromFormat('Y-m-d', $buyDateStr);
                    if ($buyDateObj) {
                        $daysDiff = max(0, (int) $buyDateObj->diff($today)->days);
                        $itemCost = (float) ($item['cost_try'] ?? 0);
                        $depositTotal += $itemCost * pow(1 + $depositAnnualRate / 100, $daysDiff / 365);
                    }
                }
```

**Step 3: Add deposit fields to all result arrays**

For each `$result[$goalId] = [...]` block (there are 4 of them — percent, cagr, drawdown, and default), add these fields:

```php
                    'deposit_value' => round($depositTotal, 2),
                    'deposit_rate' => $depositAnnualRate,
```

Note: For percent/cagr/drawdown blocks that have their own item loops, you need to add the `$depositTotal = 0.0;` init and the deposit calculation inside their respective item loops too.

**Step 4: Verify**

Run the portfolio page and check PHP logs for errors:
```bash
tail -f /Applications/ServBay/log/php/error.log
```

**Step 5: Commit**

```bash
git add includes/Portfolio.php
git commit -m "feat: calculate deposit comparison in computeGoalProgress"
```

---

### Task 4: Frontend Deposit Comparison Display

**Files:**
- Modify: `portfolio.php:1138-1172` (goal card progress stats area)
- Modify: `assets/css/style.css` (new .goal-deposit-comparison styles)
- Modify: `locales/tr.php`, `locales/en.php`, `locales/de.php`, `locales/fr.php`, `locales/ar.php`

**Step 1: Add locale strings for deposit comparison**

In `locales/tr.php`:

```php
    'portfolio.goals.deposit_label' => 'Mevduatta olsaydı',
    'portfolio.goals.deposit_better' => 'fark',
    'portfolio.goals.deposit_worse' => 'avantaj',
```

In `locales/en.php`:

```php
    'portfolio.goals.deposit_label' => 'If in deposit',
    'portfolio.goals.deposit_better' => 'gap',
    'portfolio.goals.deposit_worse' => 'advantage',
```

In `locales/de.php`:

```php
    'portfolio.goals.deposit_label' => 'Als Einlage',
    'portfolio.goals.deposit_better' => 'Differenz',
    'portfolio.goals.deposit_worse' => 'Vorteil',
```

In `locales/fr.php`:

```php
    'portfolio.goals.deposit_label' => 'En dépôt',
    'portfolio.goals.deposit_better' => 'écart',
    'portfolio.goals.deposit_worse' => 'avantage',
```

In `locales/ar.php`:

```php
    'portfolio.goals.deposit_label' => 'لو كان في وديعة',
    'portfolio.goals.deposit_better' => 'فرق',
    'portfolio.goals.deposit_worse' => 'ميزة',
```

**Step 2: Add comparison HTML to portfolio.php**

After the `goal-progress-stats` closing `</div>` (line ~1171), add:

```php
                                            <?php if (($gp['deposit_value'] ?? 0) > 0 && $gp['item_count'] > 0): ?>
                                                <?php
                                                    $depositValue = (float) $gp['deposit_value'];
                                                    $currentValue = (float) $gp['current'];
                                                    // For percent/cagr/drawdown goals, compare using cost basis
                                                    $compareValue = in_array($goalTargetType, ['percent', 'cagr', 'drawdown'])
                                                        ? $depositValue  // just show deposit value
                                                        : $currentValue;
                                                    $depositDiff = $depositValue - $compareValue;
                                                    $depositBetter = $depositDiff > 0;
                                                ?>
                                                <div class="goal-deposit-comparison <?= $depositBetter ? 'deposit-better' : 'deposit-worse' ?>">
                                                    🏦 <?= t('portfolio.goals.deposit_label') ?>: <?= formatTRY($depositValue) ?>
                                                    <span class="deposit-diff">(<?= $depositBetter ? '+' : '' ?><?= formatTRY($depositDiff) ?> <?= $depositBetter ? t('portfolio.goals.deposit_better') : t('portfolio.goals.deposit_worse') ?>)</span>
                                                </div>
                                            <?php endif; ?>
```

**Step 3: Add CSS styles**

In `assets/css/style.css`, after `.goal-complete` (line ~2921), add:

```css
/* Goal Deposit Comparison */
.goal-deposit-comparison {
    font-size: 0.72rem;
    padding: 4px 0 0;
    margin-top: 4px;
    border-top: 1px dashed var(--border);
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

.goal-deposit-comparison.deposit-better {
    color: #dc2626;
}

.goal-deposit-comparison.deposit-worse {
    color: #16a34a;
}

.deposit-diff {
    font-weight: 600;
    font-family: 'JetBrains Mono', monospace;
}
```

**Step 4: Verify visually**

Open portfolio page, check that deposit comparison lines appear below progress stats on goal cards.

**Step 5: Commit**

```bash
git add portfolio.php assets/css/style.css locales/tr.php locales/en.php locales/de.php locales/fr.php locales/ar.php
git commit -m "feat: display deposit interest comparison on goal cards"
```

---

### Task 5: Final Verification & Cleanup

**Step 1: Full page test**

- Open portfolio page
- Verify goal edit form has better spacing
- Verify deposit comparison lines appear on goal cards
- Verify admin deposit rate setting works
- Change deposit rate and confirm goal cards update

**Step 2: Mobile responsive check**

Resize browser to mobile width, verify deposit comparison doesn't break layout.

**Step 3: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix: final adjustments for goal UI and deposit comparison"
```
