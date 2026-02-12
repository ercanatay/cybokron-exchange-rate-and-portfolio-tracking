/**
 * Currency Converter Widget
 * Cybokron Exchange Rate & Portfolio Tracking
 */

(function () {
    'use strict';

    const ratesData = window.cybokronRates || {};
    const banks = Object.keys(ratesData);

    if (banks.length === 0) return;

    const amountEl = document.getElementById('converter-amount');
    const fromEl = document.getElementById('converter-from');
    const toEl = document.getElementById('converter-to');
    const rateTypeEl = document.getElementById('converter-rate-type');
    const bankEl = document.getElementById('converter-bank');
    const resultEl = document.getElementById('converter-result');

    if (!amountEl || !fromEl || !toEl || !rateTypeEl || !bankEl || !resultEl) return;

    const CURRENCY_TRY = 'TRY';

    function getRates(bankSlug) {
        return ratesData[bankSlug] || {};
    }

    function getRate(bankSlug, currencyCode, useBuy) {
        const bankRates = getRates(bankSlug);
        const currency = bankRates[currencyCode];
        if (!currency) return null;
        return useBuy ? parseFloat(currency.buy) : parseFloat(currency.sell);
    }

    function calculate() {
        const amount = parseFloat(amountEl.value) || 0;
        const from = fromEl.value;
        const to = toEl.value;
        const bankSlug = bankEl.value;
        const useBuyRate = rateTypeEl.value === 'buy';

        if (from === to) {
            resultEl.textContent = amount.toLocaleString(undefined, { maximumFractionDigits: 4 });
            return;
        }

        const bankRates = getRates(bankSlug);
        if (Object.keys(bankRates).length === 0) {
            resultEl.textContent = '—';
            return;
        }

        let result;
        if (from === CURRENCY_TRY) {
            const rate = getRate(bankSlug, to, !useBuyRate);
            if (rate === null || rate <= 0) {
                resultEl.textContent = '—';
                return;
            }
            result = amount / rate;
        } else if (to === CURRENCY_TRY) {
            const rate = getRate(bankSlug, from, useBuyRate);
            if (rate === null || rate <= 0) {
                resultEl.textContent = '—';
                return;
            }
            result = amount * rate;
        } else {
            const fromToTry = getRate(bankSlug, from, useBuyRate);
            const tryToTo = getRate(bankSlug, to, !useBuyRate);
            if (fromToTry === null || tryToTo === null || fromToTry <= 0 || tryToTo <= 0) {
                resultEl.textContent = '—';
                return;
            }
            result = (amount * fromToTry) / tryToTo;
        }

        resultEl.textContent = result.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    }

    [amountEl, fromEl, toEl, rateTypeEl, bankEl].forEach(function (el) {
        el.addEventListener('change', calculate);
        el.addEventListener('input', calculate);
    });

    calculate();
})();
