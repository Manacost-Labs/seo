<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Schema;

use IndexPilot\Frontend\FaqBlock;
use IndexPilot\Game\MaterialType;
use IndexPilot\Schema\ArticleType;
use IndexPilot\Schema\GraphBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Разметка того, что редактор заполняет на вкладке «Содержание»: вопросы и ответы, источники фактов, тип материала.
 */
final class ContentSchemaTest extends TestCase {

	private const ITEMS = array(
		array(
			'question' => 'Сколько пыли стоит колода?',
			'answer'   => 'Около 6000 пыли: две легендарные карты и четыре эпические.',
		),
		array(
			'question' => 'Подходит ли колода новичку?',
			'answer'   => "Да.\nПлан игры простой.",
		),
	);

	/**
	 * @return array<string, mixed>
	 */
	private function page(): array {
		return array(
			'site'         => array(
				'url'      => 'https://site.test/',
				'name'     => 'Manacost',
				'language' => 'ru-RU',
			),
			'organization' => array( 'name' => 'Manacost' ),
			'page'         => array(
				'type'        => 'WebPage',
				'url'         => 'https://site.test/gajd-mag/',
				'title'       => 'Гайд на мага — Manacost',
				'description' => 'Колода мага: список карт и тактика.',
			),
			'breadcrumbs'  => array(),
			'article'      => array(
				'type'     => 'Article',
				'headline' => 'Гайд на мага',
				'author'   => array(
					'name' => 'Иван',
					'url'  => 'https://site.test/author/ivan/',
				),
			),
		);
	}

