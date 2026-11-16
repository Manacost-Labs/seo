<?php
/**
 * Перевод текста записи из HTML в Markdown.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Llms;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- имена свойств DOM (childNodes, tagName, textContent) заданы PHP.

/**
 * Делает из HTML записи чистый Markdown для AI-ассистентов: заголовки, абзацы, списки, таблицы, цитаты, код,
 * картинки и ссылки. Меню, формы, скрипты, стили и встроенные фреймы выбрасываются вместе с содержимым.
 * Класс не зависит от WordPress.
 */
final class Markdown {

	/**
	 * Элементы, которые не попадают в текст вместе со всем содержимым.
	 */
	private const SKIP = array( 'script', 'style', 'noscript', 'template', 'nav', 'header', 'footer', 'aside', 'form', 'button', 'input', 'select', 'textarea', 'label', 'iframe', 'object', 'embed', 'svg', 'canvas', 'video', 'audio', 'map', 'dialog', 'head', 'title', 'meta', 'link' );

	/**
	 * Блочные элементы: каждый начинает новый абзац.
	 */
	private const BLOCKS = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'pre', 'hr', 'div', 'section', 'article', 'main', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'details', 'summary', 'address', 'center' );

	/**
	 * Документ целиком: заголовок, краткий ответ цитатой, текст и строка об источнике.
	 *
	 * @param string $title  Название записи.
	 * @param string $answer Краткий ответ; пусто — без него.
	 * @param string $html   HTML текста записи.
	 * @param string $footer Строка в конце, например адрес оригинала; пусто — без неё.
	 */
	public static function document( string $title, string $answer, string $html, string $footer = '' ): string {
		$parts = array( '# ' . self::line( $title ) );

		if ( '' !== self::line( $answer ) ) {
			$parts[] = '> ' . self::line( $answer );
		}

		$body = self::convert( $html );
		if ( '' !== $body ) {
			$parts[] = $body;
		}

		if ( '' !== self::line( $footer ) ) {
			$parts[] = '---';
			$parts[] = self::line( $footer );
		}

		return implode( "\n\n", $parts ) . "\n";
	}

	/**
	 * Переводит HTML в Markdown.
	 *
	 * @param string $html HTML.
	 */
	public static function convert( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $document->getElementsByTagName( 'div' )->item( 0 );

		if ( null === $root ) {
			return '';
		}

		$text = self::blocks( $root, "\n\n" );

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * Содержимое блочного элемента: блоки через разделитель, подряд идущий текст — одним абзацем.
	 *
	 * @param \DOMNode $node      Элемент.
	 * @param string   $separator Разделитель блоков.
	 */
	private static function blocks( \DOMNode $node, string $separator ): string {
		$blocks = array();
		$inline = '';

		foreach ( $node->childNodes as $child ) {
			$name = $child instanceof \DOMElement ? strtolower( $child->tagName ) : '';

			if ( '' !== $name && ( in_array( $name, self::BLOCKS, true ) || in_array( $name, array( 'li', 'tr' ), true ) ) ) {
				$blocks[] = self::paragraph( $inline );
				$inline   = '';
				$blocks[] = self::block( $child, $name );
				continue;
			}

			$inline .= self::inline( $child );
		}

		$blocks[] = self::paragraph( $inline );

		return implode( $separator, array_filter( $blocks, static fn( string $block ): bool => '' !== $block ) );
	}

	/**
	 * Один блочный элемент.
	 *
	 * @param \DOMElement $node Элемент.
	 * @param string      $name Имя тега в нижнем регистре.
	 */
	private static function block( \DOMElement $node, string $name ): string {
		if ( in_array( $name, self::SKIP, true ) ) {
			return '';
		}

		switch ( $name ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$text = self::paragraph( self::inline_children( $node ) );

				return '' === $text ? '' : str_repeat( '#', (int) substr( $name, 1 ) ) . ' ' . str_replace( "  \n", ' ', $text );

			case 'p':
			case 'dt':
			case 'summary':
				return self::paragraph( self::inline_children( $node ) );

			case 'figcaption':
				$text = self::paragraph( self::inline_children( $node ) );

				return '' === $text ? '' : '*' . $text . '*';

			case 'ul':
			case 'ol':
				return self::items( $node, 'ol' === $name );

			case 'table':
				return self::table( $node );

			case 'blockquote':
				$text = self::blocks( $node, "\n\n" );

				return '' === $text ? '' : (string) preg_replace( '/^/m', '> ', $text );

			case 'pre':
				$code = rtrim( (string) $node->textContent );

				return '' === trim( $code ) ? '' : "```\n" . $code . "\n```";

			case 'hr':
				return '---';

			case 'li':
				// Пункт вне списка — разметка с ошибкой: выводится отдельным пунктом.
				$text = self::blocks( $node, "\n" );

				return '' === $text ? '' : '- ' . str_replace( "\n", "\n  ", $text );

			default:
				return self::blocks( $node, "\n\n" );
		}
	}

	/**
	 * Список. Пункт может содержать абзацы и вложенный список: следующие строки пункта сдвигаются под его текст.
	 *
	 * @param \DOMNode $element Элемент ul или ol.
	 * @param bool     $ordered Нумерованный.
	 */
	private static function items( \DOMNode $element, bool $ordered ): string {
		$lines  = array();
		$number = 0;

		foreach ( $element->childNodes as $item ) {
			if ( ! $item instanceof \DOMElement || 'li' !== strtolower( $item->tagName ) ) {
				continue;
			}

			$text = self::blocks( $item, "\n" );

			if ( '' === $text ) {
				continue;
			}

			$marker  = $ordered ? ( ++$number ) . '. ' : '- ';
			$indent  = str_repeat( ' ', strlen( $marker ) );
			$lines[] = $marker . str_replace( "\n", "\n" . $indent, $text );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Таблица: первая строка — шапка.
	 *
	 * @param \DOMElement $table Элемент table.
	 */
	private static function table( \DOMElement $table ): string {
		$rows = array();

		foreach ( $table->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();

			foreach ( $row->childNodes as $cell ) {
				if ( $cell instanceof \DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
					$cells[] = str_replace( array( '|', "  \n" ), array( '\\|', ' ' ), self::paragraph( self::inline_children( $cell ) ) );
				}
			}

			if ( array() !== $cells ) {
				$rows[] = $cells;
			}
		}

		if ( array() === $rows ) {
			return '';
		}

		$width = max( array_map( 'count', $rows ) );
		$lines = array();

		foreach ( $rows as $index => $cells ) {
			$cells   = array_pad( $cells, $width, '' );
			$lines[] = '| ' . implode( ' | ', $cells ) . ' |';

			if ( 0 === $index ) {
				$lines[] = '|' . str_repeat( ' --- |', $width );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Строчный текст всех потомков элемента.
	 *
	 * @param \DOMNode $node Элемент.
	 */
	private static function inline_children( \DOMNode $node ): string {
		$text = '';

		foreach ( $node->childNodes as $child ) {
			$text .= self::inline( $child );
		}

		return $text;
	}

	/**
	 * Строчный текст узла: выделение, код, ссылки, картинки, переносы строк.
	 *
	 * @param \DOMNode $node Узел.
	 */
	private static function inline( \DOMNode $node ): string {
		if ( $node instanceof \DOMText ) {
			return (string) preg_replace( '/\s+/u', ' ', $node->data );
		}

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		$name = strtolower( $node->tagName );

		if ( in_array( $name, self::SKIP, true ) ) {
			return '';
		}

		if ( in_array( $name, self::BLOCKS, true ) || 'li' === $name ) {
			// Блок внутри строчного элемента: его текст идёт через пробел.
			return ' ' . self::inline_children( $node ) . ' ';
		}

		switch ( $name ) {
			case 'br':
				return "  \n";

			case 'strong':
			case 'b':
				return self::wrap( self::inline_children( $node ), '**' );

			case 'em':
			case 'i':
				return self::wrap( self::inline_children( $node ), '*' );

			case 'del':
			case 's':
				return self::wrap( self::inline_children( $node ), '~~' );

			case 'code':
			case 'kbd':
				$code = trim( (string) $node->textContent );

				return '' === $code ? '' : '`' . str_replace( '`', '\\`', $code ) . '`';

			case 'img':
				$src = trim( $node->getAttribute( 'src' ) );

				return self::safe_url( $src ) ? '![' . self::label( $node->getAttribute( 'alt' ) ) . '](' . self::url( $src ) . ')' : '';

			case 'a':
				$text = self::inline_children( $node );
				$href = trim( $node->getAttribute( 'href' ) );

				if ( '' === trim( $text ) || ! self::safe_url( $href ) || str_starts_with( $href, '#' ) ) {
					return $text;
				}

				return '[' . self::label( $text ) . '](' . self::url( $href ) . ')';

			default:
				return self::inline_children( $node );
		}
	}

	/**
	 * Обрамляет текст знаками выделения; пробелы по краям остаются снаружи.
	 *
	 * @param string $text Текст.
	 * @param string $mark Знак выделения.
	 */
	private static function wrap( string $text, string $mark ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}

		preg_match( '/^(\s*)(.*?)(\s*)$/su', $text, $parts );

		return $parts[1] . $mark . $parts[2] . $mark . $parts[3];
	}

	/**
	 * Абзац из строчного текста: без лишних пробелов по краям строк.
	 *
	 * @param string $text Текст.
	 */
	private static function paragraph( string $text ): string {
		$lines = array_map( static fn( string $line ): string => trim( (string) preg_replace( '/ {2,}/', ' ', $line ) ), explode( "  \n", $text ) );

		return trim( implode( "  \n", $lines ) );
	}

	/**
	 * Подпись ссылки или картинки: квадратные скобки внутри заменяются круглыми.
	 *
	 * @param string $text Текст.
	 */
	private static function label( string $text ): string {
		return str_replace( array( '[', ']' ), array( '(', ')' ), trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
	}

	/**
	 * Адрес для Markdown: пробелы и скобки кодируются.
	 *
	 * @param string $url Адрес.
	 */
	private static function url( string $url ): string {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $url );
	}

	/**
	 * Можно ли выводить адрес: не пустой и не скрипт.
	 *
	 * @param string $url Адрес.
	 */
	private static function safe_url( string $url ): bool {
		return '' !== $url && 1 !== preg_match( '/^\s*(javascript|vbscript|data):/i', $url );
	}

	/**
	 * Текст в одну строку.
	 *
	 * @param string $text Текст.
	 */
	private static function line( string $text ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс не зависит от WordPress и проверяется модульными тестами.
		return trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) );
	}
}
