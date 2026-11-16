<?php
/**
 * Вывод разметки JSON-LD.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\ContextFactory;
use IndexPilot\Schema\GraphBuilder;
use IndexPilot\Schema\PageData;

/**
 * Печатает один блок JSON-LD со всем графом страницы.
 */
final class SchemaOutput {

	/**
	 * Конструктор.
	 *
	 * @param PageData     $data    Данные страницы.
	 * @param GraphBuilder $builder Сборка графа.
	 */
	public function __construct( private PageData $data, private GraphBuilder $builder ) {
	}

	/**
	 * Подключает вывод. Вызывается на хуке template_redirect.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	/**
	 * Печатает блок разметки. На странице 404 разметки нет.
	 */
	public function render(): void {
		$context = ContextFactory::from_query();

		if ( Context::NOT_FOUND === $context->type ) {
			return;
		}

		$graph = $this->builder->build( $this->data->for_context( $context ) );

		/**
		 * Позволяет добавить в граф свои узлы, например колоду.
		 *
		 * @param \IndexPilot\Schema\Graph $graph   Граф страницы.
		 * @param Context                  $context Контекст страницы.
		 */
		do_action( 'indexpilot_schema_graph', $graph, $context );

		// Ссылка на узел, которого нет в графе, — ошибка разметки: такой блок лучше не выводить вовсе.
		if ( array() === $graph->nodes() || array() !== $graph->dangling() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON собран с JSON_HEX_TAG: угловые скобки закодированы и закрыть тег script не могут.
		echo self::script( $graph->to_json() );
	}

	/**
	 * Тег script с разметкой. Если граф не удалось закодировать в JSON (строка в неверной кодировке),
	 * JSON пуст — тогда не печатается ничего: пустой блок разметки хуже её отсутствия.
	 *
	 * @param string $json JSON графа; угловые скобки в нём уже закодированы.
	 */
	public static function script( string $json ): string {
		return '' === $json ? '' : '<script type="application/ld+json" class="indexpilot-schema">' . $json . "</script>\n";
	}
}
