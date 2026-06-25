/**
 * TRM Cart — off-canvas cart drawer behaviour.
 *
 * The cart trigger pill opens a right-hand drawer; the overlay, the Close button, or Escape close
 * it. The drawer's contents update themselves via WooCommerce's `widget_shopping_cart_content`
 * fragment, so there's nothing to refresh here. Vanilla JS, no dependencies.
 */
(function () {
    'use strict';

    var drawer = document.getElementById('trm-cart-drawer');
    if (!drawer) {
        return;
    }

    var triggers = document.querySelectorAll('[data-trm-cart-trigger]');
    var closeEls = drawer.querySelectorAll('[data-trm-cart-close]');

    function openDrawer() {
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('trm-cart-lock');
        for (var i = 0; i < triggers.length; i++) {
            triggers[i].setAttribute('aria-expanded', 'true');
        }
    }

    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('trm-cart-lock');
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

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && drawer.classList.contains('is-open')) {
            closeDrawer();
        }
    });
})();
