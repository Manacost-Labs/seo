<?php
/**
 * Страница «Площадки и блоки перехода».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Promo\ClickRouter;
use IndexPilot\Promo\Destinations;
use IndexPilot\Promo\Placement;
use IndexPilot\Promo\Rules;

/**
 * Список площадок (Boosty, свои сайты, соцсети) и правила блоков перехода в статьях.
 * Формы — обычные запросы с nonce: страница работает без JavaScript.
 */
final class PromoPage {

	public const SLUG = 'indexpilot-promo';

	private const NONCE    = 'indexpilot_promo';
	private const CAP      = 'manage_options';
	private const VARIANTS = 3;

	/**
	 * Площадки, которые предлагаются заранее: название, код, вид.
	 */
	private const SUGGESTED = array(
		array( 'Boosty', 'boosty', 'paid' ),
		array( 'Hearthpulse', 'hearthpulse', 'paid' ),
		array( 'kolodahearthstone.com', 'koloda', 'own' ),
		array( 'Telegram', 'telegram', 'social' ),
		array( 'ВКонтакте', 'vk', 'social' ),
		array( 'Twitch', 'twitch', 'social' ),
		array( 'YouTube', 'youtube', 'social' ),
		array( 'Discord', 'discord', 'social' ),
	);

	/**
	 * Сообщение о результате последнего действия.
	 *
	 * @var string
	 */
	private string $notice = '';

