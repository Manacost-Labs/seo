<?php
/**
 * Разбор HTML-страницы для сравнения SEO-тегов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tools\CompareHead;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- свойства DOM названы в camelCase.

/**
 * Достаёт из готовой страницы всё, что видит поисковик в `<head>`: заголовок, описание, canonical,
 * robots, теги og:, twitter:, article:, число H1 и типы узлов JSON-LD.
 *
 * Результат — плоский словарь «ключ → строка», его удобно сохранять в JSON и сравнивать.
 * Если тег встречается несколько раз, значения склеиваются через « | », а у основных тегов
 * отдельно записывается их число: два `<title>` на странице — тоже находка.
 */
final class HeadParser {

	public const SEPARATOR = ' | ';

	/**
	 * Основные теги, у которых считается число вхождений.
	 */
	public const CORE = array( 'title', 'description', 'canonical', 'robots' );

	/**
	 * Разбирает HTML.
	 *
	 * @param string $html Текст страницы.
	 * @return array<string, string> Ключ → значение.
	 */
	public static function parse( string $html ): array {
		$values = array(
			'title'       => array(),
			'description' => array(),
			'canonical'   => array(),
			'robots'      => array(),
		);

		$fields = array(
			'h1_count'     => '0',
			'jsonld_types' => '',
		);

		if ( '' === trim( $html ) ) {
			return self::finish( $values, array(), $fields );
		}

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// Подсказка кодировки: без неё DOMDocument читает страницу как ISO-8859-1 и портит кириллицу.
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new \DOMXPath( $doc );

		foreach ( $xpath->query( '//title[not(ancestor::svg)]' ) as $node ) {
			$values['title'][] = self::clean( $node->textContent );
		}

		$social = array();

		foreach ( $xpath->query( '//meta' ) as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$name     = strtolower( trim( $node->getAttribute( 'name' ) ) );
			$property = strtolower( trim( $node->getAttribute( 'property' ) ) );
			$content  = self::clean( $node->getAttribute( 'content' ) );

			if ( 'description' === $name ) {
				$values['description'][] = $content;
				continue;
			}

			if ( 'robots' === $name ) {
				$values['robots'][] = $content;
				continue;
			}

			// Сайты пишут og: то в property, то в name — учитываем оба.
			$key = '' !== $property ? $property : $name;

			if ( preg_match( '/^(og|twitter|article):[a-z0-9_:.-]+$/', $key ) ) {
				$social[ $key ][] = $content;
			}
		}

		foreach ( $xpath->query( '//link[@rel]' ) as $node ) {
			if ( $node instanceof \DOMElement && in_array( 'canonical', preg_split( '/\s+/', strtolower( trim( $node->getAttribute( 'rel' ) ) ) ), true ) ) {
				$values['canonical'][] = self::clean( $node->getAttribute( 'href' ) );
			}
		}

		$fields['h1_count'] = (string) $xpath->query( '//h1' )->length;

		$types  = array();
		$errors = 0;

		foreach ( $xpath->query( '//script[@type]' ) as $node ) {
			if ( ! $node instanceof \DOMElement || 'application/ld+json' !== strtolower( trim( $node->getAttribute( 'type' ) ) ) ) {
				continue;
			}

			$data = json_decode( trim( $node->textContent ), true );

			if ( ! is_array( $data ) ) {
				++$errors;
				continue;
			}

			self::collect_types( $data, $types );
		}

		$types = array_values( array_unique( $types ) );
		sort( $types, SORT_STRING );
		$fields['jsonld_types'] = implode( ', ', $types );

		if ( $errors > 0 ) {
			$fields['jsonld_errors'] = (string) $errors;
		}

		return self::finish( $values, $social, $fields );
	}

	/**
	 * Собирает плоский словарь.
	 *
	 * @param array<string, list<string>> $values Основные теги.
	 * @param array<string, list<string>> $social Теги og:, twitter:, article:.
	 * @param array<string, string>       $fields Прочие поля.
	 * @return array<string, string>
	 */
	private static function finish( array $values, array $social, array $fields ): array {
		$result = array();

		foreach ( self::CORE as $key ) {
			$result[ $key ]            = implode( self::SEPARATOR, $values[ $key ] );
			$result[ $key . '_count' ] = (string) count( $values[ $key ] );
		}

		ksort( $social, SORT_STRING );

		foreach ( $social as $key => $list ) {
			$result[ $key ] = implode( self::SEPARATOR, $list );
		}

		return array_merge( $result, $fields );
	}

	/**
	 * Собирает значения `@type` из узла JSON-LD и всех вложенных узлов.
	 *
	 * @param array<mixed> $data  Узел.
	 * @param list<string> $types Найденные типы.
	 */
	private static function collect_types( array $data, array &$types ): void {
		if ( isset( $data['@type'] ) ) {
			foreach ( (array) $data['@type'] as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					$types[] = $type;
				}
			}
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				self::collect_types( $value, $types );
			}
		}
	}

	/**
	 * Убирает лишние пробелы.
	 *
	 * @param string $text Текст.
	 */
	private static function clean( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
