<?php
/**
 * Записи в карте сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

use IndexPilot\Redirects\RedirectedPosts;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Schema\VideoData;
use IndexPilot\Settings\Settings;

/**
 * Опубликованные записи каждого типа, разбитые на страницы по диапазонам ID.
 * Закрытые от индексации, перенаправленные и записи с каноникалом на другую страницу в карту не попадают.
 */
final class PostTypeProvider {

	public const PAGE_SIZE = 1000;

	/**
	 * Опубликованные записи по типам: ID и время изменения, по возрастанию ID.
	 *
	 * @var array<string, list<array{id: int, modified: string}>>
	 */
	private array $rows = array();

	/**
	 * Конструктор.
	 *
	 * @param \wpdb           $db         База WordPress.
	 * @param MetaResolver    $resolver   Резолвер: решает, индексируется ли страница.
	 * @param Settings        $settings   Настройки.
	 * @param RedirectedPosts $redirected Записи под редиректом.
	 */
	public function __construct(
		private \wpdb $db,
		private MetaResolver $resolver,
		private Settings $settings,
		private RedirectedPosts $redirected
	) {
	}

	/**
	 * Типы записей, которые входят в карту.
	 *
	 * @return list<string>
	 */
	public function types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$config = $this->settings->post_type( $type );

