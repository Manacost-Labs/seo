/**
 * Панель IndexPilot в редакторе записи: вкладки, живой предпросмотр сниппета, счётчики, анализ.
 *
 * Заголовок и описание для предпросмотра считает сервер — тем же кодом, что выводит теги на сайте,
 * поэтому предпросмотр совпадает со страницей. Скрипт только отправляет текущие значения полей
 * и показывает ответ.
 */
( function () {
	'use strict';

	var config = window.IndexPilotPanel;
	var utils = window.IndexPilotUtils;
	var root = document.getElementById( 'indexpilot-panel' );

	if ( ! config || ! utils || ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var DEBOUNCE_MS = 600;
	var canonicalInput = root.querySelector( '#indexpilot_canonical' );
	var canonicalNotice = root.querySelector( '#indexpilot-canonical-warning' );
	if ( canonicalInput && canonicalNotice ) {
		var checkCanonical = function () {
			canonicalNotice.hidden = ! utils.canonicalLooksInvalid( canonicalInput.value, config.homeUrl );
		};
		canonicalInput.addEventListener( 'input', checkCanonical );
		checkCanonical();
	}
	var MOBILE_DESCRIPTION = 120;
	var MAX_KEYWORDS = 4;

	var requestId = 0;
	var timer = null;
	var lastMeta = null;
	var unknownVariables = [];
	var lastFocused = null;
	var canvas = document.createElement( 'canvas' ).getContext( '2d' );

	function $( selector ) {
		return root.querySelector( selector );
	}

	function field( id ) {
		return document.getElementById( id );
	}

	function value( id ) {
		var element = field( id );

		return element ? element.value : '';
	}

	/**
	 * Ширина заголовка в выдаче: Arial 20 px.
	 *
	 * @param {string} text Заголовок.
	 * @return {number} Ширина в пикселях.
	 */
	function measure( text ) {
		canvas.font = '20px Arial, sans-serif';

		return canvas.measureText( text ).width;
	}

	/* ---------- Вкладки и переключатель устройства ---------- */

	root.querySelectorAll( '.indexpilot-tab' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			root.querySelectorAll( '.indexpilot-tab' ).forEach( function ( other ) {
				var active = other === tab;

				other.classList.toggle( 'is-active', active );
				other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			root.querySelectorAll( '.indexpilot-pane' ).forEach( function ( pane ) {
				var active = pane.getAttribute( 'data-pane' ) === tab.getAttribute( 'data-tab' );

				pane.classList.toggle( 'is-active', active );
				pane.hidden = ! active;
			} );
		} );
	} );

	root.querySelectorAll( '[data-device]' ).forEach( function ( button ) {
		if ( button.tagName !== 'BUTTON' ) {
			return;
		}

		button.addEventListener( 'click', function () {
			root.querySelectorAll( 'button[data-device]' ).forEach( function ( other ) {
				other.classList.toggle( 'is-active', other === button );
			} );

			$( '[data-indexpilot-serp]' ).setAttribute( 'data-device', button.getAttribute( 'data-device' ) );
			renderSnippet();
		} );
	} );

	/* ---------- Кнопки вставки переменных ---------- */

	root.querySelectorAll( '[data-indexpilot-variables]' ).forEach( function ( holder ) {
		( config.variables || [] ).forEach( function ( name ) {
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'button button-small indexpilot-variable';
			button.textContent = '%' + name + '%';
			button.addEventListener( 'click', function () {
				var target = lastFocused || field( holder.getAttribute( 'data-target' ) );
				var start = target.selectionStart || target.value.length;
				var end = target.selectionEnd || target.value.length;

				target.value = target.value.slice( 0, start ) + '%' + name + '%' + target.value.slice( end );
				target.focus();
				target.selectionStart = target.selectionEnd = start + name.length + 2;
				schedule();
			} );

			holder.appendChild( button );
		} );
	} );

	[ 'indexpilot_title', 'indexpilot_description' ].forEach( function ( id ) {
		var element = field( id );

		if ( element ) {
			element.addEventListener( 'focus', function () {
				lastFocused = element;
			} );
		}
	} );

	/* ---------- Сбор значений и запрос к серверу ---------- */

	function editorContent() {
		var editor = window.tinymce && window.tinymce.get( 'content' );

		if ( editor && ! editor.isHidden() ) {
			return editor.getContent();
		}

		return value( 'content' );
	}

	function slug() {
		var editable = document.getElementById( 'editable-post-name-full' );

		return editable ? editable.textContent : value( 'post_name' );
	}

	function collect() {
		var data = {
			object_type: config.objectType,
			object_id: config.objectId,
			seo_title: value( 'indexpilot_title' ),
			seo_description: value( 'indexpilot_description' ),
			focus_keyword: value( 'indexpilot_focus_keyword' ),
			keywords: utils.splitKeywords( value( 'indexpilot_keywords' ), MAX_KEYWORDS ),
			og_title: value( 'indexpilot_og_title' ),
			og_description: value( 'indexpilot_og_description' ),
		};

		// Краткий ответ есть только у записей; он влияет на описание и на анализ.
		if ( field( 'indexpilot_answer' ) ) {
			data.answer = value( 'indexpilot_answer' );
		}

		// Отметка оплаченного размещения включает проверку рекламных ссылок.
		if ( field( 'indexpilot_sponsored' ) ) {
			data.sponsored = field( 'indexpilot_sponsored' ).checked ? '1' : '';
		}

		// Поля самой записи есть только в редакторе записи; у термина их нет.
		if ( field( 'title' ) ) {
			data.title = value( 'title' );
			data.content = editorContent();
			data.excerpt = value( 'excerpt' );

			if ( slug() ) {
				data.slug = slug();
			}
		}

		return data;
	}

	function refresh() {
		var current = ++requestId;

		root.classList.add( 'is-loading' );

		window.wp
			.apiFetch( { path: config.path, method: 'POST', data: collect() } )
			.then( function ( response ) {
				// Ответ на устаревший запрос не должен затирать свежий.
				if ( current !== requestId ) {
					return;
				}

				lastMeta = response.meta;
				unknownVariables = response.unknown_variables || [];
				setBlurb( response.blurb );
				renderSnippet();
				renderAnalysis( response.analysis );
			} )
			.catch( function () {
				if ( current === requestId ) {
					renderError();
				}
			} )
			.then( function () {
				if ( current === requestId ) {
					root.classList.remove( 'is-loading' );
				}
			} );
	}

	function schedule() {
		renderMeters();
		window.clearTimeout( timer );
		timer = window.setTimeout( refresh, DEBOUNCE_MS );
	}

	/* ---------- Отрисовка ---------- */

	function setParts( element, text, keyword ) {
		element.textContent = '';

		utils.keywordParts( text, keyword ).forEach( function ( part ) {
			var node = part.bold ? document.createElement( 'b' ) : document.createTextNode( part.text );

			if ( part.bold ) {
				node.textContent = part.text;
			}

			element.appendChild( node );
		} );
	}

	/**
	 * Показывает текст и подсвечивает в нём неизвестные переменные шаблона: в предпросмотре они остаются как написаны.
	 *
	 * @param {Element}  element Узел предпросмотра.
	 * @param {string}   text    Текст.
	 * @param {string[]} tokens  Неизвестные переменные вместе со знаками процента.
	 */
	function setMarked( element, text, tokens ) {
		var rest = text;

		element.textContent = '';

		while ( rest !== '' ) {
			var at = -1;
			var found = '';

			tokens.forEach( function ( token ) {
				var index = rest.indexOf( token );

				if ( index !== -1 && ( at === -1 || index < at ) ) {
					at = index;
					found = token;
				}
			} );

			if ( at === -1 ) {
				element.appendChild( document.createTextNode( rest ) );
				break;
			}

			var mark = document.createElement( 'mark' );

			mark.className = 'indexpilot-unknown';
			mark.textContent = found;
			element.appendChild( document.createTextNode( rest.slice( 0, at ) ) );
			element.appendChild( mark );
			rest = rest.slice( at + found.length );
		}
	}

	/**
	 * Предупреждение о неизвестных переменных под предпросмотром.
	 */
	function renderUnknown() {
		var warning = $( '[data-indexpilot-unknown]' );

		if ( ! warning ) {
			return;
		}

		warning.hidden = unknownVariables.length === 0;
		warning.textContent = unknownVariables.length ? config.strings.unknown.replace( '%s', unknownVariables.join( ', ' ) ) : '';
	}

	function renderSnippet() {
		if ( ! lastMeta ) {
			return;
		}

		var mobile = $( '[data-indexpilot-serp]' ).getAttribute( 'data-device' ) === 'mobile';
		var keyword = value( 'indexpilot_focus_keyword' );
		var title = utils.truncateToWidth( lastMeta.title, config.titleMaxPx, measure );
		var limit = mobile ? MOBILE_DESCRIPTION : config.descriptionMax + 5;
		var description = utils.truncateChars( lastMeta.description, limit );
		var descriptionNode = $( '[data-indexpilot-serp-description]' );

		setMarked( $( '[data-indexpilot-serp-title]' ), title, unknownVariables );
		renderUnknown();
		$( '[data-indexpilot-url]' ).textContent = utils.breadcrumb( lastMeta.canonical || config.homeUrl );

		if ( description === '' ) {
			descriptionNode.textContent = config.strings.noDescription;
			descriptionNode.classList.add( 'is-empty' );
		} else {
			setParts( descriptionNode, description, keyword );
			descriptionNode.classList.remove( 'is-empty' );
		}

		$( '[data-indexpilot-card-title]' ).textContent = lastMeta.og_title;
		$( '[data-indexpilot-card-description]' ).textContent = lastMeta.og_description;

		// В пустых полях серым показано то, что подставится автоматически.
		if ( value( 'indexpilot_title' ) === '' ) {
			field( 'indexpilot_title' ).placeholder = lastMeta.title;
		}

		if ( value( 'indexpilot_description' ) === '' ) {
			field( 'indexpilot_description' ).placeholder = lastMeta.description;
		}

		renderMeters();
	}

	function setMeter( selector, ratio, state ) {
		var meter = $( selector );

		meter.style.width = Math.min( 100, Math.round( ratio * 100 ) ) + '%';
		meter.setAttribute( 'data-state', state );
	}

	function renderMeters() {
		var title = lastMeta ? lastMeta.title : value( 'indexpilot_title' );
		var titleWidth = measure( title );
		var description = value( 'indexpilot_description' ) || ( lastMeta ? lastMeta.description : '' );
		var length = description.length;
		var descriptionState = 'ok';

		setMeter( '[data-indexpilot-title-meter]', titleWidth / config.titleMaxPx, titleWidth > config.titleMaxPx ? 'bad' : titleWidth < 200 ? 'ok' : 'good' );

		if ( length >= config.descriptionMin && length <= config.descriptionMax ) {
			descriptionState = 'good';
		} else if ( length === 0 ) {
			descriptionState = 'bad';
		}

		setMeter( '[data-indexpilot-description-meter]', length / config.descriptionMax, descriptionState );
		$( '[data-indexpilot-description-counter]' ).textContent = length + ' ' + config.strings.chars + ' · ' + config.descriptionMin + '–' + config.descriptionMax;
		renderAnswerCounter();
	}

	/**
	 * Счётчик слов краткого ответа: зелёный, когда ответ укладывается в рекомендуемый объём.
	 */
	function renderAnswerCounter() {
		var counter = $( '[data-indexpilot-answer-counter]' );

		if ( ! counter ) {
			return;
		}

		var min = parseInt( counter.getAttribute( 'data-min' ), 10 );
		var max = parseInt( counter.getAttribute( 'data-max' ), 10 );
		var words = utils.countWords( value( 'indexpilot_answer' ) );

		counter.textContent = words + ' ' + config.strings.words + ' · ' + min + '–' + max;
		counter.setAttribute( 'data-state', words === 0 ? 'empty' : words >= min && words <= max ? 'good' : 'ok' );
	}

	function renderAnalysis( analysis ) {
		var holder = $( '[data-indexpilot-analysis]' );
		var badge = $( '[data-indexpilot-score]' );

		if ( ! analysis || ! analysis.results ) {
			return;
		}

		badge.textContent = analysis.score;
		badge.setAttribute( 'data-level', utils.scoreLevel( analysis.score ) );
		holder.textContent = '';

		// Тип материала сервер определяет по названию, рубрикам и подзаголовкам.
		if ( analysis.material && analysis.material.label && $( '[data-indexpilot-material]' ) ) {
			$( '[data-indexpilot-material]' ).textContent = config.strings.material.replace( '%s', analysis.material.label );
		}

		Object.keys( config.groups ).forEach( function ( group ) {
			var items = analysis.results.filter( function ( result ) {
				return result.group === group;
			} );

			if ( ! items.length ) {
				return;
			}

			var heading = document.createElement( 'h4' );
			var list = document.createElement( 'ul' );
			var note = null;

			heading.textContent = config.groups[ group ];
			list.className = 'indexpilot-checks';

			// У группы «Читаемость» своя оценка: в число в кружке вкладки она не входит.
			if ( group === 'readability' ) {
				if ( typeof analysis.readability === 'number' ) {
					var groupScore = document.createElement( 'span' );

					groupScore.className = 'indexpilot-score';
					groupScore.setAttribute( 'data-indexpilot-readability', '' );
					groupScore.setAttribute( 'data-level', utils.scoreLevel( analysis.readability ) );
					groupScore.textContent = analysis.readability;
					heading.appendChild( groupScore );
				}

				note = document.createElement( 'p' );
				note.className = 'description';
				note.textContent = config.strings.readability;
			}

			// Сначала то, что нужно исправить.
			[ 'bad', 'ok', 'good' ].forEach( function ( status ) {
				items.forEach( function ( result ) {
					if ( result.status !== status ) {
						return;
					}

					var item = document.createElement( 'li' );

					item.setAttribute( 'data-status', result.status );
					item.textContent = result.message;
					list.appendChild( item );
				} );
			} );

			holder.appendChild( heading );

			if ( note ) {
				holder.appendChild( note );
			}

			holder.appendChild( list );
		} );
	}

	function renderError() {
		var holder = $( '[data-indexpilot-analysis]' );
		var notice = holder.querySelector( '.indexpilot-error' ) || document.createElement( 'p' );

		notice.className = 'indexpilot-error';
		notice.textContent = config.strings.error;
		holder.insertBefore( notice, holder.firstChild );
	}

	/* ---------- Картинка для соцсетей ---------- */

	var pickButton = $( '[data-indexpilot-pick-image]' );
	var clearButton = $( '[data-indexpilot-clear-image]' );

	function setCardImage( url ) {
		var holder = $( '[data-indexpilot-card-image]' );

		holder.textContent = '';

		if ( url ) {
			var image = document.createElement( 'img' );

			image.src = url;
			image.alt = '';
			holder.appendChild( image );
		}
	}

	if ( pickButton && window.wp.media ) {
		pickButton.addEventListener( 'click', function () {
			var frame = window.wp.media( {
				title: config.strings.chooseImage,
				button: { text: config.strings.useImage },
				library: { type: 'image' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();

				field( 'indexpilot_og_image_id' ).value = attachment.id;
				setCardImage( attachment.sizes && attachment.sizes.large ? attachment.sizes.large.url : attachment.url );
			} );

			frame.open();
		} );
	}

	if ( clearButton ) {
		clearButton.addEventListener( 'click', function () {
			field( 'indexpilot_og_image_id' ).value = '';
			setCardImage( '' );
		} );
	}

	/* ---------- Картинка в поиске: версии 1:1, 4:3, 16:9 ---------- */

	// Та же проверка, что SearchImages::problem() на сервере: пропорция с допуском 3 %, ширина от 1200.
	function searchProblem( width, height, ratioW, ratioH ) {
		if ( ! width || ! height ) {
			return '';
		}
		if ( Math.abs( ( width / height ) / ( ratioW / ratioH ) - 1 ) > 0.03 ) {
			return config.strings.imageShape;
		}
		return width < 1200 ? config.strings.imageSmall : '';
	}

	Array.prototype.forEach.call( root.querySelectorAll( '[data-indexpilot-search-slot]' ), function ( slot ) {
		var idField = slot.querySelector( '[data-indexpilot-search-id]' );
		var preview = slot.querySelector( '[data-indexpilot-search-preview]' );
		var size = slot.querySelector( '[data-indexpilot-search-size]' );
		var warn = slot.querySelector( '[data-indexpilot-search-warn]' );
		var ratioW = Number( slot.getAttribute( 'data-ratio-w' ) );
		var ratioH = Number( slot.getAttribute( 'data-ratio-h' ) );
		var emptySize = idField.value ? '' : size.textContent;

		function show( attachment ) {
			preview.textContent = '';
			warn.textContent = '';

			if ( ! attachment ) {
				idField.value = '';
				size.textContent = emptySize;
				return;
			}

			var image = document.createElement( 'img' );

			image.src = attachment.sizes && attachment.sizes.large ? attachment.sizes.large.url : attachment.url;
			image.alt = '';
			preview.appendChild( image );
			idField.value = attachment.id;
			size.textContent = attachment.width + ' × ' + attachment.height;
			warn.textContent = searchProblem( attachment.width, attachment.height, ratioW, ratioH );
		}

		slot.querySelector( '[data-indexpilot-search-pick]' ).addEventListener( 'click', function () {
			if ( ! window.wp.media ) {
				return;
			}

			var frame = window.wp.media( {
				title: config.strings.chooseSearch.replace( '%s', ratioW + ':' + ratioH ),
				button: { text: config.strings.useImage },
				library: { type: 'image' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				show( frame.state().get( 'selection' ).first().toJSON() );
			} );

			frame.open();
		} );

		slot.querySelector( '[data-indexpilot-search-clear]' ).addEventListener( 'click', function () {
			show( null );
		} );
	} );

	/* ---------- Вопросы и ответы: добавление и удаление строк ---------- */

	var faq = $( '[data-indexpilot-faq]' );

	if ( faq ) {
		var faqRows = faq.querySelector( '[data-indexpilot-faq-rows]' );
		var faqTemplate = faq.querySelector( '[data-indexpilot-faq-template]' );
		var faqAdd = faq.querySelector( '[data-indexpilot-faq-add]' );
		var faqMax = parseInt( faq.getAttribute( 'data-max' ), 10 ) || 10;

		var updateFaq = function () {
			var count = faqRows.querySelectorAll( '[data-indexpilot-faq-row]' ).length;

			faqAdd.disabled = count >= faqMax;
			faq.querySelector( '[data-indexpilot-faq-count]' ).textContent = config.strings.faqCount.replace( '%1$d', count ).replace( '%2$d', faqMax );
		};

		faqRows.querySelectorAll( '[data-indexpilot-faq-remove]' ).forEach( function ( button ) {
			button.hidden = false;
		} );
		faq.querySelector( '[data-indexpilot-faq-actions]' ).hidden = false;

		faqAdd.addEventListener( 'click', function () {
			if ( faqRows.querySelectorAll( '[data-indexpilot-faq-row]' ).length >= faqMax ) {
				return;
			}

			// Заготовка строки лежит в теге template: его поля в форму не отправляются.
			var row = faqTemplate.content.firstElementChild.cloneNode( true );

			row.querySelector( '[data-indexpilot-faq-remove]' ).hidden = false;
			faqRows.appendChild( row );
			row.querySelector( 'input' ).focus();
			updateFaq();
		} );

		faqRows.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-indexpilot-faq-remove]' );

			if ( button ) {
				button.closest( '[data-indexpilot-faq-row]' ).remove();
				updateFaq();
			}
		} );

		updateFaq();
	}

	/* ---------- Факты и цитаты: заготовка шорткода в текст записи ---------- */

	/**
	 * Копирует текст в буфер обмена.
	 *
	 * @param {string}   text Текст.
	 * @param {Function} done Вызывается с true, если текст скопирован.
	 */
	function copyText( text, done ) {
		var fallback = function () {
			var area = document.createElement( 'textarea' );
			var copied = false;

			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();

			try {
				copied = document.execCommand( 'copy' );
			} catch ( error ) {
				copied = false;
			}

			document.body.removeChild( area );
			done( copied );
		};

		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( text ).then( function () {
				done( true );
			}, fallback );
		} else {
			fallback();
		}
	}

	/**
	 * Ставит текст в редактор записи там, где стоит курсор: в визуальном режиме или в текстовом.
	 *
	 * @param {string} text Текст.
	 * @return {boolean} Удалось ли вставить; false — редактора на странице нет (например, это редактор блоков).
	 */
	function insertIntoEditor( text ) {
		var editor = window.tinymce && window.tinymce.get( 'content' );
		var area = field( 'content' );

		if ( editor && ! editor.isHidden() ) {
			var paragraph = document.createElement( 'p' );

			paragraph.textContent = text;
			editor.focus();
			editor.insertContent( paragraph.outerHTML );

			return true;
		}

		if ( ! area || area.tagName !== 'TEXTAREA' || area.offsetParent === null ) {
			return false;
		}

		var start = typeof area.selectionStart === 'number' ? area.selectionStart : area.value.length;
		var end = typeof area.selectionEnd === 'number' ? area.selectionEnd : area.value.length;
		var block = ( start > 0 ? '\n\n' : '' ) + text + '\n\n';

		area.value = area.value.slice( 0, start ) + block + area.value.slice( end );
		area.focus();
		area.selectionStart = area.selectionEnd = start + block.length;
		schedule();

		return true;
	}

	root.querySelectorAll( '[data-indexpilot-insert]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var status = $( '[data-indexpilot-insert-status]' );
			var text = button.getAttribute( 'data-indexpilot-insert' );

			if ( insertIntoEditor( text ) ) {
				status.textContent = config.strings.inserted;

				return;
			}

			copyText( text, function ( copied ) {
				status.textContent = copied ? config.strings.insertCopied : config.strings.copyFailed + ' ' + text;
			} );
		} );
	} );

	/* ---------- Текст для ролика и стрима ---------- */

	var blurbText = $( '[data-indexpilot-blurb-text]' );
	var blurbButton = $( '[data-indexpilot-blurb]' );
	var blurbCopy = $( '[data-indexpilot-blurb-copy]' );

	/**
	 * Запоминает текст, собранный сервером из текущих значений полей.
	 *
	 * @param {string|undefined} text Текст из ответа сервера.
	 */
	function setBlurb( text ) {
		if ( blurbText && typeof text === 'string' ) {
			blurbText.value = text;
		}
	}

	if ( blurbText && blurbButton && blurbCopy ) {
		blurbButton.addEventListener( 'click', function () {
			blurbText.hidden = false;
			blurbCopy.hidden = false;
			blurbText.focus();
			blurbText.select();
		} );

		blurbCopy.addEventListener( 'click', function () {
			copyText( blurbText.value, function ( copied ) {
				$( '[data-indexpilot-blurb-status]' ).textContent = copied ? config.strings.copied : config.strings.copyFailed;
			} );
		} );
	}

	/* ---------- Основная рубрика: список следует за отмеченными рубриками записи ---------- */

	var primaryHolder = $( '[data-indexpilot-primary-category]' );
	var categoryList = document.getElementById( 'categorychecklist' );

	if ( primaryHolder && categoryList ) {
		var primarySelect = field( 'indexpilot_primary_category' );

		var syncPrimary = function () {
			var selected = primarySelect.value;
			var checked = categoryList.querySelectorAll( 'input[type="checkbox"]:checked' );

			// Первый пункт — «Авто»; остальные пересобираются из отмеченных рубрик.
			while ( primarySelect.options.length > 1 ) {
				primarySelect.remove( 1 );
			}

			checked.forEach( function ( input ) {
				var option = document.createElement( 'option' );

				option.value = input.value;
				option.textContent = input.parentNode.textContent.trim();
				option.selected = input.value === selected;
				primarySelect.appendChild( option );
			} );

			primaryHolder.hidden = checked.length < 2;
		};

		categoryList.addEventListener( 'change', syncPrimary );
		syncPrimary();
	}

	/* ---------- ИИ-помощник: SEO-поля и alt картинок по тексту статьи ---------- */

	var aiButton = $( '[data-indexpilot-ai-fill]' );

	/**
	 * Вписывает значение в поле панели. Заполненное поле меняется, только если редактор согласился.
	 *
	 * @param {string}  id      Поле.
	 * @param {string}  text    Значение.
	 * @param {boolean} replace Заменять ли заполненное.
	 * @return {boolean} Изменилось ли поле.
	 */
	function aiSet( id, text, replace ) {
		var element = field( id );

		if ( ! element || ! text || element.value === text || ( element.value.trim() !== '' && ! replace ) ) {
			return false;
		}

		element.value = text;
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		return true;
	}

	function aiFaqRows() {
		return faq ? Array.prototype.slice.call( faq.querySelectorAll( '[data-indexpilot-faq-rows] [data-indexpilot-faq-row]' ) ) : [];
	}

	function aiFaqFilled() {
		return aiFaqRows().some( function ( row ) {
			return row.querySelector( 'input' ).value.trim() !== '' || row.querySelector( 'textarea' ).value.trim() !== '';
		} );
	}

	/**
	 * Заменяет строки «вопрос — ответ».
	 *
	 * @param {Array} items Пары { question, answer }.
	 * @return {boolean} Изменились ли строки.
	 */
	function aiSetFaq( items ) {
		if ( ! faq || ! items || ! items.length ) {
			return false;
		}

		var rows = faq.querySelector( '[data-indexpilot-faq-rows]' );
		var template = faq.querySelector( '[data-indexpilot-faq-template]' );

		aiFaqRows().forEach( function ( row ) {
			row.remove();
		} );

		items.forEach( function ( item ) {
			var row = template.content.firstElementChild.cloneNode( true );

			row.querySelector( 'input' ).value = item.question;
			row.querySelector( 'textarea' ).value = item.answer;
			row.querySelector( '[data-indexpilot-faq-remove]' ).hidden = false;
			rows.appendChild( row );
		} );

		if ( typeof updateFaq === 'function' ) {
			updateFaq();
		}

		return true;
	}

	/**
	 * Вписывает alt картинкам в тексте статьи: в визуальном редакторе и в режиме «Текст».
	 *
	 * @param {Array} alts Картинки { src, id, alt }.
	 * @return {number} Сколько картинок получили alt; -1 — классического редактора на странице нет.
	 */
	function aiSetAlts( alts ) {
		var bySrc = {};
		var count = 0;
		var editor = window.tinymce && window.tinymce.get( 'content' );
		var area = field( 'content' );

		alts.forEach( function ( item ) {
			bySrc[ item.src ] = item.alt;
		} );

		if ( editor && ! editor.isHidden() ) {
			editor.dom.select( 'img' ).forEach( function ( image ) {
				var alt = bySrc[ image.getAttribute( 'src' ) ];

				if ( alt && image.getAttribute( 'alt' ) !== alt ) {
					editor.dom.setAttrib( image, 'alt', alt );
					count++;
				}
			} );

			if ( count ) {
				editor.setDirty( true );
				editor.fire( 'change' );
			}

			return count;
		}

		if ( ! area || area.tagName !== 'TEXTAREA' ) {
			return -1;
		}

		area.value = area.value.replace( /<img\b[^>]*>/gi, function ( tag ) {
			var match = tag.match( /\ssrc\s*=\s*(?:"([^"]*)"|'([^']*)')/i );
			var alt = match ? bySrc[ ( match[ 1 ] !== undefined ? match[ 1 ] : match[ 2 ] ).replace( /&amp;/g, '&' ) ] : '';

			if ( ! alt ) {
				return tag;
			}

			count++;

			return tag.replace( /\salt\s*=\s*(?:"[^"]*"|'[^']*')/i, '' ).replace( /^<img\b/i, '<img alt="' + alt.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ) + '"' );
		} );

		return count;
	}

	if ( aiButton ) {
		var aiStatus = $( '[data-indexpilot-ai-status]' );
		var aiAlts = $( '[data-indexpilot-ai-alts]' );
		var aiNames = config.strings.aiFields;

		aiButton.addEventListener( 'click', function () {
			aiButton.disabled = true;
			aiStatus.textContent = config.strings.aiWorking;

			window.wp
				.apiFetch( {
					path: config.aiPath,
					method: 'POST',
					data: {
						post_id: config.objectId,
						title: value( 'title' ),
						content: editorContent(),
						slug: slug(),
						description: value( 'indexpilot_description' ),
						focus: value( 'indexpilot_focus_keyword' ),
					},
				} )
				.then( function ( result ) {
					var filled = [ 'indexpilot_description', 'indexpilot_focus_keyword', 'indexpilot_keywords', 'indexpilot_answer' ].some( function ( id ) {
						return field( id ) && field( id ).value.trim() !== '';
					} ) || aiFaqFilled();
					var replace = filled && window.confirm( config.strings.aiReplace );
					var done = [];

					if ( aiSet( 'indexpilot_description', result.description, replace ) ) {
						done.push( aiNames.description );
					}
					if ( aiSet( 'indexpilot_focus_keyword', result.focus_keyword, replace ) ) {
						done.push( aiNames.focus );
					}
					if ( aiSet( 'indexpilot_keywords', ( result.keywords || [] ).join( ', ' ), replace ) ) {
						done.push( aiNames.keywords );
					}
					if ( aiSet( 'indexpilot_answer', result.answer, replace ) ) {
						done.push( aiNames.answer );
					}
					if ( ( replace || ! aiFaqFilled() ) && aiSetFaq( result.faq ) ) {
						done.push( aiNames.faq );
					}

					var alts = result.alts || [];
					var count = alts.length ? aiSetAlts( alts ) : 0;
					var saved = {};

					if ( count > 0 ) {
						done.push( aiNames.alts.replace( '%d', count ) );
						alts.forEach( function ( item ) {
							if ( item.id > 0 ) {
								saved[ item.id ] = item.alt;
							}
						} );
						aiAlts.value = JSON.stringify( saved );
					}

					aiStatus.textContent = ( done.length ? config.strings.aiDone.replace( '%s', done.join( ', ' ) ) : config.strings.aiNothing ) + ( count < 0 ? ' ' + config.strings.aiNoEditor : '' );
					schedule();
				} )
				.catch( function ( error ) {
					aiStatus.textContent = error && error.message ? error.message : config.strings.aiFailed;
				} )
				.then( function () {
					aiButton.disabled = false;
				} );
		} );
	}

	/* ---------- Запуск ---------- */

	if ( config.siteIcon ) {
		var icon = document.createElement( 'img' );

		icon.src = config.siteIcon;
		icon.alt = '';
		$( '[data-indexpilot-icon]' ).appendChild( icon );
	}

	root.addEventListener( 'input', schedule );

	[ 'title', 'excerpt', 'content' ].forEach( function ( id ) {
		var element = field( id );

		if ( element ) {
			element.addEventListener( 'input', schedule );
		}
	} );

	// Визуальный редактор появляется позже полей формы.
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'tinymce-editor-init', function ( event, editor ) {
			if ( editor.id === 'content' ) {
				editor.on( 'input change keyup', schedule );
			}
		} );
	}

	renderAnalysis( config.analysis );
	renderMeters();
	refresh();
} )();
