<?php
/**
 * El único lugar que cambia el estado de pago de un pedido.
 *
 * Tres caminos pueden enterarse de un pago: el webhook `link.pagado`, la revisión
 * periódica (WP-Cron) y la página de "gracias". Pueden llegar a la vez (el webhook se
 * reintenta hasta 6 veces), así que todos pasan por aquí, bajo un candado por pedido,
 * y el pedido se marca pagado UNA sola vez.
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class QP_Pedidos {

	// Metadatos del pedido.
	const META_LINK      = '_quickpay_link_id';      // Enlace vigente.
	const META_URL       = '_quickpay_link_url';
	const META_MONTO     = '_quickpay_monto_ves';    // Monto del enlace vigente.
	const META_TASA      = '_quickpay_tasa';
	const META_MODO      = '_quickpay_modo';         // test | live del enlace vigente.
	const META_EXPIRA    = '_quickpay_expira_ts';    // Unix.
	const META_INTENTO   = '_quickpay_intento';
	const META_HIST      = '_quickpay_link_hist';    // No única: un valor por cada enlace creado.
	const META_INFO      = '_quickpay_links_info';   // JSON link_id => {monto, modo, creado}.
	const META_POLL      = '_quickpay_revisar';      // "1" mientras haya que consultarlo.
	const META_ULTIMA    = '_quickpay_ultima_consulta';
	const META_PAGADO    = '_quickpay_link_pagado';  // link_id que pagó el pedido.
	const META_REF       = '_quickpay_referencia';
	const META_AVISADO   = '_quickpay_avisado_';     // Prefijo: notas que sólo se escriben una vez.

	/** Resultados posibles de aplicar(). */
	const R_PAGADO      = 'pagado';
	const R_DUPLICADO   = 'duplicado';
	const R_REVISION    = 'requiere_revision';
	const R_DESCONOCIDO = 'desconocido';
	const R_OCUPADO     = 'ocupado';
	const R_SIN_CAMBIO  = 'sin_cambio';

	/**
	 * Busca el pedido dueño de un enlace (incluye enlaces anteriores del mismo pedido).
	 *
	 * @return WC_Order|null
	 */
	public static function pedido_por_enlace( string $link_id ) {
		if ( '' === $link_id ) {
			return null;
		}
		$ids = wc_get_orders(
			array(
				'limit'      => 2,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_HIST,
						'value' => $link_id,
					),
				),
			)
		);
		if ( 1 !== count( $ids ) ) {
			// 0 = no es nuestro. 2+ = no debería pasar nunca; no adivinamos cuál.
			return null;
		}
		$pedido = wc_get_order( $ids[0] );
		return $pedido instanceof WC_Order ? $pedido : null;
	}

	/** Información guardada de cada enlace del pedido. */
	public static function info_enlaces( WC_Order $pedido ): array {
		$info = json_decode( (string) $pedido->get_meta( self::META_INFO ), true );
		return is_array( $info ) ? $info : array();
	}

	/** Registra un enlace recién creado en el pedido (no guarda; el llamador hace save()). */
	public static function registrar_enlace( WC_Order $pedido, array $enlace, float $monto, float $tasa, string $modo, int $intento ): void {
		$id   = (string) $enlace['id'];
		$info = self::info_enlaces( $pedido );
		$nuevo = ! isset( $info[ $id ] );
		$info[ $id ] = array(
			'monto'  => $monto,
			'modo'   => $modo,
			'creado' => time(),
		);
		$pedido->update_meta_data( self::META_INFO, wp_json_encode( $info ) );
		if ( $nuevo ) {
			$pedido->add_meta_data( self::META_HIST, $id, false );
		}
		$expira = isset( $enlace['expires_at'] ) ? strtotime( (string) $enlace['expires_at'] ) : false;
		$pedido->update_meta_data( self::META_LINK, $id );
		$pedido->update_meta_data( self::META_URL, esc_url_raw( (string) $enlace['url'] ) );
		$pedido->update_meta_data( self::META_MONTO, wc_format_decimal( $monto, 2 ) );
		$pedido->update_meta_data( self::META_TASA, (string) $tasa );
		$pedido->update_meta_data( self::META_MODO, $modo );
		$pedido->update_meta_data( self::META_EXPIRA, $expira ? (string) $expira : '' );
		$pedido->update_meta_data( self::META_INTENTO, (string) $intento );
		$pedido->update_meta_data( self::META_POLL, '1' );
	}

	/**
	 * Aplica un pago confirmado por QuickPay al pedido. Idempotente.
	 *
	 * @param int    $pedido_id  Id del pedido.
	 * @param string $link_id    Enlace pagado.
	 * @param string $txn_id     Transacción de QuickPay.
	 * @param float  $monto      Monto pagado en VES, según QuickPay.
	 * @param string $referencia Referencia bancaria (sólo llega en el webhook).
	 * @param string $origen     "aviso", "consulta" o "pagina_gracias" (para la nota).
	 * @param string $modo_llave Modo de la llave configurada hoy (test|live).
	 */
	public static function aplicar_pago( int $pedido_id, string $link_id, string $txn_id, float $monto, string $referencia, string $origen, string $modo_llave ): string {
		if ( ! self::tomar_candado( $pedido_id ) ) {
			return self::R_OCUPADO;
		}
		try {
			// Releer dentro del candado: otro proceso pudo marcarlo hace un instante.
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $pedido_id, 'orders' );
				wp_cache_delete( $pedido_id, 'posts' );
				wp_cache_delete( $pedido_id, 'post_meta' );
			}
			$pedido = wc_get_order( $pedido_id );
			if ( ! $pedido instanceof WC_Order ) {
				return self::R_DESCONOCIDO;
			}
			$info = self::info_enlaces( $pedido );
			if ( ! isset( $info[ $link_id ] ) ) {
				return self::R_DESCONOCIDO;
			}
			$es_prueba = 'test' === ( $info[ $link_id ]['modo'] ?? '' );
			$etiqueta  = $es_prueba ? ' ' . __( '[PRUEBA: no se movió dinero]', 'quickpay-woocommerce' ) : '';

			$ya_pagado = (string) $pedido->get_meta( self::META_PAGADO );
			if ( '' !== $ya_pagado ) {
				if ( $ya_pagado !== $link_id ) {
					// Dos enlaces distintos del mismo pedido pagados: el cliente pagó dos veces.
					self::nota_unica(
						$pedido,
						'doble_' . $link_id,
						sprintf(
							/* translators: 1: monto, 2: id del enlace, 3: referencia, 4: etiqueta de prueba. */
							__( 'ATENCIÓN: QuickPay confirmó OTRO pago de Bs %1$s para este pedido, ya pagado, por el enlace %2$s (referencia %3$s). Revisa y devuelve el excedente al cliente.%4$s', 'quickpay-woocommerce' ),
							self::bs( $monto ),
							$link_id,
							'' !== $referencia ? $referencia : '—',
							$etiqueta
						)
					);
					do_action( 'quickpay_pago_duplicado', $pedido, $link_id, $txn_id, $monto );
				} elseif ( '' !== $referencia && '' === (string) $pedido->get_meta( self::META_REF ) ) {
					// Se marcó por consulta (sin referencia) y luego llegó el aviso: completar el dato.
					$pedido->update_meta_data( self::META_REF, sanitize_text_field( $referencia ) );
					/* translators: %s: referencia bancaria. */
					$pedido->add_order_note( sprintf( __( 'Referencia bancaria del pago QuickPay: %s.', 'quickpay-woocommerce' ), sanitize_text_field( $referencia ) ) );
					$pedido->save();
				}
				return self::R_DUPLICADO;
			}

			$monto_enlace = (float) ( $info[ $link_id ]['monto'] ?? 0 );
			if ( ! QP_Amount::iguales( $monto_enlace, $monto ) ) {
				return self::a_revision(
					$pedido,
					$link_id,
					$txn_id,
					$referencia,
					sprintf(
						/* translators: 1: monto pagado, 2: monto del enlace. */
						__( 'QuickPay informó un pago de Bs %1$s, pero el enlace se creó por Bs %2$s. El pedido queda en espera para revisión manual.', 'quickpay-woocommerce' ),
						self::bs( $monto ),
						self::bs( $monto_enlace )
					) . $etiqueta
				);
			}

			if ( $es_prueba && 'test' !== $modo_llave ) {
				// La tienda ya cobra en producción y llegó un pago de prueba: nunca es dinero real.
				self::nota_unica(
					$pedido,
					'prueba_' . $link_id,
					sprintf(
						/* translators: %s: id del enlace. */
						__( 'Se recibió la confirmación de un pago de PRUEBA (enlace %s), pero la tienda ya usa la llave de producción. No se marca pagado: un cobro de prueba nunca mueve dinero.', 'quickpay-woocommerce' ),
						$link_id
					)
				);
				$pedido->update_meta_data( self::META_POLL, '0' );
				$pedido->save();
				return self::R_SIN_CAMBIO;
			}

			$link_vigente  = (string) $pedido->get_meta( self::META_LINK );
			$monto_vigente = (float) $pedido->get_meta( self::META_MONTO );
			if ( $link_vigente !== $link_id && ! QP_Amount::iguales( $monto_vigente, $monto ) ) {
				// Pagó un enlace viejo cuyo monto ya no coincide con el del pedido.
				return self::a_revision(
					$pedido,
					$link_id,
					$txn_id,
					$referencia,
					sprintf(
						/* translators: 1: monto pagado, 2: id del enlace, 3: monto vigente. */
						__( 'El cliente pagó Bs %1$s con un enlace anterior (%2$s), pero el monto vigente del pedido es Bs %3$s. El pedido queda en espera para revisión manual.', 'quickpay-woocommerce' ),
						self::bs( $monto ),
						$link_id,
						self::bs( $monto_vigente )
					) . $etiqueta
				);
			}

			$estaba_cancelado = $pedido->has_status( array( 'cancelled', 'failed' ) );

			$pedido->update_meta_data( self::META_PAGADO, $link_id );
			$pedido->update_meta_data( self::META_POLL, '0' );
			if ( '' !== $referencia ) {
				$pedido->update_meta_data( self::META_REF, sanitize_text_field( $referencia ) );
			}
			$pedido->save();

			$pedido->add_order_note(
				sprintf(
					/* translators: 1: monto, 2: referencia, 3: id del enlace, 4: transacción, 5: origen, 6: etiqueta de prueba. */
					__( 'Pago confirmado por QuickPay: Bs %1$s. Referencia bancaria: %2$s. Enlace: %3$s. Transacción: %4$s. Confirmado por: %5$s.%6$s', 'quickpay-woocommerce' ),
					self::bs( $monto ),
					'' !== $referencia ? sanitize_text_field( $referencia ) : __( 'no disponible en la consulta', 'quickpay-woocommerce' ),
					$link_id,
					'' !== $txn_id ? $txn_id : '—',
					self::origen_legible( $origen ),
					$etiqueta
				)
			);
			if ( $estaba_cancelado ) {
				$pedido->add_order_note( __( 'El pedido estaba cancelado o fallido cuando llegó el pago. Se reactivó como pagado: revisa el inventario antes de despachar.', 'quickpay-woocommerce' ) );
			}
			$pedido->payment_complete( $txn_id );
			do_action( 'quickpay_pedido_pagado', $pedido, $link_id, $txn_id, $monto, $referencia );
			return self::R_PAGADO;
		} finally {
			self::soltar_candado( $pedido_id );
		}
	}

	/**
	 * Refleja en el pedido un estado del enlace que NO es "pagado" (sólo notas, una vez cada una).
	 */
	public static function aplicar_estado_no_pagado( WC_Order $pedido, string $link_id, string $estado ): void {
		$pedido->update_meta_data( self::META_ULTIMA, (string) time() );
		$vigente = (string) $pedido->get_meta( self::META_LINK );
		switch ( $estado ) {
			case 'expirado':
			case 'cancelado':
				if ( $vigente === $link_id ) {
					// Final: ya no hay nada que consultar de este enlace.
					$pedido->update_meta_data( self::META_POLL, '0' );
					self::nota_unica(
						$pedido,
						$estado . '_' . $link_id,
						'expirado' === $estado
							? __( 'El enlace de pago QuickPay venció sin pagarse.', 'quickpay-woocommerce' )
							: __( 'El enlace de pago QuickPay fue cancelado sin pagarse.', 'quickpay-woocommerce' )
					);
				}
				break;
			case 'procesando':
			case 'revision':
				self::nota_unica(
					$pedido,
					'procesando_' . $link_id,
					__( 'El banco está confirmando el pago de QuickPay. No le pidas al cliente que pague de nuevo: se seguirá consultando.', 'quickpay-woocommerce' )
				);
				break;
		}
		$pedido->save();
	}

	/** Deja el pedido en espera con una nota (una sola vez por enlace). */
	private static function a_revision( WC_Order $pedido, string $link_id, string $txn_id, string $referencia, string $nota ): string {
		$pedido->update_meta_data( self::META_PAGADO, $link_id );
		$pedido->update_meta_data( self::META_POLL, '0' );
		if ( '' !== $referencia ) {
			$pedido->update_meta_data( self::META_REF, sanitize_text_field( $referencia ) );
		}
		if ( '' !== $txn_id ) {
			$pedido->set_transaction_id( $txn_id );
		}
		$pedido->save();
		$pedido->update_status( 'on-hold', $nota );
		do_action( 'quickpay_pago_en_revision', $pedido, $link_id, $txn_id );
		return self::R_REVISION;
	}

	/** Agrega una nota sólo si no se agregó antes con la misma clave. */
	private static function nota_unica( WC_Order $pedido, string $clave, string $nota ): void {
		$meta = self::META_AVISADO . md5( $clave );
		if ( '' !== (string) $pedido->get_meta( $meta ) ) {
			return;
		}
		$pedido->update_meta_data( $meta, (string) time() );
		$pedido->save();
		$pedido->add_order_note( $nota );
	}

	private static function origen_legible( string $origen ): string {
		switch ( $origen ) {
			case 'aviso':
				return __( 'aviso firmado (webhook)', 'quickpay-woocommerce' );
			case 'consulta':
				return __( 'consulta periódica a QuickPay', 'quickpay-woocommerce' );
			case 'pagina_gracias':
				return __( 'consulta a QuickPay desde la página de confirmación', 'quickpay-woocommerce' );
		}
		return $origen;
	}

	/** Formato de bolívares para notas: 1.234,56. */
	public static function bs( $monto ): string {
		return number_format( (float) $monto, 2, ',', '.' );
	}

	/*
	 * Candado por pedido. Se usa un INSERT simple sobre la tabla de opciones: la columna
	 * option_name es única, así que sólo un proceso logra insertar. (add_option() no sirve:
	 * hace INSERT … ON DUPLICATE KEY UPDATE y "gana" siempre.) Funciona igual en MySQL y en
	 * cualquier motor con índice único; no depende de GET_LOCK().
	 */
	private static function nombre_candado( int $pedido_id ): string {
		return '_quickpay_candado_' . $pedido_id;
	}

	private static function tomar_candado( int $pedido_id ): bool {
		global $wpdb;
		$nombre = self::nombre_candado( $pedido_id );
		for ( $i = 0; $i < 2; $i++ ) {
			$ocultar = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $nombre, (string) time() ) );
			$wpdb->suppress_errors( $ocultar );
			if ( 1 === $ok ) {
				return true;
			}
			// Candado huérfano (un proceso murió con él tomado): se libera pasados 120 s.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$desde = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $nombre ) );
			if ( $desde > 0 && time() - $desde > 120 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $nombre, (string) $desde ) );
				continue;
			}
			return false;
		}
		return false;
	}

	private static function soltar_candado( int $pedido_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::nombre_candado( $pedido_id ) ) );
	}
}
