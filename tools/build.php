<?php
/**
 * Сборка пакета плагина IndexPilot.
 *
 * Запуск из корня проекта: bash tools/build.sh
 * Результат: dist/indexpilot/ (распакованный плагин) и dist/indexpilot-ВЕРСИЯ.zip.
 *
 * В пакет попадает только то, что нужно сайту: тесты, инструменты разработки и каталог vendor не входят
 * (у плагина есть собственная автозагрузка классов).
 */

declare( strict_types=1 );

$root   = dirname( __DIR__ );
$source = $root . '/indexpilot';
$dist   = $root . '/dist';
$target = $dist . '/indexpilot';

// Что входит в пакет.
$include = array( 'indexpilot.php', 'uninstall.php', 'readme.txt', 'CHANGELOG.md', 'src', 'assets' );

if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $source . '/indexpilot.php' ), $matches ) ) {
	fwrite( STDERR, "Не найдена версия в заголовке indexpilot.php\n" );
	exit( 1 );
}

$version = $matches[1];

// Номер версии должен совпадать везде, иначе архив получит одно имя, а сайт покажет другое.
$version_places = array(
	'константа INDEXPILOT_VERSION' => array( 'indexpilot.php', "/define\(\s*'INDEXPILOT_VERSION',\s*'([^']+)'/" ),
	'Plugin::VERSION'              => array( 'src/Plugin.php', "/const VERSION = '([^']+)'/" ),
	'Stable tag в readme.txt'      => array( 'readme.txt', '/^Stable tag:\s*(\S+)/m' ),
	'CHANGELOG.md'                 => array( 'CHANGELOG.md', '/^## (\S+)/m' ),
);

foreach ( $version_places as $place => list( $file, $pattern ) ) {
	if ( ! preg_match( $pattern, (string) @file_get_contents( $source . '/' . $file ), $found ) || $found[1] !== $version ) {
		fwrite( STDERR, "Версия $version из заголовка не совпадает: $place\n" );
		exit( 1 );
	}
}

/**
 * Удаляет каталог со всем содержимым.
 */
function indexpilot_remove_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $dir );
}

/**
 * Файлы пакета: относительный путь => полный путь.
 *
 * @param list<string> $include Файлы и каталоги верхнего уровня.
 * @return array<string, string>
 */
function indexpilot_package_files( string $source, array $include ): array {
	$files = array();

	foreach ( $include as $entry ) {
		$path = $source . '/' . $entry;

		if ( is_file( $path ) ) {
			$files[ $entry ] = $path;
			continue;
		}

		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$relative           = $entry . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $path ) + 1 ) );
				$files[ $relative ] = $item->getPathname();
			}
		}
	}

	ksort( $files );

	return $files;
}

indexpilot_remove_dir( $target );
@mkdir( $target, 0777, true );

$files = indexpilot_package_files( $source, $include );

foreach ( $files as $relative => $path ) {
	$destination = $target . '/' . $relative;

	if ( ! is_dir( dirname( $destination ) ) ) {
		mkdir( dirname( $destination ), 0777, true );
	}

	copy( $path, $destination );
}

$zip_path = $dist . '/indexpilot-' . $version . '.zip';
@unlink( $zip_path );

$zip = new ZipArchive();

if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Не удалось создать архив $zip_path\n" );
	exit( 1 );
}

foreach ( $files as $relative => $path ) {
	// Внутри архива плагин лежит в каталоге indexpilot: так его ждёт установщик WordPress.
	$zip->addFile( $path, 'indexpilot/' . $relative );
}

$zip->close();

printf( "IndexPilot %s: файлов %d, архив %s (%d КБ)\n", $version, count( $files ), str_replace( '\\', '/', $zip_path ), (int) round( filesize( $zip_path ) / 1024 ) );
