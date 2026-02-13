<?php
/**
 * Portfolio.php — Portfolio Management
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class Portfolio
{
    /**
     * Get all portfolio entries with current rates and P/L.
     * When Auth is active and user is not admin, filters by user_id.
     */
    public static function getAll(): array
    {
        $where = 'p.deleted_at IS NULL';
        $params = [];

        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (p.user_id IS NULL OR p.user_id = ?)';
                $params[] = $userId;
            }
        }

        $sql = "
            SELECT
                p.id,
                p.amount,
                p.buy_rate,
                p.buy_date,
                p.notes,
                p.group_id,
                c.code AS currency_code,
                c.name_tr AS currency_name_tr,
                c.name_en AS currency_name_en,
                c.name_tr AS currency_name,
                c.symbol AS currency_symbol,
                c.type AS currency_type,
                b.name AS bank_name,
                b.slug AS bank_slug,
                r.sell_rate AS current_rate,
                r.scraped_at AS rate_updated_at,
                g.name AS group_name,
                g.slug AS group_slug,
                g.color AS group_color,
                g.icon AS group_icon,
                (p.amount * p.buy_rate) AS cost_try,
                (p.amount * COALESCE(r.sell_rate, p.buy_rate)) AS value_try,
                ((COALESCE(r.sell_rate, p.buy_rate) - p.buy_rate) / p.buy_rate * 100) AS profit_percent
            FROM portfolio p
            JOIN currencies c ON c.id = p.currency_id
            LEFT JOIN banks b ON b.id = p.bank_id
            LEFT JOIN rates r ON r.currency_id = p.currency_id AND r.bank_id = p.bank_id
            LEFT JOIN portfolio_groups g ON g.id = p.group_id
            WHERE {$where}
            ORDER BY p.buy_date DESC
        ";
        return Database::query($sql, $params);
    }

    /**
     * Get portfolio summary (totals).
     */
    public static function getSummary(): array
    {
        $items = self::getAll();

        $totalCost = 0.0;
        $totalValue = 0.0;

        foreach ($items as $item) {
            $totalCost += (float) $item['cost_try'];
            $totalValue += (float) $item['value_try'];
        }

        $profitLoss = $totalValue - $totalCost;
        $profitPercent = $totalCost > 0 ? ($profitLoss / $totalCost * 100) : 0;

        return [
            'items' => $items,
            'total_cost' => round($totalCost, 2),
            'total_value' => round($totalValue, 2),
            'profit_loss' => round($profitLoss, 2),
            'profit_percent' => round($profitPercent, 2),
            'item_count' => count($items),
        ];
    }

    /**
     * Add a new portfolio entry.
     */
    public static function add(array $data): int
    {
        $currencyCode = strtoupper(trim((string) ($data['currency_code'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{3,10}$/', $currencyCode)) {
            throw new InvalidArgumentException('Invalid currency_code format');
        }

        $amount = self::normalizePositiveDecimal($data['amount'] ?? null, 'amount');
        $buyRate = self::normalizePositiveDecimal($data['buy_rate'] ?? null, 'buy_rate');
        $buyDate = self::normalizeDate((string) ($data['buy_date'] ?? ''));

        $notes = trim((string) ($data['notes'] ?? ''));
        if ($notes !== '' && mb_strlen($notes, 'UTF-8') > 500) {
            throw new InvalidArgumentException('notes exceeds maximum length of 500 characters');
        }
        $notes = $notes !== '' ? $notes : null;

        $currency = Database::queryOne(
            'SELECT id FROM currencies WHERE code = ? AND is_active = 1',
            [$currencyCode]
        );
        if (!$currency) {
            throw new InvalidArgumentException("Unknown currency: {$currencyCode}");
        }

        $bankId = null;
        $bankSlug = trim((string) ($data['bank_slug'] ?? ''));
        if ($bankSlug !== '') {
            if (!preg_match('/^[a-z0-9-]{1,100}$/', $bankSlug)) {
                throw new InvalidArgumentException('Invalid bank_slug format');
            }

            $bank = Database::queryOne(
                'SELECT id FROM banks WHERE slug = ? AND is_active = 1',
                [$bankSlug]
            );

            if (!$bank) {
                throw new InvalidArgumentException("Unknown bank: {$bankSlug}");
            }

            $bankId = (int) $bank['id'];
        }

        $userId = null;
        if (class_exists('Auth') && Auth::check()) {
            $userId = Auth::id();
        }

        $groupId = null;
        if (!empty($data['group_id'])) {
            $gid = (int) $data['group_id'];
            if ($gid > 0) {
                $group = Database::queryOne('SELECT id FROM portfolio_groups WHERE id = ?', [$gid]);
                if ($group) {
                    $groupId = $gid;
                }
            }
        }

        return Database::insert('portfolio', [
            'user_id' => $userId,
            'currency_id' => (int) $currency['id'],
            'bank_id' => $bankId,
            'group_id' => $groupId,
            'amount' => $amount,
            'buy_rate' => $buyRate,
            'buy_date' => $buyDate,
            'notes' => $notes,
        ]);
    }

    /**
     * Update a portfolio entry.
     */
    public static function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid portfolio id');
        }

        $update = [];

        if (isset($data['amount'])) {
            $update['amount'] = self::normalizePositiveDecimal($data['amount'], 'amount');
        }

        if (isset($data['buy_rate'])) {
            $update['buy_rate'] = self::normalizePositiveDecimal($data['buy_rate'], 'buy_rate');
        }

        if (isset($data['buy_date'])) {
            $update['buy_date'] = self::normalizeDate((string) $data['buy_date']);
        }

        if (isset($data['notes'])) {
            $notes = trim((string) $data['notes']);
            if ($notes !== '' && mb_strlen($notes, 'UTF-8') > 500) {
                throw new InvalidArgumentException('notes exceeds maximum length of 500 characters');
            }
            $update['notes'] = $notes !== '' ? $notes : null;
        }

        if (isset($data['bank_slug'])) {
            $slug = trim((string) $data['bank_slug']);
            if ($slug !== '') {
                if (!preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
                    throw new InvalidArgumentException('Invalid bank_slug format');
                }
                $bank = Database::queryOne('SELECT id FROM banks WHERE slug = ? AND is_active = 1', [$slug]);
                if (!$bank) {
                    throw new InvalidArgumentException('Invalid bank_slug');
                }
                $update['bank_id'] = (int) $bank['id'];
            } else {
                $update['bank_id'] = null;
            }
        }

        if (array_key_exists('group_id', $data)) {
            if ($data['group_id'] === '' || $data['group_id'] === null) {
                $update['group_id'] = null;
            } else {
                $gid = (int) $data['group_id'];
                if ($gid > 0) {
                    $group = Database::queryOne('SELECT id FROM portfolio_groups WHERE id = ?', [$gid]);
                    if ($group) {
                        $update['group_id'] = $gid;
                    }
                }
            }
        }

        if (empty($update)) {
            return false;
        }

        $where = 'id = ?';
        $params = [$id];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (user_id IS NULL OR user_id = ?)';
                $params[] = $userId;
            }
        }

        return Database::update('portfolio', $update, $where, $params) > 0;
    }

    /**
     * Delete a portfolio entry (soft delete when deleted_at column exists).
     */
    public static function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $where = 'id = ?';
        $params = [$id];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (user_id IS NULL OR user_id = ?)';
                $params[] = $userId;
            }
        }

        try {
            $updated = Database::update('portfolio', ['deleted_at' => date('Y-m-d H:i:s')], $where, $params);
            return $updated > 0;
        } catch (Throwable $e) {
            $hardWhere = 'id = ?';
            $hardParams = [$id];
            if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
                $userId = Auth::id();
                if ($userId !== null) {
                    $hardWhere .= ' AND (user_id IS NULL OR user_id = ?)';
                    $hardParams[] = $userId;
                }
            }
            return Database::execute('DELETE FROM portfolio WHERE ' . $hardWhere, $hardParams) > 0;
        }
    }

    /**
     * Validate numeric positive amount.
     */
    private static function normalizePositiveDecimal($value, string $field): float
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            throw new InvalidArgumentException("{$field} must be numeric");
        }

        $normalized = (float) $value;
        if ($normalized <= 0 || $normalized > 999999999999.999999) {
            throw new InvalidArgumentException("{$field} out of allowed range");
        }

        return $normalized;
    }

    /**
     * Validate Y-m-d date.
     */
    private static function normalizeDate(string $value): string
    {
        $date = trim($value);
        if ($date === '') {
            throw new InvalidArgumentException('buy_date is required');
        }

        // Validate format with regex first
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('buy_date must be in Y-m-d format');
        }

        // Validate it's a real date
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('buy_date must be a valid date');
        }

        return $date;
    }

    // ─── Group Management ─────────────────────────────────────────────────

    /**
     * Get all groups for current user.
     */
    public static function getGroups(): array
    {
        $where = '1=1';
        $params = [];

        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (user_id IS NULL OR user_id = ?)';
                $params[] = $userId;
            }
        }

        return Database::query(
            "SELECT g.*, (SELECT COUNT(*) FROM portfolio p WHERE p.group_id = g.id AND p.deleted_at IS NULL) AS item_count
             FROM portfolio_groups g WHERE {$where} ORDER BY g.name",
            $params
        );
    }

    /**
     * Add a new group.
     */
    public static function addGroup(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('Group name is required (max 100 chars)');
        }

        $slug = self::generateSlug($name);

        $color = trim((string) ($data['color'] ?? '#3b82f6'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#3b82f6';
        }

        $icon = trim((string) ($data['icon'] ?? ''));
        $icon = $icon !== '' ? mb_substr($icon, 0, 10, 'UTF-8') : null;

        $userId = null;
        if (class_exists('Auth') && Auth::check()) {
            $userId = Auth::id();
        }

        return Database::insert('portfolio_groups', [
            'user_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'color' => $color,
            'icon' => $icon,
        ]);
    }

    /**
     * Update a group.
     */
    public static function updateGroup(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }

        $update = [];

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
                throw new InvalidArgumentException('Group name is required (max 100 chars)');
            }
            $update['name'] = $name;
            $update['slug'] = self::generateSlug($name);
        }

        if (isset($data['color'])) {
            $color = trim((string) $data['color']);
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $update['color'] = $color;
            }
        }

        if (array_key_exists('icon', $data)) {
            $icon = trim((string) $data['icon']);
            $update['icon'] = $icon !== '' ? mb_substr($icon, 0, 10, 'UTF-8') : null;
        }

        if (empty($update)) {
            return false;
        }

        $where = 'id = ?';
        $params = [$id];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (user_id IS NULL OR user_id = ?)';
                $params[] = $userId;
            }
        }

        return Database::update('portfolio_groups', $update, $where, $params) > 0;
    }

    /**
     * Delete a group. Items in the group are unlinked (group_id set to NULL).
     */
    public static function deleteGroup(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $where = 'id = ?';
        $params = [$id];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (user_id IS NULL OR user_id = ?)';
                $params[] = $userId;
            }
        }

        // Unlink portfolio items first
        Database::execute('UPDATE portfolio SET group_id = NULL WHERE group_id = ?', [$id]);

        return Database::execute('DELETE FROM portfolio_groups WHERE ' . $where, $params) > 0;
    }

    /**
     * Generate a URL-safe slug from a name.
     */
    private static function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        // Transliterate common Turkish characters
        $map = [
            'ç' => 'c',
            'ğ' => 'g',
            'ı' => 'i',
            'ö' => 'o',
            'ş' => 's',
            'ü' => 'u',
            'Ç' => 'c',
            'Ğ' => 'g',
            'İ' => 'i',
            'Ö' => 'o',
            'Ş' => 's',
            'Ü' => 'u'
        ];
        $slug = strtr($slug, $map);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? mb_substr($slug, 0, 100, 'UTF-8') : 'group-' . time();
    }
}
