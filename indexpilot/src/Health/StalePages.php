<?php
/**
 * Страницы без жизни.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Health;

use IndexPilot\Game\MaterialType;

/**
 * Страница без жизни: опубликована больше 90 дней назад, за 90 дней ни одного клика и почти нет показов,
 * на неё не ссылается ни одна запись. Длина текста причиной не считается: колода из сорока слов
 * или короткая новость — нормальные страницы (книга «SEO — искусство раскрутки сайтов», с. 556–559).
 * Для каждой страницы — причина, совет и похожая страница, с которой её можно объединить.
 */
final class StalePages {

	public const AGE_DAYS = 90;

	/**
	 * Меньше скольких показов за 90 дней — «почти нет показов».
	 */
	public const MAX_IMPRESSIONS = 20;

	/**
	 * Насколько похожи названия (доля общих слов), чтобы предложить объединение.
	 */
	public const SIMILAR = 0.5;

	/**
	 * Находит страницы без жизни.
	 *
	 * @param list<array{id: int, title: string, type: string, published: int, words: int, clicks: int, impressions: int, inbound: int, kept: bool, stems: list<string>}> $pages Страницы с данными поиска за 90 дней.
	 * @param int                                                                                                                                                          $now   Текущее время.
	 * @return list<array<string, mixed>> Страницы с полями reason, advice (merge, update, noindex, remove) и similar (ID похожей или 0).
	 */
	public static function find( array $pages, int $now ): array {
		$stale = array();

		foreach ( $pages as $page ) {
			$days = (int) floor( ( $now - (int) $page['published'] ) / 86400 );

			if ( $days <= self::AGE_DAYS || (int) $page['clicks'] > 0 || (int) $page['impressions'] >= self::MAX_IMPRESSIONS || (int) $page['inbound'] > 0 || ! empty( $page['kept'] ) ) {
				continue;
			}

			$similar = self::similar( $page, $pages );
			$type    = (string) $page['type'];

			if ( $similar > 0 ) {
				$advice = 'merge';
			} elseif ( in_array( $type, array( MaterialType::DECK, MaterialType::NEWS ), true ) ) {
				$advice = 'noindex';
			} elseif ( (int) $page['impressions'] > 0 ) {
				$advice = 'update';
			} else {
				$advice = 'remove';
			}

			$stale[] = $page + array(
				'days'    => $days,
				'reason'  => sprintf( 'опубликована %d дн. назад; за 90 дней 0 кликов и %d показов; входящих ссылок нет', $days, (int) $page['impressions'] ),
				'advice'  => $advice,
				'similar' => $similar,
			);
		}

		return $stale;
	}

	/**
	 * Самая похожая другая страница по словам названия; при равенстве — та, у которой больше кликов.
	 *
	 * @param array<string, mixed>       $page  Страница.
	 * @param list<array<string, mixed>> $pages Все страницы.
	 * @return int ID или 0.
	 */
	public static function similar( array $page, array $pages ): int {
		$own = array_values( array_unique( (array) $page['stems'] ) );

		if ( array() === $own ) {
			return 0;
		}

		$best  = 0;
		$score = array( 0.0, -1 );

		foreach ( $pages as $other ) {
			if ( (int) $other['id'] === (int) $page['id'] ) {
				continue;
			}

			$stems = array_values( array_unique( (array) $other['stems'] ) );
			$union = count( array_unique( array_merge( $own, $stems ) ) );
			$share = $union > 0 ? count( array_intersect( $own, $stems ) ) / $union : 0.0;
			$value = array( $share, (int) $other['clicks'] );

			if ( $share >= self::SIMILAR && $value > $score ) {
				$score = $value;
				$best  = (int) $other['id'];
			}
		}

		return $best;
	}
}
