/**
 * Single event countdown timer (item 27).
 *
 * Drives any `[data-trm-countdown]` element whose attribute holds the event
 * start time as an absolute epoch (milliseconds). Ticks every second and
 * removes itself once the target time is reached, so the timer disappears the
 * moment the event starts — matching the server-side "future only" gate that
 * decides whether the element is rendered in the first place.
 */
(function () {
	'use strict';

	function pad( value ) {
		return value < 10 ? '0' + value : String( value );
	}

	function initCountdown( el ) {
		var target = parseInt( el.getAttribute( 'data-trm-countdown' ), 10 );
		if ( ! target ) {
			return;
		}

		var daysEl    = el.querySelector( '[data-countdown-days]' );
		var hoursEl   = el.querySelector( '[data-countdown-hours]' );
		var minutesEl = el.querySelector( '[data-countdown-minutes]' );
		var secondsEl = el.querySelector( '[data-countdown-seconds]' );

		var timer;

		function tick() {
			var diff = target - Date.now();

			if ( diff <= 0 ) {
				if ( timer ) {
					clearInterval( timer );
				}
				el.parentNode.removeChild( el );
				return;
			}

			var totalSeconds = Math.floor( diff / 1000 );
			var days    = Math.floor( totalSeconds / 86400 );
			var hours   = Math.floor( ( totalSeconds % 86400 ) / 3600 );
			var minutes = Math.floor( ( totalSeconds % 3600 ) / 60 );
			var seconds = totalSeconds % 60;

			if ( daysEl ) {
				daysEl.textContent = pad( days );
			}
			if ( hoursEl ) {
				hoursEl.textContent = pad( hours );
			}
			if ( minutesEl ) {
				minutesEl.textContent = pad( minutes );
			}
			if ( secondsEl ) {
				secondsEl.textContent = pad( seconds );
			}
		}

		tick();
		timer = setInterval( tick, 1000 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var nodes = document.querySelectorAll( '[data-trm-countdown]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			initCountdown( nodes[ i ] );
		}
	} );
})();
