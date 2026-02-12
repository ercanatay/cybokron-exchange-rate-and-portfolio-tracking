<?php
/**
 * Portfolio.php — Portfolio Management
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class Portfolio
{
    /**
     * Get all portfolio entries with current rates and P/L.
     */
    public static function getAll(): array
    {
        $sql = "
            SELECT
                p.id,
                p.amount,
                p.buy_rate,
                p.buy_date,
                p.notes,
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
                (p.amount * p.buy_rate) AS cost_try,
                (p.amount * COALESCE(r.sell_rate, p.buy_rate)) AS value_try,
                ((COALESCE(r.sell_rate, p.buy_rate) - p.buy_rate) / p.buy_rate * 100) AS profit_percent
            FROM portfolio p
            JOIN currencies c ON c.id = p.currency_id
            LEFT JOIN banks b ON b.id = p.bank_id
            LEFT JOIN rates r ON r.currency_id = p.currency_id AND r.bank_id = p.bank_id
            ORDER BY p.buy_date DESC
        ";

        return Database::query($sql);
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
            'items'          => $items,
            'total_cost'     => round($totalCost, 2),
            'total_value'    => round($totalValue, 2),
            'profit_loss'    => round($profitLoss, 2),
            'profit_percent' => round($profitPercent, 2),
            'item_count'     => count($items),
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

        return Database::insert('portfolio', [
            'currency_id' => (int) $currency['id'],
            'bank_id'     => $bankId,
            'amount'      => $amount,
            'buy_rate'    => $buyRate,
            'buy_date'    => $buyDate,
            'notes'       => $notes,
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

        if (empty($update)) {
            return false;
        }

        return Database::update('portfolio', $update, 'id = ?', [$id]) > 0;
    }

    /**
     * Delete a portfolio entry.
     */
    public static function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        return Database::execute('DELETE FROM portfolio WHERE id = ?', [$id]) > 0;
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

        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        $errors = DateTime::getLastErrors();

        if (!$parsed || !is_array($errors) || $errors['warning_count'] > 0 || $errors['error_count'] > 0 || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('buy_date must be in Y-m-d format');
        }

        return $date;
    }
}
