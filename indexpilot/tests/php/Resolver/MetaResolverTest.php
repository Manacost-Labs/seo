<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Resolver;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Settings\Settings;
use IndexPilot\Tests\Support\ArrayDataSource;
use IndexPilot\Variables\Renderer;
use IndexPilot\Variables\VariableRegistry;
use PHPUnit\Framework\TestCase;

final class MetaResolverTest extends TestCase {

	private const LONG_CONTENT = '<h2>Вступление</h2><p>Маг на заклинаниях держит темп с первых ходов. Колода наказывает медленные архетипы и закрывает игру к восьмому ходу.</p><p>Третье предложение нужно только для того, чтобы длина текста заметно превысила предел в сто пятьдесят пять символов.</p>';

	/**
	 * @param array<string, mixed> $saved
	 */
	private function resolver( ArrayDataSource $data, array $saved = array(), string $object_title = 'Колода мага' ): MetaResolver {
		$registry = new VariableRegistry();
		$registry->register( 'title', static fn(): string => $object_title );
		$registry->register( 'term', static fn(): string => $object_title );
		$registry->register( 'sitename', static fn(): string => 'Manacost' );
		$registry->register( 'tagline', static fn(): string => 'Всё о Hearthstone' );
		$registry->register( 'page', static fn( Context $c ): string => $c->page > 1 ? 'страница ' . $c->page : '' );

		return new MetaResolver( new Settings( $saved ), new Renderer( $registry, '—' ), $data );
	}

	private function post(): Context {
		return new Context( Context::POST, 7, 'post' );
	}

	public function test_title_comes_from_the_post_type_template_by_default(): void {
		$meta = $this->resolver( new ArrayDataSource() )->resolve( $this->post() );

		self::assertSame( 'Колода мага — Manacost', $meta->title );
		self::assertSame( 'template', $meta->title_source );
	}

	public function test_custom_title_beats_the_template_and_may_use_variables(): void {
		$data = new ArrayDataSource( array( MetaKeys::TITLE => 'Лучший маг сезона %sep% %sitename%' ) );
		$meta = $this->resolver( $data )->resolve( $this->post() );

		self::assertSame( 'Лучший маг сезона — Manacost', $meta->title );
		self::assertSame( 'custom', $meta->title_source );
	}

	public function test_description_priority_is_custom_then_template_then_auto(): void {
		$texts = array(
			'excerpt' => 'Отрывок записи.',
			'content' => self::LONG_CONTENT,
		);

		$custom = $this->resolver( new ArrayDataSource( array( MetaKeys::DESCRIPTION => 'Ручное описание.' ), $texts ) )->resolve( $this->post() );
		self::assertSame( array( 'Ручное описание.', 'custom' ), array( $custom->description, $custom->description_source ) );

		$saved    = array( 'post_types' => array( 'post' => array( 'description' => 'Гайд: %title% на %sitename%.' ) ) );
		$template = $this->resolver( new ArrayDataSource( array(), $texts ), $saved )->resolve( $this->post() );
		self::assertSame( array( 'Гайд: Колода мага на Manacost.', 'template' ), array( $template->description, $template->description_source ) );

		$auto = $this->resolver( new ArrayDataSource( array(), $texts ) )->resolve( $this->post() );
		self::assertSame( array( 'Отрывок записи.', 'auto' ), array( $auto->description, $auto->description_source ) );
	}

	public function test_auto_description_falls_back_to_content_and_is_cut_by_sentence(): void {
		$meta = $this->resolver( new ArrayDataSource( array(), array( 'content' => self::LONG_CONTENT ) ) )->resolve( $this->post() );

		self::assertSame(
			'Маг на заклинаниях держит темп с первых ходов. Колода наказывает медленные архетипы и закрывает игру к восьмому ходу.',
			$meta->description
		);
	}

	public function test_private_post_has_no_description_and_is_not_indexed(): void {
		$data = new ArrayDataSource( array(), array( 'content' => self::LONG_CONTENT ), 'https://example.test/item/', true );
		$meta = $this->resolver( $data )->resolve( $this->post() );

		self::assertSame( '', $meta->description );
		self::assertFalse( $meta->is_indexable() );
	}

