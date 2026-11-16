<?php
/**
 * Доступ второго сайта к списку ключевых слов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\CrossSite;

/**
 * Оба сайта владельца знают один общий ключ. Запрос без него или с другим ключом отклоняется.
 */
final class KeywordAccess {

	public const HEADER         = 'X-IndexPilot-Key';
	public const MIN_KEY_LENGTH = 24;

	/**
	 * Разрешён ли запрос.
	 *
	 * @param string $configured Ключ из настроек этого сайта.
	 * @param string $given      Ключ из запроса.
	 */
	public static function allows( string $configured, string $given ): bool {
		// Пустой или короткий ключ в настройках означает, что доступ не настроен: не пускаем никого.
		return strlen( $configured ) >= self::MIN_KEY_LENGTH && hash_equals( $configured, $given );
	}
}
