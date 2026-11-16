<?php
/**
 * Блоки фактов и цитат.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

/**
 * Шорткоды [indexpilot_stat] — число с тем, что измерено, источником и датой, и [indexpilot_quote] — слова игрока
 * с именем и ролью. Конкретные числа с источником и прямая речь — то, что ассистенты цитируют охотнее всего.
 *
 * Факт считается полным, когда у него есть значение, источник и дата. Неполный факт выводится, но редактор
 * видит под ним пометку. Источники с адресом попадают в citation разметки статьи.
 * Оформление — атрибутами style: на страницах сайта плагин не подключает ни скриптов, ни файлов стилей.
 */
final class FactBlocks {

	public const STAT  = 'indexpilot_stat';
	public const QUOTE = 'indexpilot_quote';

	/**
	 * Атрибуты шорткода факта.
	 */
	private const STAT_FIELDS = array( 'value', 'label', 'source', 'url', 'date', 'sample' );

	/**
	 * Подписи по умолчанию.
	 */
	private const LABELS = array(
		'source'     => 'Источник',
		'no_date'    => 'нет даты',
		'no_source'  => 'нет источника',
		'note'       => 'Видно только редактору: у факта %s. Без этого читатель и поисковик не могут его проверить.',
		'quote_note' => 'Видно только редактору: у цитаты нет имени. Укажите, чьи это слова.',
	);

	/**
	 * Приводит атрибуты факта к полному набору строк.
	 *
	 * @param mixed $atts Атрибуты шорткода.
	 * @return array{value: string, label: string, source: string, url: string, date: string, sample: string}
	 */
	public static function stat( mixed $atts ): array {
		$stat = array();

		foreach ( self::STAT_FIELDS as $field ) {
			$value          = is_array( $atts ) && is_scalar( $atts[ $field ] ?? null ) ? (string) $atts[ $field ] : '';
			$stat[ $field ] = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		}

		// Адрес источника — только обычная ссылка.
		if ( 1 !== preg_match( '#^https?://[^\s<>"\']+$#i', $stat['url'] ) ) {
			$stat['url'] = '';
		}

		return $stat;
	}

	/**
	 * Полон ли факт: есть значение, источник и дата.
	 *
	 * @param mixed $atts Атрибуты шорткода.
	 */
	public static function is_complete( mixed $atts ): bool {
		$stat = self::stat( $atts );

		return '' !== $stat['value'] && '' !== $stat['source'] && '' !== $stat['date'];
	}

	/**
	 * HTML блока факта. Факт без значения не выводится.
	 *
	 * @param mixed                 $atts       Атрибуты шорткода.
	 * @param bool                  $for_editor Показывать ли пометку о неполном факте.
	 * @param array<string, string> $labels     Подписи: source, no_date, no_source, note.
	 */
	public static function stat_html( mixed $atts, bool $for_editor, array $labels = array() ): string {
		$stat   = self::stat( $atts );
		$labels = $labels + self::LABELS;
		$escape = static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );

		if ( '' === $stat['value'] ) {
			return '';
		}

		$details = array();

		if ( '' !== $stat['sample'] ) {
			$details[] = $escape( $stat['sample'] );
		}

		if ( '' !== $stat['source'] ) {
			$source    = '' !== $stat['url']
				? sprintf( '<a href="%s" rel="noopener">%s</a>', $escape( $stat['url'] ), $escape( $stat['source'] ) )
				: $escape( $stat['source'] );
			$details[] = $escape( $labels['source'] ) . ': ' . $source;
		}

		if ( '' !== $stat['date'] ) {
			$iso       = self::iso_date( $stat['date'] );
			$details[] = '' !== $iso ? sprintf( '<time datetime="%s">%s</time>', $iso, $escape( $stat['date'] ) ) : $escape( $stat['date'] );
		}

		$note = '';

		if ( $for_editor && ! self::is_complete( $stat ) ) {
			$missing = array_filter(
				array(
					'' === $stat['source'] ? $labels['no_source'] : '',
					'' === $stat['date'] ? $labels['no_date'] : '',
				)
			);
			$note    = self::note( sprintf( $labels['note'], implode( ', ', $missing ) ) );
		}

