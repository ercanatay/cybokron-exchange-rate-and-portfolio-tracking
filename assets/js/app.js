/**
 * Cybokron Exchange Rate Tracker — Frontend JS
 */

document.addEventListener('DOMContentLoaded', function () {
    // Auto-refresh rates every 5 minutes
    const REFRESH_INTERVAL = 5 * 60 * 1000;

    // Check if we're on the rates page
    const rateTable = document.querySelector('.rate-table');
    if (rateTable && window.location.pathname.includes('index')) {
        setInterval(refreshRates, REFRESH_INTERVAL);
    }

    // Highlight rows on hover with animation
    document.querySelectorAll('.rate-table tbody tr').forEach(row => {
        row.style.transition = 'background-color 0.2s ease';
    });
});

/**
 * Refresh rates via API without page reload
 */
async function refreshRates() {
    try {
        const response = await fetch('api.php?action=rates');
        const data = await response.json();

        if (!data.success) return;

        // Update the page title with timestamp
        const updateInfo = document.querySelector('.update-info');
        if (updateInfo && data.data.length > 0) {
            const fetchedAt = new Date(data.data[0].fetched_at);
            const timeStr = fetchedAt.toLocaleString('tr-TR');
            updateInfo.innerHTML = `Son Güncelleme: ${timeStr} <span class="badge">Otomatik</span>`;
        }

        console.log(`[Cybokron] Rates refreshed: ${data.count} currencies`);
    } catch (error) {
        console.warn('[Cybokron] Rate refresh failed:', error.message);
    }
}

/**
 * Format number as Turkish locale
 */
function formatNumber(value, decimals = 4) {
    return new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}
