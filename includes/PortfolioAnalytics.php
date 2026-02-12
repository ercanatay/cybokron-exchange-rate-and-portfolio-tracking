<?php
/**
 * PortfolioAnalytics.php — Analytical metrics for portfolio
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class PortfolioAnalytics
{
    /**
     * Get distribution by currency (value in TRY).
     *
     * @param array<int, array{currency_code:string,value_try:float}> $items
     * @return array<int, array{currency_code:string,value:float,percent:float}>
     */
    public static function getDistribution(array $items): array
    {
        $byCurrency = [];
        $total = 0.0;

        foreach ($items as $item) {
            $code = (string) ($item['currency_code'] ?? '');
            $value = (float) ($item['value_try'] ?? $item['cost_try'] ?? 0);
            if ($code === '') continue;

            if (!isset($byCurrency[$code])) {
                $byCurrency[$code] = ['currency_code' => $code, 'value' => 0, 'percent' => 0];
            }
            $byCurrency[$code]['value'] += $value;
            $total += $value;
        }

        foreach ($byCurrency as &$row) {
            $row['percent'] = $total > 0 ? round(($row['value'] / $total) * 100, 1) : 0;
        }

        usort($byCurrency, fn($a, $b) => $b['value'] <=> $a['value']);

        return array_values($byCurrency);
    }

    /**
     * Simple annualized return approximation.
     * (current_value / total_cost)^(1/years) - 1
     *
     * @param float $totalCost
     * @param float $currentValue
     * @param string $oldestDate Y-m-d
     * @return float|null Annualized return as decimal (0.05 = 5%) or null
     */
    public static function annualizedReturn(float $totalCost, float $currentValue, string $oldestDate): ?float
    {
        if ($totalCost <= 0) return null;

        $start = DateTime::createFromFormat('Y-m-d', $oldestDate);
        if (!$start) return null;

        $days = (new DateTime())->diff($start)->days;
        if ($days < 1) return null;

        $years = $days / 365.25;
        if ($years < 0.01) return null;

        $ratio = $currentValue / $totalCost;
        return pow($ratio, 1 / $years) - 1;
    }

    /**
     * Get oldest buy_date from items.
     */
    public static function getOldestDate(array $items): ?string
    {
        $oldest = null;
        foreach ($items as $item) {
            $d = $item['buy_date'] ?? null;
            if ($d && ($oldest === null || $d < $oldest)) {
                $oldest = $d;
            }
        }
        return $oldest;
    }
}
