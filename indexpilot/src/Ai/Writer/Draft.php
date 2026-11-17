<?php
/**
 * Запрос к модели и разбор её ответа: SEO-поля статьи и alt картинок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- текст исключения выводится через esc_html.

namespace IndexPilot\Ai\Writer;

use IndexPilot\Frontend\AnswerBlock;
use IndexPilot\Meta\MetaKeys;

/**
 * Без WordPress: собирает сообщения для модели по заголовку, тексту и картинкам статьи и приводит ответ
 * к значениям полей панели. Заголовок модель не пишет — в поиске остаётся заголовок статьи.
 * Всё, что модель вернула не по правилам (слишком длинное, пустое, лишнее), обрезается или отбрасывается здесь.
 */
final class Draft {

	/**
	 * Сколько картинок без alt отдаётся модели за один раз.
	 */
	public const MAX_IMAGES = 12;

	/**
	 * Сколько знаков текста статьи отдаётся модели.
	 */
	public const MAX_TEXT = 24000;

	/**
	 * Вопросов в блоке «Вопросы и ответы» от модели.
	 */
	public const FAQ_MIN = 3;
	public const FAQ_MAX = 5;

	/**
	 * Предел длины alt: длиннее экранные дикторы читают с трудом.
	 */
	public const ALT_MAX = 125;

	/**
	 * Картинки статьи: адрес, номер вложения (по классу wp-image-N) и alt.
	 *
	 * @param string $html Текст статьи.
	 * @return list<array{src: string, id: int, alt: string}>
	 */
	public static function images( string $html ): array {
		$images = array();

		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			return $images;
		}

		foreach ( $tags[0] as $tag ) {
			$src = self::attribute( $tag, 'src' );

			if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
				continue;
			}

