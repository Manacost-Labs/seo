<?php
/**
 * Сводка автора: сколько написал, когда обновлял, лучшие материалы, о чём пишет.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Authors;

use IndexPilot\Game\GameLexicon;

/**
 * Считает сводку автора по списку его опубликованных материалов. Сводка пересчитывается при сохранении записи
 * и хранится в мете пользователя: на странице автора она читается из уже загруженной меты, без запросов.
 * Не зависит от WordPress.
 */
final class AuthorSummary {

	public const META = '_indexpilot_author_summary';

	/**
	 * Сколько материалов о классе нужно, чтобы класс попал в knowsAbout.
	 */
	private const MIN_POSTS_PER_TOPIC = 2;

	/**
	 * Сводка.
	 *
	 * @param list<array{title: string, url: string, modified: string, weight: int}> $posts   Материалы автора.
	 * @param GameLexicon                                                            $lexicon Словарь игры: классы по названию.
	 * @param string                                                                 $game    Название игры — первая тема автора.
	 * @param int                                                                    $best    Сколько лучших материалов показать.
	 * @return array{count: int, updated: string, best: list<array{title: string, url: string}>, knows_about: list<string>}
	 */
	public static function build( array $posts, GameLexicon $lexicon, string $game, int $best = 5 ): array {
		$updated = '';
		$topics  = array();

		foreach ( $posts as $post ) {
			$updated = max( $updated, (string) $post['modified'] );

			foreach ( array_unique( $lexicon->classes( (string) $post['title'] ) ) as $slug ) {
				$topics[ $slug ] = ( $topics[ $slug ] ?? 0 ) + 1;
			}
		}

		arsort( $topics );

		$knows = '' !== $game ? array( $game ) : array();

		foreach ( $topics as $slug => $count ) {
			$name = $lexicon->class_name( (string) $slug );

			if ( $count >= self::MIN_POSTS_PER_TOPIC && '' !== $name ) {
				$knows[] = $name;
			}
		}

		usort(
			$posts,
			static fn( array $a, array $b ): int => array( (int) $b['weight'], (string) $b['modified'] ) <=> array( (int) $a['weight'], (string) $a['modified'] )
		);

		return array(
			'count'       => count( $posts ),
			'updated'     => $updated,
			'best'        => array_map(
				static fn( array $post ): array => array(
					'title' => (string) $post['title'],
					'url'   => (string) $post['url'],
				),
				array_slice( $posts, 0, max( 0, $best ) )
			),
			'knows_about' => $knows,
		);
	}

	/**
	 * Незаполненные части профиля автора: description, same_as, image, role.
	 *
	 * @param array<string, mixed> $profile Профиль (AuthorProfile::get).
	 * @return list<string>
	 */
	public static function missing( array $profile ): array {
		$missing = array();

		if ( '' === trim( (string) ( $profile['description'] ?? '' ) ) ) {
			$missing[] = 'description';
		}

		if ( array() === (array) ( $profile['same_as'] ?? array() ) ) {
			$missing[] = 'same_as';
		}

		if ( empty( $profile['image'] ) ) {
			$missing[] = 'image';
		}

		if ( '' === trim( (string) ( $profile['role'] ?? '' ) ) ) {
			$missing[] = 'role';
		}

		return $missing;
	}
}
