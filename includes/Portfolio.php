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

        $totalCost = 0;
        $totalValue = 0;

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
        $required = ['currency_code', 'amount', 'buy_rate', 'buy_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Resolve currency ID
        $currency = Database::queryOne(
            "SELECT id FROM currencies WHERE code = ?",
            [$data['currency_code']]
        );
        if (!$currency) {
            throw new InvalidArgumentException("Unknown currency: {$data['currency_code']}");
        }

        // Resolve bank ID (optional)
        $bankId = null;
        if (!empty($data['bank_slug'])) {
            $bank = Database::queryOne(
                "SELECT id FROM banks WHERE slug = ?",
                [$data['bank_slug']]
            );
            $bankId = $bank ? (int) $bank['id'] : null;
        }

        return Database::insert('portfolio', [
            'currency_id' => (int) $currency['id'],
            'bank_id'     => $bankId,
            'amount'      => (float) $data['amount'],
            'buy_rate'    => (float) $data['buy_rate'],
            'buy_date'    => $data['buy_date'],
            'notes'       => $data['notes'] ?? null,
        ]);
    }

    /**
     * Update a portfolio entry.
     */
    public static function update(int $id, array $data): bool
    {
        $allowed = ['amount', 'buy_rate', 'buy_date', 'notes'];
        $update = [];

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update[$field] = $data[$field];
            }
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
        return Database::execute("DELETE FROM portfolio WHERE id = ?", [$id]) > 0;
    }
}
