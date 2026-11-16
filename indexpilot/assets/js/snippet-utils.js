/**
 * Чистые функции панели IndexPilot: обрезка сниппета, адрес-цепочка, подсветка ключа.
 * Работают и в браузере (window.IndexPilotUtils), и в Node (тесты: node --test tests/js).
 */
( function ( root, factory ) {
	var api = factory();

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.IndexPilotUtils = api;
	}
} )( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var ELLIPSIS = '…';

	/**
	 * Обрезает заголовок по ширине в пикселях, по границе слова.
	 *
	 * @param {string}   text    Заголовок.
	 * @param {number}   maxPx   Наибольшая ширина.
	 * @param {Function} measure Функция, возвращающая ширину строки в пикселях.
	 * @return {string} Заголовок, который помещается в ширину.
	 */
	function truncateToWidth( text, maxPx, measure ) {
		text = String( text || '' ).trim();

		if ( measure( text ) <= maxPx ) {
			return text;
		}

		var words = text.split( /\s+/ );

		while ( words.length > 1 ) {
			words.pop();
			var candidate = words.join( ' ' ).replace( /[\s,;:—–-]+$/, '' ) + ' ' + ELLIPSIS;

			if ( measure( candidate ) <= maxPx ) {
				return candidate;
			}
		}

		return words[ 0 ] + ELLIPSIS;
	}

	/**
	 * Обрезает описание по числу символов, по границе слова.
	 *
	 * @param {string} text Описание.
	 * @param {number} max  Наибольшая длина.
	 * @return {string} Описание не длиннее max.
	 */
	function truncateChars( text, max ) {
		text = String( text || '' ).trim();

		if ( text.length <= max ) {
			return text;
		}

		var cut = text.slice( 0, max - 1 );
		var space = cut.lastIndexOf( ' ' );

		if ( space > 0 ) {
			cut = cut.slice( 0, space );
		}

		return cut.replace( /[\s,;:—–-]+$/, '' ) + ELLIPSIS;
	}

	/**
	 * Превращает адрес в цепочку, как в выдаче: «https://site.ru › category › gajdy».
	 *
	 * @param {string} url Адрес страницы.
	 * @return {string} Цепочка.
	 */
	function breadcrumb( url ) {
		var match = /^(https?:\/\/[^\/?#]+)([^?#]*)/.exec( String( url || '' ) );

		if ( ! match ) {
			return String( url || '' );
		}

		var parts = match[ 2 ].split( '/' ).filter( function ( part ) {
			return part !== '';
		} );

		parts = parts.map( function ( part ) {
			try {
				return decodeURIComponent( part );
			} catch ( error ) {
				return part;
			}
		} );

		return [ match[ 1 ] ].concat( parts ).join( ' › ' );
	}

	/**
	 * Уровень оценки для цвета значка.
	 *
	 * @param {number|string|null} score Оценка 0–100.
	 * @return {string} none, bad, ok или good.
	 */
	function scoreLevel( score ) {
		var value = parseInt( score, 10 );

		if ( isNaN( value ) ) {
			return 'none';
		}

		if ( value <= 40 ) {
			return 'bad';
		}

		return value <= 70 ? 'ok' : 'good';
	}

	/**
	 * Делит текст на части и помечает слова ключа, чтобы выделить их жирным, как в выдаче.
	 * Формы слова учитываются приближённо: совпадение по началу слова.
	 *
	 * @param {string} text    Текст сниппета.
	 * @param {string} keyword Ключевое слово или фраза.
	 * @return {Array<{text: string, bold: boolean}>} Части текста.
	 */
	function keywordParts( text, keyword ) {
		text = String( text || '' );

		var prefixes = String( keyword || '' )
			.toLowerCase()
			.split( /[^\p{L}\p{N}]+/u )
			.filter( function ( word ) {
				return word.length >= 3;
			} )
			.map( function ( word ) {
				return word.slice( 0, Math.max( 3, word.length - 2 ) );
			} );

		if ( ! prefixes.length ) {
			return text === '' ? [] : [ { text: text, bold: false } ];
		}

		return text
			.split( /([\p{L}\p{N}]+)/u )
			.filter( function ( part ) {
				return part !== '';
			} )
			.map( function ( part ) {
				var lower = part.toLowerCase();
				var bold = prefixes.some( function ( prefix ) {
					return lower.indexOf( prefix ) === 0;
				} );

				return { text: part, bold: bold };
			} );
	}

	/**
	 * Разбирает поле «ключевые слова через запятую».
	 *
	 * @param {string} value Значение поля.
	 * @param {number} max   Наибольшее число ключей.
	 * @return {string[]} Ключевые слова без пустых и повторов.
	 */
	function splitKeywords( value, max ) {
		var seen = {};

		return String( value || '' )
			.split( ',' )
			.map( function ( item ) {
				return item.trim();
			} )
			.filter( function ( item ) {
				var key = item.toLowerCase();

				if ( item === '' || seen[ key ] ) {
					return false;
				}

				seen[ key ] = true;

				return true;
			} )
			.slice( 0, max );
	}

	/**
	 * Число слов в тексте: всё, что разделено пробелами и содержит букву или цифру.
	 * Считает так же, как сервер (AnswerBlock::word_count).
	 *
	 * @param {string} text Текст.
	 * @return {number} Число слов.
	 */
	function countWords( text ) {
		return String( text || '' )
			.split( /\s+/ )
			.filter( function ( word ) {
				return /[\p{L}\p{N}]/u.test( word );
			} ).length;
	}

	return {
		truncateToWidth: truncateToWidth,
		truncateChars: truncateChars,
		breadcrumb: breadcrumb,
		scoreLevel: scoreLevel,
		keywordParts: keywordParts,
		splitKeywords: splitKeywords,
		countWords: countWords,
	};
} );
