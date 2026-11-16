<?php
/**
 * Страницы стримеров и игроков.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Admin\HelpTip;
use IndexPilot\Resolver\Context;
use IndexPilot\Schema\Graph;
use IndexPilot\Schema\IdFactory;

/**
 * Таксономия deck_streamer на hs-manacost.ru: у игроков нет учётной записи на сайте, их страница — архив колод.
 * Страница получает разметку ProfilePage с узлом Person (тот же @id, что у игрока в разметке колоды), список
 * актуальных колод и поля термина «Ссылка на Twitch» и «О игроке». Заголовок и описание — по шаблону таксономии.
 */
final class StreamerPage {

	public const TAXONOMY = 'deck_streamer';
	public const TWITCH   = '_indexpilot_twitch';
	public const ABOUT    = '_indexpilot_player_about';

	/**
	 * Сколько актуальных колод показывать.
	 */
	private const DECKS = 10;

	/**
	 * Адрес канала Twitch по ссылке или имени канала; пусто — не Twitch.
	 *
	 * @param string $input Ссылка или имя канала.
	 */
	public static function twitch_url( string $input ): string {
		$input = trim( $input );

		if ( 1 === preg_match( '/^[A-Za-z0-9_]{3,25}$/', $input ) ) {
			return 'https://www.twitch.tv/' . strtolower( $input );
		}

		if ( 1 === preg_match( '#^(?:https?://)?(?:www\.|m\.)?twitch\.tv/([A-Za-z0-9_]{3,25})/?$#i', $input, $match ) ) {
			return 'https://www.twitch.tv/' . strtolower( $match[1] );
		}

		return '';
	}

	/**
	 * Узел игрока.
	 *
	 * @param string $home   Адрес главной.
	 * @param string $name   Имя игрока (название термина).
	 * @param string $twitch Канал Twitch.
	 * @param string $about  Об игроке.
	 * @param string $url    Адрес страницы игрока на сайте.
	 * @return array<string, mixed>
	 */
	public static function person( string $home, string $name, string $twitch, string $about, string $url ): array {
		return array(
			'@type'       => 'Person',
			'@id'         => IdFactory::player( $home, $name ),
			'name'        => trim( $name ),
			'url'         => $url,
			'description' => trim( $about ),
			'sameAs'      => '' !== $twitch ? array( $twitch ) : array(),
		);
	}

	/**
	 * Подключает поля термина в админке. Вызывается на init.
	 */
	public function register(): void {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'fields' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save' ) );
	}

	/**
	 * Подключает вывод на странице сайта. Вызывается на template_redirect.
	 */
	public function register_front(): void {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		// Блок над списком записей на первой странице архива (ArchiveText): о игроке, канал, актуальные колоды.
		add_filter( 'indexpilot_archive_top', array( $this, 'description' ), 10, 2 );

		add_action( 'indexpilot_schema_graph', array( $this, 'schema' ), 20, 2 );
	}