		return sprintf(
			'<figure class="indexpilot-stat" style="margin:1.5em 0;padding:1em 1.25em;border:1px solid rgba(128,128,128,.45);border-left:4px solid currentColor;border-radius:8px"><p style="margin:0;line-height:1.2"><strong style="font-size:1.8em">%1$s</strong>%2$s</p>%3$s%4$s</figure>',
			$escape( $stat['value'] ),
			'' !== $stat['label'] ? ' <span>' . $escape( $stat['label'] ) . '</span>' : '',
			array() !== $details ? '<figcaption style="margin:.5em 0 0;font-size:.85em;opacity:.8">' . implode( ' · ', $details ) . '</figcaption>' : '',
			$note
		);
	}

	/**
	 * HTML блока цитаты. Слова — обычный текст: разметка из них убирается, переводы строк сохраняются.
	 *
	 * @param string                $text       Слова.
	 * @param string                $name       Чьи слова.
	 * @param string                $role       Роль: стример, киберспортсмен.
	 * @param bool                  $for_editor Показывать ли пометку о цитате без имени.
	 * @param array<string, string> $labels     Подписи: quote_note.
	 */
	public static function quote_html( string $text, string $name, string $role, bool $for_editor = false, array $labels = array() ): string {
		$escape = static fn( string $value ): string => htmlspecialchars( trim( $value ), ENT_QUOTES, 'UTF-8' );
		$labels = $labels + self::LABELS;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс не зависит от WordPress; результат экранируется ниже.
		$text = trim( html_entity_decode( strip_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', (string) preg_replace( '/\s*\n\s*/u', "\n", $text ) );

		if ( '' === $text ) {
			return '';
		}

		$caption = '';

		if ( '' !== trim( $name ) ) {
			$caption = sprintf(
				'<figcaption style="margin:.6em 0 0;font-size:.9em">— <cite style="font-style:normal;font-weight:600">%s</cite>%s</figcaption>',
				$escape( $name ),
				'' !== trim( $role ) ? ', ' . $escape( $role ) : ''
			);
		}

		return sprintf(
			'<figure class="indexpilot-quote" style="margin:1.5em 0;padding:1em 1.25em;border-left:4px solid currentColor;background:rgba(128,128,128,.08);border-radius:0 8px 8px 0"><blockquote style="margin:0;padding:0;border:0"><p style="margin:0">%1$s</p></blockquote>%2$s%3$s</figure>',
			nl2br( $escape( $text ), false ),
			$caption,
			$for_editor && '' === trim( $name ) ? self::note( $labels['quote_note'] ) : ''
		);
	}

	/**
	 * Факты из текста записи: атрибуты каждого шорткода [indexpilot_stat].
	 *
	 * @param string $content Исходный текст записи.
	 * @return list<array{value: string, label: string, source: string, url: string, date: string, sample: string}>
	 */
	public static function stats( string $content ): array {
		$stats = array();

		if ( ! preg_match_all( '/\[' . self::STAT . '\b([^\]]*)\]/u', $content, $found ) ) {
			return $stats;
		}

		foreach ( $found[1] as $raw ) {
			$atts = array();

			if ( preg_match_all( '/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/u', html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $pairs, PREG_SET_ORDER ) ) {
				foreach ( $pairs as $pair ) {
					$atts[ $pair[1] ] = (string) end( $pair );
				}
			}

			$stat = self::stat( $atts );

			if ( '' !== $stat['value'] ) {
				$stats[] = $stat;
			}
		}

		return $stats;
	}

	/**
	 * Число полных фактов в тексте: только они засчитываются проверками.
	 *
	 * @param string $content Исходный текст записи.
	 */
	public static function complete_count( string $content ): int {
		return count( array_filter( self::stats( $content ), array( self::class, 'is_complete' ) ) );
	}

	/**
	 * Источники фактов с адресом — для citation в разметке статьи. Один адрес — один источник.
	 *
	 * @param string $content Исходный текст записи.
	 * @return list<array{name: string, url: string}>
	 */
	public static function citations( string $content ): array {
		$citations = array();

		foreach ( self::stats( $content ) as $stat ) {
			if ( '' !== $stat['url'] && ! isset( $citations[ $stat['url'] ] ) ) {
				$citations[ $stat['url'] ] = array(
					'name' => $stat['source'],
					'url'  => $stat['url'],
				);
			}
		}

		return array_values( $citations );
	}

	/**
	 * Дата в машинном виде (ГГГГ-ММ-ДД) из записи «15.11.2026» или «2026-11-15»; пустая строка, если дата не распознана.
	 *
	 * @param string $date Дата, как её написал редактор.
	 */
	public static function iso_date( string $date ): string {
		$date = trim( $date );

		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $parts ) ) {
			list( , $day, $month, $year ) = $parts;
		} elseif ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $parts ) ) {
			list( , $year, $month, $day ) = $parts;
		} else {
			return '';
		}

		return checkdate( (int) $month, (int) $day, (int) $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
	}

	/**
	 * Пометка для редактора.
	 *
	 * @param string $text Текст пометки.
	 */
	private static function note( string $text ): string {
		return '<p class="indexpilot-editor-note" style="margin:.6em 0 0;padding:.4em .6em;font-size:.85em;background:#fff8e5;color:#5c4400;border:1px dashed #c9a227;border-radius:4px">' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</p>';
	}

	/**
	 * Регистрирует шорткоды. Вызывается на хуке init.
	 */
	public function register(): void {
		add_shortcode( self::STAT, array( $this, 'stat_shortcode' ) );
		add_shortcode( self::QUOTE, array( $this, 'quote_shortcode' ) );
	}

	/**
	 * Шорткод факта.
	 *
	 * @param mixed $atts Атрибуты шорткода.
	 */
	public function stat_shortcode( $atts ): string {
		return self::stat_html(
			$atts,
			$this->is_editor(),
			array(
				'source'    => __( 'Источник', 'indexpilot' ),
				'no_date'   => __( 'нет даты', 'indexpilot' ),
				'no_source' => __( 'нет источника', 'indexpilot' ),
				/* translators: %s: чего не хватает факту — «нет даты», «нет источника». */
				'note'      => __( 'Видно только редактору: у факта %s. Без этого читатель и поисковик не могут его проверить.', 'indexpilot' ),
			)
		);
	}

	/**
	 * Шорткод цитаты.
	 *
	 * @param mixed  $atts    Атрибуты шорткода: name, role.
	 * @param string $content Слова между открывающим и закрывающим тегом.
	 */
	public function quote_shortcode( $atts, $content = '' ): string {
		$atts = is_array( $atts ) ? $atts : array();

		return self::quote_html(
			(string) $content,
			is_scalar( $atts['name'] ?? null ) ? (string) $atts['name'] : '',
			is_scalar( $atts['role'] ?? null ) ? (string) $atts['role'] : '',
			$this->is_editor(),
			array( 'quote_note' => __( 'Видно только редактору: у цитаты нет имени. Укажите, чьи это слова.', 'indexpilot' ) )
		);
	}

	/**
	 * Может ли текущий посетитель править запись, в которой стоит блок.
	 */
	private function is_editor(): bool {
		$post_id = (int) get_the_ID();

		return $post_id > 0 && is_user_logged_in() && current_user_can( 'edit_post', $post_id );
	}
}
