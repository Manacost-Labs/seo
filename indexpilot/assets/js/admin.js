/* Общее для экранов IndexPilot: прокрутка широких таблиц и подсказки «?», которые не выходят за край экрана. */
( function () {
	'use strict';

	var GAP = 12;
	var TIP_WIDTH = 340;

	/* Широкая таблица прокручивается сама по себе, а не вместе со всей страницей. */
	function wrapTables( scope ) {
		Array.prototype.forEach.call( scope.querySelectorAll( 'table.widefat' ), function ( table ) {
			var parent = table.parentNode;
			var wrapper;

			if ( ! parent || ( parent.classList && parent.classList.contains( 'indexpilot-table-scroll' ) ) ) {
				return;
			}

			// На телефоне таблица из многих колонок не сжимается в нечитаемые столбики, а прокручивается.
			if ( table.rows.length && table.rows[ 0 ].cells.length > 3 ) {
				table.classList.add( 'indexpilot-wide' );
			}

			wrapper = document.createElement( 'div' );
			wrapper.className = 'indexpilot-table-scroll';
			parent.insertBefore( wrapper, table );
			wrapper.appendChild( table );
		} );
	}

	/* Подсказка сдвигается так, чтобы целиком помещаться в окне; у нижнего края открывается вверх. */
	function placeTip( icon ) {
		var rect = icon.getBoundingClientRect();
		var viewport = document.documentElement.clientWidth || window.innerWidth;
		var width = Math.min( TIP_WIDTH, viewport - GAP * 2 );
		var start = rect.left - 8;

		if ( start + width > viewport - GAP ) {
			start = viewport - GAP - width;
		}

		if ( start < GAP ) {
			start = GAP;
		}

		icon.style.setProperty( '--indexpilot-tip-left', Math.round( start - rect.left ) + 'px' );
		icon.style.setProperty( '--indexpilot-tip-width', Math.round( width ) + 'px' );
		icon.classList.toggle( 'is-above', rect.bottom > ( window.innerHeight || 0 ) - 220 && rect.top > 240 );
	}

	function onTipEvent( event ) {
		var icon = event.target && event.target.closest ? event.target.closest( '.indexpilot-help' ) : null;

		if ( icon ) {
			placeTip( icon );
		}
	}

	document.addEventListener( 'mouseover', onTipEvent );
	document.addEventListener( 'focusin', onTipEvent );

	// Касание на телефоне: значок получает фокус и подсказка открывается; повторное касание её закрывает.
	document.addEventListener( 'click', function ( event ) {
		var icon = event.target && event.target.closest ? event.target.closest( '.indexpilot-help' ) : null;

		if ( ! icon ) {
			return;
		}

		event.preventDefault();

		if ( document.activeElement === icon && icon.getAttribute( 'data-open' ) ) {
			icon.removeAttribute( 'data-open' );
			icon.blur();
		} else {
			placeTip( icon );
			icon.focus();
			icon.setAttribute( 'data-open', '1' );
		}
	} );

	document.addEventListener( 'focusout', function ( event ) {
		if ( event.target && event.target.removeAttribute && event.target.classList && event.target.classList.contains( 'indexpilot-help' ) ) {
			event.target.removeAttribute( 'data-open' );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && document.activeElement && document.activeElement.classList && document.activeElement.classList.contains( 'indexpilot-help' ) ) {
			document.activeElement.blur();
		}
	} );

	if ( document.body && document.body.classList.contains( 'indexpilot-screen' ) ) {
		wrapTables( document.getElementById( 'wpbody-content' ) || document.body );

		// На телефоне вкладки группы прокручиваются вбок: открытая вкладка должна быть видна сразу.
		Array.prototype.forEach.call( document.querySelectorAll( '.indexpilot-group-tabs .nav-tab-wrapper' ), function ( row ) {
			var active = row.querySelector( '.nav-tab-active' );

			if ( active && row.scrollWidth > row.clientWidth ) {
				row.scrollLeft = Math.max( 0, active.offsetLeft - row.offsetLeft - 16 );
			}
		} );
	}

	var panel = document.getElementById( 'indexpilot-panel' );

	if ( panel ) {
		wrapTables( panel );
	}
}() );