	/**
	 * Конструктор.
	 *
	 * @param Destinations $destinations Площадки.
	 * @param Rules        $rules        Правила блоков.
	 * @param ClickRouter  $router       Адреса перехода.
	 */
	public function __construct( private Destinations $destinations, private Rules $rules, private ClickRouter $router ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 18 );
	}

	/**
	 * Добавляет пункт меню и обрабатывает формы страницы до её вывода.
	 */
	public function add(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — площадки и блоки перехода', 'indexpilot' ),
			__( 'Площадки и блоки', 'indexpilot' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'handle' ) );
		}
	}

	/**
	 * Сохраняет площадки или правила по запросу формы.
	 */
	public function handle(): void {
		if ( ! isset( $_POST['indexpilot_promo_action'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'indexpilot' ), 403 );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- массивы форм разбираются по полям, каждое поле очищается при разборе.
		if ( 'destinations' === $_POST['indexpilot_promo_action'] ) {
			$rows = $this->posted_destinations( isset( $_POST['destinations'] ) ? (array) wp_unslash( $_POST['destinations'] ) : array() );

			update_option( Destinations::OPTION, Destinations::sanitize( $rows ), true );

			/* translators: %d: число площадок. */
			$this->notice = sprintf( __( 'Сохранено площадок: %d. Строки без адреса не сохраняются.', 'indexpilot' ), count( $this->destinations->all() ) );

			return;
		}

		$this->rules->save( $this->posted_rules( isset( $_POST['rules'] ) ? (array) wp_unslash( $_POST['rules'] ) : array() ) );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		/* translators: %d: число правил. */
		$this->notice = sprintf( __( 'Сохранено правил: %d. Правило без площадки или без текста не сохраняется.', 'indexpilot' ), count( $this->rules->all() ) );
	}

	/**
	 * Площадки из формы.
	 *
	 * @param array<int|string, mixed> $posted Строки формы.
	 * @return list<array<string, mixed>>
	 */
	private function posted_destinations( array $posted ): array {
		$rows = array();

		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$url  = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ), array( 'http', 'https' ) );
			$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			$utm  = is_array( $row['utm'] ?? null ) ? $row['utm'] : array();

			$rows[] = array(
				'name' => '' !== $name ? $name : (string) wp_parse_url( $url, PHP_URL_HOST ),
				'url'  => $url,
				'kind' => sanitize_key( (string) ( $row['kind'] ?? '' ) ),
				'code' => sanitize_text_field( (string) ( $row['code'] ?? '' ) ),
				'utm'  => array(
					'source'   => sanitize_text_field( (string) ( $utm['source'] ?? '' ) ),
					'medium'   => sanitize_text_field( (string) ( $utm['medium'] ?? '' ) ),
					'campaign' => sanitize_text_field( (string) ( $utm['campaign'] ?? '' ) ),
				),
			);
		}

		return $rows;
	}

	/**
	 * Правила из формы, в порядке, заданном полем «Порядок».
	 *
	 * @param array<int|string, mixed> $posted Правила формы.
	 * @return list<array<string, mixed>>
	 */
	private function posted_rules( array $posted ): array {
		$rules = array();

		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}

			$terms = array();

			foreach ( (array) ( $row['terms'] ?? array() ) as $taxonomy => $ids ) {
				$taxonomy = sanitize_key( (string) $taxonomy );

				if ( taxonomy_exists( $taxonomy ) && is_string( $ids ) ) {
					$terms[ $taxonomy ] = wp_parse_id_list( $ids );
				}
			}

			$variants = array();

			foreach ( (array) ( $row['variants'] ?? array() ) as $variant ) {
				if ( is_array( $variant ) ) {
					$variants[] = array(
						'text'   => sanitize_text_field( (string) ( $variant['text'] ?? '' ) ),
						'button' => sanitize_text_field( (string) ( $variant['button'] ?? '' ) ),
					);
				}
			}

			$rules[] = array(
				'order'       => (int) ( $row['order'] ?? 0 ),
				'id'          => absint( $row['id'] ?? 0 ),
				'enabled'     => ! empty( $row['enabled'] ),
				'destination' => sanitize_key( (string) ( $row['destination'] ?? '' ) ),
				'place'       => sanitize_key( (string) ( $row['place'] ?? '' ) ),
				'post_types'  => array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $row['post_types'] ?? array() ) ) ), 'post_type_exists' ) ),
				'terms'       => $terms,
				'game'        => sanitize_key( (string) ( $row['game'] ?? '' ) ),
				'material'    => sanitize_key( (string) ( $row['material'] ?? '' ) ),
				'variants'    => $variants,
			);
		}

		// Порядок важен: записи достаются первые подходящие правила.
		usort( $rules, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );

		return $rules;
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$destinations = $this->destinations->all();
		?>
		<div class="wrap" data-indexpilot-promo>
			<h1><?php esc_html_e( 'IndexPilot — площадки и блоки перехода', 'indexpilot' ); ?></h1>

			<?php if ( '' !== $this->notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $this->notice ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Площадки', 'indexpilot' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Куда статьи ведут читателей: платные площадки, свои сайты и соцсети. Заполните адрес — строка без адреса не сохраняется; чтобы убрать площадку, сотрите её адрес (правила этой площадки перестанут действовать). Метки перехода добавляются к адресу площадки: пустой источник — адрес этого сайта, пустой канал — article (для блока подписки — follow), пустая кампания — код площадки.', 'indexpilot' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="indexpilot_promo_action" value="destinations">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Название', 'indexpilot' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Адрес', 'indexpilot' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Вид', 'indexpilot' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Код и адрес перехода', 'indexpilot' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Метки: источник, канал, кампания', 'indexpilot' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $this->destination_rows( $destinations ) as $index => $row ) {
							$this->render_destination( (int) $index, $row );
						}
						?>
					</tbody>
				</table>
				<?php submit_button( __( 'Сохранить площадки', 'indexpilot' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Блоки перехода', 'indexpilot' ); ?></h2>
			<p class="description">
				<?php
				/* translators: %d: наибольшее число блоков в одной статье. */
				echo esc_html( sprintf( __( 'Правило решает, в каких статьях показать блок с текстом и кнопкой на площадку. В одной статье — не больше %d блоков: берутся первые подходящие правила по порядку. Если вариантов текста несколько, они показываются по очереди, и у каждого свой счётчик переходов в отчёте. У отдельной записи блоки выключаются флажком в блоке «Опубликовать».', 'indexpilot' ), Placement::MAX_BLOCKS ) );
				?>
			</p>
			<?php if ( array() === $destinations ) : ?>
				<p><?php esc_html_e( 'Сначала сохраните хотя бы одну площадку.', 'indexpilot' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="indexpilot_promo_action" value="rules">
					<?php
					$rules = $this->rules->all();

					foreach ( $rules as $index => $rule ) {
						$this->render_rule( (int) $index, $rule, $destinations );
					}

					$this->render_rule( count( $rules ), null, $destinations );

					submit_button( __( 'Сохранить правила', 'indexpilot' ) );
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Строки формы площадок: сохранённые, затем предложенные, которых ещё нет, затем две пустые.
	 *
	 * @param list<array<string, mixed>> $saved Сохранённые площадки.
	 * @return list<array<string, mixed>>
	 */
	private function destination_rows( array $saved ): array {
		$rows  = array();
		$codes = array();
		$names = array();

		foreach ( $saved as $row ) {
			$rows[]  = $row + array( 'saved' => true );
			$codes[] = (string) $row['code'];
			$names[] = mb_strtolower( (string) $row['name'] );
		}

		foreach ( self::SUGGESTED as $suggested ) {
			if ( ! in_array( $suggested[1], $codes, true ) && ! in_array( mb_strtolower( $suggested[0] ), $names, true ) ) {
				$rows[] = array(
					'name' => $suggested[0],
					'code' => $suggested[1],
					'kind' => $suggested[2],
				);
			}
		}

		$rows[] = array();
		$rows[] = array();

		return $rows;
	}

	/**
	 * Строка формы площадок.
	 *
	 * @param int                  $index Номер строки.
	 * @param array<string, mixed> $row   Площадка; у новой строки заполнены только предложенные поля.
	 */
	private function render_destination( int $index, array $row ): void {
		$field = 'destinations[' . $index . ']';
		$saved = ! empty( $row['saved'] );
		$code  = (string) ( $row['code'] ?? '' );
		$kinds = array(
			'paid'   => __( 'Платная', 'indexpilot' ),
			'social' => __( 'Соцсеть', 'indexpilot' ),
			'own'    => __( 'Свой сайт', 'indexpilot' ),
		);
		$marks = array(
			'source'   => 'utm_source',
			'medium'   => 'utm_medium',
			'campaign' => 'utm_campaign',
		);
		?>
		<tr>
			<td><input type="text" name="<?php echo esc_attr( $field . '[name]' ); ?>" value="<?php echo esc_attr( (string) ( $row['name'] ?? '' ) ); ?>" aria-label="<?php esc_attr_e( 'Название', 'indexpilot' ); ?>"></td>
			<td><input type="url" class="regular-text" name="<?php echo esc_attr( $field . '[url]' ); ?>" value="<?php echo esc_attr( (string) ( $row['url'] ?? '' ) ); ?>" placeholder="https://" aria-label="<?php esc_attr_e( 'Адрес', 'indexpilot' ); ?>"></td>
			<td>
				<select name="<?php echo esc_attr( $field . '[kind]' ); ?>" aria-label="<?php esc_attr_e( 'Вид', 'indexpilot' ); ?>">
					<?php foreach ( $kinds as $kind => $label ) : ?>
						<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( (string) ( $row['kind'] ?? 'own' ), $kind ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<?php if ( $saved ) : ?>
					<?php // Код сохранённой площадки не меняется: на нём держатся правила и счётчик переходов. ?>
					<input type="hidden" name="<?php echo esc_attr( $field . '[code]' ); ?>" value="<?php echo esc_attr( $code ); ?>">
					<code><?php echo esc_html( $this->router->url( $code ) ); ?></code>
				<?php else : ?>
					<input type="text" class="code" size="14" name="<?php echo esc_attr( $field . '[code]' ); ?>" value="<?php echo esc_attr( $code ); ?>" pattern="[A-Za-z0-9\-]*" aria-label="<?php esc_attr_e( 'Короткий код: латинские буквы, цифры, дефис', 'indexpilot' ); ?>">
				<?php endif; ?>
			</td>
			<td>
				<?php foreach ( $marks as $key => $placeholder ) : ?>
					<input type="text" size="12" name="<?php echo esc_attr( $field . '[utm][' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) ( $row['utm'][ $key ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" aria-label="<?php echo esc_attr( $placeholder ); ?>">
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Форма одного правила.
	 *
	 * @param int                        $index        Номер правила в форме.
	 * @param array<string, mixed>|null  $rule         Правило; null — пустая форма нового правила.
	 * @param list<array<string, mixed>> $destinations Площадки.
	 */
	private function render_rule( int $index, ?array $rule, array $destinations ): void {
		$field    = 'rules[' . $index . ']';
		$variants = (array) ( $rule['variants'] ?? array() );
		$fields   = max( self::VARIANTS, count( $variants ) );
		$places   = array(
			'after_first' => __( 'После первого раздела', 'indexpilot' ),
			'middle'      => __( 'В середине', 'indexpilot' ),
			'end'         => __( 'В конце', 'indexpilot' ),
		);
		?>
		<fieldset style="margin:1em 0;padding:.5em 1em;border:1px solid #c3c4c7;background:#fff" data-indexpilot-promo-rule="<?php echo esc_attr( (string) ( $rule['id'] ?? 0 ) ); ?>">
			<legend>
				<strong>
					<?php
					/* translators: %d: номер правила. */
					echo esc_html( null === $rule ? __( 'Новое правило', 'indexpilot' ) : sprintf( __( 'Правило №%d', 'indexpilot' ), (int) $rule['id'] ) );
					?>
				</strong>
			</legend>
			<input type="hidden" name="<?php echo esc_attr( $field . '[id]' ); ?>" value="<?php echo esc_attr( (string) ( $rule['id'] ?? 0 ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $field . '[game]' ); ?>" value="<?php echo esc_attr( (string) ( $rule['game'] ?? '' ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $field . '[material]' ); ?>" value="<?php echo esc_attr( (string) ( $rule['material'] ?? '' ) ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Показ', 'indexpilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $field . '[enabled]' ); ?>" value="1" <?php checked( null === $rule || ! empty( $rule['enabled'] ) ); ?>> <?php esc_html_e( 'Включено', 'indexpilot' ); ?></label>
						&nbsp; <label><?php esc_html_e( 'Порядок', 'indexpilot' ); ?> <input type="number" class="small-text" name="<?php echo esc_attr( $field . '[order]' ); ?>" value="<?php echo esc_attr( (string) ( $index + 1 ) ); ?>"></label>
						<?php if ( null !== $rule ) : ?>
							&nbsp; <label><input type="checkbox" name="<?php echo esc_attr( $field . '[delete]' ); ?>" value="1"> <?php esc_html_e( 'Удалить правило', 'indexpilot' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Площадка и место', 'indexpilot' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $field . '[destination]' ); ?>" aria-label="<?php esc_attr_e( 'Площадка', 'indexpilot' ); ?>">
							<option value=""><?php esc_html_e( '— площадка —', 'indexpilot' ); ?></option>
							<?php foreach ( $destinations as $destination ) : ?>
								<option value="<?php echo esc_attr( (string) $destination['code'] ); ?>" <?php selected( (string) ( $rule['destination'] ?? '' ), (string) $destination['code'] ); ?>><?php echo esc_html( (string) $destination['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="<?php echo esc_attr( $field . '[place]' ); ?>" aria-label="<?php esc_attr_e( 'Место в статье', 'indexpilot' ); ?>">
							<?php foreach ( $places as $place => $label ) : ?>
								<option value="<?php echo esc_attr( $place ); ?>" <?php selected( (string) ( $rule['place'] ?? 'end' ), $place ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Типы записей', 'indexpilot' ); ?></th>
					<td>
						<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
							<?php
							if ( 'attachment' === $type->name ) {
								continue;
							}
							?>
							<label style="margin-right:1em"><input type="checkbox" name="<?php echo esc_attr( $field . '[post_types][]' ); ?>" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) ( $rule['post_types'] ?? array() ), true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Ничего не отмечено — все типы.', 'indexpilot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Термины', 'indexpilot' ); ?></th>
					<td>
						<?php foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) : ?>
							<?php
							if ( 'post_format' === $taxonomy->name ) {
								continue;
							}
							?>
							<label style="display:inline-block;margin:0 1em .4em 0"><?php echo esc_html( $taxonomy->labels->name ); ?> <input type="text" size="14" name="<?php echo esc_attr( $field . '[terms][' . $taxonomy->name . ']' ); ?>" value="<?php echo esc_attr( implode( ', ', (array) ( $rule['terms'][ $taxonomy->name ] ?? array() ) ) ); ?>" placeholder="12, 34"></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'ID терминов через запятую: рубрики, классы, архетипы. Блок показывается, если у записи есть хотя бы один из них. Пусто — термины не важны. ID виден в адресе страницы редактирования термина (tag_ID).', 'indexpilot' ); ?></p>
					</td>
				</tr>
				<?php for ( $number = 0; $number < $fields; $number++ ) : ?>
					<tr>
						<th scope="row">
							<?php
							/* translators: %d: номер варианта текста. */
							echo esc_html( sprintf( __( 'Вариант %d', 'indexpilot' ), $number + 1 ) );
							?>
						</th>
						<td>
							<input type="text" class="large-text" maxlength="300" name="<?php echo esc_attr( $field . '[variants][' . $number . '][text]' ); ?>" value="<?php echo esc_attr( (string) ( $variants[ $number ]['text'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Текст блока', 'indexpilot' ); ?>" aria-label="<?php esc_attr_e( 'Текст блока', 'indexpilot' ); ?>">
							<input type="text" class="regular-text" maxlength="60" name="<?php echo esc_attr( $field . '[variants][' . $number . '][button]' ); ?>" value="<?php echo esc_attr( (string) ( $variants[ $number ]['button'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Подпись кнопки; пусто — название площадки', 'indexpilot' ); ?>" aria-label="<?php esc_attr_e( 'Подпись кнопки', 'indexpilot' ); ?>">
						</td>
					</tr>
				<?php endfor; ?>
			</table>
		</fieldset>
		<?php
	}
}
