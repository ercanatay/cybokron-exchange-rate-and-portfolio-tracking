// Cybokron bootstrap — moved from inline script for CSP compliance
(function () {
    var el = document.getElementById('cybokron-rates-data');
    if (el) {
        try {
            window.cybokronRates = JSON.parse(el.textContent || '[]');
        } catch (e) {
            window.cybokronRates = [];
        }
    } else {
        window.cybokronRates = [];
    }
    if ('serviceWorker' in navigator) {
        // Keep this version in step with CACHE_NAME in sw.js. updateViaCache only
        // bypasses the browser's HTTP cache — the CDN still serves the old file for
        // an unchanged URL, so the query has to change for a new worker to install.
        navigator.serviceWorker.register('sw.js?v=5', { updateViaCache: 'none' }).catch(function () {});
    }
})();
