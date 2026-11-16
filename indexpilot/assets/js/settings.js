/* Страница настроек IndexPilot: выбор картинок из медиатеки. */
( function () {
	'use strict';

	if ( ! window.wp || ! window.wp.media ) {
		return;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-indexpilot-image]' ), function ( box ) {
		var input = box.querySelector( '[data-indexpilot-image-id]' );
		var preview = box.querySelector( '[data-indexpilot-image-preview]' );
		var frame = null;

		box.querySelector( '[data-indexpilot-image-select]' ).addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = window.wp.media( { library: { type: 'image' }, multiple: false } );

				frame.on( 'select', function () {
					var image = frame.state().get( 'selection' ).first().toJSON();

					input.value = image.id;
					preview.src = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
					preview.hidden = false;
				} );
			}

			frame.open();
		} );

		box.querySelector( '[data-indexpilot-image-remove]' ).addEventListener( 'click', function () {
			input.value = '0';
			preview.hidden = true;
		} );
	} );
}() );
