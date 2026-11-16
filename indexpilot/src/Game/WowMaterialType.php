<?php
/**
 * Типы игровых материалов.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Game;

use IndexPilot\Text\Cleaner;
/** Определяет тип и ожидаемые разделы без WordPress. */
final class WowMaterialType {
	public const GUIDE       = 'guide';
	public const BOSS        = 'boss';
	public const DUNGEON     = 'dungeon';
	public const PROFESSIONS = 'professions';
	public const LEVELLING   = 'levelling';
	public const GOLD        = 'gold';
	public const NEWS        = 'news';
	public const PATCH       = 'patch';
	public const TIER_LIST   = 'tier_list';
	public const OTHER       = 'other';
	private const LABELS     = array(
		'guide'       => 'Гайд по классу и специализации',
		'boss'        => 'Тактика на босса',
		'dungeon'     => 'Маршрут подземелья',
		'professions' => 'Профессии',
		'levelling'   => 'Прокачка',
		'gold'        => 'Золото',
		'news'        => 'Новость',
		'patch'       => 'Патч',
		'tier_list'   => 'Тир-лист специализаций',
		'other'       => 'Материал',
	);
	private const SECTIONS   = array(
		'talents'   => 'Таланты',
		'rotation'  => 'Ротация',
		'stats'     => 'Характеристики',
		'gear'      => 'Экипировка',
		'enchants'  => 'Чары и камни',
		'addons'    => 'Аддоны и макросы',
		'mechanics' => 'Механики босса',
		'tactics'   => 'Тактика',
		'route'     => 'Маршрут',
		'changes'   => 'Что изменилось',
		'list'      => 'Список специализаций',
	);
	private const WORDS      = array(
		'talents'   => 'талант',
		'rotation'  => 'ротаци|приоритет способност',
		'stats'     => 'характерист|приоритет стат',
		'gear'      => 'экипиров|bis|бис',
		'enchants'  => 'чар|камн',
		'addons'    => 'аддон|макрос',
		'mechanics' => 'механик|способност',
		'tactics'   => 'тактик|фаз',
		'route'     => 'маршрут',
		'changes'   => 'изменени|что нового',
		'list'      => 'список|тир|рейтинг',
	);
	/** Возвращает все типы.
	 * @return list<string>
	 */
	public static function all(): array {
		return array_keys( self::LABELS ); }
	/** Распознаёт тип материала.
	 * @param string $title Заголовок.
	 * @param list<string> $category_names Категории.
	 * @param string $html Содержимое.
	 * @param string $post_type Тип записи.
	 * @return string
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Совместимость с MaterialType.
	public static function detect( string $title, array $category_names, string $html, string $post_type = 'post' ): string {
		$text = mb_strtolower( $title . ' ' . implode( ' ', $category_names ), 'UTF-8' );
		foreach ( array(
			self::TIER_LIST   => 'тир[\s–—-]*лист',
			self::PATCH       => 'патч|обновлени|нерф|бафф',
			self::NEWS        => 'новост',
			self::BOSS        => 'босс|тактика',
			self::DUNGEON     => 'маршрут',
			self::PROFESSIONS => 'професси|кузнеч|алхими|портняж|ювелир|инженери|травнич|горное дело|снятие шкур|начертани|кожевни|наложение чар|кулинари|рыбалк',
			self::LEVELLING   => 'прокачк|повышение уровня',
			self::GOLD        => 'золот|фарм голд',
			self::GUIDE       => 'гайд|руководств|разбор|как играть',
		) as $type => $pattern ) {
			if ( preg_match( '/(?<!\p{L})(?:' . $pattern . ')/u', $text ) ) {
				return $type; }
		}
		$headings = self::headings( $html, '23' );
		return self::contains( $headings, 'talents' ) && self::contains( $headings, 'rotation' ) ? self::GUIDE : self::OTHER;
	}

	/** Возвращает русское название.
	 * @param string $type Тип.
	 * @return string
	 */
	public static function label( string $type ): string {
		return self::LABELS[ $type ] ?? self::LABELS[ self::OTHER ]; }
	/** Возвращает ожидаемые разделы.
	 * @param string $type Тип.
	 * @return array<string,string>
	 */
	public static function expected_sections( string $type ): array {
		$keys = match ( $type ) {
			self::GUIDE => array( 'talents', 'rotation', 'stats', 'gear', 'enchants', 'addons' ), self::BOSS => array( 'mechanics', 'tactics' ), self::DUNGEON => array( 'route' ), self::PATCH => array( 'changes' ), self::TIER_LIST => array( 'list' ), default => array() };
		return array_intersect_key( self::SECTIONS, array_flip( $keys ) );
	}
	/** Возвращает названия недостающих разделов.
	 * @param string $type Тип.
	 * @param string $html Содержимое.
	 * @return list<string>
	 */
	public static function missing_sections( string $type, string $html ): array {
		$headings = self::headings( $html, '234' );
		$missing  = array();
		foreach ( self::expected_sections( $type ) as $key => $label ) {
			if ( self::contains( $headings, $key ) ) {
				continue; }
			$missing[] = $label;
		}
		return $missing;
	}
	/** Извлекает заголовки.
	 * @param string $html HTML.
	 * @param string $levels Уровни.
	 * @return string
	 */
	private static function headings( string $html, string $levels ): string {
		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>|<!--.*?-->#isu', '', $html );
		preg_match_all( '#<h([' . $levels . '])\b[^>]*>(.*?)</h\1>#isu', $html, $matches );
		return implode( "\n", array_map( static fn( string $heading ): string => Cleaner::to_plain_text( $heading, false ), $matches[2] ) );
	}
	/** Проверяет словоформы раздела.
	 * @param string $text Заголовки.
	 * @param string $key Раздел.
	 * @return bool
	 */
	private static function contains( string $text, string $key ): bool {
		return 1 === preg_match( '/(?<!\p{L})(?:' . self::WORDS[ $key ] . ')/iu', $text ); }
}
