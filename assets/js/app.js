/**
 * Cybokron Exchange Rate & Portfolio Tracking
 * Dashboard JavaScript
 */

(function () {
    'use strict';

    // Auto-refresh rates every 5 minutes
    const REFRESH_INTERVAL = 5 * 60 * 1000;

    /**
     * Fetch latest rates from API and update the table.
     */
    async function refreshRates() {
        try {
            const response = await fetch('api.php?action=rates');
            const json = await response.json();

            if (json.status !== 'ok' || !json.data) return;

            // Update rate cells if they exist
            json.data.forEach(rate => {
                const row = document.querySelector(`[data-currency="${rate.currency_code}"][data-bank="${rate.bank_slug}"]`);
                if (!row) return;

                const buyCell = row.querySelector('.rate-buy');
                const sellCell = row.querySelector('.rate-sell');
                const changeCell = row.querySelector('.rate-change');

                if (buyCell) buyCell.textContent = formatNumber(rate.buy_rate);
                if (sellCell) sellCell.textContent = formatNumber(rate.sell_rate);
                if (changeCell) {
                    const change = parseFloat(rate.change_percent) || 0;
                    changeCell.textContent = `${changeArrow(change)} % ${Math.abs(change).toFixed(2).replace('.', ',')}`;
                    changeCell.className = `text-right ${changeClass(change)}`;
                }
            });

            console.log(`[Cybokron] Rates refreshed: ${json.count} rates`);

        } catch (error) {
            console.error('[Cybokron] Failed to refresh rates:', error);
        }
    }

    /**
     * Format a number with Turkish locale.
     */
    function formatNumber(value, decimals = 4) {
        return parseFloat(value).toLocaleString('tr-TR', {
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

    // Start auto-refresh if on the rates page
    if (document.querySelector('.rates-table')) {
        setInterval(refreshRates, REFRESH_INTERVAL);
    }

    console.log('[Cybokron] Dashboard loaded.');
})();
