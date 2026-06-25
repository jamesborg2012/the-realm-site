/**
 * TRM Mega Nav — off-canvas category drawer behaviour (3 levels).
 *
 * - The "All Categories" trigger opens a left drawer.
 * - Selecting a main reveals its child pane; selecting a child reveals its grandchild pane.
 *     Desktop (>=769px): panes are side-by-side columns; hovering opens them, and the first main
 *                         is primed on open so the column isn't empty. Hovering a childless item
 *                         collapses deeper columns.
 *     Mobile  (<769px) : panes slide over each other; a Back button steps up one level.
 * - Close via the X, the overlay, or Escape.
 *
 * Panes are flat ([data-trm-nav-pane="cat-{id}"]) and tagged with data-trm-nav-level (2 or 3).
 * Opening a pane clears any active pane at the same level or deeper, so switching a main collapses
 * a stale grandchild column. Vanilla JS, no dependencies.
 */
(function () {
    'use strict';

    var drawer = document.getElementById('trm-nav-drawer');
    if (!drawer) {
        return;
    }

    var triggers = document.querySelectorAll('[data-trm-nav-trigger]');
    var openBtns = drawer.querySelectorAll('[data-trm-nav-open]');
    var clearEls = drawer.querySelectorAll('[data-trm-nav-clear]');
    var panes = drawer.querySelectorAll('[data-trm-nav-pane]');
    var closeEls = drawer.querySelectorAll('[data-trm-nav-close]');
    var backEls = drawer.querySelectorAll('[data-trm-nav-back]');

    var desktopMQ = window.matchMedia('(min-width: 769px)');
    function isDesktop() {
        return desktopMQ.matches;
    }

    function paneLevel(pane) {
        return parseInt(pane.getAttribute('data-trm-nav-level'), 10) || 2;
    }

    function paneForId(id) {
        return drawer.querySelector('[data-trm-nav-pane="' + id + '"]');
    }

    /** Deactivate every pane (and its opener button) at `level` or deeper. */
    function clearFromLevel(level) {
        for (var i = 0; i < panes.length; i++) {
            if (paneLevel(panes[i]) >= level) {
                panes[i].classList.remove('is-active');
                panes[i].setAttribute('aria-hidden', 'true');
            }
        }
        for (var j = 0; j < openBtns.length; j++) {
            var target = paneForId(openBtns[j].getAttribute('data-trm-nav-open'));
            if (target && paneLevel(target) >= level) {
                openBtns[j].classList.remove('is-current');
                openBtns[j].setAttribute('aria-expanded', 'false');
            }
        }
        syncLevelClasses();
    }

    function syncLevelClasses() {
        var hasL2 = false;
        var hasL3 = false;
        for (var i = 0; i < panes.length; i++) {
            if (panes[i].classList.contains('is-active')) {
                if (paneLevel(panes[i]) >= 3) {
                    hasL3 = true;
                } else {
                    hasL2 = true;
                }
            }
        }
        drawer.classList.toggle('has-l2', hasL2);
        drawer.classList.toggle('has-l3', hasL3);
    }

    function openPane(id) {
        var pane = paneForId(id);
        if (!pane) {
            return;
        }
        var level = paneLevel(pane);
        clearFromLevel(level); // collapse same-level + deeper before opening this one
        pane.classList.add('is-active');
        pane.setAttribute('aria-hidden', 'false');

        var btn = drawer.querySelector('[data-trm-nav-open="' + id + '"]');
        if (btn) {
            btn.classList.add('is-current');
            btn.setAttribute('aria-expanded', 'true');
        }
        syncLevelClasses();
    }

    function clearAll() {
        clearFromLevel(2);
    }

    function primeFirstMain() {
        if (isDesktop() && openBtns.length) {
            // First [data-trm-nav-open] in DOM is the first main with children.
            openPane(openBtns[0].getAttribute('data-trm-nav-open'));
        }
    }

    function openDrawer() {
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('trm-nav-lock');
        for (var i = 0; i < triggers.length; i++) {
            triggers[i].setAttribute('aria-expanded', 'true');
        }
        primeFirstMain();
    }

    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('trm-nav-lock');
        clearAll();
        for (var i = 0; i < triggers.length; i++) {
            triggers[i].setAttribute('aria-expanded', 'false');
        }
    }

    for (var t = 0; t < triggers.length; t++) {
        triggers[t].addEventListener('click', function (e) {
            e.preventDefault();
            openDrawer();
        });
    }

    for (var c = 0; c < closeEls.length; c++) {
        closeEls[c].addEventListener('click', closeDrawer);
    }

    // Back steps up one level: collapse this pane (and anything deeper).
    for (var b = 0; b < backEls.length; b++) {
        backEls[b].addEventListener('click', function () {
            var pane = this.closest('[data-trm-nav-pane]');
            clearFromLevel(pane ? paneLevel(pane) : 2);
        });
    }

    for (var m = 0; m < openBtns.length; m++) {
        (function (btn) {
            var id = btn.getAttribute('data-trm-nav-open');
            btn.addEventListener('click', function () {
                openPane(id);
            });
            btn.addEventListener('mouseenter', function () {
                if (isDesktop()) {
                    openPane(id);
                }
            });
        })(openBtns[m]);
    }

    // Hovering a childless item on desktop collapses the columns it would otherwise leave stale.
    for (var l = 0; l < clearEls.length; l++) {
        (function (el) {
            var level = parseInt(el.getAttribute('data-trm-nav-clear'), 10) || 2;
            el.addEventListener('mouseenter', function () {
                if (isDesktop()) {
                    clearFromLevel(level);
                }
            });
        })(clearEls[l]);
    }

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && drawer.classList.contains('is-open')) {
            closeDrawer();
        }
    });

    // Keep state sane when crossing the desktop/mobile boundary.
    var onBreakpointChange = function () {
        clearAll();
        if (drawer.classList.contains('is-open')) {
            primeFirstMain();
        }
    };
    if (desktopMQ.addEventListener) {
        desktopMQ.addEventListener('change', onBreakpointChange);
    } else if (desktopMQ.addListener) {
        desktopMQ.addListener(onBreakpointChange);
    }
})();
