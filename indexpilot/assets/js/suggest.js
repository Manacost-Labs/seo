/* Панель IndexPilot: подсказки внутренних ссылок для текста записи. */
( function () {
	'use strict';

	var config = window.IndexPilotSuggest;
	var root = document.querySelector( '[data-indexpilot-suggest-root]' );

	if ( ! config || ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var button = root.querySelector( '[data-indexpilot-suggest]' );
	var list = root.querySelector( '[data-indexpilot-suggestions]' );
	var status = root.querySelector( '[data-indexpilot-suggest-status]' );

	function editorContent() {
		var editor = window.tinymce && window.tinymce.get( 'content' );
		var textarea = document.getElementById( 'content' );

		if ( editor && ! editor.isHidden() ) {
			return editor.getContent();
		}

		return textarea ? textarea.value : '';
	}

	function render( items ) {
		list.textContent = '';
		status.textContent = items.length ? '' : config.empty;

		items.forEach( function ( item ) {
			var row = document.createElement( 'li' );
			var link = document.createElement( 'a' );
			var copy = document.createElement( 'button' );

			link.href = item.url;
			link.target = '_blank';
			link.rel = 'noopener';
			link.textContent = item.title;

			copy.type = 'button';
			copy.className = 'button-link';
			copy.textContent = config.copy;
			copy.addEventListener( 'click', function () {
				if ( window.navigator.clipboard ) {
					window.navigator.clipboard.writeText( item.url ).then( function () {
						copy.textContent = config.copied;
					} );
				} else {
					window.prompt( config.copy, item.url );
				}
			} );

			row.appendChild( link );
			row.appendChild( document.createTextNode( ' — ' ) );
			row.appendChild( copy );
			list.appendChild( row );
		} );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		status.textContent = config.loading;

		window.wp.apiFetch( {
			path: '/indexpilot/v1/links/suggest',
			method: 'POST',
			data: { post_id: config.postId, content: editorContent() }
		} ).then( render ).catch( function () {
			status.textContent = config.failed;
		} ).then( function () {
			button.disabled = false;
		} );
	} );
}() );
