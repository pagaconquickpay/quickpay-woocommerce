// Servidor SIMULADO de la API de QuickPay, sólo para pruebas locales de los plugins.
// Sigue el contrato publicado (docs/developers/enlaces-de-pago.md y webhooks-referencia.md):
//   POST /switch/v1/links       → 201 enlace nuevo; misma idempotency_key → 200 + idempotent_replay:true
//   GET  /switch/v1/links/:id   → enlace con estado, txn_id, paid_at
// Y rutas de control para la prueba (no existen en la API real):
//   POST /_mock/pagar/:id      {webhook_url, secreto, veces}  → marca pagado y envía link.pagado firmado (v2)
//   POST /_mock/estado/:id     {estado}
//   POST /_mock/fallar-proximo {status}                       → la próxima creación responde ese HTTP (y SÍ crea el enlace)
//   GET  /_mock/links
//
//   PORT=8787 node tools/mock-quickpay.mjs
import { createServer } from 'node:http';
import { createHmac, randomUUID } from 'node:crypto';

const PORT = Number(process.env.PORT || 8787);
const links = new Map(); // id → enlace
const porLlave = new Map(); // comercio|idempotency_key → id
let fallarProximo = 0;
let creaciones = 0;

const json = (res, status, body) => {
  res.writeHead(status, { 'content-type': 'application/json' });
  res.end(JSON.stringify(body));
};
const leer = (req) => new Promise((ok) => { let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => ok(b)); });

function auth(req) {
  const k = String(req.headers['x-switch-key'] || '');
  const m = /^([^:]+):(sk_test_|sk_live_)\S+$/.exec(k);
  if (!m) return null;
  return { comercio: m[1], mode: m[2] === 'sk_test_' ? 'test' : 'live' };
}

function vista(l, crear = false) {
  const base = { id: l.id, url: l.url, wa_deeplink: null, qr_png: null, monto: l.monto, moneda: 'VES', concepto: l.concepto, estado: l.estado, expires_at: l.expires_at };
  if (crear) return { ...base, success_url: l.success_url ?? null, failure_url: null, metadata: l.metadata ?? null, mode: l.mode };
  return { ...base, comercio: l.comercio, txn_id: l.txn_id ?? null, created_at: l.created_at, paid_at: l.paid_at ?? null };
}

async function enviarWebhook(url, secreto, l) {
  const cuerpo = JSON.stringify({
    type: 'link.pagado',
    sentAt: new Date().toISOString(),
    data: { link_id: l.id, txn_id: l.txn_id, estado: 'pagado', monto: l.monto, moneda: 'VES', concepto: l.concepto, referencia: l.referencia },
  });
  const ts = String(Math.floor(Date.now() / 1000));
  const firma = 'v2=' + createHmac('sha256', secreto).update(`${ts}.${cuerpo}`).digest('hex');
  const r = await fetch(url, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-quicklink-event': 'link.pagado',
      'x-quicklink-timestamp': ts,
      'x-quicklink-signature-v2': firma,
      'x-quicklink-signature': 'sha256=' + createHmac('sha256', secreto).update(cuerpo).digest('hex'),
    },
    body: cuerpo,
    signal: AbortSignal.timeout(8000),
  });
  return { status: r.status, body: await r.text() };
}

createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const p = url.pathname;
  try {
    if (req.method === 'POST' && p === '/switch/v1/links') {
      const a = auth(req);
      if (!a) return json(res, 401, { error: 'unauthorized' });
      let b;
      try { b = JSON.parse(await leer(req)); } catch { return json(res, 400, { error: 'validation', detail: 'json' }); }
      const fe = {};
      if (!(typeof b.monto === 'number' && b.monto > 0)) fe.monto = ['Number must be greater than 0'];
      if (typeof b.concepto !== 'string' || !b.concepto || b.concepto.length > 140) fe.concepto = ['Required, max 140'];
      if (b.expira_horas !== undefined && !(Number.isInteger(b.expira_horas) && b.expira_horas >= 1 && b.expira_horas <= 720)) fe.expira_horas = ['1..720'];
      if (b.success_url !== undefined && (!/^https:\/\//.test(b.success_url) || b.success_url.length > 400)) fe.success_url = ['https, max 400'];
      if (b.idempotency_key !== undefined && (typeof b.idempotency_key !== 'string' || b.idempotency_key.length > 64)) fe.idempotency_key = ['max 64'];
      if (b.metadata && Object.keys(b.metadata).length > 20) fe.metadata = ['max 20 keys'];
      if (Object.keys(fe).length) return json(res, 400, { error: 'validation', detail: { fieldErrors: fe } });
      const llave = b.idempotency_key ? `${a.comercio}|${b.idempotency_key}` : null;
      if (llave && porLlave.has(llave)) {
        const l = links.get(porLlave.get(llave));
        return json(res, 200, { ...vista(l, true), idempotent_replay: true });
      }
      const id = randomUUID();
      const l = {
        id, url: `https://pay.pagaconquickpay.com/l/${id}`, comercio: a.comercio, mode: a.mode,
        monto: b.monto, concepto: b.concepto, estado: 'pendiente',
        expires_at: new Date(Date.now() + (b.expira_horas ?? 24) * 3600e3).toISOString(),
        success_url: b.success_url, metadata: b.metadata, created_at: new Date().toISOString(),
      };
      links.set(id, l);
      if (llave) porLlave.set(llave, id);
      creaciones++;
      if (fallarProximo) { const s = fallarProximo; fallarProximo = 0; return json(res, s, { error: 'simulado' }); }
      return json(res, 201, vista(l, true));
    }
    let m;
    if (req.method === 'GET' && (m = /^\/switch\/v1\/links\/([^/]+)$/.exec(p))) {
      const a = auth(req);
      if (!a) return json(res, 401, { error: 'unauthorized' });
      const l = links.get(decodeURIComponent(m[1]));
      if (!l || l.comercio !== a.comercio) return json(res, 404, { error: 'not_found' });
      return json(res, 200, vista(l));
    }
    if (req.method === 'POST' && (m = /^\/_mock\/pagar\/([^/]+)$/.exec(p))) {
      const b = JSON.parse((await leer(req)) || '{}');
      const l = links.get(m[1]);
      if (!l) return json(res, 404, { error: 'not_found' });
      if (l.estado !== 'pagado') {
        l.estado = 'pagado'; l.txn_id = randomUUID(); l.paid_at = new Date().toISOString();
        l.referencia = String(10000000 + Math.floor(Math.random() * 89999999));
      }
      const entregas = [];
      if (b.webhook_url) {
        const veces = b.veces || 1;
        if (b.simultaneos) {
          entregas.push(...(await Promise.all(Array.from({ length: veces }, () => enviarWebhook(b.webhook_url, b.secreto, l)))));
        } else {
          for (let i = 0; i < veces; i++) entregas.push(await enviarWebhook(b.webhook_url, b.secreto, l));
        }
      }
      return json(res, 200, { link: vista(l), referencia: l.referencia, entregas });
    }
    if (req.method === 'POST' && (m = /^\/_mock\/estado\/([^/]+)$/.exec(p))) {
      const b = JSON.parse((await leer(req)) || '{}');
      const l = links.get(m[1]);
      if (!l) return json(res, 404, { error: 'not_found' });
      l.estado = b.estado;
      if (b.estado === 'pagado' && !l.txn_id) { l.txn_id = randomUUID(); l.paid_at = new Date().toISOString(); l.referencia = '12345678'; }
      return json(res, 200, vista(l));
    }
    if (req.method === 'POST' && p === '/_mock/fallar-proximo') {
      const b = JSON.parse((await leer(req)) || '{}');
      fallarProximo = b.status || 500;
      return json(res, 200, { ok: true });
    }
    if (req.method === 'GET' && p === '/_mock/links') return json(res, 200, { creaciones, links: [...links.values()] });
    json(res, 404, { error: 'not_found' });
  } catch (e) {
    json(res, 500, { error: String(e) });
  }
}).listen(PORT, '127.0.0.1', () => console.log(`mock QuickPay en http://127.0.0.1:${PORT}`));
