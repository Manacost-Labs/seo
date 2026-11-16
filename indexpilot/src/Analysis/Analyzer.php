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
use IndexPilot\Analysis\Checks\GameChecks;
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
	 * @param \Closure|null         $matchers Поиск ключа для игры записи: fn( string $game ): ?KeywordMatcher; null — один словарь на все игры.
	 */
	public function __construct( ?array $groups = null, ?KeywordMatcher $matcher = null, private ?\Closure $matchers = null ) {
		$this->matcher = $matcher ?? new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), new Lexicon() );
		$this->groups  = $groups ?? array( new KeywordChecks(), new SnippetChecks(), new ContentChecks(), new ImageChecks(), new AnchorChecks(), new SponsoredChecks(), new VideoChecks(), new AnswerChecks(), new MaterialChecks(), new ReadabilityChecks(), new CitationChecks(), new GameChecks() );
	}

	/**
	 * Анализирует страницу.
	 *
	 * @param Paper $paper Данные страницы.
	 */
	public function analyze( Paper $paper ): Report {
		// Запись о World of Warcraft сравнивается словарём своей игры: «шп» там — жрец тьмы.
		$matcher     = ( '' !== $paper->game && null !== $this->matchers ? ( $this->matchers )( $paper->game ) : null ) ?? $this->matcher;
		$facts       = new Facts( $paper, $matcher );
		$results     = array();
		$readability = null;

		foreach ( $this->groups as $group ) {
			foreach ( $group->run( $paper, $facts, $matcher ) as $result ) {
				$results[] = $result;

				// Оценка читаемости есть, только если текст оценивался: у колод и пустых записей её нет.
				if ( $group instanceof ReadabilityChecks ) {
					$readability = (int) $facts->readability['score'];
				}
			}
		}

		$types    = class_exists( $paper->material_types ) ? $paper->material_types : MaterialType::class;
		$material = '' !== $paper->material
			? array(
				'type'  => $paper->material,
				'label' => $types::label( $paper->material ),
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
