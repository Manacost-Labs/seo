<?php
/**
 * Данные поисковых систем без зависимости от WordPress.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
/** Чистый слой: стандартные PHP-функции, сообщения экранирует интерфейс. */
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.Security.EscapeOutput.ExceptionNotEscaped
namespace IndexPilot\SearchData;

final class QueryAdvisor {
	public function hints( array $rows_for_page, string $title, string $description, string $focus_keyword, callable $same ): array {
		if ( array() === $rows_for_page ) {
			return array();}
		usort( $rows_for_page, static fn( $a, $b )=>$b['impressions'] <=> $a['impressions'] );
		$top   = $rows_for_page[0];
		$hints = array();
		$add   = static function ( $type, $row, $message ) use ( &$hints ) {
			$hints[] = array(
				'type'        => $type,
				'query'       => $row['query'],
				'impressions' => (int) $row['impressions'],
				'position'    => (float) $row['position'],
				'message'     => $message,
			);
		};
		if ( ! $same( $top['query'], $title ) ) {
			$add( 'missing_in_title', $top, 'Самый частый запрос не встречается в заголовке: ' . $top['query'] );}
		foreach ( $rows_for_page as $row ) {
			if ( 200 <= $row['impressions'] && 5 >= $row['position'] && 0.02 > $row['clicks'] / $row['impressions'] ) {
				$add( 'low_ctr', $row, 'позиция ' . $row['position'] . ', а кликов мало — перепишите заголовок и описание' );}
		}
		if ( ! $same( $top['query'], $focus_keyword ) ) {
			$add( 'focus_suggestion', $top, 'Попробуйте основной ключ: ' . $top['query'] );}
		return $hints;
	}
}
