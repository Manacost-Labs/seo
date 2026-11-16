<?php
/**
 * Анализатор страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

use IndexPilot\Analysis\Checks\AnchorChecks;
use IndexPilot\Analysis\Checks\AnswerChecks;
use IndexPilot\Analysis\Checks\CitationChecks;
use IndexPilot\Analysis\Checks\ContentChecks;
use IndexPilot\Analysis\Checks\ImageChecks;
use IndexPilot\Analysis\Checks\KeywordChecks;
use IndexPilot\Analysis\Checks\MaterialChecks;
use IndexPilot\Analysis\Checks\ReadabilityChecks;
use IndexPilot\Analysis\Checks\SnippetChecks;
use IndexPilot\Analysis\Checks\SponsoredChecks;
use IndexPilot\Analysis\Checks\VideoChecks;
use IndexPilot\Analysis\Language\EnglishStemmer;
use IndexPilot\Analysis\Language\RussianStemmer;
use IndexPilot\Game\Lexicon;
use IndexPilot\Game\MaterialType;

/**
 * Запускает группы проверок и считает оценку. Работает на сервере: в редакторе, при сохранении, при импорте и в WP-CLI.
 * Группа «Читаемость» (замечания о тексте и готовность к цитированию) оценивается отдельно и оценку SEO не меняет.
 */
final class Analyzer {

	/**
	 * Поиск ключа с учётом словоформ.
	 *
	 * @var KeywordMatcher
	 */
	private KeywordMatcher $matcher;

	/**
	 * Группы проверок.
	 *
	 * @var list<CheckGroup>
	 */
	private array $groups;

	/**
	 * Конструктор.
	 *
	 * @param list<CheckGroup>|null $groups  Группы проверок; null — стандартный набор.
	 * @param KeywordMatcher|null   $matcher Поиск ключа; null — русский и английский стеммеры и встроенный игровой словарь.
	 */
	public function __construct( ?array $groups = null, ?KeywordMatcher $matcher = null ) {
		$this->matcher = $matcher ?? new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), new Lexicon() );
		$this->groups  = $groups ?? array( new KeywordChecks(), new SnippetChecks(), new ContentChecks(), new ImageChecks(), new AnchorChecks(), new SponsoredChecks(), new VideoChecks(), new AnswerChecks(), new MaterialChecks(), new ReadabilityChecks(), new CitationChecks() );
	}

	/**
	 * Анализирует страницу.
	 *
	 * @param Paper $paper Данные страницы.
	 */
	public function analyze( Paper $paper ): Report {
		$facts       = new Facts( $paper, $this->matcher );
		$results     = array();
		$readability = null;

		foreach ( $this->groups as $group ) {
			foreach ( $group->run( $paper, $facts, $this->matcher ) as $result ) {
				$results[] = $result;

				// Оценка читаемости есть, только если текст оценивался: у колод и пустых записей её нет.
				if ( $group instanceof ReadabilityChecks ) {
					$readability = (int) $facts->readability['score'];
				}
			}
		}

		$material = '' !== $paper->material
			? array(
				'type'  => $paper->material,
				'label' => MaterialType::label( $paper->material ),
			)
			: null;

		return new Report( $results, $readability, $material );
	}

	/**
	 * Поиск ключа — тот же, которым пользуются проверки.
	 */
	public function matcher(): KeywordMatcher {
		return $this->matcher;
	}
}
