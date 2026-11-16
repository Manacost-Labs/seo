<?php
/**
 * Р”Р°РЅРЅС‹Рµ РїРѕРёСЃРєРѕРІС‹С… СЃРёСЃС‚РµРј Р±РµР· Р·Р°РІРёСЃРёРјРѕСЃС‚Рё РѕС‚ WordPress.
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
			$add( 'missing_in_title', $top, 'РЎР°РјС‹Р№ С‡Р°СЃС‚С‹Р№ Р·Р°РїСЂРѕСЃ РЅРµ РІСЃС‚СЂРµС‡Р°РµС‚СЃСЏ РІ Р·Р°РіРѕР»РѕРІРєРµ: ' . $top['query'] );}
		foreach ( $rows_for_page as $row ) {
			if ( 200 <= $row['impressions'] && 5 >= $row['position'] && 0.02 > $row['clicks'] / $row['impressions'] ) {
				$add( 'low_ctr', $row, 'РїРѕР·РёС†РёСЏ ' . $row['position'] . ', Р° РєР»РёРєРѕРІ РјР°Р»Рѕ вЂ” РїРµСЂРµРїРёС€РёС‚Рµ Р·Р°РіРѕР»РѕРІРѕРє Рё РѕРїРёСЃР°РЅРёРµ' );}
		}
		if ( ! $same( $top['query'], $focus_keyword ) ) {
			$add( 'focus_suggestion', $top, 'РџРѕРїСЂРѕР±СѓР№С‚Рµ РѕСЃРЅРѕРІРЅРѕР№ РєР»СЋС‡: ' . $top['query'] );}
		return $hints;
	}
}
