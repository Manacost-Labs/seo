<?php
/**
 * Понятный текст ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis\Checks;

use IndexPilot\Analysis\CheckGroup;
use IndexPilot\Analysis\Facts;
use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Result;
use IndexPilot\Text\Cleaner;
use IndexPilot\Text\ImageFacts;

/**
 * Текст ссылки говорит поисковику и читателю, куда она ведёт. «Здесь», «подробнее», голый адрес
 * и картинка-ссылка без alt этого не говорят (книга «SEO — искусство раскрутки сайтов», с. 177–178, 523).
 * Замечание без баллов: оценку SEO оно не меняет.
 */
final class AnchorChecks implements CheckGroup {

	private const GROUP = 'content';

	/**
	 * Сколько ссылок перечислять в замечании.
	 */
	private const EXAMPLES = 3;

	/**
	 * Тексты ссылок, которые ничего не говорят о странице назначения (после приведения к нижнему регистру).
	 */
	private const VAGUE = array(
		'здесь',
		'тут',
		'сюда',
		'подробнее',
		'подробно',
		'детали',
		'читать',
		'читать далее',
		'читать дальше',
		'читать полностью',
		'далее',
		'дальше',
		'по ссылке',
		'ссылка',
		'ссылке',
		'ссылку',
		'ссылочка',
		'клик',
		'кликни',
		'кликните',
		'жми',
		'жмите',
		'нажми',
		'нажмите',
		'перейти',
		'перейдите',
		'перейти по ссылке',
		'смотреть',
		'смотрите',
		'here',
		'click',
		'click here',
		'read more',
		'more',
		'link',
	);

	/**
	 * Выполняет проверку.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		unset( $facts, $matcher );

		$found = self::vague( $paper->content );

		if ( array() === $found ) {
			return array();
		}

		$examples = array();

		foreach ( array_slice( $found, 0, self::EXAMPLES ) as $link ) {
			$examples[] = 'image' === $link['kind']
				? sprintf( 'картинка без alt → %s', $link['href'] )
				: sprintf( '«%s» → %s', $link['text'], $link['href'] );
		}

		return array(
			new Result(
				'anchor_text',
				self::GROUP,
				Result::OK,
				0,
				sprintf( 'Непонятный текст ссылок: %d. Из текста ссылки должно быть ясно, куда она ведёт, — например «муллиган колоды жреца» вместо «здесь». У картинки-ссылки напишите alt. Исправьте: %s.', count( $found ), implode( '; ', $examples ) )
			),
		);
	}

	/**
	 * Ссылки с непонятным текстом: «здесь», «подробнее», голый адрес или картинка без alt.
	 * Ссылки на место в этой же странице (#…) не проверяются.
	 *
	 * @param string $html HTML текста.
	 * @return list<array{href: string, text: string, kind: string}> kind: word, address или image.
	 */
	public static function vague( string $html ): array {
		if ( ! preg_match_all( '#<a\b([^>]*)>(.*?)</a>#isu', $html, $links, PREG_SET_ORDER ) ) {
			return array();
		}

		$found = array();

		foreach ( $links as $link ) {
			if ( ! preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#isu', $link[1], $href ) ) {
				continue;
			}

			$address = trim( html_entity_decode( $href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			if ( '' === $address || str_starts_with( $address, '#' ) ) {
				continue;
			}

			$kind = self::kind( $link[1], $link[2] );

			if ( '' !== $kind ) {
				$found[] = array(
					'href' => $address,
					'text' => Cleaner::to_plain_text( $link[2], false ),
					'kind' => $kind,
				);
			}
		}

		return $found;
	}

	/**
	 * Чем плох текст ссылки; пусто — текст понятный.
	 *
	 * @param string $attributes Атрибуты тега ссылки.
	 * @param string $inner      Содержимое ссылки.
	 */
	private static function kind( string $attributes, string $inner ): string {
		$text = Cleaner::to_plain_text( $inner, false );

		if ( '' === $text ) {
			// Ссылка-картинка: её текст — alt картинки или подпись aria-label у самой ссылки.
			if ( preg_match( '#\baria-label\s*=\s*(["\'])\s*\S.*?\1#isu', $attributes ) ) {
				return '';
			}

			$images = ImageFacts::extract( $inner );

			if ( array() === $images ) {
				return '';
			}

			foreach ( $images as $image ) {
				if ( '' !== trim( (string) $image['alt'] ) ) {
					return '';
				}
			}

			return 'image';
		}

		if ( preg_match( '#^(?:https?://|www\.)\S+$#iu', $text ) ) {
			return 'address';
		}

		$normalized = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower( $text, 'UTF-8' ) ) ) );

		if ( in_array( $normalized, self::VAGUE, true ) ) {
			return 'word';
		}

		return preg_match( '/^(?:нажмите|нажми|жмите|жми|кликните|кликни|смотрите|смотри|читайте|читай|перейдите|click|read)\s+(?:здесь|тут|сюда|по ссылке|here)$/u', $normalized ) ? 'word' : '';
	}
}
