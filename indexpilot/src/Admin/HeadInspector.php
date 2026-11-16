<?php
/**
 * Инструмент «Проверить страницу».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

/**
 * Загружает страницу своего сайта так, как её видит посетитель, и показывает все SEO-теги из head:
 * чей это тег (IndexPilot, WordPress, другой SEO-плагин, тема), какие теги повторяются и какие вывел не IndexPilot.
 * Разбор HTML — чистые функции без WordPress.
 */
final class HeadInspector {

	public const SLUG = 'indexpilot-inspect';

	/**
	 * Источник по умолчанию: тег вне известных блоков.
	 */
	public const OTHER = 'тема или другой плагин';

	private const CAPABILITY = 'edit_others_posts';

	private const NONCE = 'indexpilot_inspect';

	/**
	 * Источники, которые считаются своими: тег из блока плагина и теги, которые WordPress печатает по фильтрам плагина.
	 */
	private const OWN = array( 'IndexPilot', 'WordPress (wp_robots)', 'WordPress (title-tag)' );

	/**
	 * Метки в HTML-комментариях, по которым узнаётся плагин: часть текста => название.
	 */
	private const MARKERS = array(
		'indexpilot'         => 'IndexPilot',
		'yoast seo'          => 'Yoast SEO',
		'all in one seo'     => 'All in One SEO',
		'rank math'          => 'Rank Math',
		'seopress'           => 'SEOPress',
		'the seo framework'  => 'The SEO Framework',
		'squirrly'           => 'Squirrly SEO',
		'svl seo'            => 'KolodaHearthstone Locker (svl-seo)',
		'jetpack open graph' => 'Jetpack',
	);

	/**
	 * Классы блоков разметки: часть класса => название.
	 */
	private const SCHEMA_CLASSES = array(
		'indexpilot-schema' => 'IndexPilot',
		'yoast-schema'      => 'Yoast SEO',
		'aioseo-schema'     => 'All in One SEO',
		'rank-math-schema'  => 'Rank Math',
	);

	/**
	 * Теги, которые на странице должны быть в одном экземпляре.
	 */
	private const SINGLE = array( 'title', 'description', 'robots', 'keywords', 'canonical', 'prev', 'next', 'author', 'og:title', 'og:description', 'og:url', 'og:type', 'og:site_name', 'og:locale', 'og:updated_time', 'twitter:card', 'twitter:title', 'twitter:description', 'article:published_time', 'article:modified_time', 'article:section', 'ld+json' );

	/**
	 * Имена meta name, которые относятся к SEO.
	 */
	private const META_NAMES = array( 'description', 'robots', 'googlebot', 'yandex', 'bingbot', 'keywords', 'news_keywords', 'author', 'google-site-verification', 'yandex-verification', 'msvalidate.01' );

	/**
	 * Разбирает head страницы.
	 *
	 * @param string $html HTML страницы.
	 * @return array{tags: list<array{key: string, value: string, source: string}>, duplicates: array<string, list<string>>, foreign: list<array{key: string, value: string, source: string}>}
	 */
	public static function inspect( string $html ): array {
		$head = 1 === preg_match( '#<head\b[^>]*>(.*?)</head>#is', $html, $match ) ? $match[1] : $html;
		$tags = array();

		preg_match_all( '#<!--(.*?)-->|<title\b[^>]*>(.*?)</title>|<script\b([^>]*)>(.*?)</script>|<(meta|link)\b([^>]*)>#is', $head, $found, PREG_SET_ORDER );

		$source = '';

		foreach ( $found as $item ) {
			if ( '' !== $item[1] ) {
				$source = self::after_comment( $item[1], $source );
				continue;
			}

			$tag = self::tag( $item );

			if ( null === $tag ) {
				continue;
			}

			$tag['source'] = '' !== $source ? $source : self::default_source( $tag['key'], $item[0], $item[3] ?? '' );
			$tags[]        = $tag;
		}

		$sources = array();

		foreach ( $tags as $tag ) {
			$sources[ $tag['key'] ][] = $tag['source'];
		}

		$duplicates = array();

		foreach ( $sources as $key => $list ) {
			if ( count( $list ) > 1 && in_array( (string) $key, self::SINGLE, true ) ) {
				$duplicates[ (string) $key ] = $list;
			}
		}

		return array(
			'tags'       => $tags,
			'duplicates' => $duplicates,
			'foreign'    => array_values( array_filter( $tags, static fn( array $tag ): bool => ! in_array( $tag['source'], self::OWN, true ) ) ),
		);
	}

