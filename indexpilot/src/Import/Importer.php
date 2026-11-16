<?php
/**
 * Перенос полей из другого SEO-плагина.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

use IndexPilot\Meta\MetaKeys;

/**
 * Обрабатывает одну пачку записей источника. Данные источника не меняются и не удаляются.
 *
 * Уже заполненные поля IndexPilot не трогаются, если явно не разрешена замена.
 */
final class Importer {

	/**
	 * Служебный ключ: источник знает только адрес картинки, а IndexPilot хранит ID вложения.
	 */
	public const OG_IMAGE_URL = 'og_image_url';

	private const SAMPLE_LIMIT = 5;

	/**
	 * Поиск вложения по адресу картинки.
	 *
	 * @var callable|null
	 */
	private $image_resolver;

	/**
	 * Вызывается для каждой записи, в которую что-то записано.
	 *
	 * @var callable|null
	 */
	private $after_import;

	/**
	 * Конструктор.
	 *
	 * @param MetaStore     $store          Хранилище меты.
	 * @param callable|null $image_resolver Функция «адрес картинки → ID вложения» (0, если не найдено).
	 * @param callable|null $after_import   Функция, получающая ID записи после записи полей.
	 */
	public function __construct( private MetaStore $store, ?callable $image_resolver = null, ?callable $after_import = null ) {
		$this->image_resolver = $image_resolver;
		$this->after_import   = $after_import;
	}

	/**
	 * Обрабатывает пачку.
	 *
	 * @param Source $source    Источник.
	 * @param int    $after_id  ID последней обработанной записи; 0 — начать сначала.
	 * @param int    $limit     Размер пачки.
	 * @param bool   $dry_run   Только показать, что будет перенесено.
	 * @param bool   $overwrite Заменять уже заполненные поля.
	 * @return array{processed: int, imported: int, skipped: int, fields: int, last_id: int, done: bool, samples: list<array{post_id: int, fields: array<string, mixed>}>}
	 */
	public function run( Source $source, int $after_id, int $limit, bool $dry_run = false, bool $overwrite = false ): array {
		$batch  = $source->batch( $after_id, $limit );
		$result = array(
			'processed' => 0,
			'imported'  => 0,
			'skipped'   => 0,
			'fields'    => 0,
			'last_id'   => $after_id,
			'done'      => count( $batch ) < $limit,
			'samples'   => array(),
		);

		foreach ( $batch as $post_id => $row ) {
			$post_id = (int) $post_id;

			++$result['processed'];
			$result['last_id'] = $post_id;

			$fields = $this->store->has( $post_id ) ? $this->fields( $source, $post_id, $row, $overwrite ) : array();

			if ( array() === $fields ) {
				++$result['skipped'];
				continue;
			}

			++$result['imported'];
			$result['fields'] += count( $fields );

			if ( $dry_run ) {
				if ( count( $result['samples'] ) < self::SAMPLE_LIMIT ) {
					$result['samples'][] = array(
						'post_id' => $post_id,
						'fields'  => $fields,
					);
				}

				continue;
			}

			foreach ( $fields as $key => $value ) {
				$this->store->set( $post_id, $key, $value );
			}

			if ( null !== $this->after_import ) {
				( $this->after_import )( $post_id );
			}
		}

		return $result;
	}

	/**
	 * Поля, которые нужно записать в запись.
	 *
	 * @param Source               $source    Источник.
	 * @param int                  $post_id   ID записи.
	 * @param array<string, mixed> $row       Строка источника.
	 * @param bool                 $overwrite Заменять уже заполненные поля.
	 * @return array<string, mixed>
	 */
	private function fields( Source $source, int $post_id, array $row, bool $overwrite ): array {
		$fields = $source->map( $row );

		if ( isset( $fields[ self::OG_IMAGE_URL ] ) ) {
			$image_id = null !== $this->image_resolver ? (int) ( $this->image_resolver )( (string) $fields[ self::OG_IMAGE_URL ] ) : 0;

			unset( $fields[ self::OG_IMAGE_URL ] );

			if ( $image_id > 0 ) {
				$fields[ MetaKeys::OG_IMAGE_ID ] = $image_id;
			}
		}

		if ( $overwrite ) {
			return $fields;
		}

		if ( $source->is_fallback() && ( $this->filled( $post_id, MetaKeys::TITLE ) || $this->filled( $post_id, MetaKeys::DESCRIPTION ) ) ) {
			return array();
		}

		foreach ( array_keys( $fields ) as $key ) {
			if ( $this->filled( $post_id, $key ) ) {
				unset( $fields[ $key ] );
			}
		}

		return $fields;
	}

	/**
	 * Заполнено ли поле IndexPilot у записи.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $key     Ключ меты.
	 */
	private function filled( int $post_id, string $key ): bool {
		return ! in_array( $this->store->get( $post_id, $key ), array( '', '0', 0, null, false, array() ), true );
	}

	/**
	 * Список ключевых слов без пустых и повторов (без учёта регистра).
	 *
	 * @param array<int, mixed> $items   Ключевые слова.
	 * @param string            $exclude Слово, которое в список не входит (обычно Focus Keyword).
	 * @return list<string>
	 */
	public static function keywords( array $items, string $exclude = '' ): array {
		$seen = array( mb_strtolower( trim( $exclude ) ) => true );
		$list = array();

		foreach ( $items as $item ) {
			$item = is_scalar( $item ) ? trim( (string) $item ) : '';
			$key  = mb_strtolower( $item );

			if ( '' === $item || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$list[]       = $item;
		}

		return $list;
	}
}
