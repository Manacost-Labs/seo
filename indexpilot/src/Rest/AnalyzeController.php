<?php
/**
 * REST-маршрут предпросмотра сниппета и анализа.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\PaperFactory;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Text\VideoBlurb;

/**
 * POST /indexpilot/v1/analyze — панель редактора присылает несохранённые значения и получает
 * итоговые заголовок и описание (как они будут на сайте) и результаты проверок.
 */
final class AnalyzeController {

	public const REST_NAMESPACE = 'indexpilot/v1';
	public const ROUTE          = '/analyze';

	/**
	 * Текстовые поля запроса, которые перекрывают сохранённые значения.
	 */
	private const TEXT_OVERRIDES = array( 'seo_title', 'seo_description', 'focus_keyword', 'title', 'excerpt', 'content', 'slug', 'og_title', 'og_description', 'answer', 'sponsored' );

	/**
	 * Предел длины текста записи в запросе: тяжёлый анализ мегабайтного текста — нагрузка на сервер.
	 */
	public const MAX_CONTENT = 300000;

	/**
	 * Предел длины остальных текстовых полей.
	 */
	public const MAX_FIELD = 1000;

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер мета-данных.
	 * @param PaperFactory $papers   Сборка данных страницы.
	 * @param Analyzer     $analyzer Анализатор.
	 */
	public function __construct(
		private MetaResolver $resolver,
		private PaperFactory $papers,
		private Analyzer $analyzer
	) {
	}

	/**
	 * Регистрирует маршрут. Вызывается на rest_api_init.
	 */
	public function register(): void {
		$text = array(
			'type'              => 'string',
			'maxLength'         => self::MAX_FIELD,
			'sanitize_callback' => static fn( $value ): string => is_string( $value ) ? $value : '',
		);

		$args = array(
			'object_type' => array(
				'type'     => 'string',
				'enum'     => array( 'post', 'term' ),
				'required' => true,
			),
			'object_id'   => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
			'taxonomy'    => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'keywords'    => array(
				'type'     => 'array',
				'maxItems' => MetaKeys::MAX_KEYWORDS,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => self::MAX_FIELD,
				),
			),
			'title_px'    => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
		);

		foreach ( self::TEXT_OVERRIDES as $field ) {
			$args[ $field ] = $text;
		}

		// Текст записи и краткий ответ длиннее заголовков.
		$args['content']['maxLength'] = self::MAX_CONTENT;
		$args['excerpt']['maxLength'] = self::MAX_CONTENT;
		$args['answer']['maxLength']  = 5000;

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Может ли пользователь редактировать объект, для которого запрошен анализ.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return bool|\WP_Error
	 */
	public function can_edit( \WP_REST_Request $request ) {
		$object_id = (int) $request['object_id'];

		$allowed = 'term' === $request['object_type']
			? current_user_can( 'edit_term', $object_id )
			: current_user_can( 'edit_post', $object_id );

		if ( $allowed ) {
			return true;
		}

		return new \WP_Error( 'indexpilot_forbidden', __( 'Недостаточно прав для анализа этой страницы.', 'indexpilot' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Считает сниппет и анализ.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$context = $this->context( $request );

		if ( $context instanceof \WP_Error ) {
			return $context;
		}

		// Предпросмотр: неизвестная переменная шаблона остаётся в тексте, чтобы редактор её увидел.
		// Анализ считается по тем значениям, которые выйдут на сайт, — там её уже нет.
		$preview = new Context( $context->type, $context->object_id, $context->subtype, $context->page, $context->overrides, true );
		$meta    = $this->resolver->resolve( $preview );
		$report  = $this->analyzer->analyze( $this->papers->create( $context ) );
		$unknown = $this->resolver->unknown_variables( $context );

		$response = array(
			'meta'              => $meta->to_array(),
			'analysis'          => $report->to_array(),
			'unknown_variables' => array_map( static fn( string $name ): string => '%' . $name . '%', $unknown ),
		);

		if ( Context::POST === $context->type ) {
			// Текст для ролика копируют как есть: в него идёт описание в том виде, в каком оно выйдет на сайт.
			$response['blurb'] = self::blurb( $context, array() === $unknown ? $meta->description : $this->resolver->resolve( $context )->description );
		}

		return new \WP_REST_Response( $response );
	}

	/**
	 * Текст для описания ролика и стрима: краткий ответ (или описание записи), подзаголовки H2 и короткая ссылка.
	 * Несохранённые значения из редактора имеют приоритет.
	 *
	 * @param Context $context     Контекст записи.
	 * @param string  $description Итоговое описание записи — на случай, если краткого ответа нет.
	 */
	public static function blurb( Context $context, string $description ): string {
		$answer  = $context->override( 'answer' );
		$content = $context->override( 'content' );
		$answer  = trim( is_string( $answer ) ? $answer : (string) get_post_meta( $context->object_id, MetaKeys::ANSWER, true ) );
		$link    = (string) wp_get_shortlink( $context->object_id );

		return VideoBlurb::build(
			'' !== $answer ? $answer : $description,
			is_string( $content ) ? $content : (string) get_post_field( 'post_content', $context->object_id ),
			'' !== $link ? $link : (string) get_permalink( $context->object_id ),
			__( 'В статье:', 'indexpilot' ),
			__( 'Читать полностью:', 'indexpilot' )
		);
	}

	/**
	 * Контекст страницы с несохранёнными значениями из запроса.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return Context|\WP_Error
	 */
	private function context( \WP_REST_Request $request ) {
		$object_id = (int) $request['object_id'];
		$overrides = array();

		foreach ( self::TEXT_OVERRIDES as $field ) {
			// Поле, которого нет в запросе, не перекрывает сохранённое значение.
			if ( $request->has_param( $field ) ) {
				$overrides[ $field ] = (string) $request[ $field ];
			}
		}

		if ( $request->has_param( 'keywords' ) ) {
			$overrides['keywords'] = array_map( 'sanitize_text_field', (array) $request['keywords'] );
		}

		if ( (int) $request['title_px'] > 0 ) {
			$overrides['title_px'] = (int) $request['title_px'];
		}

		if ( 'term' === $request['object_type'] ) {
			$term = get_term( $object_id, (string) $request['taxonomy'] );

			if ( ! $term instanceof \WP_Term ) {
				return new \WP_Error( 'indexpilot_not_found', __( 'Термин не найден.', 'indexpilot' ), array( 'status' => 404 ) );
			}

			return new Context( Context::TERM, $object_id, $term->taxonomy, 1, $overrides );
		}

		$post = get_post( $object_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'indexpilot_not_found', __( 'Запись не найдена.', 'indexpilot' ), array( 'status' => 404 ) );
		}

		return new Context( Context::POST, $object_id, $post->post_type, 1, $overrides );
	}
}