	/**
	 * Источник после HTML-комментария: комментарий с именем плагина открывает его блок, повтор того же имени закрывает.
	 *
	 * @param string $comment Текст комментария.
	 * @param string $current Текущий источник.
	 */
	private static function after_comment( string $comment, string $current ): string {
		$text = strtolower( $comment );

		foreach ( self::MARKERS as $marker => $name ) {
			if ( str_contains( $text, $marker ) ) {
				return $name === $current ? '' : $name;
			}
		}

		return $current;
	}

	/**
	 * Источник тега вне блоков: класс разметки, штатный вывод WordPress или тема.
	 *
	 * @param string $key        Ключ тега.
	 * @param string $raw        Исходный HTML тега.
	 * @param string $attributes Атрибуты тега script.
	 */
	private static function default_source( string $key, string $raw, string $attributes ): string {
		if ( 'ld+json' === $key ) {
			$class = strtolower( self::attributes( $attributes )['class'] ?? '' );

			foreach ( self::SCHEMA_CLASSES as $part => $name ) {
				if ( str_contains( $class, $part ) ) {
					return $name;
				}
			}
		}

		// Штатный вывод WordPress: wp_robots печатает атрибуты в одинарных кавычках.
		if ( 'robots' === $key && str_contains( $raw, "name='robots'" ) ) {
			return 'WordPress (wp_robots)';
		}

		if ( 'title' === $key ) {
			return 'WordPress (title-tag)';
		}

		return self::OTHER;
	}

	/**
	 * Ключ и значение SEO-тега или null, если тег к SEO не относится.
	 *
	 * @param array<int, string> $item Совпадение регулярного выражения.
	 * @return array{key: string, value: string}|null
	 */
	private static function tag( array $item ): ?array {
		if ( isset( $item[2] ) && '' !== $item[2] && 0 === stripos( $item[0], '<title' ) ) {
			return array(
				'key'   => 'title',
				'value' => self::text( $item[2] ),
			);
		}

		if ( 0 === stripos( $item[0], '<script' ) ) {
			$attributes = self::attributes( $item[3] ?? '' );

			if ( 'application/ld+json' !== strtolower( $attributes['type'] ?? '' ) ) {
				return null;
			}

			return array(
				'key'   => 'ld+json',
				'value' => implode( ', ', self::schema_types( (string) ( $item[4] ?? '' ) ) ),
			);
		}

		$attributes = self::attributes( $item[6] ?? '' );

		if ( 'link' === strtolower( $item[5] ?? '' ) ) {
			$rel = strtolower( $attributes['rel'] ?? '' );

			if ( in_array( $rel, array( 'canonical', 'prev', 'next' ), true ) ) {
				return array(
					'key'   => $rel,
					'value' => $attributes['href'] ?? '',
				);
			}

			if ( 'alternate' === $rel && isset( $attributes['hreflang'] ) ) {
				return array(
					'key'   => 'hreflang:' . strtolower( $attributes['hreflang'] ),
					'value' => $attributes['href'] ?? '',
				);
			}

			return null;
		}

		$name     = strtolower( $attributes['name'] ?? '' );
		$property = strtolower( $attributes['property'] ?? '' );
		$key      = '' !== $property ? $property : $name;

		if ( '' === $key ) {
			return null;
		}

		$seo = in_array( $key, self::META_NAMES, true ) || str_starts_with( $key, 'og:' ) || str_starts_with( $key, 'article:' ) || str_starts_with( $key, 'twitter:' );

		return $seo
			? array(
				'key'   => $key,
				'value' => self::text( $attributes['content'] ?? '' ),
			)
			: null;
	}

