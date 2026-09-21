#!/usr/bin/env bash
# Bring the sandbox to the state the [S] cases assume. Idempotent.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

wp() { docker compose exec -T cli wp --allow-root "$@"; }
php_c() { docker compose exec -T wordpress "$@"; }

echo "→ WordPress"
wp core install --url=http://localhost:8080 --title="VezmoPay Test Store" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email 2>/dev/null || true

echo "→ WooCommerce"
wp plugin install woocommerce --activate 2>/dev/null || wp plugin activate woocommerce
wp plugin activate vezmopay-woocommerce
wp option update woocommerce_currency USD
wp option update woocommerce_store_address "1 Test St"
wp option update woocommerce_default_country "US:CA"

echo "→ mock API + fixtures"
docker compose cp mu-plugins/vezmopay-api-mock.php wordpress:/var/www/html/wp-content/mu-plugins/vezmopay-api-mock.php
docker compose cp mu-plugins/rig-hosts.php wordpress:/var/www/html/wp-content/mu-plugins/rig-hosts.php
docker compose cp fixtures/mock-secure.html wordpress:/var/www/html/wp-content/uploads/mock-secure.html
docker compose cp fixtures/mock-vezmo.js wordpress:/var/www/html/wp-content/uploads/mock-vezmo.js

echo "→ a product to buy"
wp eval '
if ( ! wc_get_product( 10 ) ) {
  $p = new WC_Product_Simple();
  $p->set_name( "VezmoPay Test Widget" );
  $p->set_regular_price( "12.34" );
  $p->set_catalog_visibility( "visible" );
  $p->save();
  echo "product " . $p->get_id() . " created — use that id in regress.mjs\n";
}'

echo "→ gateway settings (test mode, mock credentials)"
wp eval '
update_option( "woocommerce_vezmopay_settings", array(
  "enabled" => "yes", "environment" => "test", "integration_mode" => "element",
  "test_api_base" => "https://api.dev.vezmo.com", "test_checkout_base" => "https://user.dev.vezmo.com",
  "test_api_key" => "vzm_mock", "test_api_secret" => "mock_secret",
  "webhook_secret" => "test_secret_for_audit", "debug" => "yes",
  "title" => "VezmoPay", "description" => "Pay securely by card or US bank account via VezmoPay.",
  "checkout_theme" => "light",
) );
echo "settings written\n";'

echo
echo "Ready: http://localhost:8080  (admin / admin)"
echo "Run one:  node regress.mjs box element success"
