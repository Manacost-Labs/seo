/* Страница настроек IndexPilot: вкладки и выбор картинок из медиатеки. */
( function () {
	'use strict';

	var STORAGE_KEY = 'indexpilotSettingsTab';

	/* Вкладки. Все разделы лежат в одной форме: переключение только прячет их, сохраняются все вкладки сразу. */
	function setupTabs() {
		var root = document.querySelector( '[data-indexpilot-settings]' );

		if ( ! root ) {
			return;
		}

		var tabs = Array.prototype.slice.call( root.querySelectorAll( '[data-indexpilot-tab]' ) );
		var sections = Array.prototype.slice.call( root.querySelectorAll( '[data-indexpilot-section]' ) );
		var form = root.querySelector( '[data-indexpilot-settings-form]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		if ( ! tabs.length || ! sections.length ) {
			return;
		}

		function exists( slug ) {
			return sections.some( function ( section ) {
				return section.getAttribute( 'data-indexpilot-section' ) === slug;
			} );
		}

		function remember( slug ) {
			try {
				window.localStorage.setItem( STORAGE_KEY, slug );
			} catch ( e ) {
				/* Хранилище недоступно (приватный режим) — вкладка просто не запомнится. */
			}
		}

		function stored() {
			try {
				return window.localStorage.getItem( STORAGE_KEY ) || '';
			} catch ( e ) {
				return '';
			}
		}

		function activate( slug, updateHash ) {
			if ( ! exists( slug ) ) {
				return;
			}

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-indexpilot-tab' ) === slug;

				tab.classList.toggle( 'nav-tab-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', active ? '0' : '-1' );
			} );

			sections.forEach( function ( section ) {
				section.classList.toggle( 'is-active', section.getAttribute( 'data-indexpilot-section' ) === slug );
			} );

			// После сохранения WordPress вернёт на адрес из этого поля — вместе с якорем откроется та же вкладка.
			if ( referer ) {
				referer.value = refererBase + '#tab-' + slug;
			}

			if ( updateHash && window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#tab-' + slug );
			}

			remember( slug );
		}

		function fromHash() {
			var match = /^#tab-([a-z]+)$/.exec( window.location.hash );

			return match ? match[ 1 ] : '';
		}

		root.classList.add( 'has-tabs' );

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activate( tab.getAttribute( 'data-indexpilot-tab' ), true );
			} );

			// Стрелки влево и вправо переключают вкладки, как в обычном списке вкладок.
			tab.addEventListener( 'keydown', function ( event ) {
				var next = null;

				if ( 'ArrowRight' === event.key ) {
					next = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( 'ArrowLeft' === event.key ) {
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				}

				if ( next ) {
					event.preventDefault();
					next.focus();
					activate( next.getAttribute( 'data-indexpilot-tab' ), true );
				}
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			var slug = fromHash();

			if ( slug ) {
				activate( slug, false );
			}
		} );

		// Поле с ошибкой на скрытой вкладке браузер не может показать: сначала открываем его вкладку.
		if ( form ) {
			form.addEventListener(
				'invalid',
				function ( event ) {
					var section = event.target.closest ? event.target.closest( '[data-indexpilot-section]' ) : null;

					if ( section && ! section.classList.contains( 'is-active' ) ) {
						activate( section.getAttribute( 'data-indexpilot-section' ), true );
					}
				},
				true
			);
		}

		// Вкладка с предупреждением (например, неизвестная переменная в шаблоне) получает отметку.
		sections.forEach( function ( section ) {
			var count = section.querySelectorAll( '.indexpilot-unknown-variable' ).length;
			var tab;

			if ( ! count ) {
				return;
			}

			tab = root.querySelector( '[data-indexpilot-tab="' + section.getAttribute( 'data-indexpilot-section' ) + '"]' );

			if ( tab ) {
				var badge = document.createElement( 'span' );

				badge.className = 'indexpilot-tab-alert';
				badge.textContent = String( count );
				badge.setAttribute( 'title', badge.textContent );
				tab.appendChild( badge );
			}
		} );

		activate( fromHash() || stored() || tabs[ 0 ].getAttribute( 'data-indexpilot-tab' ), false );
	}

	/* Выбор картинки из медиатеки. */
	function setupImages() {
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
	}

	setupTabs();
	setupImages();
}() );
