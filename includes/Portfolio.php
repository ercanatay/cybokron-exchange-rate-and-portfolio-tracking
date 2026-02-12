<?php
/**
 * Portfolio management class
 */
require_once __DIR__ . '/Database.php';

class Portfolio
{
    /**
     * Get all portfolio entries with current rates
     */
    public static function getAll(): array
    {
        return Database::fetchAll("
            SELECT 
                p.id,
                p.amount,
                p.buy_rate,
                p.buy_date,
                p.notes,
                c.code AS currency_code,
                c.name_tr AS currency_name,
                c.type AS currency_type,
                b.name AS bank_name,
                b.slug AS bank_slug,
                r.buy_rate AS current_buy_rate,
                r.sell_rate AS current_sell_rate,
                r.change_percent,
                r.fetched_at AS rate_updated_at,
                (p.amount * r.sell_rate) AS current_value_try,
                (p.amount * p.buy_rate) AS cost_try,
                ((p.amount * r.sell_rate) - (p.amount * p.buy_rate)) AS profit_loss_try,
                CASE 
                    WHEN p.buy_rate > 0 
                    THEN (((r.sell_rate - p.buy_rate) / p.buy_rate) * 100) 
                    ELSE 0 
                END AS profit_loss_percent
            FROM portfolio p
            JOIN currencies c ON p.currency_id = c.id
            LEFT JOIN banks b ON p.bank_id = b.id
            LEFT JOIN rates r ON r.currency_id = p.currency_id AND r.bank_id = COALESCE(p.bank_id, r.bank_id)
            ORDER BY p.buy_date DESC
        ");
    }

    /**
     * Get portfolio summary totals
     */
    public static function getSummary(): array
    {
        $entries = self::getAll();
        
        $totalCost = 0;
        $totalValue = 0;
        
        foreach ($entries as $entry) {
            $totalCost += $entry['cost_try'] ?? 0;
            $totalValue += $entry['current_value_try'] ?? 0;
        }

        $profitLoss = $totalValue - $totalCost;
        $profitLossPercent = $totalCost > 0 ? (($profitLoss / $totalCost) * 100) : 0;

        return [
            'total_cost_try'       => round($totalCost, 2),
            'total_value_try'      => round($totalValue, 2),
            'profit_loss_try'      => round($profitLoss, 2),
            'profit_loss_percent'  => round($profitLossPercent, 2),
            'entry_count'          => count($entries),
            'entries'              => $entries,
        ];
    }

    /**
     * Add a new portfolio entry
     */
    public static function add(string $currencyCode, float $amount, float $buyRate, string $buyDate, ?string $bankSlug = null, ?string $notes = null): int
    {
        $currency = Database::fetchOne("SELECT id FROM currencies WHERE code = ?", [$currencyCode]);
        if (!$currency) {
            throw new InvalidArgumentException("Currency '$currencyCode' not found");
        }

        $bankId = null;
        if ($bankSlug) {
            $bank = Database::fetchOne("SELECT id FROM banks WHERE slug = ?", [$bankSlug]);
            $bankId = $bank ? $bank['id'] : null;
        }

        return Database::insert('portfolio', [
            'currency_id' => $currency['id'],
            'bank_id'     => $bankId,
            'amount'      => $amount,
            'buy_rate'    => $buyRate,
            'buy_date'    => $buyDate,
            'notes'       => $notes,
        ]);
    }

    /**
     * Update a portfolio entry
     */
    public static function update(int $id, array $data): bool
    {
        $allowed = ['amount', 'buy_rate', 'buy_date', 'notes'];
        $updateData = array_intersect_key($data, array_flip($allowed));
        
        if (empty($updateData)) {
            return false;
        }

        return Database::update('portfolio', $updateData, 'id = ?', [$id]) > 0;
    }

    /**
     * Delete a portfolio entry
     */
    public static function delete(int $id): bool
    {
        return Database::delete('portfolio', 'id = ?', [$id]) > 0;
    }

    /**
     * Get a single portfolio entry
     */
    public static function getById(int $id): ?array
    {
        return Database::fetchOne("
            SELECT p.*, c.code AS currency_code, c.name_tr AS currency_name
            FROM portfolio p
            JOIN currencies c ON p.currency_id = c.id
            WHERE p.id = ?
        ", [$id]);
    }
}
