<?php
/**
 * Сборка графа разметки страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Schema;

/**
 * Собирает узлы WebSite, Organization, WebPage (или CollectionPage), BreadcrumbList, Article, Person, ImageObject, FAQPage, VideoObject
 * из готовых данных страницы. Данные из WordPress собирает PageData.
 */
final class GraphBuilder {

	/**
	 * Строит граф.
	 *
	 * @param array<string, mixed> $page Данные страницы: site, organization, page, breadcrumbs, article, profile, faq.
	 */
	public function build( array $page ): Graph {
		$graph = new Graph();
		$site  = (array) ( $page['site'] ?? array() );
		$home  = (string) ( $site['url'] ?? '' );
		$data  = (array) ( $page['page'] ?? array() );
		$url   = (string) ( $data['url'] ?? '' );

		if ( '' === $home || '' === $url ) {
			return $graph;
		}

		$language = (string) ( $site['language'] ?? '' );

		$this->add_organization( $graph, $home, (array) ( $page['organization'] ?? array() ), (string) ( $site['name'] ?? '' ) );
		$this->add_website( $graph, $home, $site );

		$image_id = $this->add_image( $graph, IdFactory::part( $url, 'primaryimage' ), $data['image'] ?? null );
		$crumb_id = $this->add_breadcrumbs( $graph, $url, (array) ( $page['breadcrumbs'] ?? array() ) );

		// На странице автора сам автор — главный объект страницы.
		$profile_id = is_array( $page['profile'] ?? null ) ? $this->add_person( $graph, $home, $page['profile'] ) : '';

		$graph->add(
			array(
				'@type'              => (string) ( $data['type'] ?? 'WebPage' ),
				'@id'                => IdFactory::part( $url, 'webpage' ),
				'url'                => $url,
				'name'               => (string) ( $data['title'] ?? '' ),
				'description'        => (string) ( $data['description'] ?? '' ),
				'inLanguage'         => $language,
				'isPartOf'           => array( '@id' => IdFactory::website( $home ) ),
				'breadcrumb'         => '' !== $crumb_id ? array( '@id' => $crumb_id ) : null,
				'primaryImageOfPage' => '' !== $image_id ? array( '@id' => $image_id ) : null,
				'mainEntity'         => '' !== $profile_id ? array( '@id' => $profile_id ) : null,
				'datePublished'      => (string) ( $data['published'] ?? '' ),
				'dateModified'       => (string) ( $data['modified'] ?? '' ),
			)
		);

		if ( is_array( $page['article'] ?? null ) ) {
			$this->add_article( $graph, $home, $url, $page['article'], $data, $language, $image_id );
		}

		$this->add_faq( $graph, $url, (array) ( $page['faq'] ?? array() ), $language );
		$this->add_videos( $graph, $url, (array) ( $page['videos'] ?? array() ) );

		return $graph;
	}

	/**
	 * Узлы VideoObject встроенных роликов. Ролик без названия, превью или даты загрузки
	 * (обязательные поля Google) в разметку не попадает.
	 *
	 * @param Graph             $graph  Граф.
	 * @param string            $url    Адрес страницы.
	 * @param array<int, mixed> $videos Ролики из меты записи (VideoData).
	 */
	private function add_videos( Graph $graph, string $url, array $videos ): void {
		$number = 0;

		foreach ( VideoData::stored( $videos ) as $video ) {
			if ( ! VideoData::is_complete( $video ) ) {
				continue;
			}

			$node             = VideoData::node( $video, IdFactory::part( $url, 'video-' . ( ++$number ) ) );
			$node['isPartOf'] = array( '@id' => IdFactory::part( $url, 'webpage' ) );

			$graph->add( $node );
		}
	}

	/**
	 * Узел организации и её логотипа.
	 *
	 * @param Graph                $graph        Граф.
	 * @param string               $home         Адрес главной страницы.
	 * @param array<string, mixed> $organization Данные организации.
	 * @param string               $site_name    Название сайта — запасное название организации.
	 */
	private function add_organization( Graph $graph, string $home, array $organization, string $site_name ): void {
		$logo_id = $this->add_image( $graph, IdFactory::logo( $home ), $organization['logo'] ?? null );
		$name    = (string) ( $organization['name'] ?? '' );

		$graph->add(
			array(
				'@type'  => 'Organization',
				'@id'    => IdFactory::organization( $home ),
				'name'   => '' !== $name ? $name : $site_name,
				'url'    => $home,
				'logo'   => '' !== $logo_id ? array( '@id' => $logo_id ) : null,
				'sameAs' => array_values( array_filter( array_map( 'strval', (array) ( $organization['same_as'] ?? array() ) ), 'strlen' ) ),
			)
		);
	}

