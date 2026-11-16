<?php
/**
 * Адреса карты сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

/**
 * Отдаёт /sitemap.xml и /sitemap_index.xml (список файлов), файлы вида /sitemap-pt-post-1.xml и /sitemap-tax-category-1.xml,
 * карту авторов и карту новостей /sitemap-news.xml.
 *
 * Адреса разбираются напрямую, без правил перезаписи WordPress: их не нужно сбрасывать при установке плагина.
 */
final class Router {

	public const INDEX = 'sitemap.xml';

	/**
	 * Второй адрес списка файлов: его знают поисковики, которым раньше отдавали карту Yoast или Rank Math.
	 */
	public const INDEX_ALIAS = 'sitemap_index.xml';

	public const AUTHORS = 'sitemap-authors-1.xml';

	/**
	 * Карта новостей для Google Новостей: записи за последние 48 часов.
	 */
	public const NEWS = 'sitemap-news.xml';

	/**
	 * Записи карты новостей, собранные в этом запросе.
	 *
	 * @var list<array{loc: string, published: string, title: string, time: int}>|null
	 */
	private ?array $news = null;

	/**
	 * Текущее время.
	 *
	 * @var int
	 */
	private int $now = 0;

	/**
	 * Конструктор.
	 *
	 * @param PostTypeProvider $posts   Записи.
	 * @param TaxonomyProvider $terms   Архивы терминов.
	 * @param AuthorProvider   $authors Страницы авторов.
	 * @param Cache            $cache   Кэш готового XML.
	 */
	public function __construct(
		private PostTypeProvider $posts,
		private TaxonomyProvider $terms,
		private AuthorProvider $authors,
		private Cache $cache
	) {
	}

	/**
	 * Разбирает адрес запроса.
	 *
	 * @param string $request_uri Адрес запроса с путём и параметрами.
	 * @param string $home_path   Путь, по которому установлен сайт: «/» или «/blog/».
	 * @return array{kind: string, name: string, page: int}|null kind: index, pt (тип записи), tax (таксономия), authors или news.
	 */
	public static function parse( string $request_uri, string $home_path ): ?array {
		$path = (string) strtok( $request_uri, '?' );
		$home = '/' . trim( $home_path, '/' );
		$home = '/' === $home ? '/' : $home . '/';

		if ( ! str_starts_with( $path, $home ) ) {
			return null;
		}

		$file = substr( $path, strlen( $home ) );

		if ( self::INDEX === $file || self::INDEX_ALIAS === $file ) {
			return array(
				'kind' => 'index',
				'name' => '',
				'page' => 0,
			);
		}

		if ( self::AUTHORS === $file ) {
			return array(
				'kind' => 'authors',
				'name' => '',
				'page' => 1,
			);
		}

		if ( self::NEWS === $file ) {
			return array(
				'kind' => 'news',
				'name' => '',
				'page' => 1,
			);
		}

		if ( 1 !== preg_match( '/^sitemap-(pt|tax)-([a-z0-9_-]+)-(\d+)\.xml$/', $file, $matches ) || (int) $matches[3] < 1 ) {
			return null;
		}

		return array(
			'kind' => $matches[1],
			'name' => $matches[2],
			'page' => (int) $matches[3],
		);
	}

	/**
	 * Адрес файла карты.
	 *
	 * @param string $kind Вид: pt или tax.
	 * @param string $name Тип записи или таксономия.
	 * @param int    $page Номер страницы.
	 */
	public static function url( string $kind, string $name, int $page ): string {
		return home_url( '/sitemap-' . $kind . '-' . $name . '-' . $page . '.xml' );
	}

