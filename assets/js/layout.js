/**
 * Cybokron — Fullwidth / Normal layout toggle
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'cybokron_layout';

    function getStoredLayout() {
        try {
            var stored = localStorage.getItem(STORAGE_KEY);
            return stored === 'fullwidth' || stored === 'normal' ? stored : null;
        } catch (e) {
            return null;
        }
    }

    function getDefaultLayout() {
        return document.documentElement.getAttribute('data-layout-default') || 'normal';
    }

    function applyLayout(layout) {
        document.documentElement.setAttribute('data-layout', layout);

        var isFullwidth = layout === 'fullwidth';

        var btns = [
            document.getElementById('layout-toggle'),
            document.getElementById('layout-toggle-mobile')
        ];

        for (var i = 0; i < btns.length; i++) {
            var btn = btns[i];
            if (!btn) continue;

            var icon = btn.querySelector('.layout-icon');
            if (icon) {
                icon.textContent = isFullwidth ? '\u2299' : '\u229E';
            }
            btn.setAttribute('aria-pressed', String(isFullwidth));

            var expandLabel = btn.getAttribute('data-label-expand') || 'Switch to fullwidth';
            var collapseLabel = btn.getAttribute('data-label-collapse') || 'Switch to normal';
            var label = isFullwidth ? collapseLabel : expandLabel;
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        }
    }

    function initLayout() {
        var stored = getStoredLayout();
        var layout = stored || getDefaultLayout();
        applyLayout(layout);
    }

    function toggleLayout() {
        var current = document.documentElement.getAttribute('data-layout') || getDefaultLayout();
        var next = current === 'fullwidth' ? 'normal' : 'fullwidth';
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch (e) {}
        applyLayout(next);
    }

    initLayout();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLayout);
    }

    document.addEventListener('click', function (e) {
        if (e.target.id === 'layout-toggle' || e.target.closest('#layout-toggle') ||
            e.target.id === 'layout-toggle-mobile' || e.target.closest('#layout-toggle-mobile')) {
            toggleLayout();
        }
    });
})();