	/**
	 * Узел сайта.
	 *
	 * @param Graph                $graph Граф.
	 * @param string               $home  Адрес главной страницы.
	 * @param array<string, mixed> $site  Данные сайта.
	 */
	private function add_website( Graph $graph, string $home, array $site ): void {
		$graph->add(
			array(
				'@type'       => 'WebSite',
				'@id'         => IdFactory::website( $home ),
				'url'         => $home,
				'name'        => (string) ( $site['name'] ?? '' ),
				'description' => (string) ( $site['description'] ?? '' ),
				'inLanguage'  => (string) ( $site['language'] ?? '' ),
				'publisher'   => array( '@id' => IdFactory::organization( $home ) ),
			)
		);
	}

	/**
	 * Узел картинки. Возвращает его @id или пустую строку, если картинки нет.
	 *
	 * @param Graph  $graph Граф.
	 * @param string $id    Идентификатор узла.
	 * @param mixed  $image Данные картинки: url, width, height.
	 */
	private function add_image( Graph $graph, string $id, mixed $image ): string {
		if ( ! is_array( $image ) || '' === (string) ( $image['url'] ?? '' ) ) {
			return '';
		}

		$graph->add(
			array(
				'@type'      => 'ImageObject',
				'@id'        => $id,
				'url'        => (string) $image['url'],
				'contentUrl' => (string) $image['url'],
				'width'      => (int) ( $image['width'] ?? 0 ) > 0 ? (int) $image['width'] : null,
				'height'     => (int) ( $image['height'] ?? 0 ) > 0 ? (int) $image['height'] : null,
			)
		);

		return $id;
	}

	/**
	 * Узел хлебных крошек. Цепочка из одной главной страницы не выводится.
	 *
	 * @param Graph                                  $graph  Граф.
	 * @param string                                 $url    Адрес страницы.
	 * @param array<int, array{name: string, url: string}> $crumbs Цепочка.
	 * @return string Идентификатор узла или пустая строка.
	 */
	private function add_breadcrumbs( Graph $graph, string $url, array $crumbs ): string {
		if ( count( $crumbs ) < 2 ) {
			return '';
		}

		$items    = array();
		$position = 0;

		foreach ( $crumbs as $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => ++$position,
				'name'     => (string) $crumb['name'],
				'item'     => (string) ( $crumb['url'] ?? '' ),
			);
		}

		$id = IdFactory::part( $url, 'breadcrumb' );

