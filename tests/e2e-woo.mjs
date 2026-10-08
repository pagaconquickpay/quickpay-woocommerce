// Prueba de punta a punta del plugin contra un WordPress + WooCommerce REAL (local) y la API
// SIMULADA de QuickPay (tools/mock-quickpay.mjs). No toca ningún servidor de QuickPay.
//
// Requisitos (ver woocommerce/tests/README.md):
//   WP en http://127.0.0.1:8080 con WooCommerce + este plugin, QUICKPAY_API_BASE apuntando al mock,
//   un producto simple con id PRODUCTO, y WP=/ruta/al/wp y WPCLI=/ruta/a/wp-cli.phar.
//
//   WP=… WPCLI=… PRODUCTO=10 node woocommerce/tests/e2e-woo.mjs
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const MOCK = process.env.MOCK || 'http://127.0.0.1:8787';
const PRODUCTO = Number(process.env.PRODUCTO || 10);
const SECRETO = 'whsec_e2e_secreto';
const WEBHOOK = `${SITE}/?rest_route=/quickpay/v1/webhook`;

let fallas = 0, total = 0;
const ok = (c, n) => { total++; if (!c) fallas++; console.log(`  ${c ? 'ok  ' : 'FALLA'} ${n}`); };

const wp = (...args) => execFileSync('php', [process.env.WPCLI, '--path=' + process.env.WP, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const php = (code) => wp('eval', code);
const ajustes = (cambios) => {
  const s = JSON.parse(wp('option', 'get', 'woocommerce_quickpay_settings', '--format=json'));
  wp('option', 'update', 'woocommerce_quickpay_settings', JSON.stringify({ ...s, ...cambios }), '--format=json');
};
const pedido = (id) => JSON.parse(php(`$o = wc_get_order(${id}); echo wp_json_encode(array(
  'status' => $o->get_status(), 'txn' => $o->get_transaction_id(), 'paid' => $o->is_paid(),
  'link' => $o->get_meta('_quickpay_link_id'), 'monto' => $o->get_meta('_quickpay_monto_ves'),
  'ref' => $o->get_meta('_quickpay_referencia'), 'poll' => $o->get_meta('_quickpay_revisar'),
  'key' => $o->get_order_key(), 'total' => $o->get_total(),
  'hist' => array_values(array_map(function($m){return $m->value;}, $o->get_meta('_quickpay_link_hist', false))),
  'notas' => array_values(array_map(function($n){return $n->content;}, wc_get_order_notes(array('order_id' => ${id}, 'order' => 'ASC')))),
));`));
const mock = async (ruta, body) => (await fetch(MOCK + ruta, { method: body ? 'POST' : 'GET', headers: { 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })).json();
const notasCon = (p, txt) => p.notas.filter((n) => n.includes(txt)).length;

async function comprar(cantidad = 1) {
  let r = await fetch(`${SITE}/?rest_route=/wc/store/v1/cart`);
  const h = { 'content-type': 'application/json', Nonce: r.headers.get('nonce'), 'Cart-Token': r.headers.get('cart-token') };
  r = await fetch(`${SITE}/?rest_route=/wc/store/v1/cart/add-item`, { method: 'POST', headers: h, body: JSON.stringify({ id: PRODUCTO, quantity: cantidad }) });
  const carrito = await r.json();
  const dir = { first_name: 'Ana', last_name: 'Prueba', address_1: 'Av. Principal 1', city: 'Caracas', state: 'VE-A', postcode: '1010', country: 'VE', email: 'ana@example.com', phone: '' };
  r = await fetch(`${SITE}/?rest_route=/wc/store/v1/checkout`, { method: 'POST', headers: h, body: JSON.stringify({ billing_address: dir, shipping_address: dir, payment_method: 'quickpay' }) });
  return { carrito, checkout: await r.json(), status: r.status };
}

function firmar(cuerpo, ts = Math.floor(Date.now() / 1000)) {
  return { ts: String(ts), firma: 'v2=' + createHmac('sha256', SECRETO).update(`${ts}.${cuerpo}`).digest('hex') };
}
async function webhookManual(data, { ts, firmaMala } = {}) {
  const cuerpo = JSON.stringify({ type: 'link.pagado', sentAt: new Date().toISOString(), data });
  const f = firmar(cuerpo, ts);
  const r = await fetch(WEBHOOK, { method: 'POST', headers: { 'content-type': 'application/json', 'x-quicklink-event': 'link.pagado', 'x-quicklink-timestamp': f.ts, 'x-quicklink-signature-v2': firmaMala ? f.firma.replace(/.$/, '0') === f.firma ? f.firma.replace(/.$/, '1') : f.firma.replace(/.$/, '0') : f.firma }, body: cuerpo });
  return { status: r.status, body: await r.json().catch(() => null) };
}

const LOG = `${process.env.WP}/wp-content/debug.log`;
const lineasLogAntes = Number(execFileSync('bash', ['-c', `wc -l < ${LOG} 2>/dev/null || echo 0`], { encoding: 'utf8' }).trim() || 0);

console.log('1. Caja por bloques (Store API) crea el enlace y redirige');
const c1 = await comprar();
ok(Array.isArray(c1.carrito.payment_methods) && c1.carrito.payment_methods.includes('quickpay'), 'quickpay aparece en la caja por bloques');
const id1 = c1.checkout.order_id;
ok(c1.status === 200 && c1.checkout.payment_result?.payment_status === 'success', `checkout responde éxito (HTTP ${c1.status})`);
ok(/^https:\/\/pay\.pagaconquickpay\.com\/l\//.test(c1.checkout.payment_result?.redirect_url || ''), 'redirige a la url del enlace');
let p1 = pedido(id1);
let links = (await mock('/_mock/links')).links;
let l1 = links.find((l) => l.id === p1.link);
ok(!!l1, 'el link_id quedó guardado en el pedido');
ok(l1 && l1.monto === 1500.5, `monto del enlace = total del pedido (${l1?.monto})`);
ok(l1 && l1.metadata?.woo_order_id === String(id1), 'metadata lleva el id del pedido');
ok(l1 && /^Pedido #\d+ · Tienda Prueba$/.test(l1.concepto), `concepto: "${l1?.concepto}"`);
ok(p1.status === 'pending' && !p1.paid, 'pedido sigue pendiente');
ok(p1.poll === '1', 'pedido marcado para revisión periódica');
ok(notasCon(p1, 'Enlace de pago QuickPay creado por Bs 1.500,50') === 1 && notasCon(p1, '[PRUEBA') === 1, 'nota en español con monto y marca de prueba');

console.log('2. Volver con ?estado=pagado NO marca pagado');
let r = await fetch(`${SITE}/?page_id=${php("echo wc_get_page_id('checkout');")}&order-received=${id1}&key=${p1.key}&estado=pagado&referencia=99999999`);
await r.text();
p1 = pedido(id1);
ok(p1.status === 'pending' && !p1.paid, 'sigue pendiente aunque la URL diga pagado');

console.log('3. Webhook firmado: 3 entregas simultáneas + 2 repetidas → pagado UNA vez');
const pago1 = await mock(`/_mock/pagar/${p1.link}`, { webhook_url: WEBHOOK, secreto: SECRETO, veces: 3, simultaneos: true });
ok(pago1.entregas.every((e) => [200, 503].includes(e.status)), `respuestas: ${pago1.entregas.map((e) => e.status).join(',')}`);
const rep = await mock(`/_mock/pagar/${p1.link}`, { webhook_url: WEBHOOK, secreto: SECRETO, veces: 2 });
ok(rep.entregas.every((e) => e.status === 200 && /duplicado/.test(e.body)), 'reintentos posteriores → 200 duplicado');
p1 = pedido(id1);
ok(p1.status === 'processing' && p1.paid, `pedido pagado (estado ${p1.status})`);
ok(p1.txn === pago1.link.txn_id, 'transaction_id = txn_id de QuickPay');
ok(p1.ref === pago1.referencia, 'referencia bancaria guardada');
ok(notasCon(p1, 'Pago confirmado por QuickPay') === 1, `exactamente una nota de pago (hay ${notasCon(p1, 'Pago confirmado por QuickPay')})`);
ok(p1.poll === '0', 'ya no se revisa periódicamente');

console.log('4. Firma inválida, vieja o sin secreto → rechazo');
ok((await webhookManual({ link_id: p1.link, estado: 'pagado', monto: 1500.5, txn_id: 'x' }, { firmaMala: true })).status === 401, 'firma alterada → 401');
ok((await webhookManual({ link_id: p1.link, estado: 'pagado', monto: 1500.5, txn_id: 'x' }, { ts: Math.floor(Date.now() / 1000) - 400 })).status === 401, 'aviso de hace 400 s → 401');
r = await fetch(WEBHOOK, { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' });
ok(r.status === 401, 'sin cabeceras → 401');
ok((await webhookManual({ link_id: 'no-existe', estado: 'pagado', monto: 1, txn_id: 'x' })).body?.ignorado === 'enlace_ajeno', 'enlace ajeno → 200 ignorado');

console.log('5. Sin webhook: la revisión periódica (WP-Cron) lo marca pagado');
const c2 = await comprar();
const id2 = c2.checkout.order_id;
let p2 = pedido(id2);
await mock(`/_mock/estado/${p2.link}`, { estado: 'procesando' });
php(`$o=wc_get_order(${id2}); $o->update_meta_data('_quickpay_ultima_consulta','0'); $o->save();`);
wp('cron', 'event', 'run', 'quickpay_revisar_pendientes');
p2 = pedido(id2);
ok(p2.status === 'pending' && notasCon(p2, 'El banco está confirmando') === 1, '"procesando": no se marca pagado, nota de espera');
await mock(`/_mock/estado/${p2.link}`, { estado: 'pagado' });
php(`$o=wc_get_order(${id2}); $o->update_meta_data('_quickpay_ultima_consulta','0'); $o->save();`);
wp('cron', 'event', 'run', 'quickpay_revisar_pendientes');
p2 = pedido(id2);
ok(p2.status === 'processing' && notasCon(p2, 'consulta periódica') === 1, `cron marcó pagado (estado ${p2.status})`);
wp('cron', 'event', 'run', 'quickpay_revisar_pendientes');
ok(notasCon(pedido(id2), 'Pago confirmado por QuickPay') === 1, 'segunda corrida del cron no duplica');

console.log('6. Página de "gracias" consulta a QuickPay');
const c3 = await comprar();
const id3 = c3.checkout.order_id;
let p3 = pedido(id3);
await mock(`/_mock/estado/${p3.link}`, { estado: 'pagado' });
r = await fetch(`${SITE}/?page_id=${php("echo wc_get_page_id('checkout');")}&order-received=${id3}&key=wrong_key`);
await r.text();
ok(pedido(id3).status === 'pending', 'con clave equivocada no consulta ni cambia nada');
r = await fetch(`${SITE}/?page_id=${php("echo wc_get_page_id('checkout');")}&order-received=${id3}&key=${p3.key}`);
const html3 = await r.text();
p3 = pedido(id3);
ok(p3.status === 'processing' && notasCon(p3, 'página de confirmación') === 1, `gracias marcó pagado (estado ${p3.status})`);
ok(html3.includes('Tu pago fue confirmado'), 'la página muestra "Tu pago fue confirmado"');

console.log('7. Error 500 al crear: reintento con la MISMA idempotency_key, un solo enlace');
const antes = (await mock('/_mock/links')).creaciones;
await mock('/_mock/fallar-proximo', { status: 502 });
const c4 = await comprar();
const id4 = c4.checkout.order_id;
const p4 = pedido(id4);
const despues = (await mock('/_mock/links'));
ok(c4.checkout.payment_result?.payment_status === 'success', 'el checkout igual sale bien');
ok(despues.creaciones - antes === 1, `un solo enlace creado (${despues.creaciones - antes})`);
ok(notasCon(p4, 'devolvió el enlace que ya existía') === 1, 'nota de repetición idempotente');

console.log('8. Volver a pagar el mismo pedido reusa el enlace; si cambió el monto crea otro');
const keyMock = (await mock('/_mock/links')).links.find((l) => l.id === p4.link);
ok(keyMock && (await mock('/_mock/links')).links.length > 0, 'enlace existe');
php(`$g = QP_Gateway::instancia(); $o = wc_get_order(${id4}); $r = $g->enlace_para_pedido($o); echo $r['url'];`);
ok(pedido(id4).link === p4.link, 'mismo monto → mismo enlace, sin llamar a la API');
php(`$o = wc_get_order(${id4}); $o->set_total(2000); $o->save(); $g = QP_Gateway::instancia(); $g->enlace_para_pedido(wc_get_order(${id4}));`);
let p4b = pedido(id4);
ok(p4b.link !== p4.link && p4b.monto === '2000.00' && p4b.hist.length === 2, `monto cambiado → enlace nuevo (${p4b.hist.length} en historial)`);
const nuevo = (await mock('/_mock/links')).links.find((l) => l.id === p4b.link);
ok(nuevo && nuevo.monto === 2000, 'el enlace nuevo es por Bs 2000');
console.log('   …y si el cliente paga el enlace VIEJO (monto distinto) → en espera, no pagado');
await mock(`/_mock/pagar/${p4.link}`, { webhook_url: WEBHOOK, secreto: SECRETO });
p4b = pedido(id4);
ok(p4b.status === 'on-hold' && notasCon(p4b, 'enlace anterior') === 1, `en espera con nota (estado ${p4b.status})`);

console.log('9. Monto del aviso distinto al del enlace → en espera');
const c5 = await comprar();
const id5 = c5.checkout.order_id;
const p5 = pedido(id5);
const w5 = await webhookManual({ link_id: p5.link, txn_id: 't-falso', estado: 'pagado', monto: 1.5, moneda: 'VES', concepto: 'x', referencia: '1' });
ok(w5.status === 200 && pedido(id5).status === 'on-hold', 'aviso con monto distinto → on-hold');

console.log('10. Pago de PRUEBA cuando la tienda ya está en producción → no se marca pagado');
const c6 = await comprar();
const id6 = c6.checkout.order_id;
const p6 = pedido(id6);
ajustes({ api_key: 'tienda-demo:sk_live_xyz789' });
await mock(`/_mock/pagar/${p6.link}`, { webhook_url: WEBHOOK, secreto: SECRETO });
const p6b = pedido(id6);
ok(p6b.status === 'pending' && !p6b.paid && notasCon(p6b, 'pago de PRUEBA') === 1, `no pagado y con nota (estado ${p6b.status})`);
ajustes({ api_key: 'tienda-demo:sk_test_abc123' });

console.log('11. Segundo pago de un pedido ya pagado → nota de ATENCIÓN, sin tocar el estado');
php(`$o = wc_get_order(${id1}); $o->set_total(1600); $o->save();`); // fuerza un enlace nuevo
php(`$g = QP_Gateway::instancia(); $g->enlace_para_pedido(wc_get_order(${id1}));`);
const p1c = pedido(id1);
await mock(`/_mock/pagar/${p1c.link}`, { webhook_url: WEBHOOK, secreto: SECRETO });
const p1d = pedido(id1);
ok(p1d.status === 'processing' && notasCon(p1d, 'ATENCIÓN') === 1, 'nota de doble pago');

console.log('12. Tienda en USD con tasa fija');
wp('option', 'update', 'woocommerce_currency', 'USD');
ajustes({ moneda_modo: 'tasa_fija', tasa: '' });
let c7 = await comprar();
ok(!(c7.carrito.payment_methods || []).includes('quickpay'), 'sin tasa: QuickPay no se ofrece');
ajustes({ tasa: '40,25' });
c7 = await comprar(2);
const p7 = pedido(c7.checkout.order_id);
const l7 = (await mock('/_mock/links')).links.find((l) => l.id === p7.link);
ok(l7 && Math.abs(l7.monto - Number(p7.total) * 40.25) < 0.005, `USD ${p7.total} × 40,25 = Bs ${l7?.monto}`);
ok(notasCon(p7, 'Tasa fija usada: Bs 40,25 por 1 USD') === 1, 'la tasa usada queda en la nota del pedido');
wp('option', 'update', 'woocommerce_currency', 'VES');
ajustes({ moneda_modo: 'ves', tasa: '' });

console.log('13. Enlace vencido: la revisión lo cierra y no cancela antes de tiempo');
const c8 = await comprar();
const id8 = c8.checkout.order_id;
const p8 = pedido(id8);
ok(php(`echo apply_filters('woocommerce_cancel_unpaid_order', true, wc_get_order(${id8})) ? 'si' : 'no';`) === 'no', 'WooCommerce no lo cancela mientras el enlace esté vigente');
await mock(`/_mock/estado/${p8.link}`, { estado: 'expirado' });
php(`$o=wc_get_order(${id8}); $o->update_meta_data('_quickpay_ultima_consulta','0'); $o->update_meta_data('_quickpay_expira_ts', (string)(time()-60)); $o->save();`);
wp('cron', 'event', 'run', 'quickpay_revisar_pendientes');
const p8b = pedido(id8);
ok(p8b.poll === '0' && notasCon(p8b, 'venció sin pagarse') === 1, 'nota de vencimiento y deja de revisarse');
ok(php(`echo apply_filters('woocommerce_cancel_unpaid_order', true, wc_get_order(${id8})) ? 'si' : 'no';`) === 'si', 'ya vencido, WooCommerce puede cancelarlo');

const log = execFileSync('bash', ['-c', `tail -n +${lineasLogAntes + 1} ${LOG} 2>/dev/null | grep -i -E 'fatal|warning|notice|deprecated|error' | grep -v -i 'textdomain' | tail -5 || true`], { encoding: 'utf8' });
ok(log.trim() === '', 'sin errores/avisos PHP nuevos en debug.log durante la prueba' + (log.trim() ? '\n' + log : ''));

console.log(`\n${total} pruebas, ${fallas} fallas`);
process.exit(fallas ? 1 : 0);
