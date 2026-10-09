<?php
/**
 * InflationProvider.php — Enflasyon oranı sağlayıcısı
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * settings tablosundan yıllık (ENAG/reel) enflasyon oranını okur ve günceller.
 * Okuma (UI/hesaplama) ile güncelleme (cron/admin) tek noktada toplanır; böylece
 * scrape kırılganlığı UI'ı etkilemez — son bilinen değer her zaman okunabilir.
 *
 * settings anahtarları:
 *   inflation_annual_rate  yıllık enflasyon yüzdesi (ör. "53.13")
 *   inflation_source       "manual" | "auto"
 *   inflation_locked       "1" ise otomatik güncelleme (cron) değeri ezemez
 */

class InflationProvider
{
    /** Otomatik güncellemede kabul edilen makul yıllık enflasyon aralığı (%). */
    public const MIN_PLAUSIBLE = 10.0;
    public const MAX_PLAUSIBLE = 200.0;

    /**
     * Yıllık enflasyon yüzdesi (ör. 53.13). Ayar yoksa 0.
     */
    public static function getAnnualRatePercent(): float
    {
        $v = self::getSettingValue('inflation_annual_rate');
        return ($v !== null && is_numeric($v)) ? (float) $v : 0.0;
    }

    /**
     * Enflasyon çarpanı (1 + oran/100). Hedef = maliyet × bu değer.
     */
    public static function getMultiplier(): float
    {
        return 1 + (self::getAnnualRatePercent() / 100);
    }

    /**
     * Gösterim için meta: oran, kaynak, kilit, son güncelleme.
     *
     * @return array{rate: float, source: string, locked: bool, updated_at: ?string}
     */
    public static function getMeta(): array
    {
        return [
            'rate'       => self::getAnnualRatePercent(),
            'source'     => self::getSettingValue('inflation_source') ?? 'manual',
            'locked'     => self::getSettingValue('inflation_locked') === '1',
            'updated_at' => self::getUpdatedAt(),
        ];
    }

    public static function isLocked(): bool
    {
        return self::getSettingValue('inflation_locked') === '1';
    }

    /**
     * Oranı güncelle (cron veya admin). Kaynak: "manual" | "auto".
     */
    public static function setRate(float $ratePercent, string $source): void
    {
        self::setSettingValue('inflation_annual_rate', (string) round($ratePercent, 2));
        self::setSettingValue('inflation_source', $source === 'auto' ? 'auto' : 'manual');
    }

    public static function setLocked(bool $locked): void
    {
        self::setSettingValue('inflation_locked', $locked ? '1' : '0');
    }

    /**
     * Otomatik güncellemenin geçerli sayılması için oran makul aralıkta mı.
     */
    public static function isPlausible(float $ratePercent): bool
    {
        return $ratePercent >= self::MIN_PLAUSIBLE && $ratePercent <= self::MAX_PLAUSIBLE;
    }

    // ── aylık seri (inflation_monthly) ──────────────────────────────────

    public const SOURCES = ['enag', 'tuik'];

    /**
     * Bir kaynağın aylık serisi: ['2026-09' => 2.10, ...], dönem sırasına göre.
     * Tablo henüz yoksa (migration uygulanmamış) boş dizi döner; hesap yıllık
     * orana düşer.
     *
     * @return array<string, float>
     */
    public static function getMonthlySeries(string $source): array
    {
        if (!in_array($source, self::SOURCES, true)) {
            return [];
        }

        try {
            $rows = Database::query(
                'SELECT period, rate_percent FROM inflation_monthly WHERE source = ? ORDER BY period',
                [$source]
            );
        } catch (Throwable $e) {
            return [];
        }

        $series = [];
        foreach ($rows as $row) {
            $series[(string) $row['period']] = (float) $row['rate_percent'];
        }

        return $series;
    }

    /**
     * Aylık değeri ekle ya da düzelt (admin).
     */
    public static function setMonthlyRate(string $source, string $period, float $ratePercent): void
    {
        if (!in_array($source, self::SOURCES, true) || !self::isValidPeriod($period)) {
            throw new InvalidArgumentException('Invalid inflation source or period');
        }

        Database::execute(
            'INSERT INTO inflation_monthly (source, period, rate_percent) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent)',
            [$source, $period, round($ratePercent, 2)]
        );
    }

