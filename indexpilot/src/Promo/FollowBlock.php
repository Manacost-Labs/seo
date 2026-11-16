<?php
/**
 * Блок «Мы в соцсетях» и ссылки «поделиться».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

/**
 * Зовёт читателя подписаться на соцсети сайта и поделиться статьёй. Всё — обычные ссылки, без скриптов соцсетей.
 * Ссылки на соцсети идут через адрес перехода: так переходы считаются и попадают в отчёт.
 *
 * Вывод: шорткод [indexpilot_follow] или автоматически после текста записи (настройка).
 */
final class FollowBlock {

	/**
	 * Конструктор.
	 *
	 * @param Destinations $destinations Площадки.
	 * @param ClickRouter  $router       Адреса перехода.
	 */
	public function __construct( private Destinations $destinations, private ClickRouter $router ) {
	}

	/**
	 * Регистрирует шорткод и, если включено, вывод после текста записи.
	 *
	 * @param bool $auto Добавлять ли блок после текста записи.
	 */
	public function register( bool $auto ): void {
		add_shortcode( 'indexpilot_follow', fn(): string => $this->render( (int) get_the_ID() ) );

		if ( $auto && ! is_admin() ) {
			// После блоков перехода (20), перед блоком «Об авторе» (30).
			add_filter( 'the_content', array( $this, 'append' ), 25 );
		}
	}

	/**
	 * HTML блока.
	 *
	 * @param list<array<string, mixed>> $destinations Площадки; в блок попадают только соцсети.
	 * @param callable( string ): string $click_url    Адрес перехода по коду площадки.
	 * @param string                     $share_url    Адрес статьи для ссылок «поделиться»; пусто — без них.
	 * @param string                     $share_title  Название статьи.
	 * @param array<string, string>      $labels       Подписи: title, share, telegram, vk.
	 */
	public static function html( array $destinations, callable $click_url, string $share_url, string $share_title, array $labels ): string {
		$escape = static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		$follow = array();
		$share  = array();

		foreach ( $destinations as $destination ) {
			if ( 'social' === ( $destination['kind'] ?? '' ) ) {
				$follow[] = sprintf( '<a href="%s" rel="nofollow">%s</a>', $escape( $click_url( (string) $destination['code'] ) ), $escape( (string) $destination['name'] ) );
			}
		}

		if ( '' !== $share_url ) {
			$links = array(
				'telegram' => 'https://t.me/share/url?url=' . rawurlencode( $share_url ) . '&text=' . rawurlencode( $share_title ),
				'vk'       => 'https://vk.com/share.php?url=' . rawurlencode( $share_url ),
			);

			foreach ( $links as $key => $url ) {
				$share[] = sprintf( '<a href="%s" target="_blank" rel="noopener nofollow">%s</a>', $escape( $url ), $escape( (string) ( $labels[ $key ] ?? $key ) ) );
			}
		}

		if ( array() === $follow && array() === $share ) {
			return '';
		}

		$line = static fn( string $class_name, string $label, array $links ): string => array() === $links
			? ''
			: sprintf( '<p class="%s" style="margin:.25em 0"><strong>%s:</strong> %s</p>', $class_name, $escape( $label ), implode( ' · ', $links ) );

		return '<aside class="indexpilot-follow" style="margin:1.5em 0">'
			. $line( 'indexpilot-follow-social', (string) ( $labels['title'] ?? '' ), $follow )
			. $line( 'indexpilot-follow-share', (string) ( $labels['share'] ?? '' ), $share )
			. '</aside>';
	}

	/**
	 * Блок для записи.
	 *
	 * @param int $post_id ID записи; 0 — блок без ссылок «поделиться», переходы считаются без записи.
	 */
	public function render( int $post_id ): string {
		$post      = $post_id > 0 ? get_post( $post_id ) : null;
		$published = $post instanceof \WP_Post && 'publish' === $post->post_status;
		$post_id   = $published ? (int) $post->ID : 0;

		return self::html(
			$this->destinations->all(),
			fn( string $code ): string => $this->router->url( $code, $post_id ),
			$published ? (string) get_permalink( $post ) : '',
			$published ? html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) : '',
			array(
				'title'    => __( 'Мы в соцсетях', 'indexpilot' ),
				'share'    => __( 'Поделиться', 'indexpilot' ),
				'telegram' => __( 'Telegram', 'indexpilot' ),
				'vk'       => __( 'ВКонтакте', 'indexpilot' ),
			)
		);
	}

	/**
	 * Добавляет блок после текста основной записи.
	 *
	 * @param string $content Текст записи.
	 * @return string
	 */
	public function append( $content ) {
		// Если блок уже вставлен шорткодом, второй раз не добавляем.
		if ( ! Blocks::is_main_post() || str_contains( (string) $content, 'indexpilot-follow' ) ) {
			return $content;
		}

		return $content . $this->render( (int) get_the_ID() );
	}
}
