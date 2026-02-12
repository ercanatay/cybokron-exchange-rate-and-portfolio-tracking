/**
 * Cybokron — Dark/Light theme toggle
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'cybokron_theme';

    function getSystemTheme() {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function getStoredTheme() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            return stored === 'light' || stored === 'dark' ? stored : null;
        } catch {
            return null;
        }
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.textContent = theme === 'dark' ? '☀️' : '🌙';
            btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
        }
    }

    function initTheme() {
        const stored = getStoredTheme();
        const theme = stored || getSystemTheme();
        applyTheme(theme);
        if (!stored) {
            document.documentElement.removeAttribute('data-theme');
        }
    }

    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme') || getSystemTheme();
        const next = current === 'dark' ? 'light' : 'dark';
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch (_) {}
        applyTheme(next);
    }

    document.addEventListener('DOMContentLoaded', initTheme);

    document.addEventListener('click', (e) => {
        if (e.target.id === 'theme-toggle' || e.target.closest('#theme-toggle')) {
            toggleTheme();
        }
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (!getStoredTheme()) {
            initTheme();
        }
    });
})();
