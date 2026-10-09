/**
 * Cybokron — declarative event bindings.
 *
 * Replaces inline on* attributes so the Content-Security-Policy can drop
 * 'unsafe-inline' from script-src. Page functions stay global; markup names
 * them through data attributes:
 *
 *   data-click="fnName"            call window.fnName on click
 *   data-change="fnName"           call window.fnName on change
 *   data-submit="fnName"           call window.fnName on form submit; a false
 *                                  return value cancels the submit
 *   data-args='["a", 1, "$el"]'    JSON argument list; "$el" is the element,
 *                                  "$value" is the element's value
 *                                  (default: no arguments)
 *   data-confirm="message"         ask before a form submits, or before a
 *                                  button's click goes through
 *   data-toggle-hidden="id"        toggle the "hidden" class on #id
 *   data-toggle-password="id"      switch #id between password and text
 *   data-autosubmit                on change, submit the element's form
 */
(function () {
    'use strict';

    function resolveArgs(el) {
        var raw = el.getAttribute('data-args');
        if (!raw) {
            return [];
        }
        var args;
        try {
            args = JSON.parse(raw);
        } catch (e) {
            return [];
        }
        if (!Array.isArray(args)) {
            return [];
        }
        return args.map(function (arg) {
            if (arg === '$el') {
                return el;
            }
            if (arg === '$value') {
                return el.value;
            }
            return arg;
        });
    }

    function invoke(el, attr) {
        var name = el.getAttribute(attr);
        var fn = name ? window[name] : null;
        if (typeof fn !== 'function') {
            return undefined;
        }
        return fn.apply(el, resolveArgs(el));
    }

    document.addEventListener('click', function (e) {
        var confirmEl = e.target.closest('button[data-confirm], input[data-confirm]');
        if (confirmEl && !confirm(confirmEl.getAttribute('data-confirm'))) {
            e.preventDefault();
            return;
        }

        var hiddenToggle = e.target.closest('[data-toggle-hidden]');
        if (hiddenToggle) {
            var target = document.getElementById(hiddenToggle.getAttribute('data-toggle-hidden'));
            if (target) {
                target.classList.toggle('hidden');
            }
            return;
        }

        var passwordToggle = e.target.closest('[data-toggle-password]');
        if (passwordToggle) {
            var input = document.getElementById(passwordToggle.getAttribute('data-toggle-password'));
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
                passwordToggle.textContent = input.type === 'password' ? '👁️' : '🙈';
            }
            return;
        }

        var clickEl = e.target.closest('[data-click]');
        if (clickEl) {
            invoke(clickEl, 'data-click');
        }
    });

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!(el instanceof Element)) {
            return;
        }
        if (el.hasAttribute('data-autosubmit') && el.form) {
            if (typeof el.form.requestSubmit === 'function') {
                el.form.requestSubmit();
            } else {
                el.form.submit();
            }
            return;
        }
        if (el.hasAttribute('data-change')) {
            invoke(el, 'data-change');
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (form.hasAttribute('data-confirm') && !confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
            return;
        }
        if (form.hasAttribute('data-submit') && invoke(form, 'data-submit') === false) {
            e.preventDefault();
        }
    });
})();
