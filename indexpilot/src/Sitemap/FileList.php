<?php
/**
 * Список файлов карты сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

/**
 * В список попадают только файлы, в которых есть хотя бы один адрес. Файл без адресов
 * (например, карта меток, если все метки закрыты или «тонкие») поисковик считает ошибкой.
 */
final class FileList {

	/**
	 * Оставляет файлы, в которых есть адреса.
	 *
	 * @param list<array{kind: string, name: string, page: int, lastmod: string}> $candidates  Возможные файлы по порядку.
	 * @param callable                                                            $has_entries Есть ли в файле хотя бы один адрес: получает файл, возвращает bool.
	 * @return list<array{kind: string, name: string, page: int, lastmod: string}>
	 */
	public static function build( array $candidates, callable $has_entries ): array {
		$files = array();

		foreach ( $candidates as $candidate ) {
			if ( $has_entries( $candidate ) ) {
				$files[] = $candidate;
			}
		}

		return $files;
	}
}
