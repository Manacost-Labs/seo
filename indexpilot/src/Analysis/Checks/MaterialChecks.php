<?php
/**
 * Полнота материала по его типу.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis\Checks;

use IndexPilot\Analysis\CheckGroup;
use IndexPilot\Analysis\Facts;
use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Result;
use IndexPilot\Game\MaterialType;

/**
 * Есть ли в тексте разделы, которых читатель ждёт от материала такого типа: в гайде — состав, муллиган, стратегия,
 * матчапы и замены. Тип определяется по названию, рубрикам и подзаголовкам, поэтому проверка — подсказка без баллов.
 * У колод текста нет: к ним она не применяется.
 */
final class MaterialChecks implements CheckGroup {

	private const GROUP = 'content';

	/**
	 * Как назвать материал в замечании: «В гайде нет раздела…».
	 */
	private const WHERE = array(
		MaterialType::GUIDE     => 'В гайде',
		MaterialType::META      => 'В мета-отчёте',
		MaterialType::TIER_LIST => 'В тир-листе',
		MaterialType::PATCH     => 'В материале о патче',
	);

	/**
	 * Выполняет проверку.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		unset( $facts, $matcher );

		if ( ! isset( self::WHERE[ $paper->material ] ) ) {
			return array();
		}

		$expected = MaterialType::expected_sections( $paper->material );
		$missing  = MaterialType::missing_sections( $paper->material, $paper->content );
		$where    = self::WHERE[ $paper->material ];

		if ( array() === $missing ) {
			return array( new Result( 'material_sections', self::GROUP, Result::GOOD, 0, sprintf( '%s есть все ожидаемые разделы: %s.', $where, implode( ', ', $expected ) ) ) );
		}

		$noun = 1 === count( $missing ) ? 'раздела' : 'разделов';

		return array( new Result( 'material_sections', self::GROUP, Result::OK, 0, sprintf( '%s нет %s: %s. Добавьте подзаголовки H2–H3 с этими темами.', $where, $noun, implode( ', ', $missing ) ) ) );
	}
}