		$graph->add(
			array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $id,
				'itemListElement' => $items,
			)
		);

		return $id;
	}

	/**
	 * Узел автора: имя, страница на сайте, рассказ о себе, роль, фото и ссылки на его площадки.
	 * Возвращает @id узла или пустую строку, если имени нет.
	 *
	 * @param Graph                $graph  Граф.
	 * @param string               $home   Адрес главной страницы.
	 * @param array<string, mixed> $person Данные автора: name, url, description, role, image, same_as.
	 */
	private function add_person( Graph $graph, string $home, array $person ): string {
		$name = (string) ( $person['name'] ?? '' );

		if ( '' === $name ) {
			return '';
		}

		$url      = (string) ( $person['url'] ?? '' );
		$id       = IdFactory::person( $home, '' !== $url ? $url : $name );
		$image_id = $this->add_image( $graph, $id . '/image', $person['image'] ?? null );

		$graph->add(
			array(
				'@type'       => 'Person',
				'@id'         => $id,
				'name'        => $name,
				'url'         => $url,
				'description' => (string) ( $person['description'] ?? '' ),
				'jobTitle'    => (string) ( $person['role'] ?? '' ),
				'image'       => '' !== $image_id ? array( '@id' => $image_id ) : null,
				'sameAs'      => array_values( array_filter( array_map( 'strval', (array) ( $person['same_as'] ?? array() ) ), 'strlen' ) ),
			)
		);

		return $id;
	}

	/**
	 * Узлы статьи и её автора.
	 *
	 * @param Graph                $graph    Граф.
	 * @param string               $home     Адрес главной страницы.
	 * @param string               $url      Адрес страницы.
	 * @param array<string, mixed> $article  Данные статьи.
	 * @param array<string, mixed> $data     Данные страницы.
	 * @param string               $language Язык.
	 * @param string               $image_id Идентификатор узла картинки или пустая строка.
	 */
	private function add_article( Graph $graph, string $home, string $url, array $article, array $data, string $language, string $image_id ): void {
		$author_id = $this->add_person( $graph, $home, (array) ( $article['author'] ?? array() ) );

		$graph->add(
			array(
				'@type'            => (string) ( $article['type'] ?? 'Article' ),
				'@id'              => IdFactory::part( $url, 'article' ),
				'headline'         => (string) ( $article['headline'] ?? '' ),
				'description'      => (string) ( $data['description'] ?? '' ),
				'inLanguage'       => $language,
				'mainEntityOfPage' => array( '@id' => IdFactory::part( $url, 'webpage' ) ),
				'isPartOf'         => array( '@id' => IdFactory::part( $url, 'webpage' ) ),
				'author'           => '' !== $author_id ? array( '@id' => $author_id ) : null,
				'publisher'        => array( '@id' => IdFactory::organization( $home ) ),
				'image'            => '' !== $image_id ? array( '@id' => $image_id ) : null,
				'datePublished'    => (string) ( $data['published'] ?? '' ),
				'dateModified'     => (string) ( $data['modified'] ?? '' ),
				'articleSection'   => (string) ( $article['section'] ?? '' ),
				'genre'            => (string) ( $article['genre'] ?? '' ),
				'wordCount'        => (int) ( $article['word_count'] ?? 0 ) > 0 ? (int) $article['word_count'] : null,
				'citation'         => $this->citations( (array) ( $article['citations'] ?? array() ) ),
			)
		);
	}

	/**
	 * Источники, на которые ссылается статья: у каждого есть адрес.
	 *
	 * @param array<int, mixed> $citations Источники: name, url.
	 * @return list<array<string, string>>
	 */
	private function citations( array $citations ): array {
		$items = array();

		foreach ( $citations as $citation ) {
			$url = is_array( $citation ) ? trim( (string) ( $citation['url'] ?? '' ) ) : '';

			if ( '' !== $url ) {
				$items[] = array(
					'@type' => 'CreativeWork',
					'name'  => trim( (string) ( $citation['name'] ?? '' ) ),
					'url'   => $url,
				);
			}
		}

		return $items;
	}

	/**
	 * Узел вопросов и ответов. Добавляется только для блока, который виден на странице: в данные страницы
	 * попадают те же пары, что выведены читателю.
	 *
	 * @param Graph             $graph    Граф.
	 * @param string            $url      Адрес страницы.
	 * @param array<int, mixed> $items    Пары: question, answer.
	 * @param string            $language Язык.
	 */
	private function add_faq( Graph $graph, string $url, array $items, string $language ): void {
		$questions = array();

		foreach ( $items as $item ) {
			$question = is_array( $item ) ? self::line( (string) ( $item['question'] ?? '' ) ) : '';
			$answer   = is_array( $item ) ? self::line( (string) ( $item['answer'] ?? '' ) ) : '';

			if ( '' !== $question && '' !== $answer ) {
				$questions[] = array(
					'@type'          => 'Question',
					'name'           => $question,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $answer,
					),
				);
			}
		}

		if ( array() === $questions ) {
			return;
		}

		$graph->add(
			array(
				'@type'      => 'FAQPage',
				'@id'        => IdFactory::part( $url, 'faq' ),
				'url'        => $url,
				'inLanguage' => $language,
				'isPartOf'   => array( '@id' => IdFactory::part( $url, 'webpage' ) ),
				'mainEntity' => $questions,
			)
		);
	}

	/**
	 * Текст в одну строку.
	 *
	 * @param string $text Текст.
	 */
	private static function line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
