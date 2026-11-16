<?php
/**
 * QR-код короткой ссылки.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\ShortLinks;

/**
 * Кодировщик QR (ISO/IEC 18004) без сторонних библиотек и сервисов: режим байтов, коррекция ошибок M,
 * версии 1–10 (до 213 байт — с запасом для короткой ссылки). Результат — картинка SVG для заставки стрима и печати.
 * Не зависит от WordPress.
 */
final class QrCode {

	/**
	 * Блоки коррекции по версиям для уровня M: число кодовых слов коррекции в блоке и группы [число блоков, данных в блоке].
	 */
	private const BLOCKS = array(
		1  => array( 10, array( array( 1, 16 ) ) ),
		2  => array( 16, array( array( 1, 28 ) ) ),
		3  => array( 26, array( array( 1, 44 ) ) ),
		4  => array( 18, array( array( 2, 32 ) ) ),
		5  => array( 24, array( array( 2, 43 ) ) ),
		6  => array( 16, array( array( 4, 27 ) ) ),
		7  => array( 18, array( array( 4, 31 ) ) ),
		8  => array( 22, array( array( 2, 38 ), array( 2, 39 ) ) ),
		9  => array( 22, array( array( 3, 36 ), array( 2, 37 ) ) ),
		10 => array( 26, array( array( 4, 43 ), array( 1, 44 ) ) ),
	);

	/**
	 * Центры выравнивающих узоров по версиям.
	 */
	private const ALIGNMENT = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/**
	 * Ширина рамки вокруг кода в модулях (требование стандарта).
	 */
	private const QUIET = 4;

	/**
	 * Модули: true — тёмный.
	 *
	 * @var list<list<bool>>
	 */
	private array $modules;

	/**
	 * Конструктор.
	 *
	 * @param list<list<bool>> $modules Модули.
	 * @param int              $version Версия.
	 * @param int              $mask    Маска.
	 */
	private function __construct( array $modules, private int $version, private int $mask ) {
		$this->modules = $modules;
	}

	/**
	 * Кодирует строку (UTF-8, режим байтов). Маска выбирается по наименьшему штрафу.
	 *
	 * @param string $text Строка.
	 * @throws \InvalidArgumentException Строка не помещается в версию 10.
	 */
	public static function encode( string $text ): self {
		$version = self::version_for( strlen( $text ) );
		$codes   = self::codewords( $text, $version );
		$best    = 0;
		$lowest  = PHP_INT_MAX;

		// Штраф считается на матрице без служебных битов формата — так же, как в распространённых кодировщиках.
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$penalty = self::penalty( self::build( $codes, $version, $mask, true ) );

			if ( $penalty < $lowest ) {
				$lowest = $penalty;
				$best   = $mask;
			}
		}

