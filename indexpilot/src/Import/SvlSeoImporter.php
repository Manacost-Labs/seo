<?php
/**
 * Импорт из самодельного SEO-модуля kolodahearthstone.com.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

use IndexPilot\Meta\MetaKeys;

/**
 * Модуль svl-seo.php плагина KolodaHearthstone Locker хранит поля в мете _svl_seo_*.
 */
final class SvlSeoImporter extends MetaSource {

	/**
	 * Формат заголовка модуля по умолчанию.
	 */
	public const DEFAULT_FORMAT = '%title% %sep% %sitename%';

	/**
	 * Идентификатор источника.
	 */
	public function id(): string {
		return 'svl_seo';
	}

	/**
	 * Название источника.
	 */
	public function label(): string {
		return 'KolodaHearthstone Locker (svl-seo)';
	}

	/**
	 * Основной источник.
	 */
	public function is_fallback(): bool {
		return false;
	}

	/**
	 * Ключи меты модуля.
	 *
	 * @return list<string>
	 */
	protected function keys(): array {
		return array(
			'_svl_seo_title',
			'_svl_seo_desc',
			'_svl_seo_focus',
			'_svl_seo_keywords',
			'_svl_seo_robots',
			'_svl_seo_canonical',
			'_svl_seo_og_image',
		);
	}

	/**
	 * Мета модуля → поля IndexPilot.
	 *
	 * @param array<string, mixed> $row Значения меты _svl_seo_*.
	 * @return array<string, mixed>
	 */
	public function map( array $row ): array {
		$focus  = trim( (string) ( $row['_svl_seo_focus'] ?? '' ) );
		$robots = self::robots_flags( $row['_svl_seo_robots'] ?? '' );

		return self::without_empty(
			array(
				MetaKeys::TITLE         => self::title( trim( (string) ( $row['_svl_seo_title'] ?? '' ) ), $this->format() ),
				MetaKeys::DESCRIPTION   => trim( (string) ( $row['_svl_seo_desc'] ?? '' ) ),
				MetaKeys::FOCUS_KEYWORD => $focus,
				MetaKeys::KEYWORDS      => Importer::keywords( explode( ',', (string) ( $row['_svl_seo_keywords'] ?? '' ) ), $focus ),
				MetaKeys::CANONICAL     => trim( (string) ( $row['_svl_seo_canonical'] ?? '' ) ),
				Importer::OG_IMAGE_URL  => trim( (string) ( $row['_svl_seo_og_image'] ?? '' ) ),
				MetaKeys::NOINDEX       => $robots['noindex'] ? '1' : '',
				MetaKeys::NOFOLLOW      => $robots['nofollow'] ? '1' : '',
			)
		);
	}

	/**
	 * Формат заголовка модуля: свой заголовок записи модуль выводил внутри него («%title% — Название сайта»).
	 */
	private function format(): string {
		$format = function_exists( 'get_option' ) ? (string) get_option( 'svl_seo_title_format', '' ) : '';

		return '' !== trim( $format ) ? $format : self::DEFAULT_FORMAT;
	}

	/**
	 * Свой заголовок записи в формате модуля — так, как его видели посетители. Переменные модуля
	 * (%sep%, %sitename%, %tagline%) совпадают с переменными IndexPilot и остаются переменными.
	 *
	 * @param string $custom Заголовок, заданный в записи; пусто — заголовка нет.
	 * @param string $format Формат модуля.
	 */
	public static function title( string $custom, string $format ): string {
		if ( '' === $custom ) {
			return '';
		}

		return str_contains( $format, '%title%' ) ? trim( str_replace( '%title%', $custom, $format ) ) : $custom;
	}
}
