<?php
/**
 * Настройки раздела World of Warcraft на странице настроек.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Game\Patch;
use IndexPilot\Settings\Settings;

/**
 * Часть страницы настроек «Игры: World of Warcraft»: корневая рубрика раздела, патч, название в разметке
 * и шаблоны рубрик раздела. Поля пишутся в общие настройки (раздел general).
 */
final class GameSettings {

	/**
	 * Печатает заголовок и таблицу полей.
	 *
	 * @param Settings $settings Настройки.
	 */
	public static function render( Settings $settings ): void {
		echo '<h2>' . esc_html__( 'Игры: World of Warcraft', 'indexpilot' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Всё, что лежит в выбранной рубрике и вложенных в неё, плагин считает материалами о World of Warcraft: свой словарь классов, свои типы материалов, свой патч и своя разметка. Остальное — Hearthstone.', 'indexpilot' ) . '</p>';
		echo '<table class="form-table" role="presentation">';

		$field = self::field( 'wow_root_term' );

		echo '<tr><th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Рубрика раздела', 'indexpilot' ) . '</label> ';
		HelpTip::render(
			__( 'рубрику, в которой лежат все материалы о World of Warcraft (например, «World of Warcraft» с вложенными «Жрец», «Маг»). Пусто — раздела нет.', 'indexpilot' ),
			__( 'по ней плагин отличает материалы о WoW от материалов о Hearthstone: «гайд на жреца» в разделе WoW не спорит за ключ с гайдом на жреца из Hearthstone, а подсказки ссылок не ведут в чужую игру.', 'indexpilot' )
		);
		echo '</th><td>';
		wp_dropdown_categories(
			array(
				'name'              => $field,
				'id'                => $field,
				'selected'          => (int) $settings->general( 'wow_root_term' ),
				'hide_empty'        => 0,
				'hierarchical'      => 1,
				'show_option_none'  => __( '— раздела нет —', 'indexpilot' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'После смены рубрики игра всех записей пересчитывается в фоне за минуту.', 'indexpilot' ) . '</p></td></tr>';

		self::text(
			$settings,
			'wow_patch_version',
			__( 'Номер патча WoW', 'indexpilot' ),
			'small-text',
			__( 'номер последнего крупного патча World of Warcraft, например 11.2.', 'indexpilot' ),
			__( 'гайды, тактики и маршруты WoW, не обновлённые после патча, получат отметку «устарело после патча», а свежие — подпись «Актуально для патча».', 'indexpilot' )
		);

		$field = self::field( 'wow_patch_date' );

		echo '<tr><th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Дата патча WoW', 'indexpilot' ) . '</label> ';
		HelpTip::render( __( 'день, когда патч вышел в игре.', 'indexpilot' ), __( 'от этой даты плагин отсчитывает, какие материалы о WoW пора проверить.', 'indexpilot' ) );
		echo '</th><td><input type="date" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $settings->general( 'wow_patch_date' ) ) . '"></td></tr>';

		self::text(
			$settings,
			'wow_schema_name',
			__( 'Название в разметке', 'indexpilot' ),
			'regular-text',
			__( 'как игра называется в разметке для поисковиков. Обычно — World of Warcraft.', 'indexpilot' ),
			__( 'поисковик читает из разметки, о какой игре страница, и не путает гайд на жреца WoW с гайдом на жреца Hearthstone.', 'indexpilot' )
		);
		self::text(
			$settings,
			'wow_term_title',
			__( 'Заголовок рубрик раздела', 'indexpilot' ),
			'large-text',
			/* translators: %game% и %parent_term% — переменные шаблона, переводить их не нужно. */
			__( 'шаблон заголовка для рубрик внутри раздела (классы, специализации). %game% — название игры, %parent_term% — рубрика выше («Жрец» у «Тьмы»).', 'indexpilot' ),
			__( 'в выдаче видно, что страница о World of Warcraft и о каком классе: «Жрец Тьма — гайды World of Warcraft».', 'indexpilot' )
		);
		self::text(
			$settings,
			'wow_term_description',
			__( 'Описание рубрик раздела', 'indexpilot' ),
			'large-text',
			__( 'шаблон описания для рубрик раздела без своего описания. Те же переменные.', 'indexpilot' ),
			__( 'у каждой страницы класса и специализации будет осмысленное описание в выдаче, даже если его не написали вручную.', 'indexpilot' )
		);

		echo '</table>';
	}

	/**
	 * Приводит дату патча WoW к виду ГГГГ-ММ-ДД; неверная дата не сохраняется.
	 *
	 * @param array<string, mixed> $input Данные формы.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		if ( isset( $input['general']['wow_patch_date'] ) ) {
			$input['general']['wow_patch_date'] = Patch::normalize_date( (string) $input['general']['wow_patch_date'] );
		}

		if ( isset( $input['general']['wow_root_term'] ) ) {
			$input['general']['wow_root_term'] = (string) max( 0, (int) $input['general']['wow_root_term'] );
		}

		return $input;
	}

	/**
	 * Строка с текстовым полем.
	 *
	 * @param Settings $settings Настройки.
	 * @param string   $key      Имя общей настройки.
	 * @param string   $label    Подпись.
	 * @param string   $css      Класс поля.
	 * @param string   $what     Что вписать.
	 * @param string   $why      Зачем.
	 */
	private static function text( Settings $settings, string $key, string $label, string $css, string $what, string $why ): void {
		$field = self::field( $key );

		echo '<tr><th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label> ';
		HelpTip::render( $what, $why );
		echo '</th><td><input type="text" class="' . esc_attr( $css ) . '" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $settings->general( $key ) ) . '"></td></tr>';
	}

	/**
	 * Имя поля формы.
	 *
	 * @param string $key Имя общей настройки.
	 */
	private static function field( string $key ): string {
		return Settings::OPTION . '[general][' . $key . ']';
	}
}
