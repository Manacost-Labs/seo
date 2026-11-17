<?php
/**
 * Ошибка ИИ-помощника с понятным редактору текстом.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Ai\Writer;

/**
 * Текст ошибки показывается в панели записи как есть.
 */
final class WriterException extends \RuntimeException {
}
