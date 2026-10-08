# Pruebas del plugin de WooCommerce

## 1. Pruebas puras (sin WordPress)

```bash
php woocommerce/tests/run.php
# con vectores de firma generados por la función del SDK de Node:
node woocommerce/tests/sign-vectors.mjs > /tmp/v.json && php woocommerce/tests/run.php /tmp/v.json
```

## 2. Punta a punta contra un WooCommerce real y la API simulada

No toca ningún servidor de QuickPay: la API es `tools/mock-quickpay.mjs`.

```bash
# API simulada
PORT=8787 node tools/mock-quickpay.mjs &

# WordPress local con SQLite (sin MySQL)
php wp-cli.phar core download --path=wp
# descomprimir woocommerce y sqlite-database-integration en wp/wp-content/plugins,
# copiar sqlite-database-integration/db.copy a wp/wp-content/db.php (reemplazando sus dos marcadores),
# enlazar woocommerce/quickpay-woocommerce en wp/wp-content/plugins.
php wp-cli.phar --path=wp config create --dbname=wp --dbuser=x --dbpass=x --skip-check --extra-php <<'PHP'
define( 'QUICKPAY_API_BASE', 'http://127.0.0.1:8787/switch/v1' );
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
PHP
php wp-cli.phar --path=wp core install --url=http://127.0.0.1:8080 --title="Tienda Prueba" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
php wp-cli.phar --path=wp plugin activate woocommerce quickpay-woocommerce
php wp-cli.phar --path=wp wc hpos enable
php wp-cli.phar --path=wp option update woocommerce_currency VES
php wp-cli.phar --path=wp option update woocommerce_coming_soon no   # si no, la página de gracias muestra "próximamente"
php wp-cli.phar --path=wp option update woocommerce_quickpay_settings '{"enabled":"yes","title":"Paga con QuickPay","description":"Paga en bolívares desde tu banco.","api_key":"tienda-demo:sk_test_abc123","webhook_secret":"whsec_e2e_secreto","moneda_modo":"ves","tasa":"","expira_horas":"24","debug":"yes"}' --format=json
php wp-cli.phar --path=wp wc product create --name=Camisa --regular_price=1500.50 --user=admin --porcelain   # → id del producto
(cd wp && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080) &

WP=$PWD/wp WPCLI=$PWD/wp-cli.phar PRODUCTO=<id> node woocommerce/tests/e2e-woo.mjs
```

`QUICKPAY_API_BASE` sólo existe para estas pruebas: en producción no se define y el plugin usa
`https://pay.pagaconquickpay.com/switch/v1`.
