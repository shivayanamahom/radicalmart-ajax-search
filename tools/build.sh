#!/usr/bin/env bash
# Собирает установочный архив Joomla: dist/mod_radicalmart_search_<версия>.zip
# Пути внутри архива записываются через "/", как требует установщик Joomla.
# Запуск из любого места:  tools/build.sh
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' mod_radicalmart_search.xml | head -1)
OUT="dist/mod_radicalmart_search_${VERSION}.zip"

mkdir -p dist
rm -f "$OUT"

# Манифест в корне архива; служебные файлы репозитория (README, LICENSE, tools) не включаем.
zip -X -q -r "$OUT" mod_radicalmart_search.xml mod_radicalmart_search.php helper.php language services src tmpl \
  -x '*.DS_Store' -x '__MACOSX/*'

if unzip -Z1 "$OUT" | grep -q '\\'; then
  echo "Ошибка: в архиве найдены обратные слэши в путях" >&2
  exit 1
fi

echo "Готово: $OUT ($(du -h "$OUT" | cut -f1))"
