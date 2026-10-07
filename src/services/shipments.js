// 킵(보관) 출고 요청 서비스
const db = require('../db');
const settings = require('../settings');
const pricing = require('./pricing');
const { newNo } = require('./orders');
const { PolicyError } = pricing;

const STATUS_LABELS = {
  pending_payment: '배송비 결제대기',
  requested: '출고 요청',
  preparing: '배송준비',
  shipped: '발송완료',
  cancelled: '취소',
};

function statusLabel(sr) {
  if (sr.status === 'pending_payment') return sr.payment_method === 'bank' ? '배송비 입금대기' : '배송비 결제대기';
  return STATUS_LABELS[sr.status] || sr.status;
}

/** 회원의 킵 보관함 요약 (출고 요청에 묶이지 않은 킵보관 주문) */
async function keepBox(userId) {
  const s = await settings.fresh();
  const orders = await db.query(
    `SELECT * FROM orders WHERE user_id = ? AND status = 'kept' AND shipment_request_id IS NULL ORDER BY paid_at, id`,
    [userId]
  );
  const ids = orders.map((o) => o.id);
  const items = ids.length ? await db.query('SELECT * FROM order_items WHERE order_id IN (?) ORDER BY id', [ids]) : [];
  for (const o of orders) o.items = items.filter((it) => it.order_id === o.id);
  const keptAmount = orders.reduce((sum, o) => sum + o.items_amount, 0);
  return {
    orders,
    keptAmount,
    fee: orders.length ? pricing.keepReleaseFee(keptAmount, s) : 0,
    remaining: pricing.remainingForFree(keptAmount, s),
    threshold: s.free_shipping_threshold,
    shippingFee: s.shipping_fee,
  };
}

/**
 * 출고 요청 생성
 * - 보관 중 상품금액 합계 >= 기준: 배송비 무료, 즉시 출고 요청 완료
 * - 미만: 배송비 결제(카드/계좌) 후 출고 요청 완료
 * TODO(정책 확인): 현재는 보관 중인 주문 전체를 한 번에 출고
 */
async function requestRelease(userId, { recipient, paymentMethod, depositorName }, opts = {}) {
  const s = await settings.fresh();
  const r = recipient || {};
  const rec = {
    name: String(r.name || '').trim().slice(0, 50),
    phone: String(r.phone || '').replace(/[^0-9]/g, '').slice(0, 20),
    zipcode: String(r.zipcode || '').trim().slice(0, 10),
    address1: String(r.address1 || '').trim().slice(0, 255),
    address2: String(r.address2 || '').trim().slice(0, 255),
    memo: String(r.memo || '').trim().slice(0, 255),
  };
  if (!rec.name || !rec.phone || !rec.address1) throw new PolicyError('받는 분, 휴대폰, 주소를 입력해 주세요', 'NEED_ADDRESS');

  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      return await db.tx(async (conn) => {
        await conn.query('SELECT id FROM users WHERE id = ? FOR UPDATE', [userId]); // 회원 단위 직렬화
        const [[pending]] = await conn.query(
          "SELECT id FROM shipment_requests WHERE user_id = ? AND status = 'pending_payment' LIMIT 1",
          [userId]
        );
        if (pending) throw new PolicyError('배송비 결제를 기다리는 출고 요청이 있습니다. 먼저 결제하거나 취소해 주세요', 'HAS_PENDING');

        const [orders] = await conn.query(
          "SELECT id, items_amount FROM orders WHERE user_id = ? AND status = 'kept' AND shipment_request_id IS NULL FOR UPDATE",
          [userId]
        );
        if (!orders.length) throw new PolicyError('보관 중인 상품이 없습니다', 'EMPTY_KEEP');

        const keptAmount = orders.reduce((sum, o) => sum + o.items_amount, 0);
        const fee = pricing.keepReleaseFee(keptAmount, s);
        let method = null;
        if (fee > 0) {
          if (paymentMethod !== 'card' && paymentMethod !== 'bank') throw new PolicyError('배송비 결제수단을 선택해 주세요', 'NEED_PAYMENT');
          if (paymentMethod === 'card' && !opts.cardEnabled) throw new PolicyError('지금은 카드결제를 사용할 수 없습니다', 'CARD_DISABLED');
          method = paymentMethod;
        }

        const now = new Date();
        let expires = null;
        if (method === 'card') expires = new Date(now.getTime() + (s.card_hold_minutes || 10) * 60000);
        else if (method === 'bank' && s.bank_auto_cancel_hours > 0) expires = new Date(now.getTime() + s.bank_auto_cancel_hours * 3600000);

        const status = fee > 0 ? 'pending_payment' : 'requested';
        const requestNo = newNo('S');
        const [ins] = await conn.query(
          `INSERT INTO shipment_requests (request_no, user_id, status, kept_amount, shipping_fee, payment_method, depositor_name,
              recipient_name, recipient_phone, zipcode, address1, address2, memo, reserve_expires_at)
           VALUES (?,?,?,?,?,?,?, ?,?,?,?,?,?,?)`,
          [requestNo, userId, status, keptAmount, fee, method, method === 'bank' ? String(depositorName || rec.name).slice(0, 50) : null,
            rec.name, rec.phone, rec.zipcode, rec.address1, rec.address2, rec.memo, expires]
        );
        const srId = ins.insertId;
        const ids = orders.map((o) => o.id);
        await conn.query('UPDATE orders SET shipment_request_id = ? WHERE id IN (?)', [srId, ids]);
        if (status === 'requested') {
          await conn.query("UPDATE orders SET status = 'preparing' WHERE id IN (?)", [ids]);
        }

        let paymentId = null;
        if (method === 'card') {
          paymentId = require('./portone').newPaymentId(requestNo);
          await conn.query(
            "INSERT INTO payments (target_type, target_id, method, pg_payment_id, amount, status) VALUES ('shipment', ?, 'card', ?, ?, 'ready')",
            [srId, paymentId, fee]
          );
        }
        return { id: srId, requestNo, status, keptAmount, fee, paymentMethod: method, paymentId, orderCount: ids.length };
      });
    } catch (err) {
      if (err && err.code === 'ER_DUP_ENTRY' && /request_no/.test(err.message)) continue;
      throw err;
    }
  }
  throw new Error('출고 요청 번호 생성 실패');
}

