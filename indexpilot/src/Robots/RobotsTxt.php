<?php
/**
 * Дополнения к robots.txt.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Robots;

/**
 * Добавляет адрес карты сайта и, если включено в настройках, запрет для роботов, которые собирают
 * тексты для обучения нейросетей. Поисковые роботы и роботы AI-поиска (они приводят читателей) не затрагиваются.
 *
 * Работает с robots.txt, который формирует WordPress. Если в корне сайта лежит настоящий файл robots.txt,
 * сервер отдаёт его, и эти дополнения в него не попадают.
 */
final class RobotsTxt {

	/**
	 * Параметры адреса, которые не меняют содержимое страницы: метки рекламы и переходов.
	 */
	public const CLEAN_PARAM = 'utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&ysclid&gclid&fbclid';

	/**
	 * Роботы, которые собирают тексты для обучения моделей, а не для поиска.
	 */
	public const TRAINING_BOTS = array(
		'GPTBot',
		'ClaudeBot',
		'anthropic-ai',
		'Applebot-Extended',
		'CCBot',
		'Bytespider',
		'meta-externalagent',
		'cohere-training-data-crawler',
		'Diffbot',
	);

	/**
	 * Роботы, которые ищут страницы для ответа ассистента и приводят читателей. Они открыты всегда:
	 * в запрет не попадают, даже если их добавил в список сторонний фильтр.
	 */
	public const SEARCH_BOTS = array(
		'OAI-SearchBot',
		'ChatGPT-User',
		'PerplexityBot',
		'Perplexity-User',
		'Claude-SearchBot',
		'Claude-User',
	);

	/**
	 * Признак Google: управляет и обучением Gemini, и использованием страниц в его ответах.
	 * Поэтому запрещается отдельной настройкой, а не вместе с роботами обучения.
	 */
	public const GOOGLE_EXTENDED = 'Google-Extended';

	/**
	 * Собирает текст robots.txt.
	 *
	 * @param string       $core                  Текст, сформированный WordPress.
	 * @param string       $sitemap_url           Адрес карты сайта; пусто — строку не добавлять.
	 * @param bool         $block_training        Запретить роботов, собирающих тексты для обучения.
	 * @param list<string> $training_bots         Список таких роботов.
	 * @param string       $clean_param           Параметры адреса, которые Яндекс должен не учитывать (через «&»); пусто — не добавлять.
	 * @param bool         $block_google_extended Запретить Google-Extended: страницы не попадут ни в обучение, ни в ответы Gemini.
	 */
	public static function build( string $core, string $sitemap_url, bool $block_training, array $training_bots = self::TRAINING_BOTS, string $clean_param = '', bool $block_google_extended = false ): string {
		$text = $core;

		$open          = array_map( 'strtolower', array_merge( self::SEARCH_BOTS, array( self::GOOGLE_EXTENDED ) ) );
		$training_bots = $block_training
			? array_values( array_filter( $training_bots, static fn( string $bot ): bool => ! in_array( strtolower( $bot ), $open, true ) ) )
			: array();

		if ( $block_google_extended ) {
			$training_bots[] = self::GOOGLE_EXTENDED;
		}

		// Clean-param — директива Яндекса, действующая из любого места файла. Отдельный раздел «User-agent: Yandex»
		// не создаётся намеренно: он отменил бы для Яндекса общие запреты из раздела «User-agent: *».
		if ( '' !== $clean_param && ! str_contains( $text, 'Clean-param: ' . $clean_param ) ) {
			$text = rtrim( $text, "\n" ) . "\nClean-param: " . $clean_param . "\n";
		}

		if ( array() !== $training_bots ) {
			$text = rtrim( $text, "\n" ) . "\n\n";

			foreach ( $training_bots as $bot ) {
				$text .= 'User-agent: ' . $bot . "\n";
			}

			$text .= "Disallow: /\n";
		}

		if ( '' !== $sitemap_url && ! str_contains( $text, 'Sitemap: ' . $sitemap_url ) ) {
			$text = rtrim( $text, "\n" ) . "\n\nSitemap: " . $sitemap_url . "\n";
		}

		return $text;
	}
}
