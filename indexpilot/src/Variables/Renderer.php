<?php
/**
 * Подстановка переменных в шаблоны заголовков и описаний.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Variables;

use IndexPilot\Resolver\Context;

/**
 * Превращает шаблон «%title% %sep% %sitename%» в готовую строку и убирает следы пустых переменных.
 * Неизвестная переменная (опечатка, остаток другого плагина) на сайт не выводится; в предпросмотре
 * (Context::$preview) она остаётся, а unknown_tokens() называет её для предупреждения.
 */
final class Renderer {

	/**
	 * Шаблон токена: латиница, цифры, подчёркивание и дефис между знаками процента.
	 */
	private const TOKEN_PATTERN = '/%([A-Za-z][A-Za-z0-9_\-]*)%/';

	/**
	 * Временная метка разделителя: символ из области частного использования Unicode.
	 */
	private const MARK = "\u{E000}";

	/**
	 * Конструктор.
	 *
	 * @param VariableRegistry $registry  Реестр переменных.
	 * @param string           $separator Разделитель для %sep%.
	 */
	public function __construct(
		private VariableRegistry $registry,
		private string $separator = '—'
	) {
	}

	/**
	 * Разделитель, которым заменяется %sep%.
	 */
	public function separator(): string {
		return trim( $this->separator );
	}

	/**
	 * Рендерит шаблон.
	 *
	 * @param string  $template Шаблон с переменными.
	 * @param Context $context  Контекст страницы.
	 */
	public function render( string $template, Context $context ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}

		$template = $this->apply_conditions( $template, $context );

		$output = preg_replace_callback(
			self::TOKEN_PATTERN,
			function ( array $matches ) use ( $context ): string {
				if ( 'sep' === $matches[1] ) {
					return self::MARK;
				}

				$value = $this->registry->resolve( $matches[1], $context );

				if ( null === $value ) {
					// Неизвестная переменная: в предпросмотре остаётся — редактор увидит её и исправит,
					// на сайт не попадает — её след убирается так же, как след пустой переменной.
					return $context->preview ? $matches[0] : '';
				}

				return str_replace( self::MARK, '', $value );
			},
			$template
		);

		return $this->tidy( (string) $output );
	}

	/**
	 * Переменные шаблона, которых нет в реестре.
	 *
	 * @param string $template Шаблон с переменными.
	 * @return list<string>
	 */
	public function unknown_tokens( string $template ): array {
		preg_match_all( self::TOKEN_PATTERN, $template, $matches );

		$unknown = array();
		foreach ( $matches[1] as $token ) {
			if ( 'sep' !== $token && ! $this->registry->has( $token ) ) {
				$unknown[ $token ] = true;
			}
		}

		return array_keys( $unknown );
	}

	/**
	 * Обрабатывает условные фрагменты {{ ... }}.
	 *
	 * Фрагмент остаётся, только если все переменные внутри него известны и не пусты.
	 * Пример: «Колода %title%{{, режим %tax_deck_mode%}}» — без режима слова «, режим» не появятся.
	 *
	 * @param string  $template Шаблон с переменными.
	 * @param Context $context  Контекст страницы.
	 */
	private function apply_conditions( string $template, Context $context ): string {
		if ( ! str_contains( $template, '{{' ) ) {
			return $template;
		}

		return (string) preg_replace_callback(
			'/\{\{(.*?)\}\}/su',
			function ( array $matches ) use ( $context ): string {
				preg_match_all( self::TOKEN_PATTERN, $matches[1], $tokens );

				foreach ( $tokens[1] as $token ) {
					if ( 'sep' !== $token && '' === (string) $this->registry->resolve( $token, $context ) ) {
						return '';
					}
				}

				return $matches[1];
			},
			$template
		);
	}

	/**
	 * Убирает следы пустых переменных: пустые скобки, висящие запятые, двойные разделители.
	 *
	 * @param string $text Строка после подстановки.
	 */
	private function tidy( string $text ): string {
		$mark = self::MARK;

		$steps = array(
			'/\s+/u'                                     => ' ',
			'/(?:\s*,\s*){2,}/u'                         => ', ',
			'/([(\[])\s*,\s*/u'                          => '$1',
			'/\s*,\s*([)\]])/u'                          => '$1',
			'/:\s*,\s*/u'                                => ': ',
			'/\(\s*\)|\[\s*\]|«\s*»/u'                   => '',
			'/\s+([,.:;!?])/u'                           => '$1',
			'/[\s,:;]+(?=' . $mark . '|$)/u'             => '',
			'/^[\s,:;]+/u'                               => '',
			'/(?:\s*' . $mark . '\s*)+/u'                => $mark,
			'/^[\s' . $mark . ']+|[\s' . $mark . ']+$/u' => '',
		);

		foreach ( $steps as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}

		$text = str_replace( $mark, ' ' . trim( $this->separator ) . ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
