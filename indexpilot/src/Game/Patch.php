<?php
/**
 * Актуальность материалов после патча.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Текущий патч игры из настроек: номер и дата выхода. Гайды и колоды, не обновлявшиеся с даты патча, устарели —
 * их нужно проверить. Новости, мета-отчёты и прочие материалы описывают своё время и не устаревают.
 * Класс не зависит от WordPress.
 */
final class Patch {

	/**
	 * Материалы, которые нужно обновлять после патча: гайды (вместе с муллиганом и матчапами) и колоды.
	 */
	public const TRACKED = array( MaterialType::GUIDE, MaterialType::MULLIGAN, MaterialType::MATCHUPS, MaterialType::DECK );

	/**
	 * Материалы World of Warcraft, которые нужно обновлять после патча: гайды, тактики, маршруты, профессии,
	 * прокачка и золото.
	 */
	public const TRACKED_WOW = array( WowMaterialType::GUIDE, WowMaterialType::BOSS, WowMaterialType::DUNGEON, WowMaterialType::PROFESSIONS, WowMaterialType::LEVELLING, WowMaterialType::GOLD );

	/**
	 * Типы материалов, за которыми следит этот патч.
	 *
	 * @var list<string>
	 */
	private array $watched;

	/**
	 * Номер патча.
	 *
	 * @var string
	 */
	public readonly string $version;

	/**
	 * Дата выхода патча в виде ГГГГ-ММ-ДД; пусто, если не задана или неверна.
	 *
	 * @var string
	 */
	public readonly string $date;

	/**
	 * Конструктор.
	 *
	 * @param string $version Номер патча, например 37.0.
	 * @param string       $date    Дата выхода: ГГГГ-ММ-ДД или ДД.ММ.ГГГГ.
	 * @param list<string> $watched Типы материалов, которые устаревают после патча; по умолчанию — Hearthstone.
	 */
	public function __construct( string $version, string $date, array $watched = self::TRACKED ) {
		$this->version = trim( $version );
		$this->date    = self::normalize_date( $date );
		$this->watched = $watched;
	}

	/**
	 * Задан ли патч: есть и номер, и верная дата.
	 */
	public function active(): bool {
		return '' !== $this->version && '' !== $this->date;
	}

	/**
	 * Нужно ли материалу этого типа обновление после патча.
	 *
	 * @param string $material Тип материала — константа MaterialType.
	 */
	public static function tracked( string $material ): bool {
		return in_array( $material, self::TRACKED, true );
	}

	/**
	 * Устарел ли материал: он из числа обновляемых и последний раз изменён раньше дня выхода патча.
	 *
	 * @param string $material Тип материала.
	 * @param string $modified Дата изменения: ГГГГ-ММ-ДД или ГГГГ-ММ-ДД ЧЧ:ММ:СС (время сайта).
	 */
	public function outdated( string $material, string $modified ): bool {
		$day = substr( trim( $modified ), 0, 10 );

		return $this->active() && in_array( $material, $this->watched, true ) && '' !== $day && $day < $this->date;
	}

	/**
	 * Актуален ли материал для текущего патча: он из числа обновляемых и изменён в день выхода патча или позже.
	 *
	 * @param string $material Тип материала.
	 * @param string $modified Дата изменения.
	 */
	public function current( string $material, string $modified ): bool {
		return $this->active() && in_array( $material, $this->watched, true ) && '' !== trim( $modified ) && ! $this->outdated( $material, $modified );
	}

	/**
	 * Дата в виде ГГГГ-ММ-ДД. Принимает ГГГГ-ММ-ДД и ДД.ММ.ГГГГ; несуществующая дата — пустая строка.
	 *
	 * @param string $date Дата.
	 */
	public static function normalize_date( string $date ): string {
		$date = trim( $date );

		if ( 1 === preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $found ) ) {
			list( , $year, $month, $day ) = $found;
		} elseif ( 1 === preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $found ) ) {
			list( , $day, $month, $year ) = $found;
		} else {
			return '';
		}

		return checkdate( (int) $month, (int) $day, (int) $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
	}
}
