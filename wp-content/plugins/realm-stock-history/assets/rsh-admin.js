/**
 * Realm Stock History — admin panel loader.
 *
 * Serves both the product-editor metabox (toggle button) and the central
 * WooCommerce -> Stock History page (auto-loading panel). One AJAX endpoint,
 * shared pagination. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	if ( typeof window.rshAdmin === 'undefined' ) {
		return;
	}

	var cfg = window.rshAdmin;

	/**
	 * Fetch a page of history into a panel element.
	 *
	 * @param {HTMLElement} panel     The .rsh-panel container.
	 * @param {number}      productId Product ID.
	 * @param {number}      page      1-based page number.
	 */
	function loadHistory( panel, productId, page ) {
		panel.classList.add( 'rsh-loading' );
		panel.innerHTML = '<p class="rsh-loading-text">' + escapeHtml( cfg.i18n.loading ) + '</p>';

		var body = new URLSearchParams();
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'product_id', String( productId ) );
		body.set( 'page', String( page ) );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( resp ) {
				return resp.json();
			} )
			.then( function ( json ) {
				panel.classList.remove( 'rsh-loading' );
				if ( json && json.success && json.data && typeof json.data.html === 'string' ) {
					panel.innerHTML = json.data.html;
					panel.dataset.loaded = '1';
					panel.dataset.page = String( page );
				} else {
					showError( panel );
				}
			} )
			.catch( function () {
				panel.classList.remove( 'rsh-loading' );
				showError( panel );
			} );
	}

	function showError( panel ) {
		panel.innerHTML = '<p class="rsh-error" role="alert">' + escapeHtml( cfg.i18n.error ) + '</p>';
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( String( str ) ) );
		return div.innerHTML;
	}

	/* ---- Metabox toggle buttons ---- */
	function wireToggle( btn ) {
		var productId = parseInt( btn.getAttribute( 'data-product' ), 10 );
		var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
		if ( ! panel ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var expanded = btn.getAttribute( 'aria-expanded' ) === 'true';

			if ( expanded ) {
				// Hide.
				panel.hidden = true;
				btn.setAttribute( 'aria-expanded', 'false' );
				btn.textContent = btn.getAttribute( 'data-label-show' );
				return;
			}

			// Show — fetch only if not yet loaded.
			panel.hidden = false;
			btn.setAttribute( 'aria-expanded', 'true' );
			btn.textContent = btn.getAttribute( 'data-label-hide' );

			if ( panel.dataset.loaded !== '1' ) {
				loadHistory( panel, productId, 1 );
			}
		} );
	}

	/* ---- Pagination (delegated per panel) ---- */
	function wirePagination( panel ) {
		panel.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.rsh-page-btn' );
			if ( ! btn || btn.disabled || ! panel.contains( btn ) ) {
				return;
			}
			var productId = parseInt( panel.getAttribute( 'data-product' ), 10 );
			var page = parseInt( btn.getAttribute( 'data-page' ), 10 );
			if ( productId && page ) {
				loadHistory( panel, productId, page );
			}
		} );
	}

	function init() {
		var toggles = document.querySelectorAll( '.rsh-toggle' );
		Array.prototype.forEach.call( toggles, wireToggle );

		var panels = document.querySelectorAll( '.rsh-panel' );
		Array.prototype.forEach.call( panels, function ( panel ) {
			wirePagination( panel );

			// Central page: auto-load on render.
			if ( panel.getAttribute( 'data-rsh-auto' ) === '1' ) {
				var productId = parseInt( panel.getAttribute( 'data-product' ), 10 );
				if ( productId ) {
					loadHistory( panel, productId, 1 );
				}
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
