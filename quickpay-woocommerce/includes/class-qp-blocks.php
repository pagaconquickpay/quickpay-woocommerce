<?php
/**
 * Integración con la caja por bloques de WooCommerce (la caja por omisión desde WooCommerce 8.3).
 * Sin esto, la pasarela sólo aparece en la caja clásica (shortcode).
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class QP_Blocks extends AbstractPaymentMethodType {

	protected $name = 'quickpay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_quickpay_settings', array() );
	}

	public function is_active() {
		return QP_Gateway::instancia()->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'quickpay-blocks',
			plugins_url( 'assets/js/blocks.js', QUICKPAY_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			QUICKPAY_WC_VERSION,
			true
		);
		return array( 'quickpay-blocks' );
	}

	public function get_payment_method_data() {
		$p = QP_Gateway::instancia();
		return array(
			'title'       => $p->get_title(),
			'description' => $p->get_description(),
			'supports'    => array_values( array_filter( $p->supports, array( $p, 'supports' ) ) ),
		);
	}
}
