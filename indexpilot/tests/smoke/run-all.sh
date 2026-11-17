#!/usr/bin/env bash
# Полный прогон проверок на свежем тестовом сайте Playground (порт 9401; другой — через SITE=http://127.0.0.1:ПОРТ).
# Запуск из каталога indexpilot после старта сервера: bash tests/smoke/run-all.sh
# Сайт должен быть свежим: часть проверок меняет его данные и рассчитана на один запуск.

set -u

if [[ -z "${PHPRC:-}" && -d D:/SEO-plugin/tools ]]; then
	export PHPRC=D:/SEO-plugin/tools
fi
SITE="${SITE:-http://127.0.0.1:9401}"
BASE="$SITE/wp-content/plugins/indexpilot/tests/smoke"
FAILED=0

for _ in $(seq 1 80); do
	[ -f tests/smoke/urls.json ] && break
	sleep 3
done
[ -f tests/smoke/urls.json ] || { echo "site is not ready: tests/smoke/urls.json not found"; exit 2; }

# Первый запрос после создания сайта иногда отдаёт перенаправление: ждём обычного ответа.
for _ in $(seq 1 20); do
	[ "$(curl -s -m 30 -o /dev/null -w '%{http_code}' "$SITE/gajd-4/")" = "200" ] && break
	sleep 3
done

php tests/smoke/check-head.php || FAILED=1

# Каждая проверка отдаёт JSON; значение false на любом уровне или ключ error — провал.
json_check() {
	curl -s -m 180 "$BASE/$1" | php -r '
		$name = $argv[1];
		$data = json_decode( stream_get_contents( STDIN ), true );
		if ( ! is_array( $data ) ) { echo "FAIL $name: not JSON\n"; exit( 1 ); }
		$bad = array();
		$walk = function ( $value, $path ) use ( &$walk, &$bad ) {
			if ( is_array( $value ) ) { foreach ( $value as $key => $item ) { $walk( $item, $path . "." . $key ); } }
			elseif ( false === $value ) { $bad[] = ltrim( $path, "." ); }
		};
		$walk( $data, "" );
		if ( isset( $data["error"] ) ) { $bad[] = "error: " . $data["error"]; }
		echo ( $bad ? "FAIL" : "ok  " ), " $name", $bad ? ": " . implode( ", ", $bad ) : "", "\n";
		exit( $bad ? 1 : 0 );
	' "$1" || FAILED=1
}

json_check rest-check.php
json_check metabox-check.php
json_check indexing-check.php
json_check sanitize-check.php
json_check breadcrumbs-check.php
json_check admin-check.php
curl -s -m 60 -o /dev/null "$BASE/admin-check.php?reset_settings=1"
json_check redirects-check.php
json_check redirect-manager-check.php
json_check links-check.php
json_check suggest-check.php
json_check bulk-check.php
json_check audit-check.php
json_check partner-check.php
json_check restfield-check.php
json_check indexnow-check.php
json_check authors-check.php
json_check game-check.php
json_check koloda-check.php
json_check search-check.php
json_check shortlinks-check.php
json_check health-check.php
json_check promo-check.php
json_check content-check.php
json_check answers-check.php
json_check searchdata-check.php
json_check wow-check.php
json_check doctor-check.php
json_check twins-check.php
json_check cards-check.php
json_check wave7-check.php

# Карта сайта, robots.txt и файл ключа проверяются последними: к этому моменту есть записи с noindex, под паролем и под редиректом.
php tests/smoke/check-sitemap.php || FAILED=1

# Настоящие экраны админки открываются без ошибок PHP.
JAR="$(mktemp)"
curl -s -m 60 -c "$JAR" -o /dev/null "$SITE/wp-login.php"
curl -s -m 60 -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "log=admin" --data-urlencode "pwd=password" --data "wp-submit=Log+In&testcookie=1" "$SITE/wp-login.php"

for PAGE in "admin.php?page=indexpilot" "admin.php?page=indexpilot-search" "admin.php?page=indexpilot-import" "admin.php?page=indexpilot-shortlinks" "admin.php?page=indexpilot-redirects" "admin.php?page=indexpilot-redirect-rules" "admin.php?page=indexpilot-404" "admin.php?page=indexpilot-health" "admin.php?page=indexpilot-links" "admin.php?page=indexpilot-bulk" "admin.php?page=indexpilot-audit" "admin.php?page=indexpilot-overview" "admin.php?page=indexpilot-promo" "admin.php?page=indexpilot-promo-report" "admin.php?page=indexpilot-patch" "admin.php?page=indexpilot-search-data" "admin.php?page=indexpilot-queries" "admin.php?page=indexpilot-topics" "admin.php?page=indexpilot-stale" "term.php?taxonomy=category&tag_ID=2" "admin.php?page=indexpilot-inspect" "admin.php?page=indexpilot-authors" "admin.php?page=indexpilot-ai" "edit-tags.php?taxonomy=deck_streamer&post_type=hs_deck" "edit.php" "edit.php?post_type=hs_deck" "edit-tags.php?taxonomy=category" "profile.php" "index.php"; do
	BODY="$(curl -s -m 120 -b "$JAR" -w '\nHTTP_CODE=%{http_code}' "$SITE/wp-admin/$PAGE")"
	if echo "$BODY" | grep -q "HTTP_CODE=200" && ! echo "$BODY" | grep -q -i "critical error\|Fatal error\|Warning</b>\|Notice</b>\|Deprecated</b>" && echo "$BODY" | grep -q "toplevel_page_indexpilot"; then
		echo "ok   admin: $PAGE"
	else
		echo "FAIL admin: $PAGE"
		FAILED=1
	fi
done
rm -f "$JAR"

[ "$FAILED" = "0" ] && echo "ALL SMOKE CHECKS PASSED" || echo "SMOKE CHECKS FAILED"
exit "$FAILED"
