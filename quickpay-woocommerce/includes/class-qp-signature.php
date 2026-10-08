<?php
/**
 * Verificación de la firma v2 de los webhooks de QuickPay.
 *
 * Clase pura (sin WordPress) para poder probarla con `php tests/run.php`.
 *
 * Contrato (docs/developers/webhooks-referencia.md):
 *   x-quicklink-timestamp    = segundos UTC en que se firmó.
 *   x-quicklink-signature-v2 = "v2=" . hex(HMAC-SHA256(secreto, timestamp . "." . cuerpo)).
 * Se rechaza un aviso con más de 300 s de diferencia para que nadie reenvíe uno viejo.
 *
 * @package QuickPay_WooCommerce
 */

defined( 'ABSPATH' ) || defined( 'QP_TESTS' ) || exit;

final class QP_Signature {

	/** Tolerancia por omisión, en segundos. */
	const TOLERANCIA = 300;

	/**
	 * Calcula la firma v2 esperada.
	 *
	 * @param string $cuerpo    Cuerpo EXACTO recibido (antes de parsearlo).
	 * @param string $timestamp Valor de la cabecera x-quicklink-timestamp.
	 * @param string $secreto   Secreto del webhook.
	 */
	public static function firmar( string $cuerpo, string $timestamp, string $secreto ): string {
		return 'v2=' . hash_hmac( 'sha256', $timestamp . '.' . $cuerpo, $secreto );
	}

	/**
	 * Verifica la firma v2 y la antigüedad del aviso.
	 *
	 * @param string      $cuerpo     Cuerpo exacto.
	 * @param string|null $firma      Cabecera x-quicklink-signature-v2.
	 * @param string|null $timestamp  Cabecera x-quicklink-timestamp.
	 * @param string      $secreto    Secreto del webhook.
	 * @param int|null    $ahora      Hora actual (para pruebas). Por omisión time().
	 * @param int         $tolerancia Segundos permitidos de diferencia.
	 */
	public static function verificar( string $cuerpo, ?string $firma, ?string $timestamp, string $secreto, ?int $ahora = null, int $tolerancia = self::TOLERANCIA ): bool {
		if ( '' === $secreto || null === $firma || '' === $firma || null === $timestamp || '' === $timestamp ) {
			return false;
		}
		// Sólo dígitos: evita que "1700000000abc" pase por (int) y firme otra cosa.
		if ( ! ctype_digit( $timestamp ) ) {
			return false;
		}
		$ahora = $ahora ?? time();
		if ( abs( $ahora - (int) $timestamp ) > $tolerancia ) {
			return false;
		}
		return hash_equals( self::firmar( $cuerpo, $timestamp, $secreto ), trim( $firma ) );
	}
}
