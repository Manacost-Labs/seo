<?php
/**
 * Граф разметки JSON-LD.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Schema;

/**
 * Узлы страницы, связанные между собой через @id. На страницу выводится один блок со всем графом.
 */
final class Graph {

	/**
	 * Узлы по их @id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $nodes = array();

	/**
	 * Добавляет узел. Узел с тем же @id заменяется. Пустые значения из узла убираются.
	 *
	 * @param array<string, mixed> $node Узел с ключами @type и @id.
	 */
	public function add( array $node ): void {
		$node = self::without_empty( $node );

		if ( isset( $node['@id'], $node['@type'] ) ) {
			$this->nodes[ (string) $node['@id'] ] = $node;
		}
	}

	/**
	 * Все узлы графа.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function nodes(): array {
		return array_values( $this->nodes );
	}

	/**
	 * Узел по его @id или null.
	 *
	 * @param string $id Идентификатор узла.
	 * @return array<string, mixed>|null
	 */
	public function node( string $id ): ?array {
		return $this->nodes[ $id ] ?? null;
	}

	/**
	 * Первый узел заданного типа или null.
	 *
	 * @param string $type Тип schema.org.
	 * @return array<string, mixed>|null
	 */
	public function first( string $type ): ?array {
		foreach ( $this->nodes as $node ) {
			if ( $type === $node['@type'] ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Ссылки @id, которые указывают на узлы, отсутствующие в графе.
	 *
	 * @return list<string>
	 */
	public function dangling(): array {
		$missing = array();

		foreach ( $this->nodes as $node ) {
			foreach ( self::references( $node ) as $id ) {
				if ( ! isset( $this->nodes[ $id ] ) ) {
					$missing[ $id ] = true;
				}
			}
		}

		return array_map( 'strval', array_keys( $missing ) );
	}

	/**
	 * Граф в виде JSON для тега script. Угловые скобки кодируются, чтобы текст не мог закрыть тег.
	 */
	public function to_json(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- класс не зависит от WordPress и проверяется модульными тестами.
		return (string) json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $this->nodes(),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
		);
	}

	/**
	 * Ссылки на другие узлы внутри значения: массивы из одного ключа @id.
	 *
	 * @param array<int|string, mixed> $value Узел или его часть.
	 * @return list<string>
	 */
	private static function references( array $value ): array {
		$ids = array();

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( array( '@id' ) === array_keys( $item ) ) {
				$ids[] = (string) $item['@id'];
			} else {
				$ids = array_merge( $ids, self::references( $item ) );
			}
		}

		return $ids;
	}

	/**
	 * Убирает пустые строки, null и пустые массивы на всех уровнях.
	 *
	 * @param array<int|string, mixed> $value Значение.
	 * @return array<int|string, mixed>
	 */
	private static function without_empty( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$item          = self::without_empty( $item );
				$value[ $key ] = $item;
			}

			if ( null === $item || '' === $item || array() === $item ) {
				unset( $value[ $key ] );
			}
		}

		return $value;
	}
}