		return new self( self::build( $codes, $version, $best, false ), $version, $best );
	}

	/**
	 * Версия кода.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Выбранная маска.
	 */
	public function mask(): int {
		return $this->mask;
	}

	/**
	 * Модули строками из «0» и «1», без рамки.
	 *
	 * @return list<string>
	 */
	public function rows(): array {
		return array_map( static fn( array $row ): string => implode( '', array_map( static fn( bool $dark ): string => $dark ? '1' : '0', $row ) ), $this->modules );
	}

	/**
	 * Картинка SVG: белый фон и один путь из тёмных модулей, с рамкой в 4 модуля.
	 *
	 * @param int $scale Размер модуля в пикселях для атрибутов width и height.
	 */
	public function svg( int $scale = 8 ): string {
		$count = count( $this->modules );
		$size  = $count + 2 * self::QUIET;
		$path  = '';

		foreach ( $this->modules as $y => $row ) {
			$x = 0;

			while ( $x < $count ) {
				if ( ! $row[ $x ] ) {
					++$x;
					continue;
				}

				$start = $x;

				while ( $x < $count && $row[ $x ] ) {
					++$x;
				}

				$path .= sprintf( 'M%d %dh%dv1h-%dz', $start + self::QUIET, $y + self::QUIET, $x - $start, $x - $start );
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges"><rect width="%1$d" height="%1$d" fill="#fff"/><path fill="#000" d="%3$s"/></svg>',
			$size,
			$size * max( 1, $scale ),
			$path
		);
	}

	/**
	 * Наименьшая версия, в которую помещается строка.
	 *
	 * @param int $length Длина в байтах.
	 * @throws \InvalidArgumentException Не помещается.
	 */
	private static function version_for( int $length ): int {
		foreach ( self::BLOCKS as $version => $blocks ) {
			if ( 4 + self::count_bits( $version ) + 8 * $length <= 8 * self::data_capacity( $version ) ) {
				return $version;
			}
		}

		throw new \InvalidArgumentException( 'Строка слишком длинная для QR-кода.' );
	}

	/**
	 * Длина поля счётчика символов в режиме байтов.
	 *
	 * @param int $version Версия.
	 */
	private static function count_bits( int $version ): int {
		return $version < 10 ? 8 : 16;
	}

	/**
	 * Число кодовых слов данных в версии.
	 *
	 * @param int $version Версия.
	 */
	private static function data_capacity( int $version ): int {
		$total = 0;

		foreach ( self::BLOCKS[ $version ][1] as $group ) {
			$total += $group[0] * $group[1];
		}

		return $total;
	}

	/**
	 * Кодовые слова: данные и коррекция, перемежённые по блокам.
	 *
	 * @param string $text    Строка.
	 * @param int    $version Версия.
	 * @return list<int>
	 */
	private static function codewords( string $text, int $version ): array {
		$capacity = self::data_capacity( $version );
		$bits     = '0100' . str_pad( decbin( strlen( $text ) ), self::count_bits( $version ), '0', STR_PAD_LEFT );

		foreach ( str_split( $text ) as $char ) {
			$bits .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
		}

		// Признак конца (до 4 нулей), добивка до целого байта и байты-заполнители.
		$bits .= str_repeat( '0', min( 4, 8 * $capacity - strlen( $bits ) ) );
		$bits .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );
		$data  = array_map( 'bindec', str_split( $bits, 8 ) );

		for ( $pad = 0; count( $data ) < $capacity; $pad++ ) {
			$data[] = 0 === $pad % 2 ? 0xEC : 0x11;
		}

		list( $ec_length, $groups ) = self::BLOCKS[ $version ];
		$data_blocks                = array();
		$ec_blocks                  = array();
		$offset                     = 0;

		foreach ( $groups as $group ) {
			for ( $i = 0; $i < $group[0]; $i++ ) {
				$block         = array_slice( $data, $offset, $group[1] );
				$offset       += $group[1];
				$data_blocks[] = $block;
				$ec_blocks[]   = self::reed_solomon( $block, $ec_length );
			}
		}

		$result = array();

		foreach ( array( $data_blocks, $ec_blocks ) as $blocks ) {
			$longest = max( array_map( 'count', $blocks ) );

			for ( $i = 0; $i < $longest; $i++ ) {
				foreach ( $blocks as $block ) {
					if ( isset( $block[ $i ] ) ) {
						$result[] = (int) $block[ $i ];
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Кодовые слова коррекции Рида — Соломона над GF(256) с полиномом 0x11D.
	 *
	 * @param list<int> $data   Данные блока.
	 * @param int       $length Число слов коррекции.
	 * @return list<int>
	 */
	private static function reed_solomon( array $data, int $length ): array {
		list( $exp, $log ) = self::galois();

		// Порождающий многочлен: произведение (x − α^i), i = 0…length−1; старший коэффициент первым.
		$generator = array( 1 );

		for ( $i = 0; $i < $length; $i++ ) {
			$next = array_fill( 0, count( $generator ) + 1, 0 );

			foreach ( $generator as $j => $coefficient ) {
				$next[ $j ] ^= $coefficient;

				if ( 0 !== $coefficient ) {
					$next[ $j + 1 ] ^= $exp[ ( $log[ $coefficient ] + $i ) % 255 ];
				}
			}

			$generator = $next;
		}

		$remainder = array_fill( 0, $length, 0 );

		foreach ( $data as $byte ) {
			$factor = $byte ^ $remainder[0];
			array_shift( $remainder );
			$remainder[] = 0;

			if ( 0 !== $factor ) {
				for ( $j = 0; $j < $length; $j++ ) {
					$remainder[ $j ] ^= $exp[ ( $log[ $generator[ $j + 1 ] ] + $log[ $factor ] ) % 255 ];
				}
			}
		}

		return $remainder;
	}

	/**
	 * Таблицы степеней и логарифмов GF(256).
	 *
	 * @return array{0: array<int, int>, 1: array<int, int>}
	 */
	private static function galois(): array {
		static $tables = null;

		if ( null === $tables ) {
			$exp   = array();
			$log   = array_fill( 0, 256, 0 );
			$value = 1;

			for ( $i = 0; $i < 255; $i++ ) {
				$exp[ $i ]     = $value;
				$log[ $value ] = $i;
				$value       <<= 1;

				if ( $value & 0x100 ) {
					$value ^= 0x11D;
				}
			}

			$tables = array( $exp, $log );
		}

		return $tables;
	}

	/**
	 * Собирает матрицу.
	 *
	 * @param list<int> $codes   Кодовые слова.
	 * @param int       $version Версия.
	 * @param int       $mask    Маска.
	 * @param bool      $trial   Пробная сборка для выбора маски: биты формата и версии светлые.
	 * @return list<list<bool>>
	 */
	private static function build( array $codes, int $version, int $mask, bool $trial ): array {
		$count = 17 + 4 * $version;
		$grid  = array_fill( 0, $count, array_fill( 0, $count, null ) );

		foreach ( array( array( 0, 0 ), array( $count - 7, 0 ), array( 0, $count - 7 ) ) as $corner ) {
			self::finder( $grid, $corner[0], $corner[1], $count );
		}

		foreach ( self::ALIGNMENT[ $version ] as $row ) {
			foreach ( self::ALIGNMENT[ $version ] as $col ) {
				if ( null !== $grid[ $row ][ $col ] ) {
					continue;
				}

				for ( $r = -2; $r <= 2; $r++ ) {
					for ( $c = -2; $c <= 2; $c++ ) {
						$grid[ $row + $r ][ $col + $c ] = 2 === max( abs( $r ), abs( $c ) ) || ( 0 === $r && 0 === $c );
					}
				}
			}
		}

		for ( $i = 8; $i < $count - 8; $i++ ) {
			if ( null === $grid[ $i ][6] ) {
				$grid[ $i ][6] = 0 === $i % 2;
			}

			if ( null === $grid[6][ $i ] ) {
				$grid[6][ $i ] = 0 === $i % 2;
			}
		}

		self::format_info( $grid, $mask, $count, $trial );

		if ( $version >= 7 ) {
			$bits = self::bch( $version, 0x1F25, 12 ) | ( $version << 12 );

			for ( $i = 0; $i < 18; $i++ ) {
				$dark = ! $trial && 1 === ( ( $bits >> $i ) & 1 );
				$grid[ intdiv( $i, 3 ) ][ $i % 3 + $count - 11 ] = $dark;
				$grid[ $i % 3 + $count - 11 ][ intdiv( $i, 3 ) ] = $dark;
			}
		}

		self::place( $grid, $codes, $mask, $count );

		return $grid;
	}

	/**
	 * Поисковый узор с белой каймой.
	 *
	 * @param array<int, array<int, bool|null>> $grid  Матрица.
	 * @param int                               $row   Верхняя строка.
	 * @param int                               $col   Левый столбец.
	 * @param int                               $count Размер.
	 */
	private static function finder( array &$grid, int $row, int $col, int $count ): void {
		for ( $r = -1; $r <= 7; $r++ ) {
			for ( $c = -1; $c <= 7; $c++ ) {
				if ( $row + $r < 0 || $row + $r >= $count || $col + $c < 0 || $col + $c >= $count ) {
					continue;
				}

				$grid[ $row + $r ][ $col + $c ] = ( $r >= 0 && $r <= 6 && ( 0 === $c || 6 === $c ) )
					|| ( $c >= 0 && $c <= 6 && ( 0 === $r || 6 === $r ) )
					|| ( $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4 );
			}
		}
	}

	/**
	 * Биты формата (уровень M и маска) и тёмный модуль.
	 *
	 * @param array<int, array<int, bool|null>> $grid  Матрица.
	 * @param int                               $mask  Маска.
	 * @param int                               $count Размер.
	 * @param bool                              $trial Пробная сборка.
	 */
	private static function format_info( array &$grid, int $mask, int $count, bool $trial ): void {
		// Уровень M кодируется как 00.
		$bits = ( ( $mask << 10 ) | self::bch( $mask, 0x537, 10 ) ) ^ 0x5412;

		for ( $i = 0; $i < 15; $i++ ) {
			$dark = ! $trial && 1 === ( ( $bits >> $i ) & 1 );

			if ( $i < 6 ) {
				$grid[ $i ][8] = $dark;
			} elseif ( $i < 8 ) {
				$grid[ $i + 1 ][8] = $dark;
			} else {
				$grid[ $count - 15 + $i ][8] = $dark;
			}

			if ( $i < 8 ) {
				$grid[8][ $count - $i - 1 ] = $dark;
			} elseif ( $i < 9 ) {
				$grid[8][ 15 - $i ] = $dark;
			} else {
				$grid[8][ 14 - $i ] = $dark;
			}
		}

		$grid[ $count - 8 ][8] = ! $trial;
	}

	/**
	 * Остаток кода БЧХ: значение, сдвинутое на степень многочлена, делится на многочлен.
	 *
	 * @param int $value      Значение.
	 * @param int $polynomial Многочлен.
	 * @param int $degree     Степень многочлена.
	 */
	private static function bch( int $value, int $polynomial, int $degree ): int {
		$remainder = $value << $degree;

		for ( $bit = 31; $bit >= $degree; $bit-- ) {
			if ( ( $remainder >> $bit ) & 1 ) {
				$remainder ^= $polynomial << ( $bit - $degree );
			}
		}

		return $remainder;
	}

	/**
	 * Раскладывает кодовые слова змейкой по парам столбцов снизу вверх и обратно, с маской.
	 *
	 * @param array<int, array<int, bool|null>> $grid  Матрица.
	 * @param list<int>                         $codes Кодовые слова.
	 * @param int                               $mask  Маска.
	 * @param int                               $count Размер.
	 */
	private static function place( array &$grid, array $codes, int $mask, int $count ): void {
		$total = count( $codes );
		$index = 0;
		$bit   = 7;
		$step  = -1;
		$row   = $count - 1;

		for ( $pair = $count - 1; $pair > 0; $pair -= 2 ) {
			// Столбец 6 занят синхронизирующей линией: пары левее него сдвигаются на один столбец.
			$col = $pair <= 6 ? $pair - 1 : $pair;

			while ( true ) {
				foreach ( array( $col, $col - 1 ) as $c ) {
					if ( null !== $grid[ $row ][ $c ] ) {
						continue;
					}

					$dark = $index < $total && 1 === ( ( $codes[ $index ] >> $bit ) & 1 );

					if ( self::masked( $mask, $row, $c ) ) {
						$dark = ! $dark;
					}

					$grid[ $row ][ $c ] = $dark;

					if ( --$bit < 0 ) {
						++$index;
						$bit = 7;
					}
				}

				$row += $step;

				if ( $row < 0 || $row >= $count ) {
					$row -= $step;
					$step = -$step;
					break;
				}
			}
		}
	}

	/**
	 * Переворачивает ли маска модуль.
	 *
	 * @param int $mask Маска 0–7.
	 * @param int $i    Строка.
	 * @param int $j    Столбец.
	 */
	private static function masked( int $mask, int $i, int $j ): bool {
		switch ( $mask ) {
			case 0:
				return 0 === ( $i + $j ) % 2;
			case 1:
				return 0 === $i % 2;
			case 2:
				return 0 === $j % 3;
			case 3:
				return 0 === ( $i + $j ) % 3;
			case 4:
				return 0 === ( intdiv( $i, 2 ) + intdiv( $j, 3 ) ) % 2;
			case 5:
				return 0 === ( $i * $j ) % 2 + ( $i * $j ) % 3;
			case 6:
				return 0 === ( ( $i * $j ) % 2 + ( $i * $j ) % 3 ) % 2;
			default:
				return 0 === ( ( $i * $j ) % 3 + ( $i + $j ) % 2 ) % 2;
		}
	}

	/**
	 * Штраф матрицы по четырём правилам стандарта: длинные полосы, квадраты 2×2, узоры, похожие на поисковый,
	 * и перекос тёмных модулей.
	 *
	 * @param list<list<bool>> $grid Матрица.
	 */
	private static function penalty( array $grid ): int {
		$count   = count( $grid );
		$penalty = 0;
		$columns = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$columns[] = array_column( $grid, $i );
		}

		foreach ( array( $grid, $columns ) as $lines ) {
			foreach ( $lines as $line ) {
				$run = 1;

				for ( $k = 1; $k <= $count; $k++ ) {
					if ( $k < $count && $line[ $k ] === $line[ $k - 1 ] ) {
						++$run;
						continue;
					}

					if ( $run >= 5 ) {
						$penalty += $run - 2;
					}

					$run = 1;
				}

				for ( $k = 0; $k + 10 < $count; $k++ ) {
					$slice = implode( '', array_map( static fn( bool $dark ): string => $dark ? '1' : '0', array_slice( $line, $k, 11 ) ) );

					if ( '10111010000' === $slice || '00001011101' === $slice ) {
						$penalty += 40;
					}
				}
			}
		}

		for ( $r = 0; $r < $count - 1; $r++ ) {
			for ( $c = 0; $c < $count - 1; $c++ ) {
				$color = $grid[ $r ][ $c ];

				if ( $color === $grid[ $r ][ $c + 1 ] && $color === $grid[ $r + 1 ][ $c ] && $color === $grid[ $r + 1 ][ $c + 1 ] ) {
					$penalty += 3;
				}
			}
		}

		$dark = 0;

		foreach ( $grid as $row ) {
			$dark += count( array_filter( $row ) );
		}

		return $penalty + 10 * intdiv( (int) abs( $dark * 100 - 50 * $count * $count ), 5 * $count * $count );
	}
}