	public function test_default_robots_are_index_follow_with_preview_limits(): void {
		$meta = $this->resolver( new ArrayDataSource() )->resolve( $this->post() );

		self::assertTrue( $meta->is_indexable() );
		self::assertSame( 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1', $meta->robots_string() );
	}

	public function test_noindex_and_nofollow_meta_are_respected(): void {
		$data = new ArrayDataSource(
			array(
				MetaKeys::NOINDEX  => '1',
				MetaKeys::NOFOLLOW => '1',
			)
		);

		self::assertSame( 'noindex, nofollow', $this->resolver( $data )->resolve( $this->post() )->robots_string() );
	}

	public function test_second_page_of_a_category_is_followed_and_has_its_own_canonical_and_title(): void {
		$data    = new ArrayDataSource( array(), array( 'term_description' => 'Все гайды по Hearthstone.' ), 'https://example.test/category/gajdy/', false, 40 );
		$context = new Context( Context::TERM, 3, 'category', 2 );
		$meta    = $this->resolver( $data, array(), 'Гайды' )->resolve( $context );

		self::assertSame( 'https://example.test/category/gajdy/page/2/', $meta->canonical );
		self::assertContains( 'follow', $meta->robots );
		self::assertNotContains( 'nofollow', $meta->robots );
		self::assertTrue( $meta->is_indexable() );
		self::assertSame( 'Гайды — страница 2 — Manacost', $meta->title );
		self::assertSame( 'Все гайды по Hearthstone. Страница 2.', $meta->description );
	}

	public function test_paged_archives_can_be_closed_but_stay_followed(): void {
		$data    = new ArrayDataSource( array(), array(), 'https://example.test/category/gajdy/', false, 40 );
		$context = new Context( Context::TERM, 3, 'category', 2 );
		$saved   = array( 'general' => array( 'index_paged_archives' => false ) );

		self::assertSame( 'noindex, follow', $this->resolver( $data, $saved, 'Гайды' )->resolve( $context )->robots_string() );
	}

	public function test_custom_title_on_a_paged_archive_gets_the_page_number(): void {
		$data    = new ArrayDataSource( array( MetaKeys::TITLE => 'Гайды Hearthstone' ), array(), 'https://example.test/category/gajdy/', false, 40 );
		$context = new Context( Context::TERM, 3, 'category', 3 );

		self::assertSame( 'Гайды Hearthstone — страница 3', $this->resolver( $data, array(), 'Гайды' )->resolve( $context )->title );
	}

	public function test_thin_term_archive_is_noindex_follow(): void {
		$data    = new ArrayDataSource( array(), array(), 'https://example.test/tag/redkaya/', false, 2 );
		$context = new Context( Context::TERM, 9, 'post_tag' );
		$meta    = $this->resolver( $data, array(), 'Редкая метка' )->resolve( $context );

		self::assertSame( 'noindex, follow', $meta->robots_string() );

		$data->count = 3;
		self::assertTrue( $this->resolver( $data, array(), 'Редкая метка' )->resolve( $context )->is_indexable() );
	}

	public function test_term_without_description_gets_the_fallback_text(): void {
		$data    = new ArrayDataSource( array(), array(), 'https://example.test/category/novosti/', false, 40 );
		$context = new Context( Context::TERM, 4, 'category' );
		$meta    = $this->resolver( $data, array(), 'Новости' )->resolve( $context );

		self::assertSame( 'Новости — все материалы раздела на сайте Manacost.', $meta->description );
	}

	public function test_search_results_are_not_indexed_and_have_no_canonical(): void {
		$meta = $this->resolver( new ArrayDataSource( array(), array(), '' ), array(), 'Поиск: маг' )->resolve( new Context( Context::SEARCH ) );

		self::assertSame( 'noindex, follow', $meta->robots_string() );
		self::assertSame( '', $meta->canonical );
	}

	public function test_custom_canonical_is_used_for_the_first_page_only(): void {
		$data = new ArrayDataSource( array( MetaKeys::CANONICAL => 'https://kolodahearthstone.com/razbor/' ) );

		self::assertSame( 'https://kolodahearthstone.com/razbor/', $this->resolver( $data )->resolve( $this->post() )->canonical );
	}

	public function test_open_graph_falls_back_to_seo_values(): void {
		$data = new ArrayDataSource( array(), array( 'excerpt' => 'Отрывок записи.' ), 'https://example.test/item/', false, -1, 55 );
		$meta = $this->resolver( $data )->resolve( $this->post() );

		self::assertSame( 'Колода мага', $meta->og_title );
		self::assertSame( 'Отрывок записи.', $meta->og_description );
		self::assertSame( 55, $meta->og_image_id );
		self::assertSame( 'article', $meta->og_type );

		$data->meta = array(
			MetaKeys::OG_TITLE    => 'Для соцсетей',
			MetaKeys::OG_IMAGE_ID => 77,
		);
		$custom     = $this->resolver( $data )->resolve( $this->post() );
		self::assertSame( array( 'Для соцсетей', 77 ), array( $custom->og_title, $custom->og_image_id ) );
	}

	public function test_keywords_are_focus_keyword_plus_additional(): void {
		$data = new ArrayDataSource(
			array(
				MetaKeys::FOCUS_KEYWORD => 'колода мага',
				MetaKeys::KEYWORDS      => array( 'берн маг', 'колода мага' ),
			)
		);

		self::assertSame( array( 'колода мага', 'берн маг' ), $this->resolver( $data )->resolve( $this->post() )->keywords );
	}

	public function test_filter_can_change_resolved_values(): void {
		$registry = new VariableRegistry();
		$registry->register( 'title', static fn(): string => 'Колода мага' );
		$registry->register( 'sitename', static fn(): string => 'Manacost' );
		$registry->register( 'page', static fn(): string => '' );

		$filter   = static fn( string $hook, mixed $value ): mixed => 'indexpilot_title' === $hook ? $value . '!' : $value;
		$resolver = new MetaResolver( new Settings(), new Renderer( $registry, '—' ), new ArrayDataSource(), 'страница %d', $filter );

		self::assertSame( 'Колода мага — Manacost!', $resolver->resolve( $this->post() )->title );
	}

	public function test_indexing_agrees_with_full_resolution(): void {
		$open   = $this->resolver( new ArrayDataSource( url: 'https://example.test/open/' ) );
		$closed = $this->resolver( new ArrayDataSource( meta: array( MetaKeys::NOINDEX => '1', MetaKeys::CANONICAL => 'https://example.test/other/' ) ) );
		$context = new Context( Context::POST, 1, 'post' );

		self::assertSame(
			array(
				'indexable' => true,
				'canonical' => 'https://example.test/open/',
			),
			$open->indexing( $context )
		);
		self::assertSame( $open->resolve( $context )->is_indexable(), $open->indexing( $context )['indexable'] );
		self::assertFalse( $closed->indexing( $context )['indexable'] );
		self::assertSame( $closed->resolve( $context )->canonical, $closed->indexing( $context )['canonical'] );
	}

	public function test_answer_becomes_the_description_when_none_is_written(): void {
		$answer = 'Маг на заклинаниях выигрывает темпом. Дешёвые заклинания чистят стол, а урон в лицо закрывает игру к восьмому ходу, пока соперник не успел развернуться и собрать свою комбинацию.';
		$texts  = array(
			'excerpt' => 'Отрывок записи.',
			'content' => self::LONG_CONTENT,
		);

		// Краткий ответ идёт раньше шаблона типа записи и автоматической выжимки из текста.
		$saved = array( 'post_types' => array( 'post' => array( 'description' => 'Шаблон: %title%.' ) ) );
		$meta  = $this->resolver( new ArrayDataSource( array( MetaKeys::ANSWER => $answer ), $texts ), $saved )->resolve( $this->post() );

		self::assertSame( 'answer', $meta->description_source );
		self::assertStringStartsWith( 'Маг на заклинаниях выигрывает темпом.', $meta->description );
		self::assertLessThanOrEqual( 155, mb_strlen( $meta->description ) );

		// Написанное вручную описание сильнее.
		$custom = $this->resolver( new ArrayDataSource( array( MetaKeys::ANSWER => $answer, MetaKeys::DESCRIPTION => 'Ручное описание.' ), $texts ) )->resolve( $this->post() );
		self::assertSame( array( 'Ручное описание.', 'custom' ), array( $custom->description, $custom->description_source ) );

		// Несохранённый ответ из редактора виден в предпросмотре; переменные в ответе не подставляются.
		$draft = $this->resolver( new ArrayDataSource( array(), $texts ) )->resolve( new Context( Context::POST, 7, 'post', 1, array( 'answer' => 'Черновик ответа про %title%.' ) ) );
		self::assertSame( array( 'Черновик ответа про %title%.', 'answer' ), array( $draft->description, $draft->description_source ) );

		// У рубрик поля нет.
		$term = $this->resolver( new ArrayDataSource( array( MetaKeys::ANSWER => $answer ) ) )->resolve( new Context( Context::TERM, 3, 'category' ) );
		self::assertNotSame( 'answer', $term->description_source );

		// Закрытая запись ответ в описании не раскрывает.
		$private = $this->resolver( new ArrayDataSource( array( MetaKeys::ANSWER => $answer ), $texts, 'https://example.test/item/', true ) )->resolve( $this->post() );
		self::assertSame( '', $private->description );
	}
}
