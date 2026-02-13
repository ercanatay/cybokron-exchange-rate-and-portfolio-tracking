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

    // ─── Bulk Group Operations ─────────────────────────────────────────

    /**
     * Bulk assign items to a group.
     */
    public static function bulkAssignGroup(array $itemIds, ?int $groupId): int
    {
        if (empty($itemIds)) {
            return 0;
        }
        $ids = array_map('intval', $itemIds);
        $ids = array_filter($ids, fn($id) => $id > 0);
        if (empty($ids)) {
            return 0;
        }

        // Validate group exists if not null
        if ($groupId !== null && $groupId > 0) {
            $group = Database::queryOne('SELECT id FROM portfolio_groups WHERE id = ?', [$groupId]);
            if (!$group) {
                return 0;
            }
        } else {
            $groupId = null;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = [$groupId];
        $where = "id IN ({$placeholders})";
        $params = array_merge($params, $ids);

        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (user_id IS NULL OR user_id = ?)';
                $params[] = $userId;
            }
        }

        return Database::execute(
            "UPDATE portfolio SET group_id = ? WHERE {$where} AND deleted_at IS NULL",
            $params
        );
    }

    // ─── Tag Management ─────────────────────────────────────────────────

    /**
     * Get all tags for current user.
     */
    public static function getTags(): array
    {
        $where = '1=1';
        $params = [];

        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (t.user_id IS NULL OR t.user_id = ?)';
                $params[] = $userId;
            }
        }

        return Database::query(
            "SELECT t.*, (SELECT COUNT(*) FROM portfolio_tag_items pti
              JOIN portfolio p ON p.id = pti.portfolio_id AND p.deleted_at IS NULL
              WHERE pti.tag_id = t.id) AS item_count
             FROM portfolio_tags t WHERE {$where} ORDER BY t.name",
            $params
        );
    }

    /**
     * Add a new tag.
     */
    public static function addTag(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 50) {
            throw new InvalidArgumentException('Tag name is required (max 50 chars)');
        }

        $slug = self::generateSlug($name);

        $color = trim((string) ($data['color'] ?? '#8b5cf6'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#8b5cf6';
        }

        $userId = null;
        if (class_exists('Auth') && Auth::check()) {
            $userId = Auth::id();
        }

        return Database::insert('portfolio_tags', [
            'user_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'color' => $color,
        ]);
    }

    /**
     * Update a tag.
     */
    public static function updateTag(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }

        $update = [];

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 50) {
                throw new InvalidArgumentException('Tag name is required (max 50 chars)');
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

        return Database::update('portfolio_tags', $update, $where, $params) > 0;
    }

    /**
     * Delete a tag and all its item associations.
     */
    public static function deleteTag(int $id): bool
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

        // Remove pivot records
        Database::execute('DELETE FROM portfolio_tag_items WHERE tag_id = ?', [$id]);

        return Database::execute('DELETE FROM portfolio_tags WHERE ' . $where, $params) > 0;
    }

    /**
     * Assign a tag to a portfolio item (ignores duplicates).
     */
    public static function assignTag(int $portfolioId, int $tagId): bool
    {
        if ($portfolioId <= 0 || $tagId <= 0) {
            return false;
        }
        // Check if already exists
        $existing = Database::queryOne(
            'SELECT id FROM portfolio_tag_items WHERE portfolio_id = ? AND tag_id = ?',
            [$portfolioId, $tagId]
        );
        if ($existing) {
            return true; // already assigned
        }
        Database::insert('portfolio_tag_items', [
            'portfolio_id' => $portfolioId,
            'tag_id' => $tagId,
        ]);
        return true;
    }

    /**
     * Remove a tag from a portfolio item.
     */
    public static function removeTag(int $portfolioId, int $tagId): bool
    {
        return Database::execute(
            'DELETE FROM portfolio_tag_items WHERE portfolio_id = ? AND tag_id = ?',
            [$portfolioId, $tagId]
        ) > 0;
    }

    /**
     * Bulk assign a tag to multiple portfolio items.
     */
    public static function bulkAssignTag(array $itemIds, int $tagId): int
    {
        if (empty($itemIds) || $tagId <= 0) {
            return 0;
        }
        $count = 0;
        foreach ($itemIds as $id) {
            $id = (int) $id;
            if ($id > 0 && self::assignTag($id, $tagId)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Bulk remove a tag from multiple portfolio items.
     */
    public static function bulkRemoveTag(array $itemIds, int $tagId): int
    {
        if (empty($itemIds) || $tagId <= 0) {
            return 0;
        }
        $count = 0;
        foreach ($itemIds as $id) {
            $id = (int) $id;
            if ($id > 0 && self::removeTag($id, $tagId)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get all tags for all portfolio items (keyed by portfolio_id).
     */
    public static function getAllItemTags(): array
    {
        $sql = "SELECT pti.portfolio_id, t.id AS tag_id, t.name, t.slug, t.color
                FROM portfolio_tag_items pti
                JOIN portfolio_tags t ON t.id = pti.tag_id
                ORDER BY t.name";
        $rows = Database::query($sql);
        $result = [];
        foreach ($rows as $row) {
            $pid = (int) $row['portfolio_id'];
            $result[$pid][] = [
                'id' => (int) $row['tag_id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'color' => $row['color'],
            ];
        }
        return $result;
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

    // ──────────────────────────────────────────────────────────────────────
    // Goals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Get all goals for the current user.
     */
    public static function getGoals(): array
    {
        $where = '1=1';
        $params = [];

        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND (g.user_id IS NULL OR g.user_id = ?)';
                $params[] = $userId;
            }
        }

        return Database::query(
            "SELECT g.* FROM portfolio_goals g WHERE {$where} ORDER BY g.created_at DESC",
            $params
        );
    }

    /**
     * Get a single goal by ID.
     */
    public static function getGoal(int $id): ?array
    {
        return Database::queryOne('SELECT * FROM portfolio_goals WHERE id = ?', [$id]) ?: null;
    }

    /**
     * Valid target types for goals.
     */
    private const GOAL_TARGET_TYPES = ['value', 'cost', 'amount'];

    /**
     * Add a new goal.
     */
    public static function addGoal(array $data): int
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Goal name is required.');
        }
        $targetValue = (float) ($data['target_value'] ?? 0);
        if ($targetValue <= 0) {
            throw new InvalidArgumentException('Target value must be positive.');
        }
        $targetType = in_array($data['target_type'] ?? '', self::GOAL_TARGET_TYPES)
            ? $data['target_type'] : 'value';

        // target_currency is required for 'amount' type
        $targetCurrency = null;
        if ($targetType === 'amount') {
            $tc = strtoupper(trim((string) ($data['target_currency'] ?? '')));
            if ($tc === '' || !preg_match('/^[A-Z0-9]{3,10}$/', $tc)) {
                throw new InvalidArgumentException('Currency is required for amount goals.');
            }
            $targetCurrency = $tc;
        }

        $goalId = Database::insert('portfolio_goals', [
            'user_id' => (class_exists('Auth') && Auth::check()) ? Auth::id() : null,
            'name' => $name,
            'target_value' => $targetValue,
            'target_type' => $targetType,
            'target_currency' => $targetCurrency,
        ]);

        return (int) $goalId;
    }

    /**
     * Update an existing goal.
     */
    public static function updateGoal(int $id, array $data): bool
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Goal name is required.');
        }
        $targetValue = (float) ($data['target_value'] ?? 0);
        if ($targetValue <= 0) {
            throw new InvalidArgumentException('Target value must be positive.');
        }
        $targetType = in_array($data['target_type'] ?? '', self::GOAL_TARGET_TYPES)
            ? $data['target_type'] : 'value';

        $targetCurrency = null;
        if ($targetType === 'amount') {
            $tc = strtoupper(trim((string) ($data['target_currency'] ?? '')));
            if ($tc === '' || !preg_match('/^[A-Z0-9]{3,10}$/', $tc)) {
                throw new InvalidArgumentException('Currency is required for amount goals.');
            }
            $targetCurrency = $tc;
        }

        return Database::execute(
            'UPDATE portfolio_goals SET name = ?, target_value = ?, target_type = ?, target_currency = ? WHERE id = ?',
            [$name, $targetValue, $targetType, $targetCurrency, $id]
        ) >= 0;
    }

    /**
     * Delete a goal and all its sources.
     */
    public static function deleteGoal(int $id): bool
    {
        return Database::execute('DELETE FROM portfolio_goals WHERE id = ?', [$id]) > 0;
    }

    /**
     * Get all sources for a goal.
     */
    public static function getGoalSources(int $goalId): array
    {
        return Database::query(
            'SELECT * FROM portfolio_goal_sources WHERE goal_id = ? ORDER BY source_type, source_id',
            [$goalId]
        );
    }

    /**
     * Get all sources for all goals, keyed by goal_id.
     */
    public static function getAllGoalSources(): array
    {
        $rows = Database::query('SELECT * FROM portfolio_goal_sources ORDER BY source_type, source_id');
        $result = [];
        foreach ($rows as $row) {
            $gid = (int) $row['goal_id'];
            $result[$gid][] = $row;
        }
        return $result;
    }

    /**
     * Add a source to a goal.
     */
    public static function addGoalSource(int $goalId, string $sourceType, int $sourceId): bool
    {
        if (!in_array($sourceType, ['group', 'tag', 'item'])) {
            return false;
        }
        // Check for duplicate
        $existing = Database::queryOne(
            'SELECT id FROM portfolio_goal_sources WHERE goal_id = ? AND source_type = ? AND source_id = ?',
            [$goalId, $sourceType, $sourceId]
        );
        if ($existing) {
            return true; // already exists
        }
        Database::insert('portfolio_goal_sources', [
            'goal_id' => $goalId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        return true;
    }

    /**
     * Remove a source from a goal.
     */
    public static function removeGoalSource(int $goalId, string $sourceType, int $sourceId): bool
    {
        return Database::execute(
            'DELETE FROM portfolio_goal_sources WHERE goal_id = ? AND source_type = ? AND source_id = ?',
            [$goalId, $sourceType, $sourceId]
        ) > 0;
    }

    /**
     * Compute goal progress for all goals.
     * Deduplicates items: an item matching multiple sources is counted only once.
     *
     * Target types:
     *   'value'  — sum current TRY value (value_try)
     *   'cost'   — sum TRY cost (cost_try)
     *   'amount' — sum raw amount of a specific currency (target_currency)
     *
     * @param array $goals          All goals
     * @param array $allItems       All portfolio items from Portfolio::getAll()
     * @param array $allItemTags    All item tags from Portfolio::getAllItemTags()
     * @param array $allGoalSources Goal sources keyed by goal_id
     * @return array Keyed by goal_id => ['current' => float, 'target' => float, 'percent' => float, 'item_count' => int, 'unit' => string]
     */
    public static function computeGoalProgress(array $goals, array $allItems, array $allItemTags, array $allGoalSources): array
    {
        $result = [];

        foreach ($goals as $goal) {
            $goalId = (int) $goal['id'];
            $targetType = $goal['target_type'] ?? 'value';
            $targetCurrency = $goal['target_currency'] ?? null;
            $sources = $allGoalSources[$goalId] ?? [];

            // Collect unique item IDs matching any source
            $matchedItemIds = [];

            foreach ($sources as $src) {
                $sType = $src['source_type'];
                $sId = (int) $src['source_id'];

                if ($sType === 'item') {
                    $matchedItemIds[$sId] = true;
                } elseif ($sType === 'group') {
                    foreach ($allItems as $item) {
                        if ((int) ($item['group_id'] ?? 0) === $sId) {
                            $matchedItemIds[(int) $item['id']] = true;
                        }
                    }
                } elseif ($sType === 'tag') {
                    foreach ($allItems as $item) {
                        $tags = $allItemTags[(int) $item['id']] ?? [];
                        foreach ($tags as $t) {
                            if ((int) $t['id'] === $sId) {
                                $matchedItemIds[(int) $item['id']] = true;
                                break;
                            }
                        }
                    }
                }
            }

            // Sum values for matched items (deduplicated)
            $current = 0.0;
            $countedItems = 0;
            foreach ($allItems as $item) {
                if (!isset($matchedItemIds[(int) $item['id']])) {
                    continue;
                }

                if ($targetType === 'amount') {
                    // Only count items with the matching currency
                    if ($targetCurrency !== null && strtoupper($item['currency_code'] ?? '') === strtoupper($targetCurrency)) {
                        $current += (float) ($item['amount'] ?? 0);
                        $countedItems++;
                    }
                } elseif ($targetType === 'cost') {
                    $current += (float) ($item['cost_try'] ?? 0);
                    $countedItems++;
                } else {
                    // 'value' (default)
                    $current += (float) ($item['value_try'] ?? 0);
                    $countedItems++;
                }
            }

            $target = (float) $goal['target_value'];
            $percent = $target > 0 ? min(($current / $target) * 100, 100) : 0;

            // Determine unit for display
            $unit = '₺';
            if ($targetType === 'amount' && $targetCurrency) {
                $unit = $targetCurrency;
            }

            $result[$goalId] = [
                'current' => $current,
                'target' => $target,
                'percent' => round($percent, 1),
                'item_count' => $countedItems,
                'unit' => $unit,
                'target_type' => $targetType,
                'target_currency' => $targetCurrency,
            ];
        }

        return $result;
    }
}
