<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Doctor;

use IndexPilot\Doctor\QueryLog;
use IndexPilot\Doctor\Token;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase {

	private const CORE = "require('wp-blog-header.php'), wp, WP->main, WP->query_posts, WP_Query->query, WP_Query->get_posts";

	public function test_plugin_queries_are_found_by_the_call_trace(): void {
		$summary = QueryLog::summarize(
			array(
				array( 'SELECT * FROM wp_posts WHERE ID = 5', 0.0020, self::CORE, 1.0, array() ),
				array( "SELECT option_value FROM wp_options\n\t\tWHERE option_name = 'indexpilot_db_version' LIMIT 1", 0.0010, "require('wp-settings.php'), do_action('init'), WP_Hook->do_action, IndexPilot\\Plugin->IndexPilot\\{closure}, IndexPilot\\Links\\Schema::maybe_install, get_option", 1.1, array() ),
				array( 'SELECT 1 FROM wp_redirection_items', 0.0005, "do_action('template_redirect'), WP_Hook->do_action, {closure:IndexPilot\\Plugin::register_hooks():405}, IndexPilot\\Redirects\\RedirectionAdapter->all", 1.2, array() ),
				// Запросы самого замера и чужого кода с похожим именем к плагину не относятся.
				array( 'UPDATE wp_options SET option_value = 1', 0.0030, "do_action('shutdown'), WP_Hook->do_action, IndexPilot\\Doctor\\Probe->finish, update_option", 1.3, array() ),
				array( 'SELECT 2', 0.0040, "require_once('plugins/indexpilot/indexpilot.php'), OtherIndexPilot_Thing->run", 1.4, array() ),
			)
		);

		self::assertSame( 4, $summary['total'], 'Запрос самого замера в общий счёт не входит.' );
		self::assertSame( 2, $summary['plugin'] );
		self::assertSame( 1.5, $summary['plugin_ms'] );
		self::assertSame( 7.5, $summary['total_ms'] );
		self::assertSame(
			array(
				array(
					'sql'    => "SELECT option_value FROM wp_options WHERE option_name = 'indexpilot_db_version' LIMIT 1",
					'ms'     => 1.0,
					'caller' => 'IndexPilot\\Links\\Schema::maybe_install',
				),
				array(
					'sql'    => 'SELECT 1 FROM wp_redirection_items',
					'ms'     => 0.5,
					'caller' => 'IndexPilot\\Redirects\\RedirectionAdapter->all',
				),
			),
			$summary['queries']
		);
	}

	public function test_core_work_started_by_the_plugin_is_not_counted(): void {
		// Язык сайта и текущего пользователя WordPress узнаёт на каждой странице сам: плагин лишь первым вызвал перевод строки.
		$locale = "do_action('plugins_loaded'), WP_Hook->do_action, IndexPilot\\Plugin::boot, IndexPilot\\Plugin->resolver, __, translate, get_translations_for_domain, _load_textdomain_just_in_time, determine_locale, get_locale, get_option";
		$user   = "do_action('plugins_loaded'), IndexPilot\\Plugin->resolver, __, translate, determine_locale, get_user_locale, wp_get_current_user, _wp_get_current_user, apply_filters('determine_current_user'), wp_validate_auth_cookie, get_user_by, WP_User::get_data_by";
		// А здесь плагин сам спрашивает пользователя: запрос его.
		$own = "IndexPilot\\Admin\\AuthorPanel->render, get_user_by, WP_User::get_data_by";

		$summary = QueryLog::summarize(
			array(
				array( "SELECT option_value FROM wp_options WHERE option_name = 'WPLANG' LIMIT 1", 0.001, $locale, 1.0, array() ),
				array( "SELECT * FROM wp_users WHERE user_login = 'admin' LIMIT 1", 0.001, $user, 1.1, array() ),
				array( 'SELECT * FROM wp_users WHERE ID = 7 LIMIT 1', 0.001, $own, 1.2, array() ),
			)
		);

		self::assertSame( 3, $summary['total'] );
		self::assertSame( 1, $summary['plugin'] );
		self::assertSame( 'IndexPilot\\Admin\\AuthorPanel->render', $summary['queries'][0]['caller'] );
	}

	public function test_empty_or_broken_log_gives_zeroes(): void {
		$summary = QueryLog::summarize( array( 'мусор', array( 'SELECT 1' ) ) );

		self::assertSame( 1, $summary['total'] );
		self::assertSame( 0, $summary['plugin'] );
		self::assertSame( array(), $summary['queries'] );
		self::assertSame( 0, QueryLog::summarize( array() )['total'] );
	}

	public function test_limit_is_four_queries(): void {
		self::assertSame( 4, QueryLog::LIMIT );
		self::assertTrue( QueryLog::within_limit( 0 ) );
		self::assertTrue( QueryLog::within_limit( 4 ) );
		self::assertFalse( QueryLog::within_limit( 5 ) );
		self::assertTrue( QueryLog::within_limit( 5, 6 ) );
	}

	public function test_long_query_text_is_cut(): void {
		$summary = QueryLog::summarize( array( array( 'SELECT ' . str_repeat( 'x', 500 ), 0.001, 'IndexPilot\\Audit\\Runner->summary', 1.0, array() ) ) );

		self::assertSame( 300, mb_strlen( $summary['queries'][0]['sql'] ) );
	}

	public function test_token_is_signed_and_expires(): void {
		$now   = 1_800_000_000;
		$token = Token::issue( 'a1b2c3d4e5f60718', $now, 'secret' );

		self::assertSame( 'a1b2c3d4e5f60718', Token::verify( $token, $now + 10, 'secret' ) );
		self::assertNull( Token::verify( $token, $now + Token::TTL + 1, 'secret' ), 'Просроченный.' );
		self::assertNull( Token::verify( $token, $now, 'other-secret' ), 'Подписан другим ключом.' );
		self::assertNull( Token::verify( str_replace( 'a1b2', 'ffff', $token ), $now, 'secret' ), 'Подменён идентификатор.' );
		self::assertNull( Token::verify( '', $now, 'secret' ) );
		self::assertNull( Token::verify( '1', $now, 'secret' ) );
		self::assertNull( Token::verify( 'a.b.c', $now, 'secret' ) );

		// Срок нельзя продлить, не зная ключа.
		$parts    = explode( '.', $token );
		$parts[1] = (string) ( (int) $parts[1] + 3600 );

		self::assertNull( Token::verify( implode( '.', $parts ), $now + Token::TTL + 1, 'secret' ) );
	}
}
