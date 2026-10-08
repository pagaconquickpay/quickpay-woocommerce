<?php
/**
 * Pruebas sin PHPUnit de las partes puras del plugin.
 *
 *   php woocommerce/tests/run.php
 *
 * Si se le pasa un archivo JSON con vectores (generados por sign-vectors.mjs con el SDK de
 * Node), también comprueba que PHP y Node firman igual:
 *
 *   node woocommerce/tests/sign-vectors.mjs > /tmp/v.json && php woocommerce/tests/run.php /tmp/v.json
 */

define( 'QP_TESTS', true );
require __DIR__ . '/../quickpay-woocommerce/includes/class-qp-signature.php';
require __DIR__ . '/../quickpay-woocommerce/includes/class-qp-amount.php';

$fallas = 0;
$total  = 0;
function ok( bool $cond, string $nombre ): void {
	global $fallas, $total;
	++$total;
	if ( $cond ) {
		echo "  ok   $nombre\n";
	} else {
		++$fallas;
		echo "  FALLA $nombre\n";
	}
}

echo "Firma v2\n";
$secreto = 'whsec_prueba_123';
$cuerpo  = '{"type":"link.pagado","sentAt":"2026-10-08T14:03:22.000Z","data":{"link_id":"3f6c2a9e-8b1d-4c7a-9e2f-5a1b7c3d9e40","txn_id":"b639fac6","estado":"pagado","monto":150,"moneda":"VES","concepto":"Pedido #1024","referencia":"10242808"}}';
$ahora   = 1791468202;
$ts      = (string) $ahora;
$firma   = QP_Signature::firmar( $cuerpo, $ts, $secreto );

// Vector calculado a mano con la fórmula del contrato: hex(HMAC-SHA256(secreto, ts.".".cuerpo)).
ok( $firma === 'v2=' . hash_hmac( 'sha256', $ts . '.' . $cuerpo, $secreto ), 'firmar() sigue la fórmula del contrato' );
ok( QP_Signature::verificar( $cuerpo, $firma, $ts, $secreto, $ahora ), 'firma correcta se acepta' );
ok( QP_Signature::verificar( $cuerpo, $firma . "\n", $ts, $secreto, $ahora ), 'espacio final en la cabecera se tolera' );
ok( ! QP_Signature::verificar( $cuerpo . ' ', $firma, $ts, $secreto, $ahora ), 'cuerpo alterado (un espacio) se rechaza' );
ok( ! QP_Signature::verificar( str_replace( '150', '15000', $cuerpo ), $firma, $ts, $secreto, $ahora ), 'monto alterado se rechaza' );
ok( ! QP_Signature::verificar( $cuerpo, $firma, $ts, 'otro_secreto', $ahora ), 'secreto distinto se rechaza' );
ok( ! QP_Signature::verificar( $cuerpo, $firma, (string) ( $ahora + 1 ), $secreto, $ahora ), 'timestamp distinto al firmado se rechaza' );
ok( QP_Signature::verificar( $cuerpo, $firma, $ts, $secreto, $ahora + 300 ), 'aviso de exactamente 300 s se acepta' );
ok( ! QP_Signature::verificar( $cuerpo, $firma, $ts, $secreto, $ahora + 301 ), 'aviso de 301 s se rechaza (repetición vieja)' );
ok( ! QP_Signature::verificar( $cuerpo, $firma, $ts, $secreto, $ahora - 301 ), 'aviso 301 s en el futuro se rechaza' );
ok( ! QP_Signature::verificar( $cuerpo, null, $ts, $secreto, $ahora ), 'sin firma se rechaza' );
ok( ! QP_Signature::verificar( $cuerpo, $firma, null, $secreto, $ahora ), 'sin timestamp se rechaza' );
ok( ! QP_Signature::verificar( $cuerpo, $firma, $ts, '', $ahora ), 'secreto vacío nunca acepta' );
ok( ! QP_Signature::verificar( $cuerpo, $firma, $ts . 'x', $secreto, $ahora ), 'timestamp con letras se rechaza' );
$v1 = 'sha256=' . hash_hmac( 'sha256', $cuerpo, $secreto );
ok( ! QP_Signature::verificar( $cuerpo, $v1, $ts, $secreto, $ahora ), 'la firma v1 (sha256=…) no se acepta como v2' );

