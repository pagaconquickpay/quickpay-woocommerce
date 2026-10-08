<?php
/**
 * Pasarela "Paga con QuickPay" para WooCommerce.
 *
 * Flujo: al pagar, se crea un enlace de pago de QuickPay por pedido (idempotency_key =
 * clave del pedido) y se manda al cliente a pagar allí. El pedido se marca pagado sólo
 * cuando QuickPay lo confirma (webhook firmado o consulta GET /links/:id), nunca porque
 * el cliente volvió a la tienda con "?estado=pagado": eso lo puede escribir cualquiera.
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class QP_Gateway extends WC_Payment_Gateway {

	const ID = 'quickpay';

	/** @var QP_Gateway|null */
	private static $instancia = null;

	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'QuickPay', 'quickpay-woocommerce' );
		$this->method_description = __( 'Cobra en bolívares con un enlace de pago de QuickPay: tu cliente elige su banco y autoriza con la clave que le envía su banco.', 'quickpay-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'mensaje_gracias' ) );
	}

	/** Instancia compartida (para webhook, cron y bloques). */
	public static function instancia(): QP_Gateway {
		if ( null === self::$instancia ) {
			$pasarelas = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
			self::$instancia = ( isset( $pasarelas[ self::ID ] ) && $pasarelas[ self::ID ] instanceof QP_Gateway ) ? $pasarelas[ self::ID ] : new self();
		}
		return self::$instancia;
	}

	public function init_form_fields() {
		$url_webhook = rest_url( 'quickpay/v1/webhook' );
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Activar', 'quickpay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activar QuickPay como método de pago', 'quickpay-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Título', 'quickpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Lo que ve el cliente al elegir cómo pagar.', 'quickpay-woocommerce' ),
				'default'     => __( 'Paga con QuickPay', 'quickpay-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Descripción', 'quickpay-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Texto que acompaña al método de pago en la caja.', 'quickpay-woocommerce' ),
				'default'     => __( 'Paga en bolívares desde tu banco. Te llevaremos a la página segura de QuickPay para completar el pago.', 'quickpay-woocommerce' ),
				'desc_tip'    => true,
			),
			'api_key'        => array(
				'title'       => __( 'Llave de API', 'quickpay-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Formato comercio:sk_test_… (prueba) o comercio:sk_live_… (producción). La sacas en tu portal, en Integraciones › Llaves de API. El modo lo decide la llave: con sk_test_ nunca se mueve dinero.', 'quickpay-woocommerce' ),
				'default'     => '',
			),
			'webhook_secret' => array(
				'title'       => __( 'Secreto del webhook', 'quickpay-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: URL del webhook de la tienda. */
					__( 'En tu portal, Integraciones › Webhooks › Agregar URL, registra esta dirección con el evento link.pagado: %s — y pega aquí el secreto que te muestra (se muestra una sola vez).', 'quickpay-woocommerce' ),
					'<code>' . esc_html( $url_webhook ) . '</code>'
				),
				'default'     => '',
			),
			'moneda_modo'    => array(
				'title'       => __( 'Moneda de la tienda', 'quickpay-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'QuickPay cobra sólo en bolívares. Si tu tienda vende en otra moneda, el total se multiplica por la tasa que escribas abajo. Este plugin no consulta ninguna tasa por su cuenta: la decides tú y la mantienes al día.', 'quickpay-woocommerce' ),
				'default'     => QP_Amount::MODO_VES,
				'options'     => array(
					QP_Amount::MODO_VES  => __( 'Mi tienda ya vende en bolívares (VES)', 'quickpay-woocommerce' ),
					QP_Amount::MODO_TASA => __( 'Mi tienda vende en otra moneda: usar una tasa fija', 'quickpay-woocommerce' ),
				),
			),
			'tasa'           => array(
				'title'       => __( 'Tasa fija (Bs por 1 unidad de la moneda de la tienda)', 'quickpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Sólo si elegiste tasa fija. Ejemplo: si vendes en USD y escribes 40,00, un pedido de 10 USD se cobra Bs 400,00. Se guarda en cada pedido la tasa usada.', 'quickpay-woocommerce' ),
				'default'     => '',
				'placeholder' => '0,00',
			),
			'expira_horas'   => array(
				'title'             => __( 'Vigencia del enlace (horas)', 'quickpay-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'De 1 a 720. Mientras el enlace esté vigente, WooCommerce no cancela el pedido por falta de pago.', 'quickpay-woocommerce' ),
				'default'           => '24',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '720',
					'step' => '1',
				),
			),
			'debug'          => array(
				'title'       => __( 'Registro', 'quickpay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Guardar un registro detallado (WooCommerce › Estado › Registros, fuente "quickpay")', 'quickpay-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Los errores se registran siempre. La llave y el secreto nunca se escriben en el registro.', 'quickpay-woocommerce' ),
			),
		);
	}

	/** Aviso del modo actual arriba de los ajustes. */
	public function admin_options() {
		$modo = QP_Api::modo_de_llave( (string) $this->get_option( 'api_key' ) );
		if ( 'test' === $modo ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Modo prueba: la llave es sk_test_. Los cobros no mueven dinero. En la página de pago usa cualquier banco, teléfono y cédula de ejemplo y cualquier clave de 8 dígitos.', 'quickpay-woocommerce' ) . '</p></div>';
		} elseif ( 'live' === $modo ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Modo producción: la llave es sk_live_. Los cobros son reales.', 'quickpay-woocommerce' ) . '</p></div>';
		} elseif ( '' !== (string) $this->get_option( 'api_key' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'La llave no tiene el formato comercio:sk_test_… o comercio:sk_live_….', 'quickpay-woocommerce' ) . '</p></div>';
		}
		if ( 0 !== strpos( (string) rest_url(), 'https://' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Tu sitio no usa https://. QuickPay sólo envía webhooks y regresa al cliente a direcciones https://.', 'quickpay-woocommerce' ) . '</p></div>';
		}
		parent::admin_options();
	}

	/** Valida la tasa al guardar. */
	public function validate_tasa_field( $key, $value ) {
		$value = is_null( $value ) ? '' : trim( (string) $value );
		if ( '' !== $value && QP_Amount::parse_tasa( $value ) <= 0 ) {
			WC_Admin_Settings::add_error( __( 'La tasa debe ser un número mayor que cero (por ejemplo 40,00).', 'quickpay-woocommerce' ) );
			return (string) $this->get_option( 'tasa' );
		}
		return $value;
	}

	public function validate_expira_horas_field( $key, $value ) {
		$horas = (int) $value;
		return (string) min( 720, max( 1, $horas ? $horas : 24 ) );
	}

	public function modo_llave(): string {
		return QP_Api::modo_de_llave( (string) $this->get_option( 'api_key' ) );
	}

	public function api(): QP_Api {
		return new QP_Api( (string) $this->get_option( 'api_key' ) );
	}

	public function secreto_webhook(): string {
		return trim( (string) $this->get_option( 'webhook_secret' ) );
	}

	/** Disponible sólo si está bien configurada y la moneda cuadra. */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( '' === $this->modo_llave() ) {
			return false;
		}
		$moneda = get_woocommerce_currency();
		$modo   = (string) $this->get_option( 'moneda_modo', QP_Amount::MODO_VES );
		if ( QP_Amount::MODO_VES === $modo && 'VES' !== strtoupper( $moneda ) ) {
			return false;
		}
		if ( QP_Amount::MODO_TASA === $modo && 'VES' !== strtoupper( $moneda ) && QP_Amount::parse_tasa( $this->get_option( 'tasa' ) ) <= 0 ) {
			return false;
		}
		return true;
	}

	/** Monto en VES del pedido según los ajustes. */
	public function monto_pedido( WC_Order $pedido ): array {
		return QP_Amount::a_bolivares(
			(float) $pedido->get_total(),
			(string) $pedido->get_currency(),
			(string) $this->get_option( 'moneda_modo', QP_Amount::MODO_VES ),
			QP_Amount::parse_tasa( $this->get_option( 'tasa' ) )
		);
	}

	public function process_payment( $order_id ) {
		$pedido = wc_get_order( $order_id );
		if ( ! $pedido instanceof WC_Order ) {
			wc_add_notice( __( 'No encontramos el pedido.', 'quickpay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$res = $this->enlace_para_pedido( $pedido );
		if ( is_wp_error( $res ) ) {
			wc_add_notice( $res->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( isset( $res['pagado'] ) && $res['pagado'] ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $pedido ),
			);
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return array(
			'result'   => 'success',
			'redirect' => $res['url'],
		);
	}

	/**
	 * Devuelve el enlace vigente del pedido o crea uno.
	 *
	 * @return array{url:string, pagado?:bool}|WP_Error
	 */
	public function enlace_para_pedido( WC_Order $pedido ) {
		$calc = $this->monto_pedido( $pedido );
		if ( ! $calc['ok'] ) {
			$this->log( 'error', 'No se pudo calcular el monto en VES del pedido ' . $pedido->get_id() . ': ' . $calc['error'] );
			return new WP_Error( 'quickpay_monto', __( 'No pudimos calcular el monto en bolívares de tu pedido. Elige otro método de pago o contacta a la tienda.', 'quickpay-woocommerce' ) );
		}
		$monto = (float) $calc['monto'];
		$modo  = $this->modo_llave();

		// Reusar el enlace vigente si sirve: mismo monto, mismo modo y le queda al menos 10 min.
		$link_actual = (string) $pedido->get_meta( QP_Pedidos::META_LINK );
		$expira      = (int) $pedido->get_meta( QP_Pedidos::META_EXPIRA );
		if ( '' !== $link_actual
			&& QP_Amount::iguales( $pedido->get_meta( QP_Pedidos::META_MONTO ), $monto )
			&& $modo === (string) $pedido->get_meta( QP_Pedidos::META_MODO )
			&& $expira > time() + 600 ) {
			return array( 'url' => (string) $pedido->get_meta( QP_Pedidos::META_URL ) );
		}

		$intento = max( 1, (int) $pedido->get_meta( QP_Pedidos::META_INTENTO ) );
		if ( '' !== $link_actual ) {
			++$intento;
		}

		$api   = $this->api();
		$horas = (int) $this->get_option( 'expira_horas', 24 );
		$horas = min( 720, max( 1, $horas ? $horas : 24 ) );

		$cuerpo = array(
			'monto'        => $monto,
			'concepto'     => QP_Amount::concepto(
				sprintf(
					/* translators: 1: número de pedido, 2: nombre de la tienda. */
					__( 'Pedido #%1$s · %2$s', 'quickpay-woocommerce' ),
					$pedido->get_order_number(),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				)
			),
			'expira_horas' => $horas,
			'metadata'     => array(
				'woo_order_id'  => (string) $pedido->get_id(),
				'woo_order_num' => substr( (string) $pedido->get_order_number(), 0, 200 ),
				'tienda'        => substr( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 0, 200 ),
			),
		);
		$retorno = $this->get_return_url( $pedido );
		if ( 0 === strpos( $retorno, 'https://' ) && strlen( $retorno ) <= 400 ) {
			$cuerpo['success_url'] = $retorno;
		} else {
			$this->log( 'warning', 'success_url omitida (no es https:// o pasa de 400 caracteres) en el pedido ' . $pedido->get_id() );
		}

		$enlace = null;
		for ( $vuelta = 0; $vuelta < 3; $vuelta++ ) {
			$cuerpo['idempotency_key'] = QP_Amount::idempotency_key( $pedido->get_order_key(), $intento );
			$resp = $api->crear_enlace( $cuerpo );
			if ( is_wp_error( $resp ) ) {
				$datos  = $resp->get_error_data();
				$status = is_array( $datos ) && isset( $datos['status'] ) ? (int) $datos['status'] : 0;
				// Red o 5xx: se reintenta UNA vez con la MISMA llave (si el primero llegó, devuelve el mismo enlace).
				if ( ( 0 === $status || $status >= 500 ) && 0 === $vuelta ) {
					$this->log( 'warning', 'Reintentando crear enlace del pedido ' . $pedido->get_id() . ': ' . $resp->get_error_message() );
					continue;
				}
				$this->log( 'error', 'Error al crear enlace del pedido ' . $pedido->get_id() . ': ' . $resp->get_error_message() . ' ' . wp_json_encode( $datos ) );
				return new WP_Error( 'quickpay_crear', __( 'No pudimos iniciar el pago con QuickPay. Intenta de nuevo en unos minutos o elige otro método de pago.', 'quickpay-woocommerce' ) );
			}
			$estado = (string) ( $resp['estado'] ?? '' );
			if ( 'pagado' === $estado ) {
				// La misma llave ya tenía un enlace pagado: el pedido ya se pagó. Aplicarlo.
				$this->registrar( $pedido, $resp, $monto, (float) $calc['tasa'], $modo, $intento );
				QP_Pedidos::aplicar_pago( $pedido->get_id(), (string) $resp['id'], (string) ( $resp['txn_id'] ?? '' ), (float) $resp['monto'], '', 'consulta', $modo );
				return array(
					'url'    => (string) $resp['url'],
					'pagado' => true,
				);
			}
			$mismo_monto = isset( $resp['monto'] ) && QP_Amount::iguales( $resp['monto'], $monto );
			if ( ! $mismo_monto || in_array( $estado, array( 'expirado', 'cancelado' ), true ) || empty( $resp['id'] ) || empty( $resp['url'] ) ) {
				// Repetición de un enlace viejo que ya no sirve: pedir uno con la siguiente llave.
				$this->log( 'info', 'Enlace devuelto no sirve para el pedido ' . $pedido->get_id() . ' (estado ' . $estado . '); se crea otro.' );
				++$intento;
				continue;
			}
			$enlace = $resp;
			break;
		}
		if ( null === $enlace ) {
			return new WP_Error( 'quickpay_crear', __( 'No pudimos iniciar el pago con QuickPay. Intenta de nuevo en unos minutos o elige otro método de pago.', 'quickpay-woocommerce' ) );
		}

		$this->registrar( $pedido, $enlace, $monto, (float) $calc['tasa'], $modo, $intento );
		$nota = sprintf(
			/* translators: 1: monto en Bs, 2: id del enlace. */
			__( 'Enlace de pago QuickPay creado por Bs %1$s (enlace %2$s).', 'quickpay-woocommerce' ),
			QP_Pedidos::bs( $monto ),
			(string) $enlace['id']
		);
		if ( 'VES' !== strtoupper( $pedido->get_currency() ) ) {
			$nota .= ' ' . sprintf(
				/* translators: 1: tasa, 2: moneda de la tienda. */
				__( 'Tasa fija usada: Bs %1$s por 1 %2$s.', 'quickpay-woocommerce' ),
				QP_Pedidos::bs( $calc['tasa'] ),
				$pedido->get_currency()
			);
		}
		if ( 'test' === $modo ) {
			$nota .= ' ' . __( '[PRUEBA: no mueve dinero]', 'quickpay-woocommerce' );
		}
		if ( ! empty( $enlace['idempotent_replay'] ) ) {
			$nota .= ' ' . __( '(QuickPay devolvió el enlace que ya existía para este pedido.)', 'quickpay-woocommerce' );
		}
		$pedido->add_order_note( $nota );
		return array( 'url' => (string) $enlace['url'] );
	}

	private function registrar( WC_Order $pedido, array $enlace, float $monto, float $tasa, string $modo, int $intento ): void {
		QP_Pedidos::registrar_enlace( $pedido, $enlace, $monto, $tasa, $modo, $intento );
		$pedido->save();
	}

	/**
	 * Consulta el enlace vigente (y los anteriores si siguen abiertos) y aplica lo que diga QuickPay.
	 *
	 * @param string $origen "consulta" o "pagina_gracias".
	 * @return string Estado del enlace vigente según QuickPay ("" si no se pudo consultar).
	 */
	public function revisar_pedido( WC_Order $pedido, string $origen ): string {
		$info    = QP_Pedidos::info_enlaces( $pedido );
		$vigente = (string) $pedido->get_meta( QP_Pedidos::META_LINK );
		if ( '' === $vigente || empty( $info ) ) {
			return '';
		}
		$pedido->update_meta_data( QP_Pedidos::META_ULTIMA, (string) time() );
		$pedido->save();

		$api            = $this->api();
		$estado_vigente = '';
		// El vigente primero; los anteriores también, porque el cliente pudo pagar uno viejo
		// desde otra pestaña. Se consultan sólo si aún pueden pagarse (creados hace < 31 días).
		$ids = array_unique( array_merge( array( $vigente ), array_keys( $info ) ) );
		foreach ( $ids as $link_id ) {
			$link_id = (string) $link_id;
			if ( $link_id !== $vigente && ( time() - (int) ( $info[ $link_id ]['creado'] ?? 0 ) ) > 31 * DAY_IN_SECONDS ) {
				continue;
			}
			$resp = $api->obtener_enlace( $link_id );
			if ( is_wp_error( $resp ) ) {
				$this->log( 'warning', 'No se pudo consultar el enlace ' . $link_id . ': ' . $resp->get_error_message() );
				continue;
			}
			$estado = (string) ( $resp['estado'] ?? '' );
			if ( $link_id === $vigente ) {
				$estado_vigente = $estado;
			}
			if ( 'pagado' === $estado ) {
				$r = QP_Pedidos::aplicar_pago( $pedido->get_id(), $link_id, (string) ( $resp['txn_id'] ?? '' ), (float) ( $resp['monto'] ?? 0 ), '', $origen, $this->modo_llave() );
				$this->log( 'info', 'Pedido ' . $pedido->get_id() . ' enlace ' . $link_id . ' pagado por ' . $origen . ': ' . $r );
				break;
			}
			$pedido = wc_get_order( $pedido->get_id() );
			QP_Pedidos::aplicar_estado_no_pagado( $pedido, $link_id, $estado );
		}
		return $estado_vigente;
	}

	/** Mensaje en la página de "gracias" según el estado real del cobro. */
	public function mensaje_gracias( $order_id ) {
		$pedido = wc_get_order( $order_id );
		if ( ! $pedido instanceof WC_Order ) {
			return;
		}
		if ( $pedido->is_paid() || '' !== (string) $pedido->get_meta( QP_Pedidos::META_PAGADO ) ) {
			if ( $pedido->has_status( 'on-hold' ) ) {
				echo '<p class="quickpay-estado">' . esc_html__( 'Recibimos tu pago y la tienda lo está revisando. Te avisaremos.', 'quickpay-woocommerce' ) . '</p>';
			} else {
				echo '<p class="quickpay-estado">' . esc_html__( '¡Gracias! Tu pago fue confirmado.', 'quickpay-woocommerce' ) . '</p>';
			}
			return;
		}
		$estado = QP_Gracias::$estado_consultado[ $pedido->get_id() ] ?? '';
		if ( in_array( $estado, array( 'procesando', 'revision', 'cobrando' ), true ) ) {
			echo '<p class="quickpay-estado">' . esc_html__( 'Tu banco está confirmando el pago. Te avisaremos. No hace falta que pagues otra vez.', 'quickpay-woocommerce' ) . '</p>';
			return;
		}
		$url = (string) $pedido->get_meta( QP_Pedidos::META_URL );
		echo '<p class="quickpay-estado">' . esc_html__( 'Todavía no recibimos tu pago.', 'quickpay-woocommerce' );
		if ( '' !== $url && $pedido->needs_payment() && ! in_array( $estado, array( 'expirado', 'cancelado' ), true ) ) {
			echo ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Pagar con QuickPay', 'quickpay-woocommerce' ) . '</a>';
		} elseif ( $pedido->needs_payment() ) {
			echo ' <a class="button" href="' . esc_url( $pedido->get_checkout_payment_url() ) . '">' . esc_html__( 'Intentar de nuevo', 'quickpay-woocommerce' ) . '</a>';
		}
		echo '</p>';
	}

	/** Registro con WC_Logger. Nunca recibe la llave ni el secreto. */
	public function log( string $nivel, string $mensaje ): void {
		if ( 'error' !== $nivel && 'yes' !== $this->get_option( 'debug' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $nivel, $mensaje, array( 'source' => 'quickpay' ) );
		}
	}
}
