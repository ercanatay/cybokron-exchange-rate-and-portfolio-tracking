/**
 * Cybokron — Live Repair Progress (EventSource Client)
 */
(function () {
    'use strict';

    var container = document.getElementById('repair-live-card');
    if (!container) return;

    var STEPS = [
        'fetch_html',
        'check_enabled',
        'cooldown_check',
        'generate_config',
        'validate_config',
        'save_config',
        'github_commit',
        'pipeline_complete'
    ];

    var stepperEl = container.querySelector('.repair-stepper');
    var summaryEl = container.querySelector('.repair-summary');
    var btnStart = container.querySelector('.btn-repair');
    var bankSelect = container.querySelector('.repair-bank-select');
    var csrfToken = container.dataset.csrf || '';

    var eventSource = null;

    function getLabel(step) {
        var attr = 'label_' + step;
        return container.dataset[attr] || step;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.textContent;
    }

    function buildStepper() {
        while (stepperEl.firstChild) {
            stepperEl.removeChild(stepperEl.firstChild);
        }
        summaryEl.className = 'repair-summary';
        while (summaryEl.firstChild) {
            summaryEl.removeChild(summaryEl.firstChild);
        }

        STEPS.forEach(function (step, i) {
            var li = document.createElement('li');
            li.className = 'repair-step step-pending';
            li.dataset.step = step;

            var icon = document.createElement('div');
            icon.className = 'repair-step-icon';
            icon.textContent = String(i + 1);

            var content = document.createElement('div');
            content.className = 'repair-step-content';

            var label = document.createElement('div');
            label.className = 'repair-step-label';
            label.textContent = getLabel(step);

            var msg = document.createElement('div');
            msg.className = 'repair-step-message';

            content.appendChild(label);
            content.appendChild(msg);
            li.appendChild(icon);
            li.appendChild(content);
            stepperEl.appendChild(li);
        });
    }

    function updateStep(step, status, message, durationMs) {
        var li = stepperEl.querySelector('[data-step="' + step + '"]');
        if (!li) return;

        li.className = 'repair-step step-' + status;
        var iconEl = li.querySelector('.repair-step-icon');
        var msgEl = li.querySelector('.repair-step-message');

        // Clear icon content safely
        while (iconEl.firstChild) {
            iconEl.removeChild(iconEl.firstChild);
        }

        if (status === 'in_progress') {
            var spinner = document.createElement('div');
            spinner.className = 'repair-spinner';
            iconEl.appendChild(spinner);
        } else if (status === 'success') {
            iconEl.textContent = '\u2713'; // checkmark
        } else if (status === 'error') {
            iconEl.textContent = '\u2717'; // X mark
        } else if (status === 'skipped') {
            iconEl.textContent = '\u2014'; // em dash
        }

        // Clear message content safely
        while (msgEl.firstChild) {
            msgEl.removeChild(msgEl.firstChild);
        }

        var textNode = document.createTextNode(escapeHtml(message || ''));
        msgEl.appendChild(textNode);

        if (durationMs !== null && durationMs !== undefined) {
            var durSpan = document.createElement('span');
            durSpan.className = 'repair-step-duration';
            durSpan.textContent = ' (' + (durationMs / 1000).toFixed(1) + 's)';
            msgEl.appendChild(durSpan);
        }

        li.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function showSummary(status, message, rateCount) {
        var successLabel = container.dataset.summary_success || 'Repair successful';
        var failedLabel = container.dataset.summary_failed || 'Repair failed';
        var ratesLabel = container.dataset.summary_rates || 'rates found';

        summaryEl.className = 'repair-summary visible summary-' + status;

        while (summaryEl.firstChild) {
            summaryEl.removeChild(summaryEl.firstChild);
        }

        var iconSpan = document.createElement('span');
        iconSpan.className = 'repair-summary-icon';
        iconSpan.textContent = status === 'success' ? '\u2705' : '\u274C';

        var textSpan = document.createElement('span');
        if (status === 'success') {
            textSpan.textContent = successLabel + ' \u2014 ' + rateCount + ' ' + ratesLabel;
        } else {
            textSpan.textContent = failedLabel + (message ? ': ' + escapeHtml(message) : '');
        }

        summaryEl.appendChild(iconSpan);
        summaryEl.appendChild(textSpan);
    }

    function cleanupConnection() {
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }
        btnStart.disabled = false;
        btnStart.textContent = container.dataset.btn_start || 'Start Repair';
    }

    function startRepair() {
        var bankId = bankSelect ? bankSelect.value : '';
        if (!bankId) return;

        btnStart.disabled = true;
        btnStart.textContent = container.dataset.btn_running || 'Running...';

        buildStepper();

        var url = 'api_repair_stream.php?bank_id=' + encodeURIComponent(bankId)
            + '&csrf_token=' + encodeURIComponent(csrfToken);

        eventSource = new EventSource(url);

        eventSource.addEventListener('step', function (e) {
            try {
                var data = JSON.parse(e.data);
                updateStep(data.step, data.status, data.message, data.duration_ms);
            } catch (err) {
                // ignore parse errors
            }
        });

        eventSource.addEventListener('complete', function (e) {
            try {
                var data = JSON.parse(e.data);
                showSummary(data.status, data.message, data.rates_count);
            } catch (err) {
                // ignore
            }
            cleanupConnection();
        });

        eventSource.addEventListener('error', function (e) {
            try {
                var data = JSON.parse(e.data);
                showSummary('error', data.message, 0);
            } catch (err) {
                showSummary('error', 'Connection lost', 0);
            }
            cleanupConnection();
        });

        eventSource.onerror = function () {
            if (eventSource && eventSource.readyState === EventSource.CLOSED) {
                return;
            }
            showSummary('error', 'Connection error', 0);
            cleanupConnection();
        };
    }

    if (btnStart) {
        btnStart.addEventListener('click', startRepair);
    }
})();
