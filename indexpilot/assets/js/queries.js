/**
 * Кнопка «сделать главным запросом» на вкладке «Запросы» панели записи и в форме рубрики:
 * вписывает запрос в поле основного ключа. Панель записи сама пересчитывает анализ по событию input.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-indexpilot-use-query]' ) : null;
		var field = document.getElementById( 'indexpilot_focus_keyword' );

		if ( ! button || ! field ) {
			return;
		}

		event.preventDefault();
		field.value = button.getAttribute( 'data-indexpilot-use-query' );
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		var status = button.parentNode.querySelector( '[data-indexpilot-use-status]' );

		if ( status ) {
			status.textContent = button.getAttribute( 'data-done' ) || '';
		}
	} );
}() );
