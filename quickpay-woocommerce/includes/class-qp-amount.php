<?php
/**
 * Conversión del total del pedido a bolívares.
 *
 * QuickPay cobra sólo en bolívares (VES). Este plugin NO consulta ninguna tasa por su cuenta:
 * o la tienda ya vende en VES, o el comercio escribe una tasa fija en los ajustes.
 * Inventar una fuente de tasa sería cobrarle al cliente un monto que nadie decidió.
 *
 * Clase pura (sin WordPress) para poder probarla con `php tests/run.php`.
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || defined( 'QP_TESTS' ) || exit;

final class QP_Amount {

	const MODO_VES  = 'ves';
	const MODO_TASA = 'tasa_fija';

	/**
	 * Normaliza la tasa escrita por el comercio ("36,52" o "36.52") a float.
	 * Devuelve 0.0 si no es un número positivo.
	 */
	public static function parse_tasa( $valor ): float {
		$valor = trim( (string) $valor );
		if ( '' === $valor ) {
			return 0.0;
		}
		// Se acepta coma decimal; no se aceptan separadores de miles para no adivinar.
		$valor = str_replace( ',', '.', $valor );
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $valor ) ) {
			return 0.0;
		}
		$f = (float) $valor;
		return $f > 0 ? $f : 0.0;
	}

	/**
	 * Calcula el monto en VES.
	 *
	 * @param float  $total         Total del pedido en la moneda de la tienda.
	 * @param string $moneda_tienda Código ISO de la tienda (p. ej. "USD", "VES").
	 * @param string $modo          self::MODO_VES o self::MODO_TASA.
	 * @param float  $tasa          Bolívares por 1 unidad de la moneda de la tienda (sólo MODO_TASA).
	 * @return array{ok:bool, monto:float, tasa:float, error:string}
	 */
	public static function a_bolivares( float $total, string $moneda_tienda, string $modo, float $tasa ): array {
		$moneda_tienda = strtoupper( $moneda_tienda );
		if ( $total <= 0 ) {
			return array( 'ok' => false, 'monto' => 0.0, 'tasa' => 0.0, 'error' => 'total_no_positivo' );
		}
		if ( self::MODO_VES === $modo ) {
			if ( 'VES' !== $moneda_tienda ) {
				return array( 'ok' => false, 'monto' => 0.0, 'tasa' => 0.0, 'error' => 'moneda_no_ves' );
			}
			return array( 'ok' => true, 'monto' => round( $total, 2 ), 'tasa' => 1.0, 'error' => '' );
		}
		if ( self::MODO_TASA === $modo ) {
			if ( 'VES' === $moneda_tienda ) {
				// Tienda en VES con modo tasa: no se multiplica dos veces.
				return array( 'ok' => true, 'monto' => round( $total, 2 ), 'tasa' => 1.0, 'error' => '' );
			}
			if ( $tasa <= 0 ) {
				return array( 'ok' => false, 'monto' => 0.0, 'tasa' => 0.0, 'error' => 'tasa_invalida' );
			}
			$monto = round( $total * $tasa, 2 );
			if ( $monto <= 0 ) {
				return array( 'ok' => false, 'monto' => 0.0, 'tasa' => $tasa, 'error' => 'total_no_positivo' );
			}
			return array( 'ok' => true, 'monto' => $monto, 'tasa' => $tasa, 'error' => '' );
		}
		return array( 'ok' => false, 'monto' => 0.0, 'tasa' => 0.0, 'error' => 'modo_desconocido' );
	}

	/** Compara dos montos en bolívares al céntimo. */
	public static function iguales( $a, $b ): bool {
		return (int) round( (float) $a * 100 ) === (int) round( (float) $b * 100 );
	}

	/**
	 * Construye la idempotency_key del enlace. Primer intento = order_key a secas
	 * (lo que pide el contrato de la integración); si hubo que crear otro enlace para
	 * el mismo pedido (venció o cambió el monto), se agrega "-2", "-3"…
	 * Hasta 64 caracteres.
	 */
	public static function idempotency_key( string $order_key, int $intento ): string {
		$base = substr( $order_key, 0, 56 );
		return $intento <= 1 ? $base : $base . '-' . $intento;
	}

	/** Recorta el concepto a 140 caracteres (multibyte). */
	public static function concepto( string $texto ): string {
		$texto = trim( preg_replace( '/\s+/u', ' ', $texto ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $texto, 0, 140 );
		}
		return substr( $texto, 0, 140 );
	}
}
