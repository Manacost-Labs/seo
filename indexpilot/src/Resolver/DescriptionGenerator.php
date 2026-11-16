<?php
/**
 * Автоматическое метаописание.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

use IndexPilot\Text\Cleaner;
use IndexPilot\Text\Truncator;

/**
 * Собирает описание из первого непустого источника: отрывка, текста записи, описания термина или произвольного поля.
 */
final class DescriptionGenerator {

	/**
	 * Источники по умолчанию для страниц, у которых они не заданы в настройках.
	 */
	private const DEFAULT_SOURCES = array(
		Context::TERM   => array( 'term_description' ),
		Context::AUTHOR => array( 'author_bio' ),
		Context::HOME   => array( 'tagline' ),
	);

	/**
	 * Конструктор.
	 *
	 * @param DataSource $data Источник данных.
	 */
	public function __construct( private DataSource $data ) {
	}

	/**
	 * Возвращает описание или пустую строку.
	 *
	 * @param Context      $context Контекст страницы.
	 * @param list<string> $sources Источники в порядке приоритета; пустой список — взять по умолчанию для вида страницы.
	 * @param int          $max     Наибольшая длина.
	 */
	public function generate( Context $context, array $sources, int $max ): string {
		if ( array() === $sources ) {
			$sources = self::DEFAULT_SOURCES[ $context->type ] ?? array();
		}

		foreach ( $sources as $source ) {
			$text = Cleaner::to_plain_text( $this->data->text( $context, $source ) );
			if ( '' !== $text ) {
				return Truncator::by_sentence( $text, $max );
			}
		}

		return '';
	}
}
