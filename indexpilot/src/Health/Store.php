<?php
/**
 * Хранилище результатов самопроверки.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Health;

/**
 * Строка — один проверенный адрес: kind (page, link, image, video), url, post_id, status, problems, fails, ms, words, checked.
 */
interface Store {

	/**
	 * Строка адреса.
	 *
	 * @param string $kind Вид адреса.
	 * @param string $url  Адрес.
	 * @return array<string, mixed>|null
	 */
	public function get( string $kind, string $url ): ?array;

	/**
	 * Добавляет или обновляет строку адреса.
	 *
	 * @param string               $kind   Вид адреса.
	 * @param string               $url    Адрес.
	 * @param array<string, mixed> $fields Поля.
	 */
	public function save( string $kind, string $url, array $fields ): void;

	/**
	 * Адреса, которые пора проверить: исправные — раз в неделю, со сбоями — раз в сутки. Сначала давно проверенные.
	 *
	 * @param list<string> $kinds Виды адресов.
	 * @param int          $now   Текущее время.
	 * @param int          $limit Сколько строк.
	 * @return list<array<string, mixed>>
	 */
	public function due( array $kinds, int $now, int $limit ): array;

	/**
	 * Удаляет строки вида, проверенные раньше указанного времени.
	 *
	 * @param string $kind   Вид адреса.
	 * @param int    $before Время.
	 */
	public function remove_older( string $kind, int $before ): void;

	/**
	 * Состояние обхода.
	 *
	 * @param string $name Имя обхода.
	 * @return array<string, mixed>
	 */
	public function state( string $name ): array;

	/**
	 * Сохраняет состояние обхода.
	 *
	 * @param string               $name  Имя обхода.
	 * @param array<string, mixed> $state Состояние.
	 */
	public function save_state( string $name, array $state ): void;
}
