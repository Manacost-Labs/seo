#!/usr/bin/env bash
# Проверка обновления со старой версии и удаления плагина (R7).
#
# 1) Подготовить каталог со старой версией (по умолчанию — первый коммит репозитория, IndexPilot 0.1.0):
#      bash tests/smoke/upgrade-run.sh prepare <каталог> [git-ссылка]
# 2) Запустить тестовый сайт на этом каталоге (так же, как для run-all.sh, но с --mount-dir "<каталог>/indexpilot"):
#      MSYS_NO_PATHCONV=1 npx @wp-playground/cli@3.1.64 server --port=9401 --blueprint=tests/smoke/site-blueprint.json \
#        --mount-dir "<каталог>/indexpilot" "/wordpress/wp-content/plugins/indexpilot"
# 3) Прогнать проверку: данные на старой версии → замена файлов текущими → обновление → удаление без и с данными:
#      bash tests/smoke/upgrade-run.sh check <каталог>
# Запуск из каталога indexpilot (текущая версия плагина).

set -u

MODE="${1:-}"
DIR="${2:-}"
SITE="${SITE:-http://127.0.0.1:9401}"
BASE="$SITE/wp-content/plugins/indexpilot/tests/smoke"
NEW="$(pwd)"
FAILED=0

[ -n "$MODE" ] && [ -n "$DIR" ] || { sed -n '2,12p' "$0"; exit 2; }

case "$MODE" in
	prepare)
		REF="${3:-$(git rev-list --max-parents=0 HEAD | tail -1)}"
		rm -rf "$DIR/indexpilot"
		mkdir -p "$DIR"
		git -C "$(git rev-parse --show-toplevel)" archive "$REF" indexpilot | tar -x -C "$DIR" || exit 1
		# Проверка обновления — новый файл, в старой версии его нет.
		cp tests/smoke/upgrade-check.php "$DIR/indexpilot/tests/smoke/"
		rm -f "$DIR/indexpilot/tests/smoke/urls.json" "$DIR/indexpilot/tests/smoke/upgrade-before.json"
		echo "old version: $(grep -m1 'Version:' "$DIR/indexpilot/indexpilot.php" | awk '{print $NF}') from $REF in $DIR/indexpilot"
		;;

	check)
		OLD="$DIR/indexpilot"
		for _ in $(seq 1 80); do
			[ -f "$OLD/tests/smoke/urls.json" ] && break
			sleep 3
		done
		[ -f "$OLD/tests/smoke/urls.json" ] || { echo "site is not ready: $OLD/tests/smoke/urls.json not found"; exit 2; }

		# Первые запросы к только что созданному сайту бывают пустыми или с перенаправлением: ждём обычного ответа.
		for _ in $(seq 1 20); do
			[ "$(curl -s -m 30 -o /dev/null -w '%{http_code}' "$SITE/gajd-4/")" = "200" ] && break
			sleep 3
		done

		step() {
			curl -s -m 600 "$BASE/upgrade-check.php?step=$1" | php -r '
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
				if ( $bad || getenv( "VERBOSE" ) ) { echo json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ), "\n"; }
				exit( $bad ? 1 : 0 );
			' "$1" || FAILED=1
		}

		step before

		# Замена файлов плагина новой версией — как при загрузке архива поверх.
		rm -rf "$OLD/src" "$OLD/assets"
		cp -r "$NEW/src" "$NEW/assets" "$OLD/"
		cp "$NEW/indexpilot.php" "$NEW/uninstall.php" "$NEW/readme.txt" "$NEW/CHANGELOG.md" "$OLD/"
		echo "     files replaced: $(grep -m1 'Version:' "$OLD/indexpilot.php" | awk '{print $NF}')"

		step after
		step uninstall_keep
		step uninstall_delete

		[ "$FAILED" = "0" ] && echo "UPGRADE CHECKS PASSED" || echo "UPGRADE CHECKS FAILED"
		exit "$FAILED"
		;;

	*)
		sed -n '2,12p' "$0"
		exit 2
		;;
esac