if ( isset( $argv[1] ) ) {
	echo "Vectores generados por el SDK de Node (verificarWebhook)\n";
	$vectores = json_decode( (string) file_get_contents( $argv[1] ), true );
	ok( is_array( $vectores ) && count( $vectores ) > 0, 'archivo de vectores legible' );
	foreach ( (array) $vectores as $i => $v ) {
		$php = QP_Signature::verificar( $v['cuerpo'], $v['firma'], $v['timestamp'], $v['secreto'], (int) $v['ahora'] );
		ok( $php === (bool) $v['node_acepta'], "vector $i: PHP=" . var_export( $php, true ) . ' Node=' . var_export( $v['node_acepta'], true ) . ' (' . $v['caso'] . ')' );
		if ( isset( $v['firma_node'] ) ) {
			ok( QP_Signature::firmar( $v['cuerpo'], $v['timestamp'], $v['secreto'] ) === $v['firma_node'], "vector $i: PHP firma igual que Node" );
		}
	}
}

echo "Monto en bolívares\n";
$r = QP_Amount::a_bolivares( 1500.5, 'VES', QP_Amount::MODO_VES, 0 );
ok( $r['ok'] && 1500.5 === $r['monto'], 'tienda en VES: monto igual' );
$r = QP_Amount::a_bolivares( 10, 'USD', QP_Amount::MODO_VES, 0 );
ok( ! $r['ok'] && 'moneda_no_ves' === $r['error'], 'tienda en USD con modo VES: no cobra' );
$r = QP_Amount::a_bolivares( 10, 'USD', QP_Amount::MODO_TASA, 40.0 );
ok( $r['ok'] && 400.0 === $r['monto'] && 40.0 === $r['tasa'], 'USD 10 × tasa 40 = Bs 400' );
$r = QP_Amount::a_bolivares( 19.99, 'usd', QP_Amount::MODO_TASA, 36.5249 );
ok( $r['ok'] && 730.13 === $r['monto'], 'redondeo al céntimo (19,99 × 36,5249 = 730,13)' );
$r = QP_Amount::a_bolivares( 10, 'USD', QP_Amount::MODO_TASA, 0 );
ok( ! $r['ok'] && 'tasa_invalida' === $r['error'], 'tasa vacía: no cobra (no se inventa tasa)' );
$r = QP_Amount::a_bolivares( 250, 'VES', QP_Amount::MODO_TASA, 40 );
ok( $r['ok'] && 250.0 === $r['monto'], 'tienda en VES con modo tasa: no multiplica dos veces' );
$r = QP_Amount::a_bolivares( 0, 'VES', QP_Amount::MODO_VES, 0 );
ok( ! $r['ok'], 'total cero: no cobra' );
$r = QP_Amount::a_bolivares( 0.001, 'USD', QP_Amount::MODO_TASA, 1 );
ok( ! $r['ok'], 'total que redondea a Bs 0,00: no cobra' );
ok( 40.0 === QP_Amount::parse_tasa( '40' ), 'parse_tasa("40")' );
ok( 36.52 === QP_Amount::parse_tasa( '36,52' ), 'parse_tasa("36,52") acepta coma decimal' );
ok( 0.0 === QP_Amount::parse_tasa( '1.234,56' ), 'parse_tasa con miles se rechaza (no se adivina)' );
ok( 0.0 === QP_Amount::parse_tasa( '-5' ), 'parse_tasa negativa se rechaza' );
ok( 0.0 === QP_Amount::parse_tasa( 'abc' ), 'parse_tasa texto se rechaza' );
ok( QP_Amount::iguales( 150, '150.00' ) && ! QP_Amount::iguales( 150, 150.01 ), 'iguales() compara al céntimo' );
ok( QP_Amount::iguales( 0.1 + 0.2, 0.3 ), 'iguales() no sufre por coma flotante' );

echo "Idempotency key y concepto\n";
ok( 'wc_order_abc123' === QP_Amount::idempotency_key( 'wc_order_abc123', 1 ), 'primer intento = clave del pedido' );
ok( 'wc_order_abc123-2' === QP_Amount::idempotency_key( 'wc_order_abc123', 2 ), 'segundo intento = clave-2' );
ok( strlen( QP_Amount::idempotency_key( str_repeat( 'k', 100 ), 999 ) ) <= 64, 'nunca pasa de 64 caracteres' );
$c = QP_Amount::concepto( 'Pedido #1 · ' . str_repeat( 'ñ', 200 ) );
ok( 140 === mb_strlen( $c ), 'concepto recortado a 140 caracteres multibyte' );
ok( mb_check_encoding( $c, 'UTF-8' ), 'concepto recortado sigue siendo UTF-8 válido' );

echo "\n$total pruebas, $fallas fallas\n";
exit( $fallas > 0 ? 1 : 0 );
