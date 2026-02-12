/**
 * Cybokron — Rate History Chart
 */
(function () {
    'use strict';

    const canvas = document.getElementById('rate-chart');
    if (!canvas || typeof Chart === 'undefined') return;

    const appLocale = (document.documentElement.lang || 'tr').toLowerCase();
    const numberLocale = appLocale === 'tr' ? 'tr-TR' : 'en-US';

    let chartInstance = null;

    async function fetchHistory(currency, days) {
        const url = `api.php?action=history&currency=${encodeURIComponent(currency)}&days=${days}&limit=500`;
        const res = await fetch(url, { cache: 'no-store' });
        const json = await res.json();
        if (json.status !== 'ok' || !Array.isArray(json.data)) return [];
        return json.data;
    }

    function formatDate(dateStr) {
        return new Intl.DateTimeFormat(numberLocale, {
            month: 'short',
            day: 'numeric',
        }).format(new Date(dateStr));
    }

    function renderChart(currency, days, data) {
        const sorted = [...data].reverse();
        const labels = sorted.map((r) => formatDate(r.scraped_at));
        const buyData = sorted.map((r) => parseFloat(r.buy_rate));
        const sellData = sorted.map((r) => parseFloat(r.sell_rate));

        const chartColor = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#3b82f6';
        const chartColorMuted = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#8b8fa3';

        const config = {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Alış',
                        data: buyData,
                        borderColor: chartColor,
                        backgroundColor: chartColor + '20',
                        fill: true,
                        tension: 0.2,
                    },
                    {
                        label: 'Satış',
                        data: sellData,
                        borderColor: chartColorMuted,
                        backgroundColor: chartColorMuted + '20',
                        fill: true,
                        tension: 0.2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { position: 'top' },
                },
                scales: {
                    x: {
                        ticks: {
                            color: chartColorMuted,
                            maxTicksLimit: 10,
                        },
                        grid: { color: chartColorMuted + '40' },
                    },
                    y: {
                        ticks: {
                            color: chartColorMuted,
                            callback: (v) => new Intl.NumberFormat(numberLocale, {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 4,
                            }).format(v),
                        },
                        grid: { color: chartColorMuted + '40' },
                    },
                },
            },
        };

        if (chartInstance) {
            chartInstance.data = config.data;
            chartInstance.options = config.options;
            chartInstance.update();
        } else {
            chartInstance = new Chart(canvas, config);
        }
    }

    async function loadChart() {
        const currency = document.getElementById('chart-currency')?.value || 'USD';
        const days = parseInt(document.getElementById('chart-days')?.value || '30', 10);
        const data = await fetchHistory(currency, days);
        if (data.length) {
            renderChart(currency, days, data);
        } else {
            if (chartInstance) {
                chartInstance.data.labels = [];
                chartInstance.data.datasets[0].data = [];
                chartInstance.data.datasets[1].data = [];
                chartInstance.update();
            }
        }
    }

    const currencySelect = document.getElementById('chart-currency');
    const daysSelect = document.getElementById('chart-days');
    if (currencySelect) currencySelect.addEventListener('change', loadChart);
    if (daysSelect) daysSelect.addEventListener('change', loadChart);

    loadChart();
})();
