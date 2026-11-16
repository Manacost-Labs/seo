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

interface Http {
	public function request( string $method, string $url, array $headers, string $body ): array;
}
