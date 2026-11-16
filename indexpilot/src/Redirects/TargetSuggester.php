<?php
/**
 * Подсказка цели редиректа для адреса из журнала 404.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Сравнивает последнюю часть адреса с ярлыками записей и рубрик и возвращает самую похожую.
 */
final class TargetSuggester {

	/**
	 * Минимальное сходство в процентах.
	 */
	private const THRESHOLD = 70.0;

	/**
	 * Части адреса, которые ничего не говорят о содержимом.
	 */
	private const SKIP = array( 'page', 'amp', 'feed', 'embed', 'comment-page', 'category', 'tag' );

	/**
	 * Самый похожий кандидат.
	 *
	 * @param string                $path       Адрес из журнала.
	 * @param array<string, string> $candidates Ключ кандидата (например, «post:12») → его ярлык.
	 * @return string Ключ кандидата или пустая строка, если похожих нет.
	 */
	public static function suggest( string $path, array $candidates ): string {
		$slug = self::slug( $path );

		if ( strlen( $slug ) < 3 ) {
			return '';
		}

		$best  = '';
		$score = self::THRESHOLD;

		foreach ( $candidates as $ref => $candidate ) {
			$candidate = mb_strtolower( rawurldecode( (string) $candidate ) );

			if ( '' === $candidate ) {
				continue;
			}

			similar_text( $slug, $candidate, $percent );

			// Обрезанный адрес (скопировали не до конца) — начало настоящего ярлыка.
			if ( strlen( $slug ) >= 5 && str_starts_with( $candidate, $slug ) ) {
				$percent = max( $percent, 90.0 );
			}

			if ( $percent > $score || ( '' === $best && $percent >= $score ) ) {
				$best  = (string) $ref;
				$score = $percent;
			}
		}

		return $best;
	}

	/**
	 * Значимая последняя часть адреса: без чисел, служебных слов и расширения файла.
	 *
	 * @param string $path Адрес.
	 */
	private static function slug( string $path ): string {
		$path     = explode( '?', $path, 2 )[0];
		$segments = array_filter(
			explode( '/', mb_strtolower( rawurldecode( $path ) ) ),
			static fn( string $part ): bool => '' !== $part && ! ctype_digit( $part ) && ! in_array( $part, self::SKIP, true )
		);

		$last = (string) end( $segments );

		return (string) preg_replace( '/\.(html?|php)$/', '', $last );
	}
}
