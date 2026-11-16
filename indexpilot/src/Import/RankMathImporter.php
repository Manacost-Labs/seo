<?php
/**
 * Импорт из Rank Math.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

use IndexPilot\Meta\MetaKeys;

/**
 * На hs-manacost.ru от Rank Math остались старые данные. Они берутся только для записей,
 * у которых основной источник не дал ни заголовка, ни описания.
 */
final class RankMathImporter extends MetaSource {

	/**
	 * Идентификатор источника.
	 */
	public function id(): string {
		return 'rank_math';
	}

	/**
	 * Название источника.
	 */
	public function label(): string {
		return 'Rank Math';
	}

	/**
	 * Запасной источник.
	 */
	public function is_fallback(): bool {
		return true;
	}

	/**
	 * Ключи меты плагина.
	 *
	 * @return list<string>
	 */
	protected function keys(): array {
		return array(
			'rank_math_title',
			'rank_math_description',
			'rank_math_focus_keyword',
			'rank_math_canonical_url',
			'rank_math_robots',
			'rank_math_facebook_title',
			'rank_math_facebook_description',
			'rank_math_facebook_image_id',
			'rank_math_facebook_image',
			'rank_math_pillar_content',
		);
	}

	/**
	 * Мета плагина → поля IndexPilot.
	 *
	 * @param array<string, mixed> $row Значения меты rank_math_*.
	 * @return array<string, mixed>
	 */
	public function map( array $row ): array {
		// Rank Math хранит ключевые слова одной строкой через запятую: первое — основное.
		$keywords = Importer::keywords( explode( ',', (string) ( $row['rank_math_focus_keyword'] ?? '' ) ) );
		$focus    = (string) array_shift( $keywords );
		$robots   = self::robots_flags( $row['rank_math_robots'] ?? '' );
		$image_id = (int) ( $row['rank_math_facebook_image_id'] ?? 0 );

		return self::without_empty(
			array(
				MetaKeys::TITLE          => VariableSyntaxConverter::from_rank_math( (string) ( $row['rank_math_title'] ?? '' ) ),
				MetaKeys::DESCRIPTION    => VariableSyntaxConverter::from_rank_math( (string) ( $row['rank_math_description'] ?? '' ) ),
				MetaKeys::FOCUS_KEYWORD  => $focus,
				MetaKeys::KEYWORDS       => $keywords,
				MetaKeys::CANONICAL      => trim( (string) ( $row['rank_math_canonical_url'] ?? '' ) ),
				MetaKeys::OG_TITLE       => VariableSyntaxConverter::from_rank_math( (string) ( $row['rank_math_facebook_title'] ?? '' ) ),
				MetaKeys::OG_DESCRIPTION => VariableSyntaxConverter::from_rank_math( (string) ( $row['rank_math_facebook_description'] ?? '' ) ),
				MetaKeys::OG_IMAGE_ID    => $image_id,
				Importer::OG_IMAGE_URL   => $image_id > 0 ? '' : trim( (string) ( $row['rank_math_facebook_image'] ?? '' ) ),
				MetaKeys::NOINDEX        => $robots['noindex'] ? '1' : '',
				MetaKeys::NOFOLLOW       => $robots['nofollow'] ? '1' : '',
				MetaKeys::CORNERSTONE    => ! empty( $row['rank_math_pillar_content'] ) ? '1' : '',
			)
		);
	}
}
