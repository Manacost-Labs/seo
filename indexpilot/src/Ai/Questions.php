<?php
/**
 * Контрольные вопросы для ручной проверки ассистентов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Ai;

/**
 * Двадцать вопросов, которые редактор раз в месяц задаёт ChatGPT, Perplexity, Алисе и другим ассистентам,
 * и отмечает: цитируют ли сайт, и кого цитируют вместо него. Не зависит от WordPress.
 */
final class Questions {

	public const OPTION = 'indexpilot_ai_questions';

	public const CITED     = 'yes';
	public const NOT_CITED = 'no';

	/**
	 * Сколько вопросов в таблице.
	 */
	public const COUNT = 20;

	/**
	 * Вопросы по умолчанию.
	 */
	private const DEFAULTS = array(
		'лучшая колода жреца hearthstone',
		'лучшая колода мага hearthstone',
		'лучшая колода охотника hearthstone',
		'лучшая колода паладина hearthstone',
		'лучшая колода разбойника hearthstone',
		'лучшая колода шамана hearthstone',
		'лучшая колода чернокнижника hearthstone',
		'лучшая колода воина hearthstone',
		'лучшая колода друида hearthstone',
		'лучшая колода охотника на демонов hearthstone',
		'лучшая колода рыцаря смерти hearthstone',
		'какая сейчас мета в hearthstone',
		'лучшие колоды для новичка hearthstone',
		'дешёвые колоды hearthstone без легендарных карт',
		'лучшие колоды вольного формата hearthstone',
		'как выбирать карты в муллигане hearthstone',
		'что крафтить в новом дополнении hearthstone',
		'колоды стримеров hearthstone',
		'лучшие герои поля сражений hearthstone',
		'как вставить код колоды в hearthstone',
	);

	/**
	 * Таблица по умолчанию.
	 *
	 * @return list<array{question: string, status: string, who: string, checked: string}>
	 */
	public static function defaults(): array {
		return array_map(
			static fn( string $question ): array => array(
				'question' => $question,
				'status'   => '',
				'who'      => '',
				'checked'  => '',
			),
			self::DEFAULTS
		);
	}

	/**
	 * Очищает таблицу из формы: пустые вопросы выбрасываются, строк не больше 20.
	 *
	 * @param mixed $input Строки формы.
	 * @return list<array{question: string, status: string, who: string, checked: string}>
	 */
	public static function sanitize( $input ): array {
		$rows = array();

		foreach ( is_array( $input ) ? $input : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$question = self::text( $row['question'] ?? '', 200 );

			if ( '' === $question ) {
				continue;
			}

			$status  = (string) ( $row['status'] ?? '' );
			$checked = (string) ( $row['checked'] ?? '' );

			$rows[] = array(
				'question' => $question,
				'status'   => in_array( $status, array( self::CITED, self::NOT_CITED ), true ) ? $status : '',
				'who'      => self::text( $row['who'] ?? '', 300 ),
				'checked'  => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $checked ) ? $checked : '',
			);

			if ( count( $rows ) >= self::COUNT ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Строка без разметки и лишних пробелов.
	 *
	 * @param mixed $value Значение.
	 * @param int   $max   Наибольшая длина.
	 */
	private static function text( $value, int $max ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс не зависит от WordPress.
		$text = is_scalar( $value ) ? trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $value ) ) ) : '';

		return mb_substr( $text, 0, $max );
	}
}