/** 배송비 입금 확인 (관리자) */
async function confirmDeposit(srId) {
  return db.tx(async (conn) => {
    const [[sr]] = await conn.query('SELECT * FROM shipment_requests WHERE id = ? FOR UPDATE', [srId]);
    if (!sr) throw new PolicyError('출고 요청을 찾을 수 없습니다', 'NOT_FOUND');
    if (sr.status !== 'pending_payment' || sr.payment_method !== 'bank') throw new PolicyError('입금 대기 상태가 아닙니다', 'NOT_PENDING');
    const now = new Date();
    await conn.query("UPDATE shipment_requests SET status = 'requested', paid_at = ?, reserve_expires_at = NULL WHERE id = ?", [now, sr.id]);
    await conn.query("UPDATE orders SET status = 'preparing' WHERE shipment_request_id = ? AND status = 'kept'", [sr.id]);
    await conn.query(
      "INSERT INTO payments (target_type, target_id, method, amount, status, paid_at) VALUES ('shipment', ?, 'bank', ?, 'paid', ?)",
      [sr.id, sr.shipping_fee, now]
    );
    // 배송비는 포인트 적립 대상 아님
    return sr;
  });
}

/** 출고 요청 취소: 묶인 주문은 다시 킵보관으로 */
async function cancelRequest(srId, { by = 'admin', userId = null, reason = 'admin' } = {}) {
  const pre = await db.one('SELECT * FROM shipment_requests WHERE id = ?', [srId]);
  if (!pre) throw new PolicyError('출고 요청을 찾을 수 없습니다', 'NOT_FOUND');
  if (userId && pre.user_id !== userId) throw new PolicyError('출고 요청을 찾을 수 없습니다', 'NOT_FOUND');
  if (pre.status === 'cancelled') return pre;
  if (pre.status === 'shipped') throw new PolicyError('발송완료된 요청은 취소할 수 없습니다', 'SHIPPED');
  if (by === 'user' && pre.status !== 'pending_payment') throw new PolicyError('출고 요청이 접수되어 취소할 수 없습니다', 'CANNOT_CANCEL');

  if (pre.payment_method === 'card' && pre.paid_at) {
    const pay = await db.one("SELECT * FROM payments WHERE target_type = 'shipment' AND target_id = ? AND status = 'paid'", [pre.id]);
    if (pay && pay.pg_payment_id) {
      await require('./portone').cancelPayment(pay.pg_payment_id, '출고 요청 취소');
      await db.query("UPDATE payments SET status = 'cancelled' WHERE id = ?", [pay.id]);
    }
  }

  return db.tx(async (conn) => {
    const [[sr]] = await conn.query('SELECT * FROM shipment_requests WHERE id = ? FOR UPDATE', [srId]);
    if (sr.status === 'cancelled') return sr;
    if (by === 'system' && sr.status !== 'pending_payment') return sr;
    await conn.query("UPDATE orders SET status = 'kept', shipment_request_id = NULL WHERE shipment_request_id = ? AND status IN ('kept','preparing')", [sr.id]);
    await conn.query(
      "UPDATE shipment_requests SET status = 'cancelled', cancelled_at = ?, cancel_reason = ?, reserve_expires_at = NULL WHERE id = ?",
      [new Date(), reason, sr.id]
    );
    await conn.query("UPDATE payments SET status = 'cancelled' WHERE target_type = 'shipment' AND target_id = ? AND status = 'ready'", [sr.id]);
    return { ...sr, status: 'cancelled' };
  });
}

