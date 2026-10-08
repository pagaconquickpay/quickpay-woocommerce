<?php
/**
 * Plugin Name:          QuickPay para WooCommerce
 * Plugin URI:           https://docs.pagaconquickpay.com/
 * Description:          Cobra en bolívares con enlaces de pago de QuickPay. El pedido se marca pagado sólo cuando QuickPay lo confirma.
 * Version:              0.1.0
 * Author:               QuickPay
 * Author URI:           https://pagaconquickpay.com/
 * Text Domain:          quickpay-woocommerce
 * Domain Path:          /languages
 * Requires at least:    6.4
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.3
 * WC tested up to:      11.2
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'QUICKPAY_WC_VERSION', '0.1.0' );
define( 'QUICKPAY_WC_FILE', __FILE__ );
define( 'QUICKPAY_WC_DIR', plugin_dir_path( __FILE__ ) );

// Compatibilidad declarada: pedidos en tablas propias (HPOS) y caja por bloques.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'quickpay_wc_cargar', 11 );

// Traducciones en "init" (WordPress 6.7+ avisa si se cargan antes).
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'quickpay-woocommerce', false, dirname( plugin_basename( QUICKPAY_WC_FILE ) ) . '/languages' );
	}
);

function quickpay_wc_cargar() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'QuickPay para WooCommerce necesita que WooCommerce esté activo.', 'quickpay-woocommerce' ) . '</p></div>';
			}
		);
		return;
	}

	require_once QUICKPAY_WC_DIR . 'includes/class-qp-signature.php';
	require_once QUICKPAY_WC_DIR . 'includes/class-qp-amount.php';
	require_once QUICKPAY_WC_DIR . 'includes/class-qp-api.php';
	require_once QUICKPAY_WC_DIR . 'includes/class-qp-pedidos.php';
	require_once QUICKPAY_WC_DIR . 'includes/class-qp-gateway.php';
	require_once QUICKPAY_WC_DIR . 'includes/class-qp-webhook.php';
	require_once QUICKPAY_WC_DIR . 'includes/class-qp-cron.php';
	require_once QUICKPAY_WC_DIR . 'includes/class-qp-gracias.php';

	add_filter(
		'woocommerce_payment_gateways',
		static function ( $pasarelas ) {
			$pasarelas[] = 'QP_Gateway';
			return $pasarelas;
		}
	);

	QP_Webhook::registrar();
	QP_Cron::registrar();
	QP_Gracias::registrar();

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		static function ( $registro ) {
			require_once QUICKPAY_WC_DIR . 'includes/class-qp-blocks.php';
			$registro->register( new QP_Blocks() );
		}
	);

	add_filter(
		'plugin_action_links_' . plugin_basename( __FILE__ ),
		static function ( $enlaces ) {
			$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=quickpay' );
			array_unshift( $enlaces, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ajustes', 'quickpay-woocommerce' ) . '</a>' );
			return $enlaces;
		}
	);
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'quickpay_revisar_pendientes' );
	}
);
