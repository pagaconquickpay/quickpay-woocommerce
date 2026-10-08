<?php
/**
 * Al desinstalar: se borran los ajustes (incluye la llave y el secreto) y la tarea programada.
 * Los datos de los pedidos (enlace, referencia, notas) NO se borran: son historial contable.
 *
 * @package QuickPay_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_quickpay_settings' );
wp_clear_scheduled_hook( 'quickpay_revisar_pendientes' );
