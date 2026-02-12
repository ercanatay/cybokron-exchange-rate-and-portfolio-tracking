/**
 * Cybokron Exchange Rate & Portfolio Tracking
 * Dashboard JavaScript
 */

(function () {
    'use strict';

    const REFRESH_INTERVAL = 5 * 60 * 1000;
    const appLocale = (document.documentElement.lang || 'tr').toLowerCase();
    const numberLocale = appLocale === 'tr' ? 'tr-TR' : 'en-US';

    /**
     * Fetch latest rates from API and update the table.
     */
    async function refreshRates() {
        try {
            const response = await fetch('api.php?action=rates');
            const json = await response.json();

            if (json.status !== 'ok' || !json.data) return;

            json.data.forEach(rate => {
                const row = document.querySelector(`[data-currency="${rate.currency_code}"][data-bank="${rate.bank_slug}"]`);
                if (!row) return;

                const buyCell = row.querySelector('.rate-buy');
                const sellCell = row.querySelector('.rate-sell');
                const changeCell = row.querySelector('.rate-change');

                if (buyCell) buyCell.textContent = formatNumber(rate.buy_rate, 4);
                if (sellCell) sellCell.textContent = formatNumber(rate.sell_rate, 4);
                if (changeCell) {
                    const change = parseFloat(rate.change_percent) || 0;
                    changeCell.textContent = `${changeArrow(change)} % ${formatNumber(Math.abs(change), 2)}`;
                    changeCell.className = `text-right rate-change ${changeClass(change)}`;
                }
            });

            console.log(`[Cybokron] Rates refreshed: ${json.count} rates`);

        } catch (error) {
            console.error('[Cybokron] Failed to refresh rates:', error);
        }
    }

    /**
     * Format number for the active UI locale.
     */
    function formatNumber(value, decimals = 4) {
        const numeric = parseFloat(value);
        if (Number.isNaN(numeric)) return '0';

        return numeric.toLocaleString(numberLocale, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }

    /**
     * Get arrow for change direction.
     */
    function changeArrow(change) {
        if (change > 0) return '▲';
        if (change < 0) return '▼';
        return '–';
    }

    /**
     * Get CSS class for change.
     */
    function changeClass(change) {
        if (change > 0) return 'text-success';
        if (change < 0) return 'text-danger';
        return 'text-muted';
    }

    if (document.querySelector('.rates-table')) {
        setInterval(refreshRates, REFRESH_INTERVAL);
    }

    console.log(`[Cybokron] Dashboard loaded (locale: ${appLocale}).`);
})();
