/**
 * Portfolio Analytics — Pie chart for distribution
 */
(function () {
    const data = window.portfolioDistribution;
    if (!data || !data.length || typeof Chart === 'undefined') return;

    const canvas = document.getElementById('portfolio-pie-chart');
    if (!canvas) return;

    const colors = ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];
    const config = {
        type: 'doughnut',
        data: {
            labels: data.map((d) => d.currency_code),
            datasets: [{
                data: data.map((d) => d.value),
                backgroundColor: data.map((_, i) => colors[i % colors.length]),
                borderWidth: 2,
                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || '#0f1117',
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                            const locale = (document.documentElement.lang || 'tr').toLowerCase();
                            const numberLocale = locale === 'tr' ? 'tr-TR' : 'en-US';
                            return ctx.label + ': ' + new Intl.NumberFormat(numberLocale, {
                                style: 'currency',
                                currency: 'TRY',
                                minimumFractionDigits: 0,
                            }).format(ctx.raw) + ' (' + pct + '%)';
                        },
                    },
                },
            },
        },
    };

    new Chart(canvas, config);
})();
