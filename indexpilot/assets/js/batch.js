/* Отчёты IndexPilot: работа пачками. Кнопка повторяет запрос, пока сервер не сообщит, что всё сделано. */
( function () {
	'use strict';

	var text = window.IndexPilotBatch;
	var root = document.querySelector( '[data-indexpilot-batch-root]' );

	if ( ! text || ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var status = root.querySelector( '[data-indexpilot-batch-status]' );

	function setBusy( busy ) {
		Array.prototype.forEach.call( root.querySelectorAll( '[data-indexpilot-batch]' ), function ( button ) {
			button.disabled = busy;
		} );
	}

	function run( button ) {
		var path = button.getAttribute( 'data-indexpilot-batch' );
		var mode = button.getAttribute( 'data-mode' );
		var restart = '1' === button.getAttribute( 'data-restart' );

		function step( first ) {
			window.wp.apiFetch( { path: path, method: 'POST', data: { restart: first && restart } } ).then( function ( result ) {
				var finished = 'progress' === mode ? result.done : ( 0 === result.remaining || 0 === result.checked );

				if ( finished ) {
					window.location.reload();
					return;
				}

				if ( status ) {
					status.textContent = 'progress' === mode
						? text.progress.replace( '%1$d', String( result.processed ) ).replace( '%2$d', String( result.total ) )
						: text.remaining.replace( '%d', String( result.remaining ) );
				}

				step( false );
			} ).catch( function () {
				if ( status ) {
					status.textContent = text.failed;
				}

				setBusy( false );
			} );
		}

		setBusy( true );
		step( true );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-indexpilot-batch]' );

		if ( button ) {
			run( button );
		}
	} );
}() );
