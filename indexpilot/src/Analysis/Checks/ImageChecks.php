<?php
/**
 * Проверки картинок.
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
use IndexPilot\Text\ImageFacts;

/**
 * Alt картинок, размеры, вес файлов и миниатюра записи. Картинка-украшение (класс is-decorative,
 * role="presentation" или aria-hidden="true") без alt — норма: программе чтения с экрана её описывать незачем
 * (книга, с. 659–663). Баллы даёт только проверка alt; остальное — замечания без баллов.
 */
final class ImageChecks implements CheckGroup {

	private const GROUP = 'content';

	/**
	 * Тяжёлой считается картинка больше 300 КБ.
	 */
	public const MAX_BYTES = 300 * 1024;

	/**
	 * Наименьшая миниатюра для карточки в соцсетях.
	 */
	public const MIN_WIDTH  = 1200;
	public const MIN_HEIGHT = 630;

	/**
	 * Сколько картинок перечислять в замечании.
	 */
	private const EXAMPLES = 3;

	/**
	 * Выполняет проверки.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		unset( $facts, $matcher );

		$images  = ImageFacts::extract( $paper->content );
		$results = array();

		if ( array() !== $images ) {
			$results = $this->content_images( $images, $paper->image_bytes );
		}

		$featured = $this->featured( $paper );

		if ( null !== $featured ) {
			$results[] = $featured;
		}

		return $results;
	}

	/**
	 * Проверки картинок в тексте.
	 *
	 * @param list<array<string, mixed>> $images Картинки из ImageFacts::extract().
	 * @param array<int, int>            $bytes  Вес файлов медиатеки.
	 * @return list<Result>
	 */
	private function content_images( array $images, array $bytes ): array {
		$by_issue = array();

		foreach ( ImageFacts::issues( $images ) as $issue ) {
			$image = $images[ $issue['index'] ];

			// Картинке из медиатеки (класс wp-image-ID) WordPress сам допишет width и height при выводе.
			if ( ImageFacts::NO_SIZE === $issue['issue'] && (int) $image['attachment_id'] > 0 ) {
				continue;
			}

			$by_issue[ $issue['issue'] ][] = self::name( $image );
		}

		$results = array();
		$content = array_filter( $images, static fn( array $image ): bool => ! $image['decorative'] );

		if ( array() !== $content ) {
			$bad = array_merge( $by_issue[ ImageFacts::NO_ALT ] ?? array(), $by_issue[ ImageFacts::ALT_IS_FILENAME ] ?? array() );

			$results[] = array() === $bad
				? new Result( 'image_alt', self::GROUP, Result::GOOD, 3, 'У всех картинок есть понятное описание (alt).' )
				: new Result( 'image_alt', self::GROUP, Result::BAD, 3, sprintf( 'Картинок без описания (alt) или с именем файла вместо него: %d. Опишите, что на картинке: «Колода жреца на воскрешении — список карт». Если картинка только украшение, оставьте alt пустым и добавьте ей класс is-decorative. Картинки: %s.', count( $bad ), self::examples( $bad ) ) );
		}

		if ( isset( $by_issue[ ImageFacts::DUPLICATE_ALT ] ) ) {
			$results[] = new Result( 'image_alt_duplicate', self::GROUP, Result::OK, 0, sprintf( 'У нескольких картинок одинаковое описание (alt): %d. Опишите каждую по-своему — что именно на ней видно. Картинки: %s.', count( $by_issue[ ImageFacts::DUPLICATE_ALT ] ), self::examples( $by_issue[ ImageFacts::DUPLICATE_ALT ] ) ) );
		}

		if ( isset( $by_issue[ ImageFacts::NO_SIZE ] ) ) {
			$results[] = new Result( 'image_size', self::GROUP, Result::OK, 0, sprintf( 'У картинок не указаны размеры width и height: %d. Пока такая картинка грузится, страница «прыгает». Вставьте картинку из медиатеки или допишите размеры. Картинки: %s.', count( $by_issue[ ImageFacts::NO_SIZE ] ), self::examples( $by_issue[ ImageFacts::NO_SIZE ] ) ) );
		}

		$heavy = array();
		foreach ( $images as $image ) {
			$id = (int) $image['attachment_id'];

			if ( $id > 0 && ( $bytes[ $id ] ?? 0 ) > self::MAX_BYTES ) {
				$heavy[] = sprintf( '%s (%d КБ)', self::name( $image ), (int) round( $bytes[ $id ] / 1024 ) );
			}
		}

		if ( array() !== $heavy ) {
			$results[] = new Result( 'image_weight', self::GROUP, Result::OK, 0, sprintf( 'Тяжёлые картинки — больше 300 КБ: %d. Они замедляют загрузку страницы, особенно с телефона. Сожмите их или сохраните в WebP. Картинки: %s.', count( $heavy ), self::examples( $heavy ) ) );
		}

		return $results;
	}

	/**
	 * Миниатюра записи: есть ли она и годится ли для карточки в соцсетях.
	 *
	 * @param Paper $paper Данные страницы.
	 */
	private function featured( Paper $paper ): ?Result {
		if ( null === $paper->featured_image ) {
			return null;
		}

		if ( array() === $paper->featured_image ) {
			// Если нет и картинки для соцсетей, об этом уже говорит проверка social_image.
			return false === $paper->has_social_image
				? null
				: new Result( 'featured_image', self::GROUP, Result::OK, 0, sprintf( 'У записи нет миниатюры. Её показывают тема сайта, поиск по картинкам и лента Google Discover. Лучший размер — %d × %d.', self::MIN_WIDTH, self::MIN_HEIGHT ) );
		}

		$width  = (int) ( $paper->featured_image['width'] ?? 0 );
		$height = (int) ( $paper->featured_image['height'] ?? 0 );

		if ( $width >= self::MIN_WIDTH && $height >= self::MIN_HEIGHT ) {
			return null;
		}

		return new Result( 'featured_image', self::GROUP, Result::OK, 0, sprintf( 'Миниатюра записи маленькая: %1$d × %2$d. Для карточки в соцсетях и Google Discover нужно не меньше %3$d × %4$d.', $width, $height, self::MIN_WIDTH, self::MIN_HEIGHT ) );
	}

	/**
	 * Как назвать картинку в замечании: имя файла, иначе адрес.
	 *
	 * @param array<string, mixed> $image Картинка.
	 */
	private static function name( array $image ): string {
		$name = (string) ( $image['filename'] ?? '' );

		return '' !== $name ? $name : (string) ( $image['src'] ?? '' );
	}

	/**
	 * Первые несколько примеров через точку с запятой.
	 *
	 * @param list<string> $items Примеры.
	 */
	private static function examples( array $items ): string {
		$items = array_values( array_unique( $items ) );
		$text  = implode( '; ', array_slice( $items, 0, self::EXAMPLES ) );

		return count( $items ) > self::EXAMPLES ? $text . '…' : $text;
	}
}
