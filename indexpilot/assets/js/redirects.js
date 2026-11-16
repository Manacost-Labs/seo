/* Отчёт по редиректам IndexPilot: проверка целей пачками. */
( function () {
	'use strict';

	var text = window.IndexPilotRedirects;
	var root = document.querySelector( '[data-indexpilot-redirects]' );
	var button = root ? root.querySelector( '[data-indexpilot-check]' ) : null;

	if ( ! text || ! button || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var status = root.querySelector( '[data-indexpilot-check-status]' );

	function step() {
		window.wp.apiFetch( { path: '/indexpilot/v1/redirects/check', method: 'POST' } ).then( function ( result ) {
			// Пачка ничего не проверила — проверять больше нечего.
			if ( 0 === result.remaining || 0 === result.checked ) {
				window.location.reload();
				return;
			}

			status.textContent = text.checking.replace( '%d', String( result.remaining ) );
			step();
		} ).catch( function () {
			status.textContent = text.failed;
			button.disabled = false;
		} );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		step();
	} );
}() );
