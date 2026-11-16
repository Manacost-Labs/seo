/* Страница «Поисковики» и «Короткие ссылки» IndexPilot: копирование адресов и ручная работа с IndexNow. */
( function () {
	'use strict';

	var text = window.IndexPilotSearch || {};

	// Кнопки «скопировать» работают на любой странице плагина, где есть атрибут data-indexpilot-copy.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-indexpilot-copy]' );

		if ( ! button ) {
			return;
		}

		var value = button.getAttribute( 'data-indexpilot-copy' );
		var label = button.textContent;

		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( value ).then( function () {
				button.textContent = text.copied || label;
				window.setTimeout( function () {
					button.textContent = label;
				}, 1500 );
			} );
		} else {
			window.prompt( label, value );
		}
	} );

	var root = document.querySelector( '[data-indexpilot-search]' );

	if ( ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var status = root.querySelector( '[data-indexpilot-indexnow-status]' );
	var urls = root.querySelector( '[data-indexpilot-urls]' );

	function format( template, first, second ) {
		return template.replace( '%1$d', String( first ) ).replace( '%2$d', String( second ) ).replace( '%d', String( first ) );
	}

	function setBusy( busy ) {
		Array.prototype.forEach.call( root.querySelectorAll( '[data-indexpilot-indexnow]' ), function ( button ) {
			button.disabled = busy;
		} );
	}

	function request( data ) {
		return window.wp.apiFetch( { path: '/indexpilot/v1/indexnow', method: 'POST', data: data } );
	}

	function fail( error ) {
		status.textContent = error && error.message ? error.message : text.failed;
		setBusy( false );
	}

	function report( result ) {
		if ( ! result.ok ) {
			status.textContent = format( text.refused, result.code );
		} else if ( 0 === result.sent ) {
			status.textContent = text.nothing;
		} else {
			status.textContent = format( text.sent, result.sent, result.code );
		}
	}

	function submitAll( step, total ) {
		request( { action: 'submit_all', step: step } ).then( function ( result ) {
			if ( ! result.ok ) {
				report( result );
				setBusy( false );
				return;
			}

			if ( result.done ) {
				window.location.reload();
				return;
			}

			status.textContent = format( text.progress, result.step, result.files );
			submitAll( result.step, total + result.sent );
		} ).catch( fail );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-indexpilot-indexnow]' );

		if ( ! button ) {
			return;
		}

		var action = button.getAttribute( 'data-indexpilot-indexnow' );

		if ( 'submit_all' === action && ! window.confirm( text.confirm ) ) {
			return;
		}

		setBusy( true );
		status.textContent = text.working;

		if ( 'submit_all' === action ) {
			submitAll( 0, 0 );
			return;
		}

		request( { action: action, urls: urls ? urls.value : '' } ).then( function ( result ) {
			if ( 'send_queue' === action ) {
				window.location.reload();
				return;
			}

			report( result );
			setBusy( false );
		} ).catch( fail );
	} );
}() );
