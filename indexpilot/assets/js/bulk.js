/* Массовый редактор IndexPilot: таблица заголовков и описаний с сохранением пачкой. */
( function () {
	'use strict';

	var config = window.IndexPilotBulk;
	var root = document.querySelector( '[data-indexpilot-bulk]' );

	if ( ! config || ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var text = config.text;
	var typeSelect = root.querySelector( '[data-indexpilot-bulk-type]' );
	var filterSelect = root.querySelector( '[data-indexpilot-bulk-filter]' );
	var searchInput = root.querySelector( '[data-indexpilot-bulk-search]' );
	var head = root.querySelector( '[data-indexpilot-bulk-head]' );
	var body = root.querySelector( '[data-indexpilot-bulk-rows]' );
	var pagesNode = root.querySelector( '[data-indexpilot-bulk-pages]' );
	var statusNode = root.querySelector( '[data-indexpilot-bulk-status]' );
	var prevButton = root.querySelector( '[data-indexpilot-bulk-prev]' );
	var nextButton = root.querySelector( '[data-indexpilot-bulk-next]' );
	var state = { page: 1, pages: 1, total: 0, limit: {}, rows: {} };

	function element( tag, content, className ) {
		var node = document.createElement( tag );

		if ( content ) {
			node.textContent = content;
		}

		if ( className ) {
			node.className = className;
		}

		return node;
	}

	function target() {
		var parts = typeSelect.value.split( ':' );

		return { kind: parts[ 0 ], type: parts[ 1 ] };
	}

	function changed() {
		return Object.keys( state.rows ).filter( function ( id ) {
			var row = state.rows[ id ];

			return row.titleInput.value !== row.data.seo_title || row.descriptionInput.value !== row.data.seo_description;
		} );
	}

	function hint( row ) {
		var data = row.data;
		var length = data.resolved_description.length;
		var notes = [];

		if ( ! data.indexable ) {
			notes.push( text.noindex );
		}

		if ( 'auto' === data.description_source || 'none' === data.description_source ) {
			notes.push( text.auto );
		}

		row.titleHint.textContent = text.now + ' ' + data.resolved_title + ' (' + data.title_px + ' px)';
		row.titleHint.style.color = data.title_px > state.limit.title_px ? '#d63638' : '';
		row.descriptionHint.textContent = text.now + ' ' + data.resolved_description + ' (' + length + ' ' + text.chars + ')' + ( notes.length ? ' — ' + notes.join( ', ' ) : '' );
		row.descriptionHint.style.color = length > state.limit.description_max || 0 === length ? '#d63638' : '';
		row.scoreCell.textContent = '' === data.score ? '—' : data.score;
	}

	function addRow( data ) {
		var line = document.createElement( 'tr' );
		var nameCell = element( 'td' );
		var titleCell = element( 'td' );
		var descriptionCell = element( 'td' );
		var link = element( 'a', data.name );
		var row = {
			data: data,
			titleInput: document.createElement( 'input' ),
			descriptionInput: document.createElement( 'textarea' ),
			titleHint: element( 'p', '', 'description' ),
			descriptionHint: element( 'p', '', 'description' ),
			scoreCell: element( 'td' )
		};

		link.href = data.edit_url;
		nameCell.appendChild( link );

		row.titleInput.type = 'text';
		row.titleInput.className = 'large-text';
		row.titleInput.value = data.seo_title;
		row.titleInput.setAttribute( 'aria-label', text.title + ': ' + data.name );

		row.descriptionInput.className = 'large-text';
		row.descriptionInput.rows = 2;
		row.descriptionInput.value = data.seo_description;
		row.descriptionInput.setAttribute( 'aria-label', text.description + ': ' + data.name );

		titleCell.appendChild( row.titleInput );
		titleCell.appendChild( row.titleHint );
		descriptionCell.appendChild( row.descriptionInput );
		descriptionCell.appendChild( row.descriptionHint );

		line.appendChild( nameCell );
		line.appendChild( titleCell );
		line.appendChild( descriptionCell );
		line.appendChild( row.scoreCell );
		body.appendChild( line );

		state.rows[ data.id ] = row;
		hint( row );
	}

	function render( result ) {
		head.textContent = '';
		body.textContent = '';
		state.rows = {};
		state.pages = Math.max( 1, result.pages );
		state.total = result.total;
		state.limit = result.limit;

		var headRow = document.createElement( 'tr' );
		[ text.name, text.title, text.description, text.score ].forEach( function ( label ) {
			var cell = element( 'th', label );

			cell.scope = 'col';
			headRow.appendChild( cell );
		} );
		head.appendChild( headRow );

		if ( ! result.rows.length ) {
			var empty = document.createElement( 'tr' );
			var cell = element( 'td', text.empty );

			cell.colSpan = 4;
			empty.appendChild( cell );
			body.appendChild( empty );
		}

		result.rows.forEach( addRow );

		pagesNode.textContent = text.pages.replace( '%1$d', state.page ).replace( '%2$d', state.pages ).replace( '%3$d', state.total );
		prevButton.disabled = state.page <= 1;
		nextButton.disabled = state.page >= state.pages;
	}

	function load( page ) {
		if ( changed().length && ! window.confirm( text.unsaved ) ) {
			return;
		}

		var where = target();

		state.page = page;
		statusNode.textContent = text.loading;

		window.wp.apiFetch( {
			path: '/indexpilot/v1/bulk?kind=' + encodeURIComponent( where.kind ) + '&type=' + encodeURIComponent( where.type ) +
				'&filter=' + encodeURIComponent( filterSelect.value ) + '&page=' + page + '&search=' + encodeURIComponent( searchInput.value )
		} ).then( function ( result ) {
			statusNode.textContent = '';
			render( result );
		} ).catch( function () {
			statusNode.textContent = text.failed;
		} );
	}

	function save() {
		var ids = changed();
		var where = target();

		if ( ! ids.length ) {
			statusNode.textContent = text.nothing;
			return;
		}

		statusNode.textContent = text.loading;

		window.wp.apiFetch( {
			path: '/indexpilot/v1/bulk',
			method: 'POST',
			data: {
				kind: where.kind,
				type: where.type,
				items: ids.map( function ( id ) {
					var row = state.rows[ id ];

					return { id: Number( id ), seo_title: row.titleInput.value, seo_description: row.descriptionInput.value };
				} )
			}
		} ).then( function ( response ) {
			var saved = 0;
			var rejected = 0;

			response.results.forEach( function ( result ) {
				var row = state.rows[ result.id ];

				if ( ! result.saved ) {
					++rejected;
					return;
				}

				++saved;
				row.data = result.row;
				row.titleInput.value = result.row.seo_title;
				row.descriptionInput.value = result.row.seo_description;
				hint( row );
			} );

			statusNode.textContent = text.saved.replace( '%1$d', saved ).replace( '%2$d', rejected );
		} ).catch( function () {
			statusNode.textContent = text.failed;
		} );
	}

	function syncFilters() {
		var isTerm = 'term' === target().kind;

		Array.prototype.forEach.call( filterSelect.querySelectorAll( '[data-posts-only]' ), function ( option ) {
			option.disabled = isTerm;
		} );

		if ( isTerm && filterSelect.selectedOptions[ 0 ].disabled ) {
			filterSelect.value = 'all';
		}
	}

	typeSelect.addEventListener( 'change', function () {
		syncFilters();
		load( 1 );
	} );
	filterSelect.addEventListener( 'change', function () {
		load( 1 );
	} );
	root.querySelector( '[data-indexpilot-bulk-load]' ).addEventListener( 'click', function () {
		load( 1 );
	} );
	searchInput.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			load( 1 );
		}
	} );
	prevButton.addEventListener( 'click', function () {
		load( state.page - 1 );
	} );
	nextButton.addEventListener( 'click', function () {
		load( state.page + 1 );
	} );
	root.querySelector( '[data-indexpilot-bulk-save]' ).addEventListener( 'click', save );

	syncFilters();
	load( 1 );
}() );