/** 관리자: 배송준비 / 발송완료 처리 (묶인 주문도 함께) */
async function setStatus(srId, next, { courier, trackingNo } = {}) {
  return db.tx(async (conn) => {
    const [[sr]] = await conn.query('SELECT * FROM shipment_requests WHERE id = ? FOR UPDATE', [srId]);
    if (!sr) throw new PolicyError('출고 요청을 찾을 수 없습니다', 'NOT_FOUND');
    const allowed = { requested: ['preparing', 'shipped'], preparing: ['shipped', 'requested'], shipped: ['preparing'] };
    if (!(allowed[sr.status] || []).includes(next)) throw new PolicyError(`${statusLabel(sr)} → ${STATUS_LABELS[next]} 변경 불가`, 'BAD_TRANSITION');
    const now = new Date();
    await conn.query(
      'UPDATE shipment_requests SET status = ?, shipped_at = ?, courier = COALESCE(?, courier), tracking_no = COALESCE(?, tracking_no) WHERE id = ?',
      [next, next === 'shipped' ? now : sr.shipped_at, courier || null, trackingNo || null, sr.id]
    );
    const orderStatus = next === 'shipped' ? 'shipped' : 'preparing';
    await conn.query(
      'UPDATE orders SET status = ?, shipped_at = ?, courier = COALESCE(?, courier), tracking_no = COALESCE(?, tracking_no) WHERE shipment_request_id = ?',
      [orderStatus, next === 'shipped' ? now : null, courier || null, trackingNo || null, sr.id]
    );
    return { ...sr, status: next };
  });
}

async function expireStale({ now = new Date() } = {}) {
  const portoneReady = require('../config').portone.enabled;
  const rows = await db.query(
    "SELECT id, payment_method FROM shipment_requests WHERE status = 'pending_payment' AND reserve_expires_at IS NOT NULL AND reserve_expires_at < ? LIMIT 200",
    [now]
  );
  let n = 0;
  for (const r of rows) {
    try {
      if (r.payment_method === 'card' && portoneReady) {
        const pay = await db.one("SELECT pg_payment_id FROM payments WHERE target_type = 'shipment' AND target_id = ? AND status = 'ready'", [r.id]);
        if (pay) {
          try {
            const pg = await require('./portone').getPayment(pay.pg_payment_id);
            if (pg.status === 'PAID') {
              await require('./orders').applyCardPayment(pg);
              continue;
            }
          } catch (e) {
            if (e.status !== 404) throw e;
          }
        }
      }
      await cancelRequest(r.id, { by: 'system', reason: r.payment_method === 'card' ? 'timeout' : 'auto_cancel' });
      n++;
    } catch (e) {
      console.error('[expire] shipment', r.id, e.message);
    }
  }
  return n;
}

module.exports = { STATUS_LABELS, statusLabel, keepBox, requestRelease, confirmDeposit, cancelRequest, setStatus, expireStale };
