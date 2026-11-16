<?php
/**
 * XML карты сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

/**
 * Собирает XML по протоколу sitemaps.org. Все значения экранируются.
 */
final class Xml {

	private const HEADER = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

	/**
	 * Список страниц.
	 *
	 * Ролики (расширение Google video:) печатаются после картинок; у каждого — превью, название, описание и адрес плеера.
	 *
	 * @param list<array{loc: string, lastmod?: string, images?: list<string>, videos?: list<array{thumbnail_loc: string, title: string, description: string, player_loc: string}>}> $entries Страницы.
	 */
	public static function urlset( array $entries ): string {
		$xml = self::HEADER . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		foreach ( $entries as $entry ) {
			$xml .= "\t<url>\n\t\t<loc>" . self::escape( (string) $entry['loc'] ) . "</loc>\n";

			if ( '' !== (string) ( $entry['lastmod'] ?? '' ) ) {
				$xml .= "\t\t<lastmod>" . self::escape( (string) $entry['lastmod'] ) . "</lastmod>\n";
			}

			foreach ( (array) ( $entry['images'] ?? array() ) as $image ) {
				$xml .= "\t\t<image:image><image:loc>" . self::escape( (string) $image ) . "</image:loc></image:image>\n";
			}

			foreach ( (array) ( $entry['videos'] ?? array() ) as $video ) {
				$xml .= "\t\t<video:video>";

				foreach ( array( 'thumbnail_loc', 'title', 'description', 'player_loc' ) as $field ) {
					$xml .= '<video:' . $field . '>' . self::escape( (string) ( $video[ $field ] ?? '' ) ) . '</video:' . $field . '>';
				}

				$xml .= "</video:video>\n";
			}

			$xml .= "\t</url>\n";
		}

		return $xml . "</urlset>\n";
	}

	/**
	 * Карта новостей для Google Новостей.
	 *
	 * @param list<array{loc: string, published: string, title: string}> $entries     Новости: адрес, время публикации в формате W3C и название.
	 * @param string                                                     $publication Название издания — название сайта.
	 * @param string                                                     $language    Код языка издания по ISO 639.
	 */
	public static function news( array $entries, string $publication, string $language ): string {
		$xml  = self::HEADER . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";
		$name = "\t\t\t<news:publication>\n\t\t\t\t<news:name>" . self::escape( $publication ) . "</news:name>\n\t\t\t\t<news:language>" . self::escape( $language ) . "</news:language>\n\t\t\t</news:publication>\n";

		foreach ( $entries as $entry ) {
			$xml .= "\t<url>\n\t\t<loc>" . self::escape( (string) $entry['loc'] ) . "</loc>\n\t\t<news:news>\n" . $name
				. "\t\t\t<news:publication_date>" . self::escape( (string) $entry['published'] ) . "</news:publication_date>\n"
				. "\t\t\t<news:title>" . self::escape( (string) $entry['title'] ) . "</news:title>\n\t\t</news:news>\n\t</url>\n";
		}

		return $xml . "</urlset>\n";
	}

	/**
	 * Список файлов карты.
	 *
	 * @param list<array{loc: string, lastmod?: string}> $sitemaps Файлы карты.
	 */
	public static function index( array $sitemaps ): string {
		$xml = self::HEADER . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $sitemaps as $sitemap ) {
			$xml .= "\t<sitemap>\n\t\t<loc>" . self::escape( (string) $sitemap['loc'] ) . "</loc>\n";

			if ( '' !== (string) ( $sitemap['lastmod'] ?? '' ) ) {
				$xml .= "\t\t<lastmod>" . self::escape( (string) $sitemap['lastmod'] ) . "</lastmod>\n";
			}

			$xml .= "\t</sitemap>\n";
		}

		return $xml . "</sitemapindex>\n";
	}

	/**
	 * Экранирует значение для XML.
	 *
	 * @param string $value Значение.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