			$images[] = array(
				'src' => $src,
				'id'  => preg_match( '/\bwp-image-(\d+)\b/', self::attribute( $tag, 'class' ), $id ) ? (int) $id[1] : 0,
				'alt' => trim( self::attribute( $tag, 'alt' ) ),
			);
		}

		return $images;
	}

	/**
	 * Картинки, которым нужен alt: без него или с именем файла вместо описания. Повторы адреса — один раз.
	 *
	 * @param list<array{src: string, id: int, alt: string}> $images Картинки статьи.
	 * @return list<array{src: string, id: int, alt: string}>
	 */
	public static function without_alt( array $images ): array {
		$result = array();
		$seen   = array();

		foreach ( $images as $image ) {
			$file = strtolower( (string) pathinfo( (string) parse_url( $image['src'], PHP_URL_PATH ), PATHINFO_FILENAME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс работает и без WordPress.
			$alt  = strtolower( $image['alt'] );

			if ( isset( $seen[ $image['src'] ] ) || ( '' !== $alt && $alt !== $file && ! preg_match( '/^(img|image|dsc|screenshot|photo)[\s_\-]*\d*$/', $alt ) ) ) {
				continue;
			}

			$seen[ $image['src'] ] = true;
			$result[]              = $image;

			if ( count( $result ) >= self::MAX_IMAGES ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Сообщения для модели.
	 *
	 * @param array<string, mixed> $article Статья и сайт: site, about, title, text, description_min, description_max; slug и description — адрес и текущее описание из формы.
	 * @param list<string>         $images  Картинки: адрес или data:-адрес, по порядку.
	 * @return list<array<string, mixed>>
	 */
	public static function messages( array $article, array $images ): array {
		$min = max( 50, $article['description_min'] );
		$max = max( $min + 10, $article['description_max'] );

		$system = sprintf(
			'Ты — опытный SEO-редактор русскоязычного сайта «%s»%s. Пишешь живым русским языком для читателей и для поиска Google и Яндекса. '
			. 'Опираешься только на текст статьи: не выдумываешь цифры, карты, патчи и факты. Отвечаешь одним объектом JSON без пояснений.',
			$article['site'],
			'' !== $article['about'] ? ' (' . $article['about'] . ')' : ''
		);

		$rules = array(
			'Заполни SEO-поля статьи. Заголовок не меняй и не предлагай.',
			'',
			'Поля ответа:',
			'"focus_keyword" — главный поисковый запрос статьи, 2–5 слов в нижнем регистре, так, как его набирают люди. Заголовок статьи не меняется, а поисковики ищут ключ именно в нём, поэтому запрос бери из заголовка: все его слова должны быть в заголовке (окончания могут отличаться). Из заголовка «Масса урона за один ход: гайд на Берн Мага от TrueBench» — «гайд на берн мага», а не «колода берн мага». Если в заголовке нет поисковой фразы, выбери самую близкую к нему.',
			sprintf( '"keywords" — до %d дополнительных запросов: другие формулировки, сленг игроков, английское название, если оно есть в статье. Без повтора главного запроса и без запятых внутри запроса.', MetaKeys::MAX_KEYWORDS ),
			sprintf( '"description" — мета-описание для выдачи: строго %d–%d знаков с пробелами. Главный запрос дословно — в первой половине. Конкретика из статьи (что внутри, чем полезно), законченная мысль. Не повторяй заголовок дословно, без «В этой статье», без кавычек-ёлочек вокруг всего текста.', $min, $max ),
			sprintf( '"answer" — краткий ответ на вопрос из заголовка: %d–%d слов, понятен без остальной статьи, начинается с сути, с конкретными названиями и числами из текста.', AnswerBlock::MIN_WORDS, AnswerBlock::MAX_WORDS ),
			sprintf( '"faq" — %d–%d вопросов, которые игроки действительно задают по этой теме, с ответами в 1–3 предложения строго по тексту статьи. Вопросы не повторяют заголовок.', self::FAQ_MIN, self::FAQ_MAX ),
		);

		if ( array() !== $images ) {
			$rules[] = sprintf( '"alts" — alt для каждой картинки ниже: что на ней изображено, по-русски, до %d знаков, с названием колоды, карты или героя, если их видно или они понятны из статьи. Без слов «картинка», «изображение», «скриншот». Главный запрос естественно используй в alt одной картинки, которая прямо показывает тему статьи (колода, карты, обложка этого гайда); в alt разделителей, иконок, логотипов и обложек других статей запрос не вставляй, а если подходящей картинки нет — не используй его нигде. Разделителям и иконкам — короткий alt в 2–4 слова. Формат: [{"image": номер, "alt": "текст"}].', self::ALT_MAX );
		}

		$rules[] = '';
		$rules[] = 'Формат ответа: {"focus_keyword": "", "keywords": [], "description": "", "answer": "", "faq": [{"question": "", "answer": ""}]' . ( array() !== $images ? ', "alts": []' : '' ) . '}';
		$rules[] = '';
		$rules[] = 'Заголовок статьи: ' . $article['title'];

		if ( '' !== (string) ( $article['slug'] ?? '' ) ) {
			$rules[] = 'Адрес страницы: ' . $article['slug'];
		}

		if ( '' !== (string) ( $article['description'] ?? '' ) ) {
			$rules[] = 'Текущее описание (редактор может его оставить — тогда хорошо, если главный запрос есть и в нём): ' . $article['description'];
		}
		$rules[] = '';
		$rules[] = 'Текст статьи:';
		$rules[] = '"""';
		$rules[] = mb_substr( $article['text'], 0, self::MAX_TEXT );
		$rules[] = '"""';

		$content = array(
			array(
				'type' => 'text',
				'text' => implode( "\n", $rules ),
			),
		);

		foreach ( array_values( $images ) as $index => $url ) {
			$content[] = array(
				'type' => 'text',
				'text' => sprintf( 'Картинка %d:', $index + 1 ),
			);
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url'    => $url,
					'detail' => 'low',
				),
			);
		}

		return array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $content,
			),
		);
	}

	/**
	 * Значения полей из ответа модели.
	 *
	 * @param string $raw             Ответ модели.
	 * @param int    $images          Сколько картинок отдано модели.
	 * @param int    $description_max Предел длины описания.
	 * @param string $title           Заголовок статьи: главный запрос должен в нём быть.
	 * @return array{focus_keyword: string, keywords: list<string>, description: string, answer: string, faq: list<array{question: string, answer: string}>, alts: array<int, string>}
	 * @throws WriterException Ответ не похож на JSON.
	 */
	public static function parse( string $raw, int $images, int $description_max, string $title = '' ): array {
		$start = strpos( $raw, '{' );
		$end   = strrpos( $raw, '}' );
		$data  = false !== $start && false !== $end ? json_decode( substr( $raw, $start, $end - $start + 1 ), true ) : null;

		if ( ! is_array( $data ) ) {
			throw new WriterException( 'Модель ответила не по формату. Нажмите кнопку ещё раз.' );
		}

		$focus    = mb_strtolower( self::line( $data['focus_keyword'] ?? '', 80 ) );
		$keywords = array();

		foreach ( (array) ( $data['keywords'] ?? array() ) as $keyword ) {
			$keyword = mb_strtolower( self::line( str_replace( ',', ' ', is_scalar( $keyword ) ? (string) $keyword : '' ), 80 ) );

			if ( '' !== $keyword && $keyword !== $focus && ! in_array( $keyword, $keywords, true ) ) {
				$keywords[] = $keyword;
			}
		}

		// Главного запроса нет в заголовке, а дополнительный там есть — они меняются местами.
		if ( '' !== $title && '' !== $focus && ! self::in_title( $focus, $title ) ) {
			foreach ( $keywords as $index => $keyword ) {
				if ( self::in_title( $keyword, $title ) ) {
					$keywords[ $index ] = $focus;
					$focus              = $keyword;
					break;
				}
			}
		}

		$faq = array();

		foreach ( (array) ( $data['faq'] ?? array() ) as $item ) {
			$question = is_array( $item ) ? self::line( $item['question'] ?? '', 200 ) : '';
			$answer   = is_array( $item ) ? self::line( $item['answer'] ?? '', 600 ) : '';

			if ( '' !== $question && '' !== $answer ) {
				$faq[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}

		$alts = array();

		foreach ( (array) ( $data['alts'] ?? array() ) as $item ) {
			$number = is_array( $item ) ? (int) ( $item['image'] ?? 0 ) : 0;
			$alt    = is_array( $item ) ? self::shorten( str_replace( '"', '', self::line( $item['alt'] ?? '', 400 ) ), self::ALT_MAX, '' ) : '';

			if ( $number >= 1 && $number <= $images && '' !== $alt ) {
				$alts[ $number - 1 ] = $alt;
			}
		}

		return array(
			'focus_keyword' => $focus,
			'keywords'      => array_slice( $keywords, 0, MetaKeys::MAX_KEYWORDS ),
			'description'   => self::shorten( self::unquote( self::line( $data['description'] ?? '', 600 ) ), max( 60, $description_max ), '…' ),
			'answer'        => self::line( $data['answer'] ?? '', 1200 ),
			'faq'           => array_slice( $faq, 0, self::FAQ_MAX ),
			'alts'          => $alts,
		);
	}

	/**
	 * Все слова запроса есть в заголовке, с точностью до окончания.
	 *
	 * @param string $phrase Запрос.
	 * @param string $title  Заголовок.
	 */
	public static function in_title( string $phrase, string $title ): bool {
		$stem  = static fn( string $word ): string => mb_substr( $word, 0, max( 3, mb_strlen( $word ) - 2 ) );
		$title = mb_strtolower( str_replace( 'ё', 'е', $title ) );
		$words = (array) preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( str_replace( 'ё', 'е', $phrase ) ), -1, PREG_SPLIT_NO_EMPTY );

		foreach ( $words as $word ) {
			if ( mb_strlen( (string) $word ) > 2 && ! preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $stem( (string) $word ), '/' ) . '/u', $title ) ) {
				return false;
			}
		}

		return array() !== $words;
	}

	/**
	 * Текст не длиннее предела: обрезка по границе слова.
	 *
	 * @param string $text  Текст.
	 * @param int    $limit Предел, знаков.
	 * @param string $tail  Что ставится в конце обрезанного текста.
	 */
	public static function shorten( string $text, int $limit, string $tail ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut  = mb_substr( $text, 0, $limit - mb_strlen( $tail ) );
		$last = mb_strrpos( $cut, ' ' );
		$cut  = false !== $last && $last > $limit / 2 ? mb_substr( $cut, 0, $last ) : $cut;

		return (string) preg_replace( '/[\s,.;:—-]+$/u', '', $cut ) . $tail;
	}

	/**
	 * Строка без переносов, тегов и лишних пробелов.
	 *
	 * @param mixed $value Значение.
	 * @param int   $limit Предел длины, знаков.
	 */
	private static function line( $value, int $limit ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс работает и без WordPress.

		return mb_substr( trim( $text ), 0, $limit );
	}

	/**
	 * Без кавычек вокруг всего текста.
	 *
	 * @param string $text Текст.
	 */
	private static function unquote( string $text ): string {
		return (string) preg_replace( '/^[«"„“](.*)[»"“”]$/u', '$1', $text );
	}

	/**
	 * Значение атрибута тега.
	 *
	 * @param string $tag  Тег.
	 * @param string $name Атрибут.
	 */
	private static function attribute( string $tag, string $name ): string {
		if ( ! preg_match( '/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $match ) ) {
			return '';
		}

		return html_entity_decode( (string) ( $match[1] ?? '' ) . (string) ( $match[2] ?? '' ) . (string) ( $match[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
