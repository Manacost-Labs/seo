<?php
/**
 * Модуль «Переходы»: площадки, блоки перехода, подписка и отчёт.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

use IndexPilot\Admin\PromoPage;
use IndexPilot\Admin\Reports\PromoReport;
use IndexPilot\Plugin;

/**
 * Собирает модуль и подключает его к WordPress: статьи ведут читателей на платные площадки и в соцсети,
 * а отчёт показывает, какая статья сколько переходов дала.
 */
final class Module {

	/**
	 * Площадки.
	 *
	 * @var Destinations|null
	 */
	private ?Destinations $destinations = null;

	/**
	 * Адреса перехода.
	 *
	 * @var ClickRouter|null
	 */
	private ?ClickRouter $router = null;

	/**
	 * Конструктор.
	 *
	 * @param Plugin $plugin Плагин: настройки читаются по мере надобности.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Подключает учёт переходов и вывод блоков.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'init' ), 100 );

		add_action(
			'parse_request',
			function (): void {
				$this->router()->handle();
			},
			1
		);

		// Метки площадок предлагаются готовыми наборами на странице коротких ссылок.
		add_filter( 'indexpilot_shortlink_utm_presets', array( $this, 'utm_presets' ) );
	}

	/**
	 * Создаёт таблицу при первом запуске и подключает вывод на сайте. Хуки подключаются после загрузки тем:
	 * к этому времени известны настройки.
	 */
	public function init(): void {
		if ( $this->store()->maybe_install() ) {
			// Опции создаются сразу и с автозагрузкой: чтение отсутствующей опции стоило бы запроса на каждой странице.
			// Список площадок начинается с площадок команды, правил блоков поначалу нет.
			add_option( Destinations::OPTION, Destinations::sanitize( Destinations::DEFAULTS ), '', true );
			add_option( Rule::OPTION, array(), '', true );
		}

		$this->follow()->register( (bool) $this->plugin->settings()->general( 'promo_follow_auto' ) );

		if ( ! is_admin() ) {
			$this->blocks()->register();
		}
	}

	/**
	 * Подключает страницы админки. Вызывается на хуке init в админке.
	 * Флажок «Не показывать блоки перехода» у записи печатает и сохраняет панель записи (вкладка «Дополнительно»).
	 */
	public function register_admin(): void {
		( new PromoReport( $this->store(), $this->destinations(), $this->rules() ) )->register();
		( new PromoPage( $this->destinations(), $this->rules(), $this->router() ) )->register();
	}

	/**
	 * Добавляет к наборам меток для коротких ссылок метки площадок.
	 *
	 * @param mixed $presets Наборы меток: label, source, medium, campaign.
	 * @return list<array<string, string>>
	 */
	public function utm_presets( $presets ): array {
		$own = array_filter(
			$this->destinations()->utm_presets(),
			static fn( array $preset ): bool => '' !== $preset['source'] . $preset['medium'] . $preset['campaign']
		);

		return array_merge( is_array( $presets ) ? array_values( $presets ) : array(), array_values( $own ) );
	}

	/**
	 * Площадки.
	 */
	public function destinations(): Destinations {
		if ( null === $this->destinations ) {
			$this->destinations = new Destinations();
		}

		return $this->destinations;
	}

	/**
	 * Правила блоков перехода.
	 */
	public function rules(): Rules {
		return new Rules( $this->destinations() );
	}

	/**
	 * Счётчик переходов.
	 */
	public function store(): WpClickStore {
		global $wpdb;

		return new WpClickStore( $wpdb );
	}

	/**
	 * Адреса перехода и перенаправление.
	 */
	public function router(): ClickRouter {
		if ( null === $this->router ) {
			/**
			 * Префикс адресов перехода: /to/КОД.
			 *
			 * @param string $prefix Префикс без косых черт.
			 */
			$prefix = sanitize_title( (string) apply_filters( 'indexpilot_promo_prefix', ClickRouter::PREFIX ) );

			$this->router = new ClickRouter( $this->store(), $this->destinations(), $this->rules(), '' !== $prefix ? $prefix : ClickRouter::PREFIX );
		}

		return $this->router;
	}

	/**
	 * Блоки перехода в тексте записи.
	 */
	public function blocks(): Blocks {
		return new Blocks( $this->destinations(), $this->rules(), $this->router() );
	}

	/**
	 * Блок «Мы в соцсетях».
	 */
	public function follow(): FollowBlock {
		return new FollowBlock( $this->destinations(), $this->router() );
	}
}