	/**
	 * Отдаёт карту, если запрошен её адрес. Вызывается на хуке parse_request.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- адрес только сравнивается с шаблоном имён файлов карты.
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request = self::parse( $uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		if ( null === $request ) {
			return;
		}

		$xml = $this->render( $request );

		if ( null === $xml ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		// Сам файл карты в выдаче не нужен.
		header( 'X-Robots-Tag: noindex, follow' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML собран классом Xml, все значения в нём экранированы.
		echo $xml;
		exit;
	}

	/**
	 * XML для разобранного запроса; null — такого файла нет или в нём нет ни одного адреса.
	 *
	 * @param array{kind: string, name: string, page: int} $request Разобранный запрос.
	 */
	public function render( array $request ): ?string {
		$name = $request['kind'] . ':' . $request['name'] . ':' . $request['page'];

		return $this->cache->remember(
			$name,
			function () use ( $request ): ?string {
				if ( 'index' === $request['kind'] ) {
					return Xml::index( $this->files() );
				}

				if ( 'news' === $request['kind'] ) {
					$news = $this->news();

					return array() === $news ? null : Xml::news( $news, wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), News::language( (string) get_bloginfo( 'language' ) ) );
				}

				$entries = $this->entries( $request );

				// Пустой файл поисковик считает ошибкой: вместо него отдаётся обычная страница 404.
				return array() === $entries ? null : Xml::urlset( $entries );
			},
			fn(): int => $this->ttl( $request['kind'] )
		);
	}

	/**
	 * Файлы карты в виде разобранных запросов — в том же порядке, что в списке файлов.
	 * Карты новостей здесь нет: её адреса уже входят в карту записей.
	 *
	 * @return list<array{kind: string, name: string, page: int}>
	 */
	public function file_requests(): array {
		$requests = array();

		foreach ( $this->listed() as $file ) {
			if ( 'news' !== $file['kind'] ) {
				$requests[] = array(
					'kind' => $file['kind'],
					'name' => $file['name'],
					'page' => $file['page'],
				);
			}
		}

		return $requests;
	}

	/**
	 * Адреса страниц одного файла карты.
	 *
	 * @param array{kind: string, name: string, page: int} $request Разобранный запрос файла.
	 * @return list<string>
	 */
	public function locs( array $request ): array {
		$entries = 'news' === $request['kind'] ? $this->news() : $this->entries( $request );

		return array_values( array_map( 'strval', array_column( $entries, 'loc' ) ) );
	}

	/**
	 * Файлы карты: адрес и время последнего изменения. Файлов без адресов в списке нет.
	 *
	 * @return list<array{loc: string, lastmod: string}>
	 */
	public function files(): array {
		$files = array();

		foreach ( $this->listed() as $file ) {
			if ( 'authors' === $file['kind'] ) {
				$loc = home_url( '/' . self::AUTHORS );
			} elseif ( 'news' === $file['kind'] ) {
				$loc = home_url( '/' . self::NEWS );
			} else {
				$loc = self::url( $file['kind'], $file['name'], $file['page'] );
			}

			$files[] = array(
				'loc'     => $loc,
				'lastmod' => $file['lastmod'],
			);
		}

		return $files;
	}

	/**
	 * Файлы карты, в которых есть хотя бы один адрес.
	 *
	 * @return list<array{kind: string, name: string, page: int, lastmod: string}>
	 */
	private function listed(): array {
		$candidates = array();

		foreach ( $this->posts->types() as $type ) {
			foreach ( $this->posts->pages( $type ) as $page ) {
				$candidates[] = array(
					'kind'    => 'pt',
					'name'    => $type,
					'page'    => $page['page'],
					'lastmod' => $page['lastmod'],
				);
			}
		}

		foreach ( $this->terms->types() as $taxonomy ) {
			foreach ( $this->terms->pages( $taxonomy ) as $page ) {
				$candidates[] = array(
					'kind'    => 'tax',
					'name'    => $taxonomy,
					'page'    => $page['page'],
					'lastmod' => $page['lastmod'],
				);
			}
		}

		$candidates[] = array(
			'kind'    => 'authors',
			'name'    => '',
			'page'    => 1,
			'lastmod' => $this->authors->lastmod(),
		);
		$candidates[] = array(
			'kind'    => 'news',
			'name'    => '',
			'page'    => 1,
			'lastmod' => (string) ( $this->news()[0]['published'] ?? '' ),
		);

		return FileList::build(
			$candidates,
			function ( array $file ): bool {
				return match ( $file['kind'] ) {
					'pt'      => $this->posts->has_entries( $file['name'], $file['page'] ),
					'tax'     => $this->terms->has_entries( $file['name'], $file['page'] ),
					'authors' => array() !== $this->authors->entries(),
					default   => array() !== $this->news(),
				};
			}
		);
	}

	/**
	 * Адреса файла записей, терминов или авторов; пустой список — такого файла нет или он пуст.
	 *
	 * @param array{kind: string, name: string, page: int} $request Разобранный запрос файла.
	 * @return list<array<string, mixed>>
	 */
	private function entries( array $request ): array {
		if ( 'authors' === $request['kind'] ) {
			return $this->authors->entries();
		}

		$entries = 'pt' === $request['kind']
			? $this->posts->entries( $request['name'], $request['page'] )
			: $this->terms->entries( $request['name'], $request['page'] );

		return (array) $entries;
	}

	/**
	 * Записи карты новостей; собираются один раз за запрос.
	 *
	 * @return list<array{loc: string, published: string, title: string, time: int}>
	 */
	private function news(): array {
		if ( null === $this->news ) {
			/**
			 * Типы записей, которые попадают в карту новостей.
			 *
			 * @param list<string> $types Типы записей.
			 */
			$types = array_map( 'strval', (array) apply_filters( 'indexpilot_news_post_types', array( 'post' ) ) );

			// Тип записей, выключенный из карты сайта в настройках, не попадает и в карту новостей.
			$this->news = $this->posts->news()->entries( array_values( array_intersect( $types, $this->posts->types() ) ), $this->now() );
		}

		return $this->news;
	}

	/**
	 * Срок хранения готового файла. Карта новостей живёт, пока самая старая её запись остаётся новостью,
	 * список файлов — пока в карте новостей есть хотя бы одна запись: так в списке не окажется пустого файла.
	 *
	 * @param string $kind Вид файла.
	 */
	private function ttl( string $kind ): int {
		if ( 'news' !== $kind && 'index' !== $kind ) {
			return Cache::TTL;
		}

		$times = array_column( $this->news(), 'time' );

		if ( array() === $times ) {
			return Cache::TTL;
		}

		return News::ttl( (int) ( 'news' === $kind ? min( $times ) : max( $times ) ), $this->now(), Cache::TTL );
	}

	/**
	 * Текущее время, одно на весь запрос.
	 */
	private function now(): int {
		if ( 0 === $this->now ) {
			$this->now = time();
		}

		return $this->now;
	}
}
