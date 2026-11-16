<?php
/**
 * Перенос правил плагина Redirection в свою таблицу редиректов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

use IndexPilot\Redirects\Manager;
use IndexPilot\Redirects\Matcher;
use IndexPilot\Redirects\Store;

/**
 * Переносит правила с теми же признаками: шаблон, выключено, код ответа, число срабатываний.
 * После переноса цепочки схлопываются, правила-петли выключаются. Повторный перенос ничего не дублирует.
 */
final class RedirectionImporter {

	public const NOTE = 'из Redirection';

	/**
	 * Как переносятся коды перенаправления Redirection.
	 */
	private const CODES = array(
		301 => 301,
		302 => 302,
		303 => 302,
		304 => 301,
		307 => 307,
		308 => 301,
	);

	public function __construct( private Store $store, private Manager $manager ) {
	}

	/**
	 * Переносит строки таблицы Redirection.
	 *
	 * @param list<array<string, mixed>> $rows Строки таблицы {prefix}redirection_items.
	 * @return array{imported: int, skipped: int, duplicates: int, collapsed: int, loops: int}
	 */
	public function import( array $rows ): array {
		$result = array(
			'imported'   => 0,
			'skipped'    => 0,
			'duplicates' => 0,
			'collapsed'  => 0,
			'loops'      => 0,
		);

		$patterns = array();
		foreach ( $this->store->all() as $rule ) {
			if ( ! empty( $rule['regex'] ) ) {
				$patterns[ (string) $rule['source'] ] = true;
			}
		}

		foreach ( $rows as $row ) {
			$rule = self::convert( $row );

			if ( null === $rule ) {
				++$result['skipped'];
				continue;
			}

			$exists = $rule['regex'] ? isset( $patterns[ $rule['source'] ] ) : null !== $this->store->find_by_source( Matcher::key( $rule['source'] ) );

			if ( $exists ) {
				++$result['duplicates'];
				continue;
			}

			$this->store->insert( $rule );

			if ( $rule['regex'] ) {
				$patterns[ $rule['source'] ] = true;
			}

			++$result['imported'];
		}

		return array_merge( $result, $this->manager->collapse_all() );
	}

	/**
	 * Правило Redirection в виде своего правила; null — такое правило не переносится.
	 *
	 * @param array<string, mixed> $row Строка таблицы Redirection.
	 * @return array<string, mixed>|null
	 */
	public static function convert( array $row ): ?array {
		$source = trim( (string) ( $row['url'] ?? '' ) );
		$regex  = ! empty( $row['regex'] );
		$type   = (string) ( $row['action_type'] ?? '' );
		$code   = (int) ( $row['action_code'] ?? 0 );
		$target = trim( (string) ( $row['action_data'] ?? '' ) );

		// Условия (вход в систему, браузер, источник перехода) свой менеджер не поддерживает.
		if ( '' === $source || 'url' !== (string) ( $row['match_type'] ?? 'url' ) ) {
			return null;
		}

		if ( $regex ? ! Matcher::valid_pattern( $source ) : ! str_starts_with( $source, '/' ) ) {
			return null;
		}

		if ( 'error' === $type && Matcher::is_gone( $code ) ) {
			$target = '';
		} elseif ( 'url' === $type && isset( self::CODES[ $code ] ) && '' !== $target ) {
			$code = self::CODES[ $code ];
		} else {
			return null;
		}

		return array(
			'source'  => $source,
			'regex'   => $regex,
			'target'  => $target,
			'code'    => $code,
			'enabled' => 'enabled' === (string) ( $row['status'] ?? 'enabled' ),
			'hits'    => (int) ( $row['last_count'] ?? 0 ),
			'note'    => self::NOTE,
		);
	}
}