	public function test_questions_of_the_block_get_into_faq_page(): void {
		$page        = $this->page();
		$page['faq'] = FaqBlock::complete( self::ITEMS );

		$graph = ( new GraphBuilder() )->build( $page );
		$faq   = $graph->first( 'FAQPage' );

		self::assertNotNull( $faq );
		self::assertSame( 'https://site.test/gajd-mag/#faq', $faq['@id'] );
		self::assertSame( array( '@id' => 'https://site.test/gajd-mag/#webpage' ), $faq['isPartOf'] );
		self::assertCount( 2, $faq['mainEntity'] );
		self::assertSame( 'Question', $faq['mainEntity'][0]['@type'] );
		self::assertSame( 'Сколько пыли стоит колода?', $faq['mainEntity'][0]['name'] );
		self::assertSame( 'Answer', $faq['mainEntity'][0]['acceptedAnswer']['@type'] );
		self::assertSame( 'Около 6000 пыли: две легендарные карты и четыре эпические.', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
		self::assertSame( 'Да. План игры простой.', $faq['mainEntity'][1]['acceptedAnswer']['text'] );

		// Каждая ссылка @id ведёт на узел графа.
		self::assertSame( array(), $graph->dangling() );

		// Те же вопросы — в видимом блоке.
		$html = FaqBlock::html( $page['faq'], 'Вопросы и ответы' );
		foreach ( $faq['mainEntity'] as $question ) {
			self::assertStringContainsString( htmlspecialchars( $question['name'], ENT_QUOTES, 'UTF-8' ), $html );
		}
	}

	public function test_without_a_visible_block_there_is_no_faq_page(): void {
		$page = $this->page();

		self::assertNull( ( new GraphBuilder() )->build( $page )->first( 'FAQPage' ) );

		$page['faq'] = array();
		self::assertNull( ( new GraphBuilder() )->build( $page )->first( 'FAQPage' ) );
	}

	public function test_fact_sources_get_into_article_citation(): void {
		$page                         = $this->page();
		$page['article']['citations'] = array(
			array(
				'name' => 'HSReplay',
				'url'  => 'https://hsreplay.net/decks/',
			),
			array(
				'name' => '',
				'url'  => 'https://www.vicioussyndicate.com/',
			),
			array(
				'name' => 'Без адреса',
				'url'  => '',
			),
		);

		$graph   = ( new GraphBuilder() )->build( $page );
		$article = $graph->first( 'Article' );

		self::assertSame(
			array(
				array(
					'@type' => 'CreativeWork',
					'name'  => 'HSReplay',
					'url'   => 'https://hsreplay.net/decks/',
				),
				array(
					'@type' => 'CreativeWork',
					'url'   => 'https://www.vicioussyndicate.com/',
				),
			),
			$article['citation']
		);
		self::assertSame( array(), $graph->dangling() );
		self::assertArrayNotHasKey( 'citation', ( new GraphBuilder() )->build( $this->page() )->first( 'Article' ) );
	}

	public function test_article_type_follows_the_choice_in_the_post_panel(): void {
		// Авто: тип из настроек типа записи.
		self::assertSame( 'Article', ArticleType::resolve( '', 'Article' ) );
		self::assertSame( 'BlogPosting', ArticleType::resolve( '', 'BlogPosting' ) );
		self::assertSame( '', ArticleType::resolve( '', 'WebPage' ) );

		// Выбор у записи сильнее настроек.
		self::assertSame( 'NewsArticle', ArticleType::resolve( 'NewsArticle', 'Article' ) );
		self::assertSame( 'Article', ArticleType::resolve( ArticleType::GUIDE, 'WebPage' ), 'Гайд размечается как Article: TechArticle в справке Google не назван.' );
		self::assertSame( 'Article', ArticleType::resolve( '', 'TechArticle' ) );
		self::assertSame( 'Article', ArticleType::resolve( 'Article', 'NewsArticle' ) );
		self::assertSame( '', ArticleType::resolve( 'none', 'Article' ) );

		// Неизвестное значение считается «Авто».
		self::assertSame( '', ArticleType::sanitize( 'Recipe' ) );
		self::assertSame( 'NewsArticle', ArticleType::sanitize( 'NewsArticle' ) );
		self::assertSame( 'none', ArticleType::sanitize( 'none' ) );

		$page                    = $this->page();
		$page['article']['type'] = ArticleType::resolve( 'NewsArticle', 'Article' );

		self::assertNotNull( ( new GraphBuilder() )->build( $page )->first( 'NewsArticle' ) );
	}

	public function test_guide_is_an_article_with_a_genre(): void {
		self::assertTrue( ArticleType::is_guide( ArticleType::GUIDE, 'Article' ) );
		self::assertTrue( ArticleType::is_guide( '', 'TechArticle' ) );
		self::assertFalse( ArticleType::is_guide( '', 'Article' ) );
		self::assertFalse( ArticleType::is_guide( 'Article', 'TechArticle' ), 'Выбор «Статья» у записи сильнее настроек.' );
		self::assertSame( 'TechArticle', ArticleType::sanitize( 'TechArticle' ), 'Сохранённый раньше выбор «Гайд» остаётся выбором «Гайд».' );

		$page                     = $this->page();
		$page['article']['type']  = ArticleType::resolve( ArticleType::GUIDE, 'Article' );
		$page['article']['genre'] = 'Гайд';

		$graph = ( new GraphBuilder() )->build( $page );

		self::assertNull( $graph->first( 'TechArticle' ) );
		self::assertSame( 'Гайд', $graph->first( 'Article' )['genre'] );
		self::assertArrayNotHasKey( 'genre', ( new GraphBuilder() )->build( $this->page() )->first( 'Article' ), 'У обычной статьи жанра нет.' );
	}

	public function test_auto_type_follows_the_detected_material(): void {
		// Новость и патч при выборе «Авто» получают NewsArticle.
		self::assertSame( 'NewsArticle', ArticleType::resolve( '', 'Article', MaterialType::NEWS ) );
		self::assertSame( 'NewsArticle', ArticleType::resolve( '', 'Article', MaterialType::PATCH ) );
		self::assertSame( 'NewsArticle', ArticleType::resolve( '', 'BlogPosting', MaterialType::NEWS ) );

		// Остальное — тип из настроек типа записи.
		self::assertSame( 'Article', ArticleType::resolve( '', 'Article', MaterialType::GUIDE ) );
		self::assertSame( 'Article', ArticleType::resolve( '', 'Article', MaterialType::DECK ) );
		self::assertSame( 'BlogPosting', ArticleType::resolve( '', 'BlogPosting', MaterialType::OTHER ) );

		// Тип записей без разметки статьи новостью не становится; выбор редактора сильнее определённого типа.
		self::assertSame( '', ArticleType::resolve( '', 'WebPage', MaterialType::NEWS ) );
		self::assertSame( 'Article', ArticleType::resolve( 'Article', 'Article', MaterialType::NEWS ) );
		self::assertSame( '', ArticleType::resolve( 'none', 'Article', MaterialType::PATCH ) );
		self::assertFalse( ArticleType::is_guide( '', 'TechArticle', MaterialType::NEWS ) );
	}
}
