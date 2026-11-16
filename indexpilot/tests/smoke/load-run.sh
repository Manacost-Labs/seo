#!/usr/bin/env bash
# Замер нагрузки на большом сайте (R4): наполнение и все замеры по порядку.
# Сайт — отдельный Playground (по умолчанию порт 9403), запущенный с tests/smoke/load-blueprint.json:
#   MSYS_NO_PATHCONV=1 npx @wp-playground/cli@3.1.64 server --port=9403 --blueprint=tests/smoke/load-blueprint.json \
#     --mount-dir "<путь к indexpilot>" "/wordpress/wp-content/plugins/indexpilot"
# Запуск из каталога indexpilot: bash tests/smoke/load-run.sh [записей] [колод]
# SKIP_SETUP=1 — не наполнять сайт (данные уже есть). Итог — строки «ok»/«FAIL» и сводка в конце.

set -u

export PHPRC="${PHPRC:-D:/SEO-plugin/tools}"
SITE="${SITE:-http://127.0.0.1:9403}"
BASE="$SITE/wp-content/plugins/indexpilot/tests/smoke"
POSTS="${1:-15000}"
DECKS="${2:-5000}"
LIMIT_MB=256
FAILED=0

get() { curl -s -m 900 "$BASE/$1"; }

# Поле JSON по пути через точку: field '{"a":{"b":1}}' a.b
field() { php -r '$d = json_decode( $argv[1], true ); foreach ( explode( ".", $argv[2] ) as $k ) { $d = is_array( $d ) ? ( $d[ $k ] ?? null ) : null; } echo is_bool( $d ) ? ( $d ? "true" : "false" ) : ( is_array( $d ) ? json_encode( $d ) : (string) $d );' "$1" "$2"; }

for _ in $(seq 1 60); do
	[ "$(curl -s -m 60 -o /dev/null -w '%{http_code}' "$SITE/")" = "200" ] && break
	sleep 5
done

if [ "${SKIP_SETUP:-0}" != "1" ]; then
	START=$(date +%s)
	TERMS="$(get "load-setup.php?step=terms")"
	[ -n "$(field "$TERMS" terms)" ] || { echo "FAIL setup: ${TERMS:-no response}"; exit 1; }
	for (( from = 0; from < POSTS; from += 1000 )); do
		get "load-setup.php?step=posts&from=$from&count=$(( POSTS - from < 1000 ? POSTS - from : 1000 ))&total=$POSTS" > /dev/null
	done
	for (( from = 0; from < DECKS; from += 1000 )); do
		get "load-setup.php?step=decks&from=$from&count=$(( DECKS - from < 1000 ? DECKS - from : 1000 ))&total=$DECKS" > /dev/null
	done
	echo "site: $(get 'load-setup.php?step=finish' | tr -d '\n\t ') ($(( $(date +%s) - START )) s)"
fi

get "load-check.php?task=reset" > /dev/null

# Пачки задачи до конца: время, самая долгая пачка, пиковая память запроса.
run_batches() {
	local task="$1" per_request="$2" restart=1 requests=0 max_batch=0 max_peak=0 start out
	start=$(date +%s)
	while :; do
		out="$(get "load-check.php?task=$task&batches=$per_request&restart=$restart")"
		restart=0
		requests=$(( requests + 1 ))
		if [ -n "$(field "$out" error)" ] || [ -z "$out" ]; then
			echo "FAIL $task: ${out:-no response}"
			FAILED=1
			return
		fi
		max_batch=$(php -r 'echo max( (float) $argv[1], (float) $argv[2] );' "$max_batch" "$(field "$out" batch_max_s)")
		max_peak=$(php -r 'echo max( (float) $argv[1], (float) $argv[2] );' "$max_peak" "$(field "$out" peak_mb)")
		[ "$(field "$out" done)" = "true" ] && break
	done
	local verdict="ok  "
	php -r 'exit( (float) $argv[1] <= (float) $argv[2] ? 0 : 1 );' "$max_peak" "$LIMIT_MB" || { verdict="FAIL"; FAILED=1; }
	echo "$verdict $task: requests $requests, total $(( $(date +%s) - start )) s, slowest batch ${max_batch} s, peak ${max_peak} MB (limit ${LIMIT_MB}), result $(field "$out" result)"
}

run_batches import 1
run_batches audit 10
run_batches links 10

# Карта сайта: каждый файл собирается с пустым кэшем.
FILES="$(get 'load-check.php?task=sitemap_files')"
SLOWEST=0
for FILE in sitemap_index.xml $(php -r 'foreach ( json_decode( $argv[1], true )["files"] ?? array() as $url ) { echo basename( $url ), "\n"; }' "$FILES"); do
	out="$(get "load-check.php?task=sitemap_file&file=$FILE")"
	BUILD="$(field "$out" build_s)"
	SLOWEST=$(php -r 'echo max( (float) $argv[1], (float) $argv[2] );' "$SLOWEST" "$BUILD")
	printf '     sitemap %-34s %6ss (db %ss)  urls %5s  queries %3s  peak %s MB\n' "$FILE" "$BUILD" "$(field "$out" db_s)" "$(field "$out" urls)" "$(field "$out" queries)" "$(field "$out" peak_mb)"
done
php -r 'exit( (float) $argv[1] < 2 ? 0 : 1 );' "$SLOWEST" && echo "ok   sitemap: slowest file ${SLOWEST} s (< 2 s)" || { echo "FAIL sitemap: slowest file ${SLOWEST} s (limit 2 s)"; FAILED=1; }

# Запросы плагина на страницах: первый показ (кэши записи ещё не собраны) и повторный.
for PASS in first repeat; do
	out="$(get 'load-check.php?task=pages&offset=11')"
	php -r '
		$d = json_decode( $argv[1], true );
		foreach ( $d["pages"] ?? array() as $name => $page ) {
			printf( "     %-6s %-10s status %s, plugin queries %s, all queries %s, plugin %s ms%s\n", $argv[2], $name, $page["status"], $page["plugin"], $page["total"], $page["plugin_ms"], $page["error"] ? ", " . $page["error"] : "" );
		}
	' "$out" "$PASS"
	POST_QUERIES="$(field "$out" pages.post.plugin)"
done
[ -n "$POST_QUERIES" ] && [ "$POST_QUERIES" -le 4 ] && echo "ok   post page: $POST_QUERIES plugin queries (<= 4)" || { echo "FAIL post page: ${POST_QUERIES:-?} plugin queries (limit 4)"; FAILED=1; }

[ "$FAILED" = "0" ] && echo "LOAD CHECKS PASSED" || echo "LOAD CHECKS FAILED"
exit "$FAILED"
