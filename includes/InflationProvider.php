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
