/* Страница импорта IndexPilot: запросы пачками, пока источник не закончится. */
( function () {
	'use strict';

	var config = window.IndexPilotImport;
	var root = document.querySelector( '[data-indexpilot-import]' );

	if ( ! config || ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var log = root.querySelector( '[data-indexpilot-log]' );
	var overwrite = root.querySelector( '[data-indexpilot-overwrite]' );

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );

		return template.replace( /%(\d)\$d/g, function ( match, index ) {
			return String( args[ Number( index ) - 1 ] );
		} ).replace( /%d/g, String( args[ 0 ] ) );
	}

	function request( data ) {
		return window.wp.apiFetch( { path: '/indexpilot/v1/import', method: 'POST', data: data } );
	}

	function setBusy( busy ) {
		Array.prototype.forEach.call( root.querySelectorAll( 'button, input' ), function ( control ) {
			control.disabled = busy;
		} );
	}

	function element( tag, text ) {
		var node = document.createElement( tag );

		if ( undefined !== text ) {
			node.textContent = text;
		}

		return node;
	}

	function showSamples( samples ) {
		log.textContent = '';

		if ( ! samples.length ) {
			return;
		}

		log.appendChild( element( 'h2', config.text.samples ) );

		samples.forEach( function ( sample ) {
			var list = element( 'ul' );

			Object.keys( sample.fields ).forEach( function ( key ) {
				var value = sample.fields[ key ];
				var item = element( 'li' );

				item.appendChild( element( 'strong', ( config.labels[ key ] || key ) + ': ' ) );
				item.appendChild( document.createTextNode( Array.isArray( value ) ? value.join( ', ' ) : String( value ) ) );
				list.appendChild( item );
			} );

			log.appendChild( element( 'h3', format( config.text.post, sample.post_id ) ) );
			log.appendChild( list );
		} );
	}

	function fail( status ) {
		status.textContent = config.text.failed;
		setBusy( false );
	}

	// Пробный прогон: ничего не записывает, считает итог по всем пачкам.
	function preview( row ) {
		var status = row.querySelector( '[data-indexpilot-status]' );
		var total = Number( row.getAttribute( 'data-total' ) );
		var sum = { processed: 0, imported: 0, fields: 0, skipped: 0, samples: [] };

		function step( afterId ) {
			request( {
				source: row.getAttribute( 'data-indexpilot-source' ),
				dry_run: true,
				overwrite: overwrite.checked,
				after_id: afterId
			} ).then( function ( result ) {
				sum.processed += result.processed;
				sum.imported += result.imported;
				sum.fields += result.fields;
				sum.skipped += result.skipped;
				sum.samples = sum.samples.concat( result.samples ).slice( 0, 5 );

				if ( result.done ) {
					status.textContent = format( config.text.preview, sum.imported, sum.fields, sum.skipped );
					showSamples( sum.samples );
					setBusy( false );
					return;
				}

				status.textContent = format( config.text.checking, sum.processed, total );
				step( result.last_id );
			} ).catch( function () {
				fail( status );
			} );
		}

		setBusy( true );
		step( 0 );
	}

	// Настоящий импорт: место остановки хранит сервер.
	function run( row, restart ) {
		var status = row.querySelector( '[data-indexpilot-status]' );
		var total = Number( row.getAttribute( 'data-total' ) );

		function step( first ) {
			request( {
				source: row.getAttribute( 'data-indexpilot-source' ),
				overwrite: overwrite.checked,
				restart: first && restart
			} ).then( function ( result ) {
				var state = result.state;

				if ( result.done ) {
					status.textContent = format( config.text.finished, state.imported, state.fields, state.skipped );
					setBusy( false );
					return;
				}

				status.textContent = format( config.text.importing, state.processed, total );
				step( false );
			} ).catch( function () {
				fail( status );
			} );
		}

		log.textContent = '';
		setBusy( true );
		step( true );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-indexpilot-action]' );

		if ( ! button ) {
			return;
		}

		var row = button.closest( '[data-indexpilot-source]' );
		var action = button.getAttribute( 'data-indexpilot-action' );

		if ( 'preview' === action ) {
			preview( row );
		} else if ( 'run' === action ) {
			run( row, false );
		} else if ( 'restart' === action && window.confirm( config.text.confirm ) ) {
			run( row, true );
		}
	} );
}() );