	/**
	 * Атрибуты тега.
	 *
	 * @param string $source Текст атрибутов.
	 * @return array<string, string>
	 */
	private static function attributes( string $source ): array {
		$result = array();

		preg_match_all( '/([a-zA-Z_:.-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $source, $found, PREG_SET_ORDER );

		foreach ( $found as $item ) {
			$result[ strtolower( $item[1] ) ] = html_entity_decode( $item[2] . ( $item[3] ?? '' ) . ( $item[4] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return $result;
	}

	/**
	 * Типы узлов в блоке разметки.
	 *
	 * @param string $json Содержимое блока.
	 * @return list<string>
	 */
	private static function schema_types( string $json ): array {
		$data  = json_decode( trim( $json ), true );
		$nodes = is_array( $data ) ? ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( array_is_list( $data ) ? $data : array( $data ) ) ) : array();
		$types = array();

		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) ) {
				$types[] = is_array( $node['@type'] ) ? implode( '/', array_map( 'strval', $node['@type'] ) ) : (string) $node['@type'];
			}
		}

		return array() === $types ? array( '?' ) : $types;
	}

	/**
	 * Текст без лишних пробелов и сущностей.
	 *
	 * @param string $value Текст.
	 */
	private static function text( string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Подключает экран.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 14 );
	}

	/**
	 * Пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — проверить страницу', 'indexpilot' ),
			__( 'Проверить страницу', 'indexpilot' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Адрес для проверки: только страницы своего сайта. Путь «/…» дополняется адресом сайта.
	 *
	 * @param string $input Введённый адрес.
	 * @param string $home  Адрес главной с косой чертой на конце.
	 */
	public static function own_url( string $input, string $home ): string {
		$input = trim( $input );

		if ( str_starts_with( $input, '/' ) && ! str_starts_with( $input, '//' ) ) {
			$input = rtrim( $home, '/' ) . $input;
		}

		return '' !== $home && str_starts_with( $input, $home ) && 1 !== preg_match( '/[\s<>"]/', $input ) ? $input : '';
	}

	/**
	 * Печатает экран.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$input = '';
		$url   = '';

		if ( isset( $_GET['url'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) {
			$input = esc_url_raw( wp_unslash( $_GET['url'] ) );
			$url   = self::own_url( $input, home_url( '/' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Проверить страницу', 'indexpilot' ); ?></h1>
			<p><?php esc_html_e( 'Страница загружается так, как её видит посетитель без входа на сайт. Показываются все SEO-теги из head, их источник и повторы.', 'indexpilot' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php wp_nonce_field( self::NONCE, '_wpnonce', false ); ?>
				<label for="indexpilot-inspect-url"><?php esc_html_e( 'Адрес страницы', 'indexpilot' ); ?></label>
				<?php HelpTip::render( __( 'Полный адрес страницы этого сайта или путь, например /deck/zhrec/.', 'indexpilot' ), __( 'Тема или другой плагин могут вывести второй description, canonical или блок разметки. Поисковик тогда выбирает сам — и не всегда наш вариант.', 'indexpilot' ) ); ?>
				<input type="text" class="regular-text" id="indexpilot-inspect-url" name="url" value="<?php echo esc_attr( '' !== $input ? $input : home_url( '/' ) ); ?>">
				<?php submit_button( __( 'Проверить', 'indexpilot' ), 'primary', '', false ); ?>
			</form>
			<?php
			if ( '' !== $input && '' === $url ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Проверяются только страницы этого сайта.', 'indexpilot' ) . '</p></div>';
			} elseif ( '' !== $url ) {
				$this->report( $url );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Видно ли основное содержимое без JavaScript (7.4). Для колоды названия карт берутся из фильтра
	 * indexpilot_deck_card_names — их источник на боевых сайтах свой.
	 *
	 * @param string $url  Адрес страницы.
	 * @param string $html HTML страницы.
	 */
	private function rendered( string $url, string $html ): void {
		$post_id = (int) url_to_postid( $url );

		/**
		 * Названия карт колоды: каждое должно быть в HTML страницы текстом.
		 *
		 * @param list<string> $cards   Названия карт.
		 * @param int          $post_id ID записи или 0.
		 */
		$cards  = $post_id > 0 ? array_values( array_map( 'strval', (array) apply_filters( 'indexpilot_deck_card_names', array(), $post_id ) ) ) : array();
		$result = \IndexPilot\Audit\RenderedPage::analyze( $html, $cards );

		if ( $result['hidden'] ) {
			echo '<div class="notice notice-error inline" data-indexpilot-no-js="hidden"><p><strong>' . esc_html__( 'Основное содержимое не видно без JavaScript.', 'indexpilot' ) . '</strong> ';
			/* translators: %d: число слов. */
			echo esc_html( sprintf( __( 'В основной части страницы без скриптов %d слов. Поисковик и ассистент, не выполняющие скрипты, видят почти пустую страницу: состав колоды нужно выводить текстом на сервере.', 'indexpilot' ), $result['words'] ) );

			if ( array() !== $result['missing'] ) {
				echo ' ' . esc_html( __( 'Нет в тексте:', 'indexpilot' ) . ' ' . implode( ', ', $result['missing'] ) );
			}

			echo '</p></div>';

			return;
		}

		/* translators: %d: число слов. */
		echo '<p data-indexpilot-no-js="visible">' . esc_html( sprintf( __( 'Без JavaScript видно %d слов основного текста.', 'indexpilot' ), $result['words'] ) ) . '</p>';
	}

	/**
	 * Загружает страницу и печатает отчёт.
	 *
	 * @param string $url Адрес своей страницы.
	 */
	private function report( string $url ): void {
		// Адрес проверен: это страница своего сайта. Перенаправления не выполняются — о них сообщается.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $response->get_error_message() ) . '</p></div>';
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 300 && $code < 400 ) {
			/* translators: 1: код ответа, 2: адрес перенаправления. */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Страница отвечает перенаправлением %1$d на %2$s. Проверьте конечный адрес.', 'indexpilot' ), $code, (string) wp_remote_retrieve_header( $response, 'location' ) ) ) . '</p></div>';
			return;
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$report = self::inspect( $body );

		/* translators: %d: код ответа. */
		echo '<p data-indexpilot-inspect-status>' . esc_html( sprintf( __( 'Ответ сервера: %d.', 'indexpilot' ), $code ) ) . '</p>';

		$this->rendered( $url, $body );

		if ( array() === $report['duplicates'] ) {
			echo '<div class="notice notice-success inline"><p data-indexpilot-inspect-clean>' . esc_html__( 'Повторов нет.', 'indexpilot' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Повторы:', 'indexpilot' ) . '</strong></p><ul>';

			foreach ( $report['duplicates'] as $key => $sources ) {
				printf( '<li data-indexpilot-duplicate="%1$s"><code>%1$s</code> — %2$s</li>', esc_attr( $key ), esc_html( implode( ', ', $sources ) ) );
			}

			echo '</ul></div>';
		}

		if ( array() !== $report['foreign'] ) {
			/* translators: %d: число тегов. */
			echo '<p><strong>' . esc_html( sprintf( __( 'Тегов не от IndexPilot: %d. Их выводит тема или другой плагин — уберите их там, чтобы не было двух версий.', 'indexpilot' ), count( $report['foreign'] ) ) ) . '</strong></p>';
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Тег', 'indexpilot' ) . '</th><th>' . esc_html__( 'Значение', 'indexpilot' ) . '</th><th>' . esc_html__( 'Источник', 'indexpilot' ) . '</th></tr></thead><tbody>';

		foreach ( $report['tags'] as $tag ) {
			$own = in_array( $tag['source'], self::OWN, true );

			printf(
				'<tr%4$s><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( $tag['key'] ),
				esc_html( mb_substr( $tag['value'], 0, 300 ) ),
				esc_html( $tag['source'] ),
				$own ? '' : ' style="background:#fcf0f1"'
			);
		}

		echo '</tbody></table>';
	}
}
