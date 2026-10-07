// 포트원 V2 웹훅: 서명 검증 → webhook-id 로 중복 수신 차단 → 결제 조회 후 반영
const db = require('../db');
const portone = require('../services/portone');
const orders = require('../services/orders');
const live = require('../services/live');

module.exports = async function webhook(req, res) {
  const raw = Buffer.isBuffer(req.body) ? req.body.toString('utf8') : '';
  let meta;
  try {
    meta = portone.verifyWebhook(raw, req.headers);
  } catch (e) {
    console.warn('[webhook] 거부:', e.message);
    return res.status(400).send('invalid');
  }

  let body;
  try {
    body = JSON.parse(raw);
  } catch (_) {
    return res.status(400).send('bad json');
  }

  // 같은 webhook-id 재전송이면 이미 처리됨
  try {
    await db.query('INSERT INTO webhook_events (webhook_id, event_type, payload) VALUES (?, ?, ?)', [meta.id, body.type || null, raw.slice(0, 60000)]);
  } catch (e) {
    if (e.code === 'ER_DUP_ENTRY') return res.status(200).send('duplicate');
    throw e;
  }

  try {
    const paymentId = body.data && body.data.paymentId;
    if (paymentId && /^Transaction\./.test(body.type || '')) {
      const known = await db.one('SELECT id FROM payments WHERE pg_payment_id = ?', [paymentId]);
      if (known) {
        // 웹훅 내용은 신뢰하지 않고 API로 다시 조회해서 반영 (applyCardPayment 는 중복 호출에 안전)
        await orders.completeCardPayment(paymentId);
        live.invalidate();
      }
    }
    res.status(200).send('ok');
  } catch (e) {
    console.error('[webhook] 처리 실패', e);
    // 처리 실패 시 재전송 받도록 기록 삭제 후 5xx
    await db.query('DELETE FROM webhook_events WHERE webhook_id = ?', [meta.id]).catch(() => {});
    res.status(500).send('error');
  }
};
