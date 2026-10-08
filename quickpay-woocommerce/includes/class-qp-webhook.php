<?php
/**
 * Webhook de QuickPay: POST /wp-json/quickpay/v1/webhook
 *
 * Reglas del contrato (docs/developers/webhooks-referencia.md):
 *  - Verificar la firma v2 sobre el cuerpo EXACTO y rechazar avisos de más de 5 minutos.
 *  - Responder 2xx en menos de 8 s; si no, QuickPay reintenta hasta 6 veces.
 *  - El mismo aviso puede llegar más de una vez: el pedido se marca pagado una sola vez.
 *  - El aviso no trae metadata: el pedido se ubica por link_id.
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class QP_Webhook {

	public static function registrar(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'ruta' ) );
	}

	public static function ruta(): void {
		register_rest_route(
			'quickpay/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'recibir' ),
				// Público a propósito: la autenticación es la firma HMAC, verificada en recibir().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $req Petición.
	 * @return WP_REST_Response
	 */
	public static function recibir( WP_REST_Request $req ) {
		$pasarela = QP_Gateway::instancia();
		$secreto  = $pasarela->secreto_webhook();
		$cuerpo   = (string) $req->get_body();
		$firma    = $req->get_header( 'x-quicklink-signature-v2' );
		$ts       = $req->get_header( 'x-quicklink-timestamp' );

		if ( '' === $secreto ) {
			$pasarela->log( 'error', 'Webhook recibido pero no hay secreto configurado: se rechaza.' );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'sin_secreto' ), 503 );
		}
		if ( ! QP_Signature::verificar( $cuerpo, $firma, $ts, $secreto ) ) {
			$pasarela->log( 'error', 'Webhook con firma inválida o vencida (timestamp ' . sanitize_text_field( (string) $ts ) . ').' );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'firma_invalida' ), 401 );
		}

		$evento = json_decode( $cuerpo, true );
		if ( ! is_array( $evento ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'json_invalido' ), 400 );
		}
		$tipo = (string) ( $evento['type'] ?? $req->get_header( 'x-quicklink-event' ) ?? '' );
		$data = isset( $evento['data'] ) && is_array( $evento['data'] ) ? $evento['data'] : array();

		if ( 'link.pagado' !== $tipo ) {
			// link.acreditado y otros: se aceptan para que no se reintenten, pero no cambian el pedido.
			$pasarela->log( 'info', 'Webhook ' . sanitize_text_field( $tipo ) . ' ignorado.' );
			return new WP_REST_Response( array( 'ok' => true, 'ignorado' => $tipo ), 200 );
		}

		$link_id = sanitize_text_field( (string) ( $data['link_id'] ?? '' ) );
		if ( 'pagado' !== ( $data['estado'] ?? '' ) || '' === $link_id ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignorado' => 'estado' ), 200 );
		}

		$pedido = QP_Pedidos::pedido_por_enlace( $link_id );
		if ( null === $pedido ) {
			// Puede ser un cobro del mismo comercio creado fuera de esta tienda (portal, otro sistema).
			$pasarela->log( 'info', 'Webhook link.pagado de un enlace que no es de esta tienda: ' . $link_id );
			return new WP_REST_Response( array( 'ok' => true, 'ignorado' => 'enlace_ajeno' ), 200 );
		}

		$r = QP_Pedidos::aplicar_pago(
			$pedido->get_id(),
			$link_id,
			sanitize_text_field( (string) ( $data['txn_id'] ?? '' ) ),
			(float) ( $data['monto'] ?? 0 ),
			sanitize_text_field( (string) ( $data['referencia'] ?? '' ) ),
			'aviso',
			$pasarela->modo_llave()
		);
		$pasarela->log( 'info', 'Webhook link.pagado ' . $link_id . ' → pedido ' . $pedido->get_id() . ': ' . $r );

		if ( QP_Pedidos::R_OCUPADO === $r ) {
			// Otro proceso lo está aplicando ahora mismo: que QuickPay reintente.
			return new WP_REST_Response( array( 'ok' => false, 'resultado' => $r ), 503 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'resultado' => $r ), 200 );
	}
}
