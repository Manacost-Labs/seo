<?php
/**
 * Стеммер.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis\Language;

/**
 * Приводит слово к основе, чтобы разные формы одного слова считались одним ключом.
 */
interface Stemmer {

	/**
	 * Основа слова в нижнем регистре.
	 *
	 * @param string $word Слово.
	 */
	public function stem( string $word ): string;
}
