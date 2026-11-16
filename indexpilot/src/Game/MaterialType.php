<?php
/**
 * Типы игровых материалов.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Game;

use IndexPilot\Text\Cleaner;
/** Определяет тип и ожидаемые разделы без WordPress. */
final class MaterialType {
	public const GUIDE     = 'guide';
	public const DECK      = 'deck';
	public const MULLIGAN  = 'mulligan';
	public const MATCHUPS  = 'matchups';
	public const META      = 'meta';
	public const TIER_LIST = 'tier_list';
	public const NEWS      = 'news';
	public const PATCH     = 'patch';
	public const OTHER     = 'other';
	private const LABELS   = array(
		'guide'     => 'Гайд по архетипу',
		'deck'      => 'Колода',
		'mulligan'  => 'Муллиган',
		'matchups'  => 'Матчапы',
		'meta'      => 'Мета-отчёт',
		'tier_list' => 'Тир-лист',
		'news'      => 'Новость',
		'patch'     => 'Патч',
		'other'     => 'Материал',
	);
	private const SECTIONS = array(
		'deck'         => 'Состав и код колоды',
		'mulligan'     => 'Муллиган',
		'strategy'     => 'Стратегия',
		'matchups'     => 'Матчапы',
		'replacements' => 'Замены',
		'list'         => 'Список колод или тиров',
		'changes'      => 'Что изменилось',
		'impact'       => 'Как это повлияет на мету',
	);
	private const WORDS    = array(
		'deck'         => 'колод|дек|код|состав|сборк|список карт',
		'mulligan'     => 'муллиган|мулиган|стартов',
		'strategy'     => 'стратеги|как играть|план игры|тактик',
		'matchups'     => 'матчап|против',
		'replacements' => 'замен|альтернатив|бюджет',
		'list'         => 'тир|список|лучшие колоды|топ',
		'changes'      => 'изменени|что нового|нерф|бафф',
		'impact'       => 'мет|влияни|что это значит',
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
	public static function detect( string $title, array $category_names, string $html, string $post_type = 'post' ): string {
		if ( in_array( $post_type, array( 'hs_deck', 'deck' ), true ) ) {
			return self::DECK; }
		$text = mb_strtolower( $title . ' ' . implode( ' ', $category_names ), 'UTF-8' );
		foreach ( array(
			self::TIER_LIST => 'тир[\s–—-]*лист',
			self::META      => 'мета[\s–—-]*отч[её]т|обзор меты',
			self::PATCH     => 'патч|обновлени|нерф|бафф',
			self::MULLIGAN  => 'муллиган|мулиган',
			self::MATCHUPS  => 'матчап',
		) as $type => $pattern ) {
			if ( preg_match( '/(?<!\p{L})(?:' . $pattern . ')/u', $text ) ) {
				return $type; }
		}
		if ( preg_match( '/(?<!\p{L})новости(?!\p{L})/iu', implode( ' ', $category_names ) ) ) {
			return self::NEWS; }
		if ( preg_match( '/(?<!\p{L})(?:гайд|руководств|как играть)/u', $text ) ) {
			return self::GUIDE; }
		$headings = self::headings( $html, '23' );
		return self::contains( $headings, 'mulligan' ) && ( self::contains( $headings, 'matchups' ) || self::contains( $headings, 'strategy' ) ) ? self::GUIDE : self::OTHER;
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
			self::GUIDE => array( 'deck', 'mulligan', 'strategy', 'matchups', 'replacements' ), self::DECK => array( 'deck', 'mulligan' ), self::META, self::TIER_LIST => array( 'list', 'changes' ), self::PATCH => array( 'changes', 'impact' ), default => array() };
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
			if ( self::contains( $headings, $key ) || ( self::DECK === $type && 'deck' === $key && preg_match( '/(?<![A-Za-z0-9+\/])AAE[A-Za-z0-9+\/]{37,}={0,2}(?![A-Za-z0-9+\/])/u', Cleaner::to_plain_text( $html ) ) ) ) {
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
