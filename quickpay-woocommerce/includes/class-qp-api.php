<?php
/**
 * Cliente mínimo de la API de QuickPay (enlaces de pago).
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class QP_Api {

	const BASE_URL = 'https://pay.pagaconquickpay.com/switch/v1';

	/** @var string "<comercio>:<sk_test_…|sk_live_…>" */
	private $llave;

	/** @var string */
	private $base;

	public function __construct( string $llave ) {
		$this->llave = trim( $llave );
		/*
		 * La URL base sólo se puede cambiar con una constante en wp-config.php, pensada para
		 * pruebas locales contra un servidor simulado. No es un ajuste del panel a propósito:
		 * nadie debe poder desviar los cobros desde la administración.
		 */
		$this->base = defined( 'QUICKPAY_API_BASE' ) ? rtrim( (string) QUICKPAY_API_BASE, '/' ) : self::BASE_URL;
	}

	/** "test", "live" o "" si la llave no tiene el formato esperado. */
	public static function modo_de_llave( string $llave ): string {
		$partes = explode( ':', trim( $llave ), 2 );
		if ( 2 !== count( $partes ) || '' === $partes[0] ) {
			return '';
		}
		if ( 0 === strpos( $partes[1], 'sk_test_' ) ) {
			return 'test';
		}
		if ( 0 === strpos( $partes[1], 'sk_live_' ) ) {
			return 'live';
		}
		return '';
	}

	/**
	 * POST /links. Devuelve el enlace o WP_Error.
	 *
	 * @param array $cuerpo Campos del contrato: monto, concepto, expira_horas, success_url, metadata, idempotency_key.
	 * @return array|WP_Error
	 */
	public function crear_enlace( array $cuerpo ) {
		$cuerpo['moneda'] = 'VES';
		return $this->pedir( 'POST', '/links', $cuerpo );
	}

	/**
	 * GET /links/:id.
	 *
	 * @return array|WP_Error
	 */
	public function obtener_enlace( string $id ) {
		return $this->pedir( 'GET', '/links/' . rawurlencode( $id ) );
	}

	/**
	 * @return array|WP_Error
	 */
	private function pedir( string $metodo, string $ruta, ?array $cuerpo = null ) {
		if ( '' === self::modo_de_llave( $this->llave ) ) {
			return new WP_Error( 'quickpay_llave', __( 'La llave de API de QuickPay no tiene el formato comercio:sk_test_… o comercio:sk_live_….', 'quickpay-woocommerce' ) );
		}
		$args = array(
			'method'  => $metodo,
			'timeout' => 20,
			'headers' => array(
				'x-switch-key' => $this->llave,
				'accept'       => 'application/json',
			),
		);
		if ( null !== $cuerpo ) {
			$args['headers']['content-type'] = 'application/json';
			$args['body']                    = wp_json_encode( $cuerpo );
		}
		$resp = wp_remote_request( $this->base . $ruta, $args );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'quickpay_red', $resp->get_error_message(), array( 'status' => 0 ) );
		}
		$codigo = (int) wp_remote_retrieve_response_code( $resp );
		$datos  = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $codigo < 200 || $codigo >= 300 ) {
			$error = is_array( $datos ) && isset( $datos['error'] ) ? (string) $datos['error'] : 'http_' . $codigo;
			return new WP_Error(
				'quickpay_http',
				/* translators: 1: código HTTP, 2: código de error de la API. */
				sprintf( __( 'QuickPay respondió HTTP %1$d (%2$s).', 'quickpay-woocommerce' ), $codigo, $error ),
				array(
					'status' => $codigo,
					'error'  => $error,
					'detail' => is_array( $datos ) && isset( $datos['detail'] ) ? $datos['detail'] : null,
				)
			);
		}
		if ( ! is_array( $datos ) ) {
			return new WP_Error( 'quickpay_json', __( 'QuickPay respondió algo que no es JSON.', 'quickpay-woocommerce' ), array( 'status' => $codigo ) );
		}
		$datos['_http'] = $codigo;
		return $datos;
	}
}
