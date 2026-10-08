<?php
/**
 * Revisión periódica (WP-Cron) de pedidos con enlace abierto.
 *
 * Es el respaldo del webhook: si el aviso nunca llega (servidor caído, secreto mal
 * copiado), el pedido igual se entera consultando GET /links/:id.
 *
 * Sin ventana de días a propósito: un proceso que sólo mira "los últimos N días" abandona
 * en silencio lo que queda fuera. Aquí se revisa todo pedido marcado "_quickpay_revisar=1",
 * y la marca se quita sólo cuando el enlace llega a un estado final (pagado, expirado,
 * cancelado). Un cobro en "procesando" o "revision" se sigue consultando hasta que cierre.
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class QP_Cron {

	const EVENTO    = 'quickpay_revisar_pendientes';
	const INTERVALO = 'quickpay_cada_10_min';
	const LOTE      = 40; // QuickPay permite 120 peticiones/min por comercio; se deja holgura para los cobros.

	public static function registrar(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'intervalos' ) );
		add_action( self::EVENTO, array( __CLASS__, 'correr' ) );
		add_action( 'init', array( __CLASS__, 'programar' ) );
		// No cancelar por falta de pago un pedido cuyo enlace sigue vigente.
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'no_cancelar_si_vigente' ), 10, 2 );
	}

	public static function intervalos( $s ) {
		$s[ self::INTERVALO ] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 10 minutos (QuickPay)', 'quickpay-woocommerce' ),
		);
		return $s;
	}

	public static function programar(): void {
		if ( ! wp_next_scheduled( self::EVENTO ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVALO, self::EVENTO );
		}
	}

	public static function desprogramar(): void {
		wp_clear_scheduled_hook( self::EVENTO );
	}

	public static function correr(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$pasarela = QP_Gateway::instancia();
		if ( '' === $pasarela->modo_llave() ) {
			return;
		}
		$ids = wc_get_orders(
			array(
				'limit'          => 500,
				'return'         => 'ids',
				'type'           => 'shop_order',
				'payment_method' => QP_Gateway::ID,
				'status'         => array_keys( wc_get_order_statuses() ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => QP_Pedidos::META_POLL,
						'value' => '1',
					),
				),
			)
		);
		// Los consultados hace más tiempo primero: así un grupo de cobros atascados en
		// "procesando" no deja sin revisar a los demás.
		$cola = array();
		foreach ( $ids as $id ) {
			$pedido = wc_get_order( $id );
			if ( ! $pedido instanceof WC_Order ) {
				continue;
			}
			if ( '' !== (string) $pedido->get_meta( QP_Pedidos::META_PAGADO ) ) {
				$pedido->update_meta_data( QP_Pedidos::META_POLL, '0' );
				$pedido->save();
				continue;
			}
			$ultima = (int) $pedido->get_meta( QP_Pedidos::META_ULTIMA );
			if ( time() - $ultima < 5 * MINUTE_IN_SECONDS ) {
				continue;
			}
			$cola[ $id ] = $ultima;
		}
		asort( $cola );
		foreach ( array_slice( array_keys( $cola ), 0, self::LOTE ) as $id ) {
			$pedido = wc_get_order( $id );
			if ( $pedido instanceof WC_Order ) {
				$pasarela->revisar_pedido( $pedido, 'consulta' );
			}
		}
	}

	/**
	 * @param bool     $cancelar Lo que WooCommerce iba a hacer.
	 * @param WC_Order $pedido   Pedido.
	 */
	public static function no_cancelar_si_vigente( $cancelar, $pedido ) {
		if ( ! $cancelar || ! $pedido instanceof WC_Order || QP_Gateway::ID !== $pedido->get_payment_method() ) {
			return $cancelar;
		}
		$expira = (int) $pedido->get_meta( QP_Pedidos::META_EXPIRA );
		if ( $expira > time() || '1' === (string) $pedido->get_meta( QP_Pedidos::META_POLL ) ) {
			// Enlace vigente o cobro aún sin estado final (p. ej. "procesando"): no cancelar.
			return false;
		}
		return $cancelar;
	}
}
