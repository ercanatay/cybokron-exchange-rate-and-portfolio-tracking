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
        navigator.serviceWorker.register('sw.js').catch(function () {});
    }
})();
