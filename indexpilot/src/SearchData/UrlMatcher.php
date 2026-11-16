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

final class UrlMatcher {
	private $post;
	private $term;
	private $author;
	public function __construct( callable $post, callable $term, callable $author ) {
		$this->post   = $post;
		$this->term   = $term;
		$this->author = $author;}
	public function match( string $url ): array {
		$url = preg_replace( '/[?#].*$/', '', $url );
		do {
			$old = $url;
			$url = preg_replace( '~/(?:amp|page/\d+)/?$~', '', rtrim( $url, '/' ) );
		} while ( $old !== $url );
		foreach ( array(
			'post'   => $this->post,
			'term'   => $this->term,
			'author' => $this->author,
		) as $type => $lookup ) {
			$id = (int) $lookup( $url );
			if ( 0 < $id ) {
				return array(
					'type' => $type,
					'id'   => $id,
				);}
		}
		return array(
			'type' => '' === trim( (string) parse_url( $url, PHP_URL_PATH ), '/' ) ? 'home' : 'unknown',
			'id'   => 0,
		);
	}
}