    public static function isValidPeriod(string $period): bool
    {
        return (bool) preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $period);
    }

    /**
     * Alış gününden bugüne aylık seriyle bileşik enflasyon çarpanı.
     *
     * Her ayın aylık oranı, o ay içinde elde tutulan gün oranı kadar uygulanır:
     * (1 + r/100) ^ (tutulan gün / ayın gün sayısı). Alış günü sayılır, bugün
     * sayılmaz; böylece bugün alınan kalemin çarpanı tam 1'dir.
     *
     * Seride olmayan aylar kendisinden önceki son açıklanan ayın oranını alır
     * (içinde bulunulan ay ve henüz açıklanmamış aylar dahil). Serinin ilk
     * ayından önceki aylar serinin ilk ayının oranını alır.
     * Seri boşsa 1 döner; çağıran yıllık orana düşmelidir.
     *
     * @param array<string, float> $series ['YYYY-MM' => aylık yüzde]
     */
    public static function compoundFactor(DateTimeImmutable $buy, DateTimeImmutable $today, array $series): float
    {
        if ($series === [] || $buy >= $today) {
            return 1.0;
        }

        ksort($series);
        $periods = array_keys($series);
        $first = $periods[0];

        $factor = 1.0;
        $cursor = $buy->modify('first day of this month');
        $todayPeriod = $today->format('Y-m');
        $buyPeriod = $buy->format('Y-m');

        while ($cursor->format('Y-m') <= $todayPeriod) {
            $period = $cursor->format('Y-m');
            $daysInMonth = (int) $cursor->format('t');
            $start = $period === $buyPeriod ? ((int) $buy->format('j')) - 1 : 0;
            $end = $period === $todayPeriod ? ((int) $today->format('j')) - 1 : $daysInMonth;

            if ($end > $start) {
                $rate = $series[$period] ?? self::nearestEarlierRate($series, $periods, $period, $first);
                $factor *= (1 + $rate / 100) ** (($end - $start) / $daysInMonth);
            }

            $cursor = $cursor->modify('first day of next month');
        }

        return $factor;
    }

    /**
     * Alış tarihinden tam 1 yıl sonrası için enflasyon çarpanı.
     *
     * Alış → min(bugün, alış+1 yıl) arası gerçekleşen aylık seriyle
     * (compoundFactor), bugün → alış+1 yıl arası kalan süre ise yıllık oranla
     * tahmin edilir: annualMultiplier ^ (kalan gün / 365.25). 1 yılı dolmuş
     * kalemde tahmin kısmı yoktur; yıl tamamen gerçekleşen veriyle hesaplanır.
     * Seri boşsa gerçekleşen kısım da yıllık oranla hesaplanır.
     *
     * @param array<string, float> $series
     * @return array{factor: float, horizon: DateTimeImmutable}
     */
    public static function oneYearFactor(
        DateTimeImmutable $buy,
        DateTimeImmutable $today,
        array $series,
        float $annualMultiplier
    ): array {
        $horizon = $buy->modify('+1 year');
        $realisedEnd = $horizon < $today ? $horizon : $today;

        if ($series !== []) {
            $realised = InflationProvider::compoundFactor($buy, $realisedEnd, $series);
        } else {
            $realisedDays = $realisedEnd > $buy ? (int) $buy->diff($realisedEnd)->days : 0;
            $realised = $annualMultiplier ** ($realisedDays / 365.25);
        }

        $remainingDays = $horizon > $today ? (int) $today->diff($horizon)->days : 0;
        $projected = $annualMultiplier ** ($remainingDays / 365.25);

        return ['factor' => $realised * $projected, 'horizon' => $horizon];
    }

    /**
     * Seride olmayan bir ay için kullanılacak oran: kendisinden önceki son
     * açıklanan ay; seri o aydan sonra başlıyorsa serinin ilk ayı.
     *
     * @param array<string, float> $series
     * @param string[] $periods sıralı dönem listesi
     */
    private static function nearestEarlierRate(array $series, array $periods, string $period, string $first): float
    {
        $match = $first;
        foreach ($periods as $p) {
            if ($p > $period) {
                break;
            }
            $match = $p;
        }

        return $series[$match];
    }

    // ── settings erişimi ────────────────────────────────────────────────

    private static function getSettingValue(string $key): ?string
    {
        $row = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', [$key]);
        return ($row && array_key_exists('value', $row)) ? (string) $row['value'] : null;
    }

    private static function setSettingValue(string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
    }

    private static function getUpdatedAt(): ?string
    {
        $row = Database::queryOne(
            'SELECT updated_at FROM settings WHERE `key` = ?',
            ['inflation_annual_rate']
        );
        return ($row && !empty($row['updated_at'])) ? (string) $row['updated_at'] : null;
    }
}
