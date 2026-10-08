// Genera vectores de firma con la MISMA función del SDK oficial de Node
// (docs/developers/sdk-node/quickpay.ts → verificarWebhook), copiada aquí sin cambios de lógica,
// para comprobar que el plugin PHP acepta y rechaza exactamente lo mismo.
//
//   node woocommerce/tests/sign-vectors.mjs > /tmp/v.json && php woocommerce/tests/run.php /tmp/v.json
import { createHmac, timingSafeEqual } from 'node:crypto';

function verificarWebhook(o, ahoraSeg) {
  if (!o.firma || !o.timestamp || !o.secreto) return false;
  const ts = Number(o.timestamp);
  if (!Number.isFinite(ts) || Math.abs(ahoraSeg - ts) > (o.toleranciaSeg ?? 300)) return false;
  const esperado = Buffer.from('v2=' + createHmac('sha256', o.secreto).update(`${o.timestamp}.${o.cuerpo}`).digest('hex'));
  const recibido = Buffer.from(String(o.firma));
  return esperado.length === recibido.length && timingSafeEqual(esperado, recibido);
}
const firmar = (cuerpo, ts, secreto) => 'v2=' + createHmac('sha256', secreto).update(`${ts}.${cuerpo}`).digest('hex');

const ahora = 1791468202;
const secreto = 'whsec_node_456';
const cuerpos = [
  JSON.stringify({ type: 'link.pagado', sentAt: '2026-10-08T14:03:22.000Z', data: { link_id: 'a1', txn_id: 't1', estado: 'pagado', monto: 450.5, moneda: 'VES', concepto: 'Pedido #1001 · Tienda Ñandú', referencia: '10242808' } }),
  '{"type":"link.pagado","data":{"concepto":"Café con acentos y emojis 🎉"}}',
  '',
];
const out = [];
for (const cuerpo of cuerpos) {
  const ts = String(ahora - 10);
  const f = firmar(cuerpo, ts, secreto);
  const casos = [
    ['válida', { cuerpo, firma: f, timestamp: ts, secreto }],
    ['cuerpo alterado', { cuerpo: cuerpo + 'x', firma: f, timestamp: ts, secreto }],
    ['secreto distinto', { cuerpo, firma: f, timestamp: ts, secreto: 'otro' }],
    ['vieja (400 s)', { cuerpo, firma: firmar(cuerpo, String(ahora - 400), secreto), timestamp: String(ahora - 400), secreto }],
  ];
  for (const [caso, o] of casos) {
    out.push({ caso, ...o, ahora, node_acepta: verificarWebhook(o, ahora), firma_node: firmar(o.cuerpo, o.timestamp, o.secreto) });
  }
}
process.stdout.write(JSON.stringify(out, null, 1));
