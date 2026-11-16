<?php
/**
 * Итог анализа страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

/**
 * Оценка SEO 0–100 и список результатов проверок. Группа «Читаемость» оценивается отдельно
 * и в оценку SEO не входит.
 */
final class Report {

	/**
	 * Группа проверок, которая не влияет на оценку SEO.
	 */
	public const READABILITY = 'readability';

	/**
	 * Оценка страницы.
	 *
	 * @var int
	 */
	public readonly int $score;

	/**
	 * Конструктор.
	 *
	 * @param list<Result>                            $results     Результаты проверок.
	 * @param int|null                                $readability Оценка читаемости 0–100; null — текст не оценивался.
	 * @param array{type: string, label: string}|null $material    Определённый тип материала; null — не определялся.
	 */
	public function __construct(
		public readonly array $results,
		public readonly ?int $readability = null,
		public readonly ?array $material = null
	) {
		$earned = 0;
		$max    = 0;

		foreach ( $results as $result ) {
			if ( self::READABILITY === $result->group ) {
				continue;
			}

			$earned += $result->score;
			$max    += $result->max;
		}

		$this->score = $max > 0 ? (int) round( $earned / $max * 100 ) : 0;
	}

	/**
	 * Уровень оценки для цвета значка. Границы те же, что в панели редактора (snippet-utils.js).
	 *
	 * @param int $score Оценка от 0 до 100.
	 */
	public static function level( int $score ): string {
		if ( $score > 70 ) {
			return 'good';
		}

		return $score > 40 ? 'ok' : 'bad';
	}

	/**
	 * Массив для REST и хранения.
	 *
	 * @return array{score: int, results: list<array<string, mixed>>, readability: int|null, material: array{type: string, label: string}|null}
	 */
	public function to_array(): array {
		return array(
			'score'       => $this->score,
			'results'     => array_map( static fn( Result $result ): array => $result->to_array(), $this->results ),
			'readability' => $this->readability,
			'material'    => $this->material,
		);
	}
}
