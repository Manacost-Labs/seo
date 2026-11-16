<?php
/**
 * Правило для «тонких» архивов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

/**
 * Архив термина, в котором записей меньше порога, не несёт пользы в поиске и закрывается от индексации.
 */
final class ThinArchiveRule {

	/**
	 * Является ли архив «тонким».
	 *
	 * @param Context $context   Контекст страницы.
	 * @param int     $count     Число записей в архиве; -1, если неизвестно.
	 * @param int     $threshold Порог из настроек; 0 отключает правило.
	 */
	public static function is_thin( Context $context, int $count, int $threshold ): bool {
		// Страница автора без единой опубликованной записи пуста: её в индексе быть не должно.
		// То же с адресом несуществующего автора (/author/nobody/): пользователя нет, хотя записи без автора на сайте могут быть.
		if ( Context::AUTHOR === $context->type ) {
			return $context->object_id <= 0 || 0 === $count;
		}

		return Context::TERM === $context->type && $threshold > 0 && $count >= 0 && $count < $threshold;
	}
}
