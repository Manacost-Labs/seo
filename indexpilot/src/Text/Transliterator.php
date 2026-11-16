<?php
/**
 * Транслитерация по тем же правилам, что у плагина Cyr-To-Lat на сайтах.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Text;

/**
 * Нужна, чтобы сравнивать русское ключевое слово с латинским слагом записи.
 */
final class Transliterator {

	/**
	 * Таблица ISO 9 в варианте Cyr-To-Lat («ц» → «cz», «щ» → «shh», «й» → «j»).
	 */
	private const TABLE = array(
		'а' => 'a',
		'б' => 'b',
		'в' => 'v',
		'г' => 'g',
		'д' => 'd',
		'е' => 'e',
		'ё' => 'yo',
		'ж' => 'zh',
		'з' => 'z',
		'и' => 'i',
		'й' => 'j',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'о' => 'o',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'у' => 'u',
		'ф' => 'f',
		'х' => 'h',
		'ц' => 'cz',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'shh',
		'ъ' => '',
		'ы' => 'y',
		'ь' => '',
		'э' => 'e',
		'ю' => 'yu',
		'я' => 'ya',
	);

	/**
	 * Переводит кириллицу в латиницу, регистр приводится к нижнему.
	 *
	 * @param string $text Исходный текст.
	 */
	public static function to_latin( string $text ): string {
		return strtr( mb_strtolower( $text ), self::TABLE );
	}

	/**
	 * Делает из текста слаг: латиница, цифры и дефисы.
	 *
	 * @param string $text Исходный текст.
	 */
	public static function slugify( string $text ): string {
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', self::to_latin( $text ) );

		return trim( $slug, '-' );
	}
}
