/**
 * leverage.js — Leverage Rule Management UI
 * Cybokron Exchange Rate & Portfolio Tracking
 */
(function () {
    'use strict';

    // ─── DOM References ──────────────────────────────────────────────────────
    var modal = document.getElementById('rule-modal');
    var modalTitle = document.getElementById('modal-title');
    var modalClose = document.getElementById('modal-close');
    var btnNewRule = document.getElementById('btn-new-rule');
    var formAction = document.getElementById('form-action');
    var formRuleId = document.getElementById('form-rule-id');
    var formSubmitBtn = document.getElementById('form-submit-btn');
    var formCancelBtn = document.getElementById('form-cancel-btn');
    var ruleForm = document.getElementById('rule-form');

    var nameInput = document.getElementById('rule-name');
    var currencySelect = document.getElementById('rule-currency');
    var referencePriceInput = document.getElementById('rule-reference-price');
    var buyThresholdInput = document.getElementById('rule-buy-threshold');
    var sellThresholdInput = document.getElementById('rule-sell-threshold');
    var aiEnabledCheckbox = document.getElementById('rule-ai-enabled');
    var strategyTextarea = document.getElementById('rule-strategy');
    var strategyChars = document.getElementById('strategy-chars');
    var btnGetCurrentPrice = document.getElementById('btn-get-current-price');

    var groupSelectGroup = document.getElementById('group-select-group');
    var tagSelectGroup = document.getElementById('tag-select-group');
    var sourceGroupSelect = document.getElementById('source-group');
    var sourceTagSelect = document.getElementById('source-tag');

    // New field references
    var trailingStopCheckbox = document.getElementById('trailing_stop_enabled');
    var trailingStopOptions = document.getElementById('trailing-stop-options');
    var trailingStopPctInput = document.getElementById('trailing_stop_pct');
    var buyThresholdWeakInput = document.getElementById('buy_threshold_weak');
    var sellThresholdWeakInput = document.getElementById('sell_threshold_weak');

    // ─── Modal Open/Close ────────────────────────────────────────────────────

    function openModal() {
        if (modal) modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        if (modal) modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    function resetForm() {
        if (ruleForm) ruleForm.reset();
        if (formAction) formAction.value = 'create_rule';
        if (formRuleId) formRuleId.value = '';
        if (modalTitle) modalTitle.textContent = window.leverageFormTitleCreate || 'New Rule';
        if (formSubmitBtn) formSubmitBtn.textContent = window.leverageFormSubmitCreate || 'Create';
        if (buyThresholdInput) buyThresholdInput.value = '-15.00';
        if (sellThresholdInput) sellThresholdInput.value = '30.00';
        if (aiEnabledCheckbox) aiEnabledCheckbox.checked = true;
        if (buyThresholdWeakInput) buyThresholdWeakInput.value = '';
        if (sellThresholdWeakInput) sellThresholdWeakInput.value = '';
        if (trailingStopCheckbox) trailingStopCheckbox.checked = false;
        if (trailingStopOptions) trailingStopOptions.style.display = 'none';
        if (trailingStopPctInput) trailingStopPctInput.value = '';
        var tsTypeRadios = document.querySelectorAll('input[name="trailing_stop_type"]');
        tsTypeRadios.forEach(function (radio) {
            radio.checked = radio.value === 'auto';
        });
        updateSourceTypeVisibility('currency');
        updateStrategyCounter();
    }

    // New Rule button
    if (btnNewRule) {
        btnNewRule.addEventListener('click', function () {
            resetForm();
            openModal();
        });
    }

    // Close modal
    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }
    if (formCancelBtn) {
        formCancelBtn.addEventListener('click', closeModal);
    }

    // Close on overlay click
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (modal && modal.classList.contains('open')) {
                closeModal();
            }
            var backtestModal = document.getElementById('backtest-modal');
            if (backtestModal && backtestModal.classList.contains('open')) {
                if (typeof closeBacktestModal === 'function') closeBacktestModal();
            }
            var glossaryModal = document.getElementById('glossary-modal');
            if (glossaryModal && glossaryModal.classList.contains('open')) {
                if (typeof closeGlossaryModal === 'function') closeGlossaryModal();
            }
        }
    });

    // Backtest modal overlay click
    var backtestModalEl = document.getElementById('backtest-modal');
    if (backtestModalEl) {
        backtestModalEl.addEventListener('click', function (e) {
            if (e.target === backtestModalEl) {
                if (typeof closeBacktestModal === 'function') closeBacktestModal();
            }
        });
    }

    // ─── Source Type Radio Handler ────────────────────────────────────────────

    function updateSourceTypeVisibility(sourceType) {
        if (groupSelectGroup) groupSelectGroup.style.display = sourceType === 'group' ? '' : 'none';
        if (tagSelectGroup) tagSelectGroup.style.display = sourceType === 'tag' ? '' : 'none';

        // Disable the hidden select so its value is not submitted
        if (sourceGroupSelect) sourceGroupSelect.disabled = sourceType !== 'group';
        if (sourceTagSelect) sourceTagSelect.disabled = sourceType !== 'tag';
    }

    var sourceRadios = document.querySelectorAll('input[name="source_type"]');
    sourceRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            updateSourceTypeVisibility(this.value);
        });
    });

    // Initialize visibility
    updateSourceTypeVisibility('currency');

    // ─── Trailing Stop Checkbox Toggle ────────────────────────────────────

    if (trailingStopCheckbox) {
        trailingStopCheckbox.addEventListener('change', function () {
            if (trailingStopOptions) {
                trailingStopOptions.style.display = this.checked ? 'block' : 'none';
            }
        });
    }

    // ─── Strategy Context Character Counter ──────────────────────────────────

    function updateStrategyCounter() {
        if (strategyTextarea && strategyChars) {
            strategyChars.textContent = strategyTextarea.value.length;
        }
    }

    if (strategyTextarea) {
        strategyTextarea.addEventListener('input', updateStrategyCounter);
    }

    // ─── Get Current Price Button ────────────────────────────────────────────

    if (btnGetCurrentPrice) {
        btnGetCurrentPrice.addEventListener('click', function () {
            var currencyCode = currencySelect ? currencySelect.value : '';
            if (!currencyCode) {
                currencySelect.focus();
                return;
            }

            // First try from the pre-loaded rates map
            var rates = window.leverageCurrentRates || {};
            if (rates[currencyCode] && rates[currencyCode] > 0) {
                if (referencePriceInput) {
                    referencePriceInput.value = parseFloat(rates[currencyCode]).toFixed(2);
                }
                return;
            }

            // Fallback: fetch from API
            btnGetCurrentPrice.disabled = true;
            btnGetCurrentPrice.textContent = '...';

            fetch('api.php?action=rates&currency=' + encodeURIComponent(currencyCode))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.status === 'ok' && data.data && data.data.length > 0) {
                        // Find best sell rate
                        var bestRate = 0;
                        data.data.forEach(function (item) {
                            var sell = parseFloat(item.sell_rate || 0);
                            if (sell > bestRate) bestRate = sell;
                        });
                        if (bestRate > 0 && referencePriceInput) {
                            referencePriceInput.value = bestRate.toFixed(2);
                        }
                    }
                })
                .catch(function () {
                    // Silently fail
                })
                .finally(function () {
                    btnGetCurrentPrice.disabled = false;
                    btnGetCurrentPrice.textContent = window.leverageFormGetCurrent || btnGetCurrentPrice.getAttribute('data-original-text') || btnGetCurrentPrice.textContent;
                });

            // Store original text for restoration
            if (!btnGetCurrentPrice.getAttribute('data-original-text')) {
                btnGetCurrentPrice.setAttribute('data-original-text', btnGetCurrentPrice.textContent);
            }
        });
    }

    // ─── Edit Rule ───────────────────────────────────────────────────────────

    var editButtons = document.querySelectorAll('.btn-edit-rule');
    editButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = btn.closest('.leverage-rule-card');
            if (!card) return;

            // Populate modal from data attributes
            if (formAction) formAction.value = 'update_rule';
            if (formRuleId) formRuleId.value = card.getAttribute('data-rule-id') || '';
            if (modalTitle) modalTitle.textContent = window.leverageFormTitleEdit || 'Edit Rule';
            if (formSubmitBtn) formSubmitBtn.textContent = window.leverageFormSubmitUpdate || 'Update';

            if (nameInput) nameInput.value = card.getAttribute('data-name') || '';
            if (currencySelect) currencySelect.value = card.getAttribute('data-currency-code') || '';
            if (referencePriceInput) referencePriceInput.value = card.getAttribute('data-reference-price') || '';
            if (buyThresholdInput) buyThresholdInput.value = card.getAttribute('data-buy-threshold') || '-15.00';
            if (sellThresholdInput) sellThresholdInput.value = card.getAttribute('data-sell-threshold') || '30.00';
            if (aiEnabledCheckbox) aiEnabledCheckbox.checked = card.getAttribute('data-ai-enabled') === '1';
            if (strategyTextarea) strategyTextarea.value = card.getAttribute('data-strategy-context') || '';

            // Source type
            var sourceType = card.getAttribute('data-source-type') || 'currency';
            var sourceId = card.getAttribute('data-source-id') || '';
            sourceRadios.forEach(function (radio) {
                radio.checked = radio.value === sourceType;
            });
            updateSourceTypeVisibility(sourceType);

            if (sourceType === 'group' && sourceGroupSelect) {
                sourceGroupSelect.value = sourceId;
            } else if (sourceType === 'tag' && sourceTagSelect) {
                sourceTagSelect.value = sourceId;
            }

            // Weak thresholds
            if (buyThresholdWeakInput) buyThresholdWeakInput.value = card.getAttribute('data-buy-threshold-weak') || '';
            if (sellThresholdWeakInput) sellThresholdWeakInput.value = card.getAttribute('data-sell-threshold-weak') || '';

            // Trailing stop
            var tsEnabled = card.getAttribute('data-trailing-stop-enabled') === '1';
            if (trailingStopCheckbox) trailingStopCheckbox.checked = tsEnabled;
            if (trailingStopOptions) trailingStopOptions.style.display = tsEnabled ? 'block' : 'none';

            var tsType = card.getAttribute('data-trailing-stop-type') || 'auto';
            var tsTypeRadios = document.querySelectorAll('input[name="trailing_stop_type"]');
            tsTypeRadios.forEach(function (radio) {
                radio.checked = radio.value === tsType;
            });

            if (trailingStopPctInput) trailingStopPctInput.value = card.getAttribute('data-trailing-stop-pct') || '';

            updateStrategyCounter();
            openModal();
        });
    });

    // ─── Delete Confirmation ─────────────────────────────────────────────────

    var deleteForms = document.querySelectorAll('.form-delete-rule');
    deleteForms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var confirmText = window.leverageDeleteConfirmText || 'Are you sure you want to delete this rule?';
            if (!confirm(confirmText)) {
                e.preventDefault();
            }
        });
    });

    // ─── Client-side Form Validation ─────────────────────────────────────────

    if (ruleForm) {
        ruleForm.addEventListener('submit', function (e) {
            var name = nameInput ? nameInput.value.trim() : '';
            var currency = currencySelect ? currencySelect.value : '';
            var refPrice = referencePriceInput ? parseFloat(referencePriceInput.value) : 0;

            if (!name) {
                e.preventDefault();
                nameInput.focus();
                return;
            }
            if (!currency) {
                e.preventDefault();
                currencySelect.focus();
                return;
            }
            if (!refPrice || refPrice <= 0) {
                e.preventDefault();
                referencePriceInput.focus();
                return;
            }
        });
    }
})();
