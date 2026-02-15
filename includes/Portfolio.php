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
                $where .= ' AND p.user_id = ?';
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
                $gWhere = 'id = ?';
                $gParams = [$gid];
                if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
                    $uid = Auth::id();
                    if ($uid !== null) {
                        $gWhere .= ' AND user_id = ?';
                        $gParams[] = $uid;
                    }
                }
                $group = Database::queryOne("SELECT id FROM portfolio_groups WHERE {$gWhere}", $gParams);
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
                    $gWhere = 'id = ?';
                    $gParams = [$gid];
                    if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
                        $uid = Auth::id();
                        if ($uid !== null) {
                            $gWhere .= ' AND user_id = ?';
                            $gParams[] = $uid;
                        }
                    }
                    $group = Database::queryOne("SELECT id FROM portfolio_groups WHERE {$gWhere}", $gParams);
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
                $where .= ' AND user_id = ?';
                $params[] = $userId;
            }
        }

        // Verify the row exists and is accessible before updating
        $exists = Database::queryOne("SELECT id FROM portfolio WHERE {$where}", $params);
        if (!$exists) {
            return false;
        }

        // Update returns 0 when data is identical — that's still a success
        Database::update('portfolio', $update, $where, $params);
        return true;
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
                $where .= ' AND user_id = ?';
                $params[] = $userId;
            }
        }

        try {
            $updated = Database::update('portfolio', ['deleted_at' => date('Y-m-d H:i:s')], $where, $params);
            return $updated > 0;
        } catch (Throwable $e) {
            // Only fall back to hard-delete when deleted_at column doesn't exist (schema mismatch)
            if (stripos($e->getMessage(), 'deleted_at') === false) {
                throw $e;
            }
            $hardWhere = 'id = ?';
            $hardParams = [$id];
            if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
                $userId = Auth::id();
                if ($userId !== null) {
                    $hardWhere .= ' AND user_id = ?';
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
                $where .= ' AND user_id = ?';
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
                $where .= ' AND user_id = ?';
                $params[] = $userId;
            }
        }

        $exists = Database::queryOne("SELECT id FROM portfolio_groups WHERE {$where}", $params);
        if (!$exists) {
            return false;
        }

        Database::update('portfolio_groups', $update, $where, $params);
        return true;
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
                $where .= ' AND user_id = ?';
                $params[] = $userId;
            }
        }

        // Delete group first (with ownership check), then unlink items
        $deleted = Database::execute('DELETE FROM portfolio_groups WHERE ' . $where, $params) > 0;
        if ($deleted) {
            Database::execute('UPDATE portfolio SET group_id = NULL WHERE group_id = ?', [$id]);
        }
        return $deleted;
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

        // Validate group exists and user owns it
        if ($groupId !== null && $groupId > 0) {
            $gWhere = 'id = ?';
            $gParams = [$groupId];
            if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
                $uid = Auth::id();
                if ($uid !== null) {
                    $gWhere .= ' AND user_id = ?';
                    $gParams[] = $uid;
                }
            }
            $group = Database::queryOne("SELECT id FROM portfolio_groups WHERE {$gWhere}", $gParams);
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
                $where .= ' AND user_id = ?';
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
                $where .= ' AND t.user_id = ?';
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
                $where .= ' AND user_id = ?';
                $params[] = $userId;
            }
        }

        $exists = Database::queryOne("SELECT id FROM portfolio_tags WHERE {$where}", $params);
        if (!$exists) {
            return false;
        }

        Database::update('portfolio_tags', $update, $where, $params);
        return true;
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
                $where .= ' AND user_id = ?';
                $params[] = $userId;
            }
        }

        // Delete tag first (with ownership check), then remove pivot records
        $deleted = Database::execute('DELETE FROM portfolio_tags WHERE ' . $where, $params) > 0;
        if ($deleted) {
            Database::execute('DELETE FROM portfolio_tag_items WHERE tag_id = ?', [$id]);
        }
        return $deleted;
    }

    /**
     * Assign a tag to a portfolio item (ignores duplicates).
     */
    public static function assignTag(int $portfolioId, int $tagId): bool
    {
        if ($portfolioId <= 0 || $tagId <= 0) {
            return false;
        }

        // Verify current user owns the portfolio item
        $itemWhere = 'id = ? AND deleted_at IS NULL';
        $itemParams = [$portfolioId];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $itemWhere .= ' AND user_id = ?';
                $itemParams[] = $userId;
            }
        }
        if (!Database::queryOne("SELECT id FROM portfolio WHERE {$itemWhere}", $itemParams)) {
            return false;
        }

        // Verify current user owns the tag
        $tagWhere = 'id = ?';
        $tagParams = [$tagId];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $tagWhere .= ' AND user_id = ?';
                $tagParams[] = $userId;
            }
        }
        if (!Database::queryOne("SELECT id FROM portfolio_tags WHERE {$tagWhere}", $tagParams)) {
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
        // Verify current user owns the portfolio item
        $itemWhere = 'id = ? AND deleted_at IS NULL';
        $itemParams = [$portfolioId];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $itemWhere .= ' AND user_id = ?';
                $itemParams[] = $userId;
            }
        }
        if (!Database::queryOne("SELECT id FROM portfolio WHERE {$itemWhere}", $itemParams)) {
            return false;
        }

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
        $where = 'p.deleted_at IS NULL';
        $params = [];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where .= ' AND p.user_id = ?';
                $params[] = $userId;
            }
        }
        $sql = "SELECT pti.portfolio_id, t.id AS tag_id, t.name, t.slug, t.color
                FROM portfolio_tag_items pti
                JOIN portfolio_tags t ON t.id = pti.tag_id
                JOIN portfolio p ON p.id = pti.portfolio_id
                WHERE {$where}
                ORDER BY t.name";
        $rows = Database::query($sql, $params);
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
                $where .= ' AND g.user_id = ?';
                $params[] = $userId;
            }
        }

        return Database::query(
            "SELECT g.* FROM portfolio_goals g WHERE {$where} ORDER BY g.is_favorite DESC, g.created_at DESC",
            $params
        );
    }

    /**
     * Build a WHERE clause scoping goals to the current user (non-admin).
     * Returns [whereClause, params] to append to queries.
     */
    private static function goalOwnerScope(): array
    {
        $where = '';
        $params = [];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $userId = Auth::id();
            if ($userId !== null) {
                $where = ' AND user_id = ?';
                $params[] = $userId;
            }
        }
        return [$where, $params];
    }

    /**
     * Get a single goal by ID.
     */
    public static function getGoal(int $id): ?array
    {
        [$ownerWhere, $ownerParams] = self::goalOwnerScope();
        return Database::queryOne(
            'SELECT * FROM portfolio_goals WHERE id = ?' . $ownerWhere,
            array_merge([$id], $ownerParams)
        ) ?: null;
    }

    /**
     * Valid target types for goals.
     */
    private const GOAL_TARGET_TYPES = ['value', 'cost', 'amount', 'currency_value', 'percent', 'cagr', 'drawdown'];
    private const PERCENT_DATE_MODES = ['all', 'range', 'since_first', 'weighted'];

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

        // target_currency is required for 'amount' and 'currency_value' types
        $targetCurrency = null;
        if ($targetType === 'amount' || $targetType === 'currency_value') {
            $tc = strtoupper(trim((string) ($data['target_currency'] ?? '')));
            if ($tc === '' || !preg_match('/^[A-Z0-9]{3,10}$/', $tc)) {
                throw new InvalidArgumentException('Currency is required for this goal type.');
            }
            $targetCurrency = $tc;
        }

        // Optional bank filter
        $bankSlug = null;
        $bs = trim((string) ($data['bank_slug'] ?? ''));
        if ($bs !== '' && preg_match('/^[a-z0-9_-]+$/i', $bs)) {
            $bankSlug = $bs;
        }

        // Percent goal fields
        $percentDateMode = null;
        $percentDateStart = null;
        $percentDateEnd = null;
        $percentPeriodMonths = 12;
        if ($targetType === 'percent') {
            $pdm = $data['percent_date_mode'] ?? 'all';
            $percentDateMode = in_array($pdm, self::PERCENT_DATE_MODES) ? $pdm : 'all';
            if ($percentDateMode === 'range') {
                $ds = trim($data['percent_date_start'] ?? '');
                $de = trim($data['percent_date_end'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ds)) $percentDateStart = $ds;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $percentDateEnd = $de;
            } elseif ($percentDateMode === 'since_first') {
                $pm = (int) ($data['percent_period_months'] ?? 12);
                $percentPeriodMonths = max(1, min(120, $pm));
            }
        }

        // Goal deadline (not for percent goals — they have their own date modes)
        $goalDeadline = null;
        if ($targetType !== 'percent') {
            $dl = trim((string) ($data['goal_deadline'] ?? ''));
            if ($dl !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dl)) {
                $goalDeadline = $dl;
            }
        }

        // Per-goal deposit interest rate override
        $depositRate = null;
        $dr = trim((string) ($data['deposit_rate'] ?? ''));
        if ($dr !== '') {
            $drVal = (float) $dr;
            if ($drVal >= 0 && $drVal <= 200) {
                $depositRate = $drVal;
            }
        }

        $goalId = Database::insert('portfolio_goals', [
            'user_id' => (class_exists('Auth') && Auth::check()) ? Auth::id() : null,
            'name' => $name,
            'target_value' => $targetValue,
            'target_type' => $targetType,
            'target_currency' => $targetCurrency,
            'bank_slug' => $bankSlug,
            'percent_date_mode' => $percentDateMode,
            'percent_date_start' => $percentDateStart,
            'percent_date_end' => $percentDateEnd,
            'percent_period_months' => $targetType === 'percent' ? $percentPeriodMonths : null,
            'goal_deadline' => $goalDeadline,
            'deposit_rate' => $depositRate,
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
        if ($targetType === 'amount' || $targetType === 'currency_value') {
            $tc = strtoupper(trim((string) ($data['target_currency'] ?? '')));
            if ($tc === '' || !preg_match('/^[A-Z0-9]{3,10}$/', $tc)) {
                throw new InvalidArgumentException('Currency is required for this goal type.');
            }
            $targetCurrency = $tc;
        }

        // Optional bank filter
        $bankSlug = null;
        $bs = trim((string) ($data['bank_slug'] ?? ''));
        if ($bs !== '' && preg_match('/^[a-z0-9_-]+$/i', $bs)) {
            $bankSlug = $bs;
        }

        // Percent goal fields
        $percentDateMode = null;
        $percentDateStart = null;
        $percentDateEnd = null;
        $percentPeriodMonths = 12;
        if ($targetType === 'percent') {
            $pdm = $data['percent_date_mode'] ?? 'all';
            $percentDateMode = in_array($pdm, self::PERCENT_DATE_MODES) ? $pdm : 'all';
            if ($percentDateMode === 'range') {
                $ds = trim($data['percent_date_start'] ?? '');
                $de = trim($data['percent_date_end'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ds)) $percentDateStart = $ds;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $percentDateEnd = $de;
            } elseif ($percentDateMode === 'since_first') {
                $pm = (int) ($data['percent_period_months'] ?? 12);
                $percentPeriodMonths = max(1, min(120, $pm));
            }
        }

        // Goal deadline (not for percent goals)
        $goalDeadline = null;
        if ($targetType !== 'percent') {
            $dl = trim((string) ($data['goal_deadline'] ?? ''));
            if ($dl !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dl)) {
                $goalDeadline = $dl;
            }
        }

        // Per-goal deposit interest rate override
        $depositRate = null;
        $dr = trim((string) ($data['deposit_rate'] ?? ''));
        if ($dr !== '') {
            $drVal = (float) $dr;
            if ($drVal >= 0 && $drVal <= 200) {
                $depositRate = $drVal;
            }
        }

        [$ownerWhere, $ownerParams] = self::goalOwnerScope();
        $params = [$name, $targetValue, $targetType, $targetCurrency, $bankSlug, $percentDateMode, $percentDateStart, $percentDateEnd, $targetType === 'percent' ? $percentPeriodMonths : null, $goalDeadline, $depositRate, $id];

        return Database::execute(
            'UPDATE portfolio_goals SET name = ?, target_value = ?, target_type = ?, target_currency = ?, bank_slug = ?, percent_date_mode = ?, percent_date_start = ?, percent_date_end = ?, percent_period_months = ?, goal_deadline = ?, deposit_rate = ? WHERE id = ?' . $ownerWhere,
            array_merge($params, $ownerParams)
        ) > 0;
    }

    /**
     * Toggle favorite status of a goal.
     */
    public static function toggleGoalFavorite(int $id): bool
    {
        [$ownerWhere, $ownerParams] = self::goalOwnerScope();
        return Database::execute(
            'UPDATE portfolio_goals SET is_favorite = NOT is_favorite WHERE id = ?' . $ownerWhere,
            array_merge([$id], $ownerParams)
        ) > 0;
    }

    /**
     * Delete a goal and all its sources.
     */
    public static function deleteGoal(int $id): bool
    {
        [$ownerWhere, $ownerParams] = self::goalOwnerScope();
        $deleted = Database::execute('DELETE FROM portfolio_goals WHERE id = ?' . $ownerWhere, array_merge([$id], $ownerParams)) > 0;
        if ($deleted) {
            Database::execute('DELETE FROM portfolio_goal_sources WHERE goal_id = ?', [$id]);
        }
        return $deleted;
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
        [$ownerWhere, $ownerParams] = self::goalOwnerScope();
        $rows = Database::query(
            'SELECT gs.* FROM portfolio_goal_sources gs
             JOIN portfolio_goals g ON g.id = gs.goal_id
             WHERE 1=1' . str_replace('user_id', 'g.user_id', $ownerWhere) .
            ' ORDER BY gs.source_type, gs.source_id',
            $ownerParams
        );
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
        // Verify goal ownership
        [$ownerWhere, $ownerParams] = self::goalOwnerScope();
        $goal = Database::queryOne('SELECT id FROM portfolio_goals WHERE id = ?' . $ownerWhere, array_merge([$goalId], $ownerParams));
        if (!$goal) {
            return false;
        }

        // Verify source exists and current user owns it
        $sourceOwnerWhere = 'id = ?';
        $sourceOwnerParams = [$sourceId];
        if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
            $uid = Auth::id();
            if ($uid !== null) {
                $sourceOwnerWhere .= ' AND (user_id IS NULL OR user_id = ?)';
                $sourceOwnerParams[] = $uid;
            }
        }
        if ($sourceType === 'group') {
            if (!Database::queryOne("SELECT id FROM portfolio_groups WHERE {$sourceOwnerWhere}", $sourceOwnerParams)) {
                return false;
            }
        } elseif ($sourceType === 'tag') {
            if (!Database::queryOne("SELECT id FROM portfolio_tags WHERE {$sourceOwnerWhere}", $sourceOwnerParams)) {
                return false;
            }
        } elseif ($sourceType === 'item') {
            $itemWhere = $sourceOwnerWhere . ' AND deleted_at IS NULL';
            if (!Database::queryOne("SELECT id FROM portfolio WHERE {$itemWhere}", $sourceOwnerParams)) {
                return false;
            }
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
        // Verify goal ownership
        [$ownerWhere, $ownerParams] = self::goalOwnerScope();
        $goal = Database::queryOne('SELECT id FROM portfolio_goals WHERE id = ?' . $ownerWhere, array_merge([$goalId], $ownerParams));
        if (!$goal) {
            return false;
        }
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
     *   'value'          — sum current TRY value (value_try)
     *   'cost'           — sum TRY cost (cost_try)
     *   'amount'         — sum raw amount of a specific currency (target_currency)
     *   'currency_value' — sum TRY value, then convert to target_currency (e.g. USD, EUR)
     *
     * @param array $goals          All goals
     * @param array $allItems       All portfolio items from Portfolio::getAll()
     * @param array $allItemTags    All item tags from Portfolio::getAllItemTags()
     * @param array $allGoalSources Goal sources keyed by goal_id
     * @param array $currencyRates  Optional: currency sell rates keyed by code => sell_rate (TRY per unit)
     * @param string|null $periodFilter Optional: period filter for date-based progress ('7d','14d','1m','3m','6m','9m','1y')
     * @param string|null $periodStart  Optional: custom period start date (Y-m-d)
     * @param string|null $periodEnd    Optional: custom period end date (Y-m-d)
     * @return array Keyed by goal_id => ['current' => float, 'target' => float, 'percent' => float, 'item_count' => int, 'unit' => string]
     */
    public static function computeGoalProgress(array $goals, array $allItems, array $allItemTags, array $allGoalSources, array $currencyRates = [], ?string $periodFilter = null, ?string $periodStart = null, ?string $periodEnd = null): array
    {
        $result = [];

        // Deposit comparison: read annual net interest rate from settings
        $depositRateRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['deposit_interest_rate']);
        $depositAnnualRate = $depositRateRow ? (float) $depositRateRow['value'] : 40.0;
        $today = new DateTime();

        // Pre-index items for O(1) lookups
        $itemsById = [];
        $itemsByGroupId = [];
        foreach ($allItems as $item) {
            $id = (int) $item['id'];
            $itemsById[$id] = $item;
            $itemsByGroupId[(int) ($item['group_id'] ?? 0)][] = $id;
        }

        // Pre-index item tags for O(1) lookups
        $itemsByTagId = [];
        foreach ($allItemTags as $pid => $tags) {
            foreach ($tags as $tag) {
                $itemsByTagId[(int) $tag['id']][] = $pid;
            }
        }

        foreach ($goals as $goal) {
            $goalId = (int) $goal['id'];
            $targetType = $goal['target_type'] ?? 'value';
            $targetCurrency = $goal['target_currency'] ?? null;
            $goalBankSlug = $goal['bank_slug'] ?? null;
            $sources = $allGoalSources[$goalId] ?? [];

            // Per-goal deposit rate override (NULL = use admin default)
            $goalDepositRate = ($goal['deposit_rate'] !== null) ? (float) $goal['deposit_rate'] : $depositAnnualRate;

            // Collect unique item IDs matching any source
            $matchedItemIds = [];

            foreach ($sources as $src) {
                $sType = $src['source_type'];
                $sId = (int) $src['source_id'];

                if ($sType === 'item') {
                    $matchedItemIds[$sId] = true;
                } elseif ($sType === 'group') {
                    foreach (($itemsByGroupId[$sId] ?? []) as $id) {
                        $matchedItemIds[$id] = true;
                    }
                } elseif ($sType === 'tag') {
                    foreach (($itemsByTagId[$sId] ?? []) as $id) {
                        $matchedItemIds[$id] = true;
                    }
                }
            }

            $current = 0.0;
            $countedItems = 0;

            // Compute period date filter bounds (applies to non-percent-range goals)
            $periodDateStart = null;
            $periodDateEnd = null;
            if ($periodFilter !== null || ($periodStart !== null && $periodEnd !== null)) {
                $periodDateEnd = date('Y-m-d');
                if ($periodStart !== null && $periodEnd !== null) {
                    $periodDateStart = $periodStart;
                    $periodDateEnd = $periodEnd;
                } else {
                    $periodMap = ['7d' => '-7 days', '14d' => '-14 days', '1m' => '-1 month', '3m' => '-3 months', '6m' => '-6 months', '9m' => '-9 months', '1y' => '-1 year'];
                    $modifier = $periodMap[$periodFilter] ?? null;
                    if ($modifier) {
                        $periodDateStart = date('Y-m-d', strtotime($modifier));
                    }
                }
            }

            // Deadline info
            $goalDeadline = $goal['goal_deadline'] ?? null;
            $deadlineMonths = null;
            if ($goalDeadline) {
                $now = new DateTime();
                $dl = DateTime::createFromFormat('Y-m-d', $goalDeadline);
                if ($dl) {
                    $diff = $now->diff($dl);
                    $deadlineMonths = $diff->invert ? 0 : (int) round($diff->days / 30.44);
                }
            }

            // Percent goal: compute profit percentage
            if ($targetType === 'percent') {
                $dateMode = $goal['percent_date_mode'] ?? 'all';
                $dateStart = $goal['percent_date_start'] ?? null;
                $dateEnd = $goal['percent_date_end'] ?? null;
                $periodMonths = (int) ($goal['percent_period_months'] ?? 12);

                // For since_first mode: find earliest buy_date among matched items
                $earliestDate = null;
                if ($dateMode === 'since_first') {
                    foreach ($matchedItemIds as $itemId => $ignored) {
                        $item = $itemsById[$itemId] ?? null;
                        if (!$item) continue;
                        if ($goalBankSlug !== null && ($item['bank_slug'] ?? '') !== $goalBankSlug) continue;
                        $bd = $item['buy_date'] ?? null;
                        if ($bd && ($earliestDate === null || $bd < $earliestDate)) {
                            $earliestDate = $bd;
                        }
                    }
                    if ($earliestDate) {
                        $dateStart = $earliestDate;
                        $dt = new DateTime($earliestDate);
                        $dt->modify("+{$periodMonths} months");
                        $dateEnd = $dt->format('Y-m-d');
                    }
                }

                $totalCost = 0.0;
                $totalValue = 0.0;
                $weightedSum = 0.0;
                $depositTotal = 0.0;
                $depositMaxDays = 0;

                foreach ($matchedItemIds as $itemId => $ignored) {
                    $item = $itemsById[$itemId] ?? null;
                    if (!$item) continue;
                    if ($goalBankSlug !== null && ($item['bank_slug'] ?? '') !== $goalBankSlug) continue;

                    // Date filter for range and since_first modes
                    if (($dateMode === 'range' || $dateMode === 'since_first') && $dateStart && $dateEnd) {
                        $bd = $item['buy_date'] ?? '';
                        if ($bd < $dateStart || $bd > $dateEnd) continue;
                    }

                    // Period filter for all/weighted modes
                    if (($dateMode === 'all' || $dateMode === 'weighted') && $periodDateStart && $periodDateEnd) {
                        $bd = $item['buy_date'] ?? '';
                        if ($bd < $periodDateStart || $bd > $periodDateEnd) continue;
                    }

                    $itemCost = (float) ($item['cost_try'] ?? 0);
                    $itemValue = (float) ($item['value_try'] ?? 0);

                    if ($itemCost <= 0) continue;

                    $totalCost += $itemCost;
                    $totalValue += $itemValue;
                    $countedItems++;

                    if ($dateMode === 'weighted') {
                        $itemReturn = ($itemValue - $itemCost) / $itemCost;
                        $weightedSum += $itemReturn * $itemCost;
                    }

                    // Deposit comparison
                    $buyDateStr = $item['buy_date'] ?? '';
                    if ($buyDateStr && $goalDepositRate > 0) {
                        $buyDateObj = DateTime::createFromFormat('Y-m-d', $buyDateStr);
                        if ($buyDateObj) {
                            $daysDiff = max(0, (int) $buyDateObj->diff($today)->days);
                            $depositTotal += $itemCost * pow(1 + $goalDepositRate / 100, $daysDiff / 365);
                            if ($daysDiff > $depositMaxDays) $depositMaxDays = $daysDiff;
                        }
                    }
                }

                if ($dateMode === 'weighted' && $totalCost > 0) {
                    $current = ($weightedSum / $totalCost) * 100;
                } elseif ($totalCost > 0) {
                    $current = (($totalValue - $totalCost) / $totalCost) * 100;
                }

                $target = (float) $goal['target_value'];
                $percent = $target != 0 ? min(($current / $target) * 100, 999) : 0;
                if ($percent < 0) $percent = 0;

                $result[$goalId] = [
                    'current' => round($current, 2),
                    'target' => $target,
                    'percent' => round($percent, 1),
                    'item_count' => $countedItems,
                    'unit' => '%',
                    'target_type' => $targetType,
                    'target_currency' => $targetCurrency,
                    'bank_slug' => $goalBankSlug,
                    'percent_date_mode' => $dateMode,
                    'is_favorite' => (int) ($goal['is_favorite'] ?? 0),
                    'goal_deadline' => $goalDeadline,
                    'deadline_months' => $deadlineMonths,
                    'deposit_value' => round($depositTotal, 2),
                    'deposit_rate' => $goalDepositRate,
                    'deposit_days' => $depositMaxDays,
                ];
                continue;
            }

            // CAGR goal: compound annual growth rate
            if ($targetType === 'cagr') {
                $totalCost = 0.0;
                $totalValue = 0.0;
                $earliestDate = null;
                $depositTotal = 0.0;
                $depositMaxDays = 0;

                foreach ($matchedItemIds as $itemId => $ignored) {
                    $item = $itemsById[$itemId] ?? null;
                    if (!$item) continue;
                    if ($goalBankSlug !== null && ($item['bank_slug'] ?? '') !== $goalBankSlug) continue;

                    // Period filter
                    if ($periodDateStart && $periodDateEnd) {
                        $bd = $item['buy_date'] ?? '';
                        if ($bd < $periodDateStart || $bd > $periodDateEnd) continue;
                    }

                    $itemCost = (float) ($item['cost_try'] ?? 0);
                    $itemValue = (float) ($item['value_try'] ?? 0);
                    if ($itemCost <= 0) continue;

                    $totalCost += $itemCost;
                    $totalValue += $itemValue;
                    $countedItems++;

                    $bd = $item['buy_date'] ?? null;
                    if ($bd && ($earliestDate === null || $bd < $earliestDate)) {
                        $earliestDate = $bd;
                    }

                    // Deposit comparison
                    $buyDateStr = $item['buy_date'] ?? '';
                    if ($buyDateStr && $goalDepositRate > 0) {
                        $buyDateObj = DateTime::createFromFormat('Y-m-d', $buyDateStr);
                        if ($buyDateObj) {
                            $daysDiff = max(0, (int) $buyDateObj->diff($today)->days);
                            $depositTotal += $itemCost * pow(1 + $goalDepositRate / 100, $daysDiff / 365);
                            if ($daysDiff > $depositMaxDays) $depositMaxDays = $daysDiff;
                        }
                    }
                }

                $current = 0.0;
                if ($totalCost > 0 && $earliestDate) {
                    $start = DateTime::createFromFormat('Y-m-d', $earliestDate);
                    if ($start) {
                        $days = (int) (new DateTime())->diff($start)->days;
                        if ($days >= 7) {
                            $years = $days / 365.25;
                            $ratio = $totalValue / $totalCost;
                            $current = (pow($ratio, 1 / $years) - 1) * 100;
                        }
                    }
                }

                $target = (float) $goal['target_value'];
                $percent = $target != 0 ? min(($current / $target) * 100, 999) : 0;
                if ($percent < 0) $percent = 0;

                $result[$goalId] = [
                    'current' => round($current, 2),
                    'target' => $target,
                    'percent' => round($percent, 1),
                    'item_count' => $countedItems,
                    'unit' => '%',
                    'target_type' => $targetType,
                    'target_currency' => $targetCurrency,
                    'bank_slug' => $goalBankSlug,
                    'is_favorite' => (int) ($goal['is_favorite'] ?? 0),
                    'goal_deadline' => $goalDeadline,
                    'deadline_months' => $deadlineMonths,
                    'deposit_value' => round($depositTotal, 2),
                    'deposit_rate' => $goalDepositRate,
                    'deposit_days' => $depositMaxDays,
                ];
                continue;
            }

            // Drawdown goal: max loss limit
            if ($targetType === 'drawdown') {
                $totalCost = 0.0;
                $totalValue = 0.0;
                $depositTotal = 0.0;
                $depositMaxDays = 0;

                foreach ($matchedItemIds as $itemId => $ignored) {
                    $item = $itemsById[$itemId] ?? null;
                    if (!$item) continue;
                    if ($goalBankSlug !== null && ($item['bank_slug'] ?? '') !== $goalBankSlug) continue;

                    // Period filter
                    if ($periodDateStart && $periodDateEnd) {
                        $bd = $item['buy_date'] ?? '';
                        if ($bd < $periodDateStart || $bd > $periodDateEnd) continue;
                    }

                    $itemCost = (float) ($item['cost_try'] ?? 0);
                    $itemValue = (float) ($item['value_try'] ?? 0);
                    if ($itemCost <= 0) continue;

                    $totalCost += $itemCost;
                    $totalValue += $itemValue;
                    $countedItems++;

                    // Deposit comparison
                    $buyDateStr = $item['buy_date'] ?? '';
                    if ($buyDateStr && $goalDepositRate > 0) {
                        $buyDateObj = DateTime::createFromFormat('Y-m-d', $buyDateStr);
                        if ($buyDateObj) {
                            $daysDiff = max(0, (int) $buyDateObj->diff($today)->days);
                            $depositTotal += $itemCost * pow(1 + $goalDepositRate / 100, $daysDiff / 365);
                            if ($daysDiff > $depositMaxDays) $depositMaxDays = $daysDiff;
                        }
                    }
                }

                // current = current drawdown % (0 or positive when losing)
                $currentDrawdown = 0.0;
                if ($totalCost > 0 && $totalValue < $totalCost) {
                    $currentDrawdown = (($totalCost - $totalValue) / $totalCost) * 100;
                }

                $limit = (float) $goal['target_value'];
                // Progress: how much safety buffer remains (100% = no loss, 0% = limit reached)
                $percent = $limit > 0 ? max(0, (1 - $currentDrawdown / $limit) * 100) : 100;

                $result[$goalId] = [
                    'current' => round($currentDrawdown, 2),
                    'target' => $limit,
                    'percent' => round($percent, 1),
                    'item_count' => $countedItems,
                    'unit' => '%',
                    'target_type' => $targetType,
                    'target_currency' => $targetCurrency,
                    'bank_slug' => $goalBankSlug,
                    'is_favorite' => (int) ($goal['is_favorite'] ?? 0),
                    'goal_deadline' => $goalDeadline,
                    'deadline_months' => $deadlineMonths,
                    'deposit_value' => round($depositTotal, 2),
                    'deposit_rate' => $goalDepositRate,
                    'deposit_days' => $depositMaxDays,
                ];
                continue;
            }

            $depositTotal = 0.0;
            $depositMaxDays = 0;
            foreach ($matchedItemIds as $itemId => $ignored) {
                $item = $itemsById[$itemId] ?? null;
                if (!$item) continue;

                // Bank filter: skip items not from the goal's bank
                if ($goalBankSlug !== null && ($item['bank_slug'] ?? '') !== $goalBankSlug) {
                    continue;
                }

                // Period filter
                if ($periodDateStart && $periodDateEnd) {
                    $bd = $item['buy_date'] ?? '';
                    if ($bd < $periodDateStart || $bd > $periodDateEnd) continue;
                }

                if ($targetType === 'amount') {
                    // Only count items with the matching currency
                    if ($targetCurrency !== null && strtoupper($item['currency_code'] ?? '') === strtoupper($targetCurrency)) {
                        $current += (float) ($item['amount'] ?? 0);
                        $countedItems++;
                    }
                } elseif ($targetType === 'currency_value') {
                    // Sum TRY values — will convert to target currency after loop
                    $current += (float) ($item['value_try'] ?? 0);
                    $countedItems++;
                } elseif ($targetType === 'cost') {
                    $current += (float) ($item['cost_try'] ?? 0);
                    $countedItems++;
                } else {
                    // 'value' (default)
                    $current += (float) ($item['value_try'] ?? 0);
                    $countedItems++;
                }

                // Deposit comparison
                $buyDateStr = $item['buy_date'] ?? '';
                if ($buyDateStr && $goalDepositRate > 0) {
                    $buyDateObj = DateTime::createFromFormat('Y-m-d', $buyDateStr);
                    if ($buyDateObj) {
                        $daysDiff = max(0, (int) $buyDateObj->diff($today)->days);
                        $itemCostTry = (float) ($item['cost_try'] ?? 0);
                        $depositTotal += $itemCostTry * pow(1 + $goalDepositRate / 100, $daysDiff / 365);
                        if ($daysDiff > $depositMaxDays) $depositMaxDays = $daysDiff;
                    }
                }
            }

            // For currency_value: convert TRY sum to target currency
            if ($targetType === 'currency_value' && $targetCurrency !== null) {
                $rate = $currencyRates[strtoupper($targetCurrency)] ?? 0;
                if ($rate > 0) {
                    $current = $current / $rate;
                }
                // If rate is 0, current stays as TRY (fallback)
            }

            $target = (float) $goal['target_value'];
            $percent = $target > 0 ? min(($current / $target) * 100, 100) : 0;

            // Determine unit for display
            $unit = '₺';
            if (($targetType === 'amount' || $targetType === 'currency_value') && $targetCurrency) {
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
                'bank_slug' => $goalBankSlug,
                'is_favorite' => (int) ($goal['is_favorite'] ?? 0),
                'goal_deadline' => $goalDeadline,
                'deadline_months' => $deadlineMonths,
                'deposit_value' => round($depositTotal, 2),
                'deposit_rate' => $goalDepositRate,
                'deposit_days' => $depositMaxDays,
            ];
        }

        return $result;
    }
}
