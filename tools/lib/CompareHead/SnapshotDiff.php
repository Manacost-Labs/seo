<?php
/**
 * Сравнение двух снимков тегов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tools\CompareHead;

/**
 * Сравнивает снимки «до» и «после» по каждому адресу: что пропало, изменилось, добавилось.
 *
 * Снимок — массив `pages`: адрес → `{ status, final_url, error?, head: { ключ → строка } }`.
 * Для сравнения статус и итоговый адрес идут вместе с тегами как обычные поля.
 */
final class SnapshotDiff {

	/**
	 * Сравнивает снимки.
	 *
	 * @param array<string, mixed> $before Снимок «до».
	 * @param array<string, mixed> $after  Снимок «после».
	 * @return array{pages: array<string, array{missing?: string, removed: array<string, string>, changed: array<string, array{0: string, 1: string}>, added: array<string, string>, lost_title: bool, lost_description: bool}>, summary: array{urls: int, changed: int, lost_title: int, lost_description: int, lost_any: int, missing: int}}
	 */
	public static function compare( array $before, array $after ): array {
		$before_pages = is_array( $before['pages'] ?? null ) ? $before['pages'] : array();
		$after_pages  = is_array( $after['pages'] ?? null ) ? $after['pages'] : array();

		$pages   = array();
		$summary = array(
			'urls'             => 0,
			'changed'          => 0,
			'lost_title'       => 0,
			'lost_description' => 0,
			'lost_any'         => 0,
			'missing'          => 0,
		);

		$urls = array_values( array_unique( array_merge( array_keys( $before_pages ), array_keys( $after_pages ) ) ) );

		foreach ( $urls as $url ) {
			$url = (string) $url;
			++$summary['urls'];

			$old = isset( $before_pages[ $url ] ) && is_array( $before_pages[ $url ] ) ? self::fields( $before_pages[ $url ] ) : null;
			$new = isset( $after_pages[ $url ] ) && is_array( $after_pages[ $url ] ) ? self::fields( $after_pages[ $url ] ) : null;

			$page = array(
				'removed'          => array(),
				'changed'          => array(),
				'added'            => array(),
				'lost_title'       => false,
				'lost_description' => false,
			);

			if ( null === $old || null === $new ) {
				$page['missing'] = null === $old ? 'before' : 'after';
				++$summary['missing'];
			}

			$old = $old ?? array();
			$new = $new ?? array();

			foreach ( self::ordered_keys( $old, $new ) as $key ) {
				$was = $old[ $key ] ?? '';
				$now = $new[ $key ] ?? '';

				if ( $was === $now ) {
					continue;
				}

				if ( '' === $now ) {
					$page['removed'][ $key ] = $was;
				} elseif ( '' === $was ) {
					$page['added'][ $key ] = $now;
				} else {
					$page['changed'][ $key ] = array( $was, $now );
				}
			}

			$page['lost_title']       = '' !== ( $old['title'] ?? '' ) && '' === ( $new['title'] ?? '' );
			$page['lost_description'] = '' !== ( $old['description'] ?? '' ) && '' === ( $new['description'] ?? '' );

			$summary['lost_title']       += (int) $page['lost_title'];
			$summary['lost_description'] += (int) $page['lost_description'];
			$summary['lost_any']         += (int) ( $page['lost_title'] || $page['lost_description'] );

			if ( isset( $page['missing'] ) || $page['removed'] || $page['changed'] || $page['added'] ) {
				++$summary['changed'];
			}

			$pages[ $url ] = $page;
		}

		return array(
			'pages'   => $pages,
			'summary' => $summary,
		);
	}

	/**
	 * Текстовый отчёт.
	 *
	 * @param array{pages: array<string, array<string, mixed>>, summary: array<string, int>} $report Результат compare().
	 */
	public static function render( array $report ): string {
		$lines = array();

		foreach ( $report['pages'] as $url => $page ) {
			$block = array();

			if ( isset( $page['missing'] ) ) {
				$block[] = 'before' === $page['missing'] ? '  ! адреса нет в снимке «до»' : '  ! адреса нет в снимке «после»';
			}

			foreach ( $page['removed'] as $key => $value ) {
				$block[] = sprintf( '  - пропало     %s: %s', $key, self::short( $value ) );
			}

			foreach ( $page['changed'] as $key => $pair ) {
				$block[] = sprintf( '  ~ изменилось  %s: %s → %s', $key, self::short( $pair[0] ), self::short( $pair[1] ) );
			}

			foreach ( $page['added'] as $key => $value ) {
				$block[] = sprintf( '  + добавилось  %s: %s', $key, self::short( $value ) );
			}

			if ( $page['lost_title'] ) {
				$block[] = '  !! потерян заголовок';
			}

			if ( $page['lost_description'] ) {
				$block[] = '  !! потеряно описание';
			}

			if ( $block ) {
				$lines[] = $url;
				array_push( $lines, ...$block );
				$lines[] = '';
			}
		}

		$summary = $report['summary'];
		$lines[] = sprintf( 'Адресов: %d, с различиями: %d, без пары в другом снимке: %d.', $summary['urls'], $summary['changed'], $summary['missing'] );
		$lines[] = sprintf(
			'Потеряли заголовок или описание: %d (заголовок — %d, описание — %d).',
			$summary['lost_any'],
			$summary['lost_title'],
			$summary['lost_description']
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Поля страницы для сравнения: статус, итоговый адрес, ошибка и теги.
	 *
	 * @param array<string, mixed> $page Страница из снимка.
	 * @return array<string, string>
	 */
	private static function fields( array $page ): array {
		$fields = array(
			'status'    => (string) ( $page['status'] ?? '' ),
			'final_url' => (string) ( $page['final_url'] ?? '' ),
		);

		if ( ! empty( $page['error'] ) ) {
			$fields['error'] = (string) $page['error'];
		}

		foreach ( (array) ( $page['head'] ?? array() ) as $key => $value ) {
			$fields[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $fields;
	}

	/**
	 * Ключи обоих снимков: сначала статус и основные теги, затем остальные по алфавиту.
	 *
	 * @param array<string, string> $old Поля «до».
	 * @param array<string, string> $now Поля «после».
	 * @return list<string>
	 */
	private static function ordered_keys( array $old, array $now ): array {
		$first = array( 'status', 'final_url', 'error' );

		foreach ( HeadParser::CORE as $key ) {
			$first[] = $key;
			$first[] = $key . '_count';
		}

		$first[] = 'h1_count';
		$first[] = 'jsonld_types';

		$rest = array_diff( array_unique( array_merge( array_keys( $old ), array_keys( $now ) ) ), $first );
		sort( $rest, SORT_STRING );

		return array_merge( $first, $rest );
	}

	/**
	 * Укорачивает длинное значение для отчёта.
	 *
	 * @param string $value Значение.
	 */
	private static function short( string $value ): string {
		return '«' . ( mb_strlen( $value ) > 160 ? mb_substr( $value, 0, 157 ) . '…' : $value ) . '»';
	}
}
