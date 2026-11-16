<?php
/**
 * Сохранение пачки строк массового редактора.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Bulk;

/**
 * Права проверяются для каждой строки отдельно: строка без прав отклоняется, остальные сохраняются.
 * Сохраняются только заголовок и описание — другие поля из запроса игнорируются.
 */
final class BatchSaver {

	public const MAX_ITEMS = 50;

	private const FIELDS = array( 'seo_title', 'seo_description' );

	/**
	 * Проверка права на правку объекта.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Запись полей объекта.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Конструктор.
	 *
	 * @param callable $can_edit Функция: ID объекта => можно ли его править.
	 * @param callable $write    Функция: ID объекта и поля (seo_title, seo_description) => запись.
	 */
	public function __construct( callable $can_edit, callable $write ) {
		$this->can_edit = $can_edit;
		$this->write    = $write;
	}

	/**
	 * Сохраняет пачку.
	 *
	 * @param array<int, mixed> $items Строки: id и изменённые поля.
	 * @return list<array{id: int, saved: bool}>
	 */
	public function save( array $items ): array {
		$results = array();

		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			$id = is_array( $item ) ? (int) ( $item['id'] ?? 0 ) : 0;

			if ( $id <= 0 ) {
				continue;
			}

			$fields = array();
			foreach ( self::FIELDS as $field ) {
				if ( isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ) {
					$fields[ $field ] = (string) $item[ $field ];
				}
			}

			$saved = array() !== $fields && (bool) ( $this->can_edit )( $id );

			if ( $saved ) {
				( $this->write )( $id, $fields );
			}

			$results[] = array(
				'id'    => $id,
				'saved' => $saved,
			);
		}

		return $results;
	}
}
