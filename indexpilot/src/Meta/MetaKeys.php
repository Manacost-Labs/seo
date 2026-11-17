<?php
/**
 * Ключи мета-данных плагина.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Meta;

/**
 * Единственное место, где перечислены ключи меты записей и терминов.
 */
final class MetaKeys {

	public const TITLE          = '_indexpilot_title';
	public const DESCRIPTION    = '_indexpilot_description';
	public const FOCUS_KEYWORD  = '_indexpilot_focus_keyword';
	public const KEYWORDS       = '_indexpilot_keywords';
	public const CANONICAL      = '_indexpilot_canonical';
	public const NOINDEX        = '_indexpilot_noindex';
	public const NOFOLLOW       = '_indexpilot_nofollow';
	public const OG_TITLE       = '_indexpilot_og_title';
	public const OG_DESCRIPTION = '_indexpilot_og_description';
	public const OG_IMAGE_ID    = '_indexpilot_og_image_id';
	public const SCHEMA_TYPE    = '_indexpilot_schema_type';
	public const CORNERSTONE    = '_indexpilot_cornerstone';
	public const SCORE          = '_indexpilot_score';
	public const ANALYSIS       = '_indexpilot_analysis';
	public const INTRO          = '_indexpilot_intro';
	public const OUTRO          = '_indexpilot_outro';

	/**
	 * Вкладка «Содержание» панели записи: краткий ответ и пары «вопрос — ответ».
	 */
	public const ANSWER = '_indexpilot_answer';
	public const FAQ    = '_indexpilot_faq';

	/**
	 * Название записи в хлебных крошках и основная рубрика, когда рубрик несколько.
	 */
	public const BREADCRUMB_TITLE = '_indexpilot_breadcrumb_title';
	public const PRIMARY_CATEGORY = '_indexpilot_primary_category';

	/**
	 * Вкладка «Картинка в поиске»: версии обложки в пропорциях 1:1, 4:3 и 16:9 (ID вложений) для разметки статьи.
	 */
	public const IMAGE_1X1  = '_indexpilot_image_1x1';
	public const IMAGE_4X3  = '_indexpilot_image_4x3';
	public const IMAGE_16X9 = '_indexpilot_image_16x9';

	/**
	 * Наибольшее число дополнительных ключевых слов.
	 */
	public const MAX_KEYWORDS = 4;

	/**
	 * Описание всех ключей.
	 *
	 * Поля: type — тип для register_meta; kind — способ очистки; objects — к чему относится;
	 * editable — можно ли менять через REST (оценку и результат анализа пишет только сервер).
	 *
	 * @return array<string, array{type: string, kind: string, objects: list<string>, editable: bool}>
	 */
	public static function definitions(): array {
		$both = array( 'post', 'term' );

		return array(
			self::TITLE            => self::define( 'string', 'text', $both ),
			self::DESCRIPTION      => self::define( 'string', 'textarea', $both ),
			self::FOCUS_KEYWORD    => self::define( 'string', 'text', $both ),
			self::KEYWORDS         => self::define( 'array', 'keywords', $both ),
			self::CANONICAL        => self::define( 'string', 'url', $both ),
			self::NOINDEX          => self::define( 'boolean', 'flag', $both ),
			self::NOFOLLOW         => self::define( 'boolean', 'flag', $both ),
			self::OG_TITLE         => self::define( 'string', 'text', $both ),
			self::OG_DESCRIPTION   => self::define( 'string', 'textarea', $both ),
			self::OG_IMAGE_ID      => self::define( 'integer', 'int', $both ),
			self::SCHEMA_TYPE      => self::define( 'string', 'schema_type', array( 'post' ) ),
			self::CORNERSTONE      => self::define( 'boolean', 'flag', array( 'post' ) ),
			self::SCORE            => self::define( 'integer', 'int', $both, false ),
			self::ANALYSIS         => self::define( 'string', 'json', $both, false ),
			self::INTRO            => self::define( 'string', 'html', array( 'term' ) ),
			self::OUTRO            => self::define( 'string', 'html', array( 'term' ) ),
			self::ANSWER           => self::define( 'string', 'textarea', array( 'post' ) ),
			self::FAQ              => self::define( 'array', 'faq', array( 'post' ) ),
			self::BREADCRUMB_TITLE => self::define( 'string', 'text', array( 'post' ) ),
			self::PRIMARY_CATEGORY => self::define( 'integer', 'int', array( 'post' ) ),
			self::IMAGE_1X1        => self::define( 'integer', 'int', array( 'post' ) ),
			self::IMAGE_4X3        => self::define( 'integer', 'int', array( 'post' ) ),
			self::IMAGE_16X9       => self::define( 'integer', 'int', array( 'post' ) ),
		);
	}

	/**
	 * Все ключи списком — для удаления данных и для импорта.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * Собирает описание ключа.
	 *
	 * @param string       $type     Тип для register_meta.
	 * @param string       $kind     Способ очистки.
	 * @param list<string> $objects  «post», «term» или оба.
	 * @param bool         $editable Доступно ли поле для записи через REST.
	 * @return array{type: string, kind: string, objects: list<string>, editable: bool}
	 */
	private static function define( string $type, string $kind, array $objects, bool $editable = true ): array {
		return array(
			'type'     => $type,
			'kind'     => $kind,
			'objects'  => $objects,
			'editable' => $editable,
		);
	}
}
