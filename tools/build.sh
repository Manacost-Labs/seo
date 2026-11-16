#!/usr/bin/env bash
# Сборка пакета плагина: dist/indexpilot/ и dist/indexpilot-ВЕРСИЯ.zip.
# Запуск из корня проекта: bash tools/build.sh

set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PHPRC="$ROOT/tools"

php "$ROOT/tools/build.php"
