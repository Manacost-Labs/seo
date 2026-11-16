<?php
/**
 * Вывод хлебных крошек на странице.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Breadcrumbs;

use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\ContextFactory;

/**
 * Шорткод [indexpilot_breadcrumbs] и функция indexpilot_breadcrumbs() для шаблонов темы.
 * Разметка для поисковиков выводится отдельно, в блоке JSON-LD, поэтому здесь только ссылки.
 */
final class Output {

	/**
	 * Конструктор.
	 *
	 * @param Trail $trail Построитель цепочки.
	 */
	public function __construct( private Trail $trail ) {
	}

	/**
	 * Регистрирует шорткод.
	 */
	public function register(): void {
		add_shortcode( 'indexpilot_breadcrumbs', fn(): string => $this->html() );
	}

	/**
	 * HTML цепочки для текущей страницы. На главной странице цепочка не выводится.
	 *
	 * @param Context|null $context Контекст страницы; по умолчанию — текущая страница сайта.
	 */
	public function html( ?Context $context = null ): string {
		$crumbs = $this->trail->for_context( $context ?? ContextFactory::from_query() );
		$last   = count( $crumbs ) - 1;

		if ( $last < 1 ) {
			return '';
		}

		$parts = array();

		foreach ( $crumbs as $index => $crumb ) {
			if ( $index === $last || '' === $crumb['url'] ) {
				$parts[] = sprintf( '<span%s>%s</span>', $index === $last ? ' aria-current="page"' : '', esc_html( $crumb['name'] ) );
			} else {
				$parts[] = sprintf( '<a href="%s">%s</a>', esc_url( $crumb['url'] ), esc_html( $crumb['name'] ) );
			}
		}

		/**
		 * Разделитель между звеньями цепочки.
		 *
		 * @param string $separator Разделитель.
		 */
		$separator = (string) apply_filters( 'indexpilot_breadcrumb_separator', '›' );

		return sprintf(
			'<nav class="indexpilot-breadcrumbs" aria-label="%s">%s</nav>',
			esc_attr__( 'Хлебные крошки', 'indexpilot' ),
			implode( ' <span class="indexpilot-breadcrumbs-sep" aria-hidden="true">' . esc_html( $separator ) . '</span> ', $parts )
		);
	}
}