	/**
	 * Поля в форме термина.
	 *
	 * @param \WP_Term $term Термин.
	 */
	public function fields( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		wp_nonce_field( 'indexpilot_streamer', 'indexpilot_streamer_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label for="indexpilot-twitch"><?php esc_html_e( 'Ссылка на Twitch', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'Адрес канала, например https://www.twitch.tv/pizzahs, или просто имя канала.', 'indexpilot' ), __( 'Ссылка попадает в разметку игрока (sameAs): поисковик связывает страницу с его каналом.', 'indexpilot' ) ); ?></th>
			<td><input type="text" id="indexpilot-twitch" name="indexpilot_twitch" class="regular-text" value="<?php echo esc_attr( (string) get_term_meta( $term->term_id, self::TWITCH, true ) ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="indexpilot-player-about"><?php esc_html_e( 'О игроке', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'Два-три предложения: кто это, чем известен, в каком режиме играет.', 'indexpilot' ), __( 'Текст выводится над списком колод и идёт в разметку игрока — страница перестаёт быть пустым списком.', 'indexpilot' ) ); ?></th>
			<td><textarea id="indexpilot-player-about" name="indexpilot_player_about" rows="4" class="large-text"><?php echo esc_textarea( (string) get_term_meta( $term->term_id, self::ABOUT, true ) ); ?></textarea></td>
		</tr>
		<?php
	}

	/**
	 * Сохраняет поля термина.
	 *
	 * @param int $term_id ID термина.
	 */
	public function save( $term_id ): void {
		if ( ! isset( $_POST['indexpilot_streamer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['indexpilot_streamer_nonce'] ) ), 'indexpilot_streamer' ) || ! current_user_can( 'edit_term', (int) $term_id ) ) {
			return;
		}

		$twitch = isset( $_POST['indexpilot_twitch'] ) ? self::twitch_url( sanitize_text_field( wp_unslash( $_POST['indexpilot_twitch'] ) ) ) : '';
		$about  = isset( $_POST['indexpilot_player_about'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['indexpilot_player_about'] ) ), 0, 1000 ) : '';

		foreach ( array(
			self::TWITCH => $twitch,
			self::ABOUT  => $about,
		) as $key => $value ) {
			if ( '' === $value ) {
				delete_term_meta( (int) $term_id, $key );
			} else {
				update_term_meta( (int) $term_id, $key, $value );
			}
		}
	}

	/**
	 * Описание страницы игрока: «О игроке», ссылка на канал и актуальные колоды. Один запрос к базе.
	 *
	 * @param string   $description Текст над списком записей.
	 * @param \WP_Term $term        Открытый термин.
	 */
	public function description( $description, $term = null ): string {
		$description = (string) $description;

		if ( ! $term instanceof \WP_Term || self::TAXONOMY !== $term->taxonomy || str_contains( $description, 'indexpilot-streamer' ) ) {
			return $description;
		}

		$about  = (string) get_term_meta( $term->term_id, self::ABOUT, true );
		$twitch = (string) get_term_meta( $term->term_id, self::TWITCH, true );
		$html   = '<div class="indexpilot-streamer">';

		if ( '' !== $about ) {
			$html .= wpautop( esc_html( $about ) );
		}

		if ( '' !== $twitch ) {
			$html .= '<p><a href="' . esc_url( $twitch ) . '" rel="me noopener">' . esc_html__( 'Канал на Twitch', 'indexpilot' ) . '</a></p>';
		}

		$decks = $this->current_decks( (int) $term->term_id );

		if ( array() !== $decks ) {
			$html .= '<p><strong>' . esc_html__( 'Актуальные колоды', 'indexpilot' ) . '</strong></p><ul>';

			foreach ( $decks as $deck ) {
				$html .= '<li><a href="' . esc_url( (string) get_permalink( $deck ) ) . '">' . esc_html( wp_strip_all_tags( get_the_title( $deck ) ) ) . '</a></li>';
			}

			$html .= '</ul>';
		}

		return $description . $html . '</div>';
	}

	/**
	 * Актуальные (не архивные) колоды игрока, новые сверху.
	 *
	 * @param int $term_id ID термина.
	 * @return list<\WP_Post>
	 */
	private function current_decks( int $term_id ): array {
		static $cache = array();

		if ( ! isset( $cache[ $term_id ] ) ) {
			$cache[ $term_id ] = get_posts(
				array(
					'post_type'              => 'hs_deck',
					'post_status'            => 'publish',
					'posts_per_page'         => self::DECKS,
					'orderby'                => 'modified',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- одна выборка на странице игрока.
					'tax_query'              => array(
						array(
							'taxonomy' => self::TAXONOMY,
							'terms'    => $term_id,
						),
					),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- архивные колоды не показываются.
					'meta_query'             => array(
						'relation' => 'OR',
						array(
							'key'     => '_hs_deck_archived',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_hs_deck_archived',
							'value'   => '1',
							'compare' => '!=',
						),
					),
				)
			);
		}

		return $cache[ $term_id ];
	}

	/**
	 * Разметка: на странице игрока — ProfilePage с узлом Person; в разметке колоды узел игрока получает
	 * адрес его страницы и ссылку на канал.
	 *
	 * @param mixed $graph   Граф страницы.
	 * @param mixed $context Контекст страницы.
	 */
	public function schema( $graph, $context ): void {
		if ( ! $graph instanceof Graph || ! $context instanceof Context ) {
			return;
		}

		$home = home_url( '/' );

		if ( Context::TERM === $context->type && self::TAXONOMY === $context->subtype ) {
			$term = get_term( $context->object_id, self::TAXONOMY );
			$page = $graph->first( 'CollectionPage' );

			if ( ! $term instanceof \WP_Term || null === $page ) {
				return;
			}

			$person = self::person( $home, $term->name, (string) get_term_meta( $term->term_id, self::TWITCH, true ), (string) get_term_meta( $term->term_id, self::ABOUT, true ), (string) get_term_link( $term ) );

			$graph->add( $person );
			$page['@type']      = 'ProfilePage';
			$page['mainEntity'] = array( '@id' => $person['@id'] );
			$graph->add( $page );

			return;
		}

		if ( Context::POST !== $context->type || 'hs_deck' !== $context->subtype ) {
			return;
		}

		$terms = get_the_terms( $context->object_id, self::TAXONOMY );

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$node = $graph->node( IdFactory::player( $home, $term->name ) );

			if ( null !== $node ) {
				$twitch = (string) get_term_meta( $term->term_id, self::TWITCH, true );
				$graph->add( $node + self::person( $home, $term->name, $twitch, '', (string) get_term_link( $term ) ) );
			}
		}
	}
}
