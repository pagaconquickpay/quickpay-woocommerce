#!/usr/bin/env bash
# Empaqueta el plugin en dist/quickpay-woocommerce-<versión>.zip, listo para subir a WordPress.
# Antes de empaquetar: php -l a todos los archivos y las pruebas puras. Si algo falla, no hay zip.
set -euo pipefail
cd "$(dirname "$0")/.."
PLUGIN=quickpay-woocommerce
VERSION=$(sed -n 's/^ \* Version: *//p' "$PLUGIN/$PLUGIN.php" | tr -d '[:space:]')
[ -n "$VERSION" ] || { echo "No encontré la versión en la cabecera del plugin" >&2; exit 1; }
STABLE=$(sed -n 's/^Stable tag: *//p' "$PLUGIN/readme.txt" | tr -d '[:space:]')
[ "$VERSION" = "$STABLE" ] || { echo "Versión del plugin ($VERSION) distinta del Stable tag del readme ($STABLE)" >&2; exit 1; }

echo "== php -l"
find "$PLUGIN" tests -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' && exit 1 || true
echo "== pruebas"
php tests/run.php > /dev/null || { php tests/run.php; exit 1; }

mkdir -p dist
ZIP="dist/$PLUGIN-$VERSION.zip"
rm -f "$ZIP"
zip -qr "$ZIP" "$PLUGIN" -x '*.DS_Store' -x '*/.git*'
echo "== listo: $ZIP"
unzip -l "$ZIP" | tail -n +4 | head -n -2