			if ( 'attachment' !== $type && is_post_type_viewable( $type ) && ! empty( $config['sitemap'] ) && empty( $config['noindex'] ) ) {
				$types[] = (string) $type;
			}
		}

		return $types;
	}

	/**
	 * Страницы карты для типа записи: номер и время последнего изменения записей страницы.
	 *
	 * @param string $type Тип записи.
	 * @return list<array{page: int, lastmod: string}>
	 */
	public function pages( string $type ): array {
		$rows  = $this->rows( $type );
		$pages = array();

		foreach ( array_chunk( $rows, self::PAGE_SIZE ) as $index => $chunk ) {
			$pages[] = array(
				'page'    => $index + 1,
				'lastmod' => Lastmod::w3c( max( array_column( $chunk, 'modified' ) ) ),
			);
		}

		// Главная страница с лентой записей не является записью: она идёт первой строкой карты страниц.
		if ( array() === $pages && $this->has_home_entry( $type ) ) {
			$pages[] = array(
				'page'    => 1,
				'lastmod' => '',
			);
		}

		return $pages;
	}

	/**
	 * Записи страницы карты; null — такой страницы нет.
	 *
	 * @param string $type Тип записи.
	 * @param int    $page Номер страницы, начиная с 1.
	 * @return list<array{loc: string, lastmod: string, images: list<string>}>|null
	 */
	public function entries( string $type, int $page ): ?array {
		$posts   = $this->open_posts( $type, $page, 0 );
		$entries = array();

		if ( 1 === $page && $this->has_home_entry( $type ) && in_array( $type, $this->types(), true ) ) {
			$entries[] = array(
				'loc'     => home_url( '/' ),
				'lastmod' => '',
				'images'  => array(),
			);
		}

		if ( null === $posts ) {
			return array() === $entries ? null : $entries;
		}

		$base = untrailingslashit( home_url() );

		foreach ( $posts as $url => $post ) {
			$images    = ImageExtractor::from_html( $post->post_content, $base );
			$thumbnail = (string) get_the_post_thumbnail_url( $post, 'full' );

			if ( '' !== $thumbnail && ! in_array( $thumbnail, $images, true ) ) {
				array_unshift( $images, $thumbnail );
			}

			$entries[] = array(
				'loc'     => (string) $url,
				'lastmod' => Lastmod::w3c( $post->post_modified_gmt ),
				'images'  => $images,
				// Ролики собраны при сохранении записи; мета уже загружена вместе с пачкой записей.
				'videos'  => self::videos( get_post_meta( (int) $post->ID, VideoData::META, true ) ),
			);
		}

		return $entries;
	}

	/**
	 * Ролики записи для карты: только с полными данными (превью, название, описание, плеер).
	 *
	 * @param mixed $stored Мета записи VideoData::META.
	 * @return list<array{thumbnail_loc: string, title: string, description: string, player_loc: string}>
	 */
	public static function videos( $stored ): array {
		$videos = array();

		foreach ( VideoData::stored( $stored ) as $video ) {
			if ( VideoData::is_complete( $video ) && '' !== trim( $video['description'] ) ) {
				$videos[] = array(
					'thumbnail_loc' => $video['thumbnail'],
					'title'         => $video['title'],
					'description'   => $video['description'],
					'player_loc'    => $video['embed_url'],
				);
			}
		}

		return $videos;
	}

	/**
	 * Есть ли на странице карты хотя бы один адрес. Проверка останавливается на первой открытой записи.
	 *
	 * @param string $type Тип записи.
	 * @param int    $page Номер страницы, начиная с 1.
	 */
	public function has_entries( string $type, int $page ): bool {
		if ( 1 === $page && $this->has_home_entry( $type ) && in_array( $type, $this->types(), true ) ) {
			return true;
		}

		return array() !== (array) $this->open_posts( $type, $page, 1 );
	}

	/**
	 * Карта новостей: свежие записи этого же сайта.
	 */
	public function news(): NewsProvider {
		return new NewsProvider( $this->db, $this->resolver, $this->redirected );
	}

	/**
	 * Открытые для индексации записи страницы карты; null — такой страницы нет.
	 *
	 * @param string $type  Тип записи.
	 * @param int    $page  Номер страницы, начиная с 1.
	 * @param int    $limit Сколько записей достаточно найти; 0 — все.
	 * @return array<string, \WP_Post>|null Адрес записи => запись.
	 */
	private function open_posts( string $type, int $page, int $limit ): ?array {
		if ( ! in_array( $type, $this->types(), true ) ) {
			return null;
		}

		$range = Chunker::range( Chunker::boundaries( array_column( $this->rows( $type ), 'id' ), self::PAGE_SIZE ), $page );

		if ( null === $range ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- выборка по диапазону ID; готовый XML кэшируется целиком.
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT ID FROM {$this->db->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' AND ID > %d AND ID <= %d ORDER BY ID ASC",
				$type,
				$range['after'],
				$range['upto']
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		$redirected = $this->redirected->map();
		$posts      = array();

		// Для проверки «есть ли хоть одна запись» незачем загружать всю тысячу: хватает первых.
		foreach ( array_chunk( $ids, $limit > 0 ? 20 : self::PAGE_SIZE ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );

			foreach ( $chunk as $id ) {
				$post = get_post( $id );
				$url  = $post instanceof \WP_Post ? (string) get_permalink( $post ) : '';

				if ( '' === $url || ! EntryPolicy::allows( $url, $this->resolver->indexing( new Context( Context::POST, $id, $type ) ), isset( $redirected[ $id ] ) ) ) {
					continue;
				}

				$posts[ $url ] = $post;

				if ( $limit > 0 && count( $posts ) >= $limit ) {
					return $posts;
				}
			}
		}

		return $posts;
	}

	/**
	 * Нужна ли в карте этого типа строка главной страницы.
	 *
	 * @param string $type Тип записи.
	 */
	private function has_home_entry( string $type ): bool {
		return 'page' === $type && 'posts' === get_option( 'show_on_front' );
	}

	/**
	 * Опубликованные записи типа: ID и время изменения.
	 *
	 * @param string $type Тип записи.
	 * @return list<array{id: int, modified: string}>
	 */
	private function rows( string $type ): array {
		if ( ! isset( $this->rows[ $type ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- два столбца по индексу; готовый XML кэшируется целиком.
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT ID, post_modified_gmt FROM {$this->db->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC",
					$type
				),
				ARRAY_A
			);

			$this->rows[ $type ] = array();
			foreach ( (array) $rows as $row ) {
				$this->rows[ $type ][] = array(
					'id'       => (int) $row['ID'],
					'modified' => (string) $row['post_modified_gmt'],
				);
			}
		}

		return $this->rows[ $type ];
	}
}
