/**
 * Cybokron Exchange Rate & Portfolio Tracking
 * Dashboard JavaScript
 */

(function () {
    'use strict';

    const BASE_REFRESH_INTERVAL = 5 * 60 * 1000;
    const MAX_REFRESH_INTERVAL = 15 * 60 * 1000;
    const appLocale = (document.documentElement.lang || 'tr').toLowerCase();
    const numberLocale = appLocale === 'tr' ? 'tr-TR' : 'en-US';
    const hasRatesTable = document.querySelector('.rates-table') !== null;
    const refreshStatus = document.getElementById('rates-refresh-status');
    const refreshStatusTemplate = refreshStatus ? (refreshStatus.getAttribute('data-updated-template') || '') : '';
    const rateRowCache = hasRatesTable ? buildRateRowCache() : new Map();
    const numberFormatterCache = new Map();
    let latestRatesVersion = '';
    let unchangedRefreshCount = 0;
    let refreshTimerId = null;

    /**
     * Cache row/cell nodes once instead of querying selectors on every refresh.
     * This removes roughly 4 selector lookups per updated rate on each tick.
     */
    function buildRateRowCache() {
        const cache = new Map();
        const rows = document.querySelectorAll('tr[data-currency][data-bank]');

        rows.forEach(row => {
            const currencyCode = row.getAttribute('data-currency');
            const bankSlug = row.getAttribute('data-bank');
            if (!currencyCode || !bankSlug) return;

            cache.set(`${bankSlug}::${currencyCode}`, {
                buyCell: row.querySelector('.rate-buy'),
                sellCell: row.querySelector('.rate-sell'),
                changeCell: row.querySelector('.rate-change'),
            });
        });

        return cache;
    }

    /**
     * Fetch latest rates from API and update the table.
     */
    async function refreshRates() {
        try {
            const versionQuery = latestRatesVersion !== ''
                ? `&version=${encodeURIComponent(latestRatesVersion)}`
                : '';
            const response = await fetch(`api.php?action=rates&compact=1${versionQuery}`, { cache: 'no-store' });
            const json = await response.json();

            if (json.status !== 'ok') return;

            if (typeof json.version === 'string' && json.version !== '') {
                latestRatesVersion = json.version;
            }

            if (json.unchanged === true || !json.data) {
                unchangedRefreshCount = Math.min(unchangedRefreshCount + 1, 2);
                return;
            }

            unchangedRefreshCount = 0;

            json.data.forEach(rate => {
                const rowCells = rateRowCache.get(`${rate.bank_slug}::${rate.currency_code}`);
                if (!rowCells) return;
                const { buyCell, sellCell, changeCell } = rowCells;

                // Skip DOM writes when content/class is unchanged to avoid extra style/layout work.
                if (buyCell) {
                    const buyText = formatNumber(rate.buy_rate, 4);
                    if (buyCell.textContent !== buyText) {
                        buyCell.textContent = buyText;
                    }
                }

                if (sellCell) {
                    const sellText = formatNumber(rate.sell_rate, 4);
                    if (sellCell.textContent !== sellText) {
                        sellCell.textContent = sellText;
                    }
                }

                if (changeCell) {
                    const change = parseFloat(rate.change_percent) || 0;
                    const changeText = `${changeArrow(change)} % ${formatNumber(Math.abs(change), 2)}`;
                    const changeClassName = `text-right rate-change ${changeClass(change)}`;

                    if (changeCell.textContent !== changeText) {
                        changeCell.textContent = changeText;
                    }
                    if (changeCell.className !== changeClassName) {
                        changeCell.className = changeClassName;
                    }
                }
            });

            announceRefresh();
            console.log(`[Cybokron] Rates refreshed: ${json.count} rates`);

        } catch (error) {
            console.error('[Cybokron] Failed to refresh rates:', error);
        } finally {
            if (hasRatesTable) {
                scheduleNextRefresh();
            }
        }
    }

    /**
     * Back off polling when data is stable and while the tab is hidden.
     */
    function getNextRefreshDelay() {
        const adaptiveStep = Math.min(unchangedRefreshCount, 2);
        const adaptiveDelay = BASE_REFRESH_INTERVAL + (adaptiveStep * 5 * 60 * 1000);
        return document.hidden ? MAX_REFRESH_INTERVAL : adaptiveDelay;
    }

    /**
     * Schedule the next refresh tick with adaptive visibility-aware timing.
     */
    function scheduleNextRefresh(delayMs = getNextRefreshDelay()) {
        if (refreshTimerId !== null) {
            clearTimeout(refreshTimerId);
        }

        refreshTimerId = setTimeout(() => {
            refreshRates();
        }, delayMs);
    }

    /**
     * Format number for the active UI locale.
     */
    function formatNumber(value, decimals = 4) {
        const numeric = parseFloat(value);
        if (Number.isNaN(numeric)) return '0';

        return getNumberFormatter(decimals).format(numeric);
    }

    /**
     * Reuse Intl.NumberFormat instances to reduce allocations in refresh loop.
     */
    function getNumberFormatter(decimals) {
        if (!numberFormatterCache.has(decimals)) {
            numberFormatterCache.set(decimals, new Intl.NumberFormat(numberLocale, {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }));
        }

        return numberFormatterCache.get(decimals);
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

    /**
     * Announce successful auto-refresh to assistive technologies.
     */
    function announceRefresh() {
        if (!refreshStatus || refreshStatusTemplate === '') {
            return;
        }

        const time = new Intl.DateTimeFormat(numberLocale, {
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date());

        refreshStatus.textContent = refreshStatusTemplate.replace('__TIME__', time);
    }

    if (hasRatesTable) {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                scheduleNextRefresh(MAX_REFRESH_INTERVAL);
                return;
            }

            // When users return to the tab, refresh quickly once.
            scheduleNextRefresh(1000);
        });

        scheduleNextRefresh(BASE_REFRESH_INTERVAL);
    }

    console.log(`[Cybokron] Dashboard loaded (locale: ${appLocale}).`);
})();
