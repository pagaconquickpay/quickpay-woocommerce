<?php
/**
 * Consulta el cobro cuando el cliente vuelve a la página de "pedido recibido".
 *
 * QuickPay agrega "?estado=pagado&referencia=…" al volver, pero eso NO prueba el pago:
 * cualquiera puede escribir esa dirección. Aquí se ignoran esos parámetros y se consulta
 * GET /links/:id antes de que la página se dibuje, para mostrar el estado real.
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class QP_Gracias {

	/** @var array<int,string> Estado consultado en esta petición, por pedido. */
	public static $estado_consultado = array();

	public static function registrar(): void {
		add_action( 'template_redirect', array( __CLASS__, 'revisar' ) );
	}

	public static function revisar(): void {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}
		global $wp;
		$pedido_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$clave  = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		$pedido = $pedido_id ? wc_get_order( $pedido_id ) : null;
		// Sólo quien tiene la clave del pedido dispara una consulta a QuickPay.
		if ( ! $pedido instanceof WC_Order || '' === $clave || ! hash_equals( $pedido->get_order_key(), $clave ) ) {
			return;
		}
		if ( QP_Gateway::ID !== $pedido->get_payment_method() || '' !== (string) $pedido->get_meta( QP_Pedidos::META_PAGADO ) ) {
			return;
		}
		// No más de una consulta cada 10 s por pedido (recargas seguidas de la página).
		if ( time() - (int) $pedido->get_meta( QP_Pedidos::META_ULTIMA ) < 10 ) {
			return;
		}
		$estado = QP_Gateway::instancia()->revisar_pedido( $pedido, 'pagina_gracias' );
		self::$estado_consultado[ $pedido_id ] = $estado;
	}
}
