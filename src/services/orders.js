// 주문 서비스: 재고 선점(원자적 차감), 주문 생성, 입금 확인, 카드 결제 반영, 취소, 만료 처리
const crypto = require('crypto');
const db = require('../db');
const settings = require('../settings');
const pricing = require('./pricing');
const { applyPoints } = require('./points');
const { PolicyError } = pricing;

const STATUS_LABELS = {
  pending: '입금대기',
  paid: '결제완료',
  kept: '킵보관',
  preparing: '배송준비',
  shipped: '발송완료',
  cancelled: '취소',
};
const PAYMENT_LABELS = { card: '카드결제', bank: '계좌이체' };
const DELIVERY_LABELS = { direct: '바로배송', keep: '킵' };
const CANCEL_REASON_LABELS = {
  timeout: '결제 시간 초과',
  auto_cancel: '입금 기한 초과',
  user: '고객 취소',
  admin: '관리자 취소',
  stock: '재고 소진',
};

function statusLabel(o) {
  if (o.status === 'pending') return o.payment_method === 'card' ? '결제대기' : '입금대기';
  return STATUS_LABELS[o.status] || o.status;
}

function newNo(prefix) {
  const d = new Date(Date.now() + 9 * 3600 * 1000); // KST
  const ymd = d.toISOString().slice(2, 10).replace(/-/g, '');
  const rand = crypto.randomInt(0, 100000).toString().padStart(5, '0');
  return `${prefix}${ymd}-${rand}`;
}

// ===== 재고 =====

/**
 * 원자적 재고 차감. 동시 주문에서도 stock 이 음수가 되지 않습니다.
 * @returns true 성공 / false 재고 부족
 */
async function reserveStock(conn, productId, qty) {
  const [r] = await conn.query(
    `UPDATE products SET stock = stock - ?
      WHERE id = ? AND stock >= ? AND deleted_at IS NULL AND is_visible = 1 AND is_soldout = 0`,
    [qty, productId, qty]
  );
  return r.affectedRows === 1;
}

async function releaseStock(conn, productId, qty) {
  await conn.query('UPDATE products SET stock = stock + ? WHERE id = ?', [qty, productId]);
}

async function releaseOrderStock(conn, orderId) {
  const [items] = await conn.query('SELECT product_id, qty FROM order_items WHERE order_id = ? ORDER BY product_id', [orderId]);
  for (const it of items) await releaseStock(conn, it.product_id, it.qty);
}

function paidStatusFor(deliveryType) {
  return deliveryType === 'keep' ? 'kept' : 'paid';
}

function addMinutes(date, min) {
  return new Date(date.getTime() + min * 60000);
}

function dayBounds(date) {
  const start = new Date(date);
  start.setHours(0, 0, 0, 0);
  const end = new Date(start);
  end.setDate(end.getDate() + 1);
  return { start, end };
}

/**
 * tier 적립은 같은 고객의 당일 계좌이체 구매금액을 합산합니다.
 * 기준을 넘는 순간 해당 날짜의 기존 적립도 함께 3%로 보정합니다.
 */
async function reconcileDailyTierPoints(conn, userId, at, policy) {
  const { start, end } = dayBounds(at);
  const [rows] = await conn.query(
    `SELECT id, order_no, items_amount, point_used, point_earned
       FROM orders
      WHERE user_id = ? AND payment_method = 'bank' AND status <> 'cancelled'
        AND paid_at >= ? AND paid_at < ?
      ORDER BY paid_at, id FOR UPDATE`,
    [userId, start, end]
  );
  const total = rows.reduce((sum, row) => sum + Math.max(0, row.items_amount - row.point_used), 0);
  const rate = total >= policy.threshold ? policy.overRate : policy.underRate;
  const earnedById = new Map();

  for (const row of rows) {
    const earned = pricing.earnPoints({ paymentMethod: 'bank', itemsAmount: row.items_amount, pointUsed: row.point_used }, rate);
    const previous = Number(row.point_earned || 0);
    const delta = earned - previous;
    if (delta) {
      await applyPoints(conn, {
        userId,
        type: previous ? 'adjust' : 'earn',
        delta,
        orderId: row.id,
        memo: `주문 ${row.order_no} 당일 합산 적립 ${rate}% 적용`,
      });
      await conn.query('UPDATE orders SET point_earned = ? WHERE id = ?', [earned, row.id]);
    }
    earnedById.set(row.id, earned);
  }
  return { total, rate, earnedById };
}

// ===== 주문 생성 =====

/**
 * @param userId
 * @param input { lines:[{productId, opened, unopened, qty}], deliveryType, paymentMethod,
 *                recipient:{name, phone, zipcode, address1, address2, memo}, depositorName, cashReceipt, pointUse }
 * @param opts { cardEnabled, skipLiveCheck }
 */
async function createOrder(userId, input, opts = {}) {
  const s = await settings.fresh();
  if (!s.live_on && !opts.skipLiveCheck) throw new PolicyError('방송 중에만 주문할 수 있습니다', 'NOT_LIVE');

  const deliveryType = input.deliveryType === 'keep' ? 'keep' : input.deliveryType === 'direct' ? 'direct' : null;
  if (!deliveryType) throw new PolicyError('배송방식을 선택해 주세요', 'NEED_DELIVERY');
  const paymentMethod = input.paymentMethod;
  if (paymentMethod !== 'card' && paymentMethod !== 'bank') throw new PolicyError('결제수단을 선택해 주세요', 'NEED_PAYMENT');
  if (paymentMethod === 'card' && !opts.cardEnabled) throw new PolicyError('지금은 카드결제를 사용할 수 없습니다', 'CARD_DISABLED');

  const r = input.recipient || {};
  const recipient = {
    name: String(r.name || '').trim().slice(0, 50),
    phone: String(r.phone || '').replace(/[^0-9]/g, '').slice(0, 20),
    zipcode: String(r.zipcode || '').trim().slice(0, 10),
    address1: String(r.address1 || '').trim().slice(0, 255),
    address2: String(r.address2 || '').trim().slice(0, 255),
    memo: String(r.memo || '').trim().slice(0, 255),
  };
  if (!recipient.name || !recipient.phone || !recipient.address1) {
    throw new PolicyError('받는 분, 휴대폰, 주소를 입력해 주세요', 'NEED_ADDRESS');
  }
  const depositorName = String(input.depositorName || recipient.name).trim().slice(0, 50);
  const receipt = input.cashReceipt || {};
  let cashReceiptType = paymentMethod === 'bank' && s.cash_receipt_enabled ? String(receipt.type || 'none') : 'none';
  if (!['none', 'income', 'expense'].includes(cashReceiptType)) {
    throw new PolicyError('현금영수증 신청 유형을 선택해 주세요', 'BAD_CASH_RECEIPT');
  }
  let cashReceiptValue = cashReceiptType === 'none' ? null : String(receipt.value || '').replace(/[^0-9]/g, '').slice(0, 30);
  if (cashReceiptType !== 'none' && cashReceiptValue.length < 8) {
    throw new PolicyError('현금영수증 발급 정보를 정확히 입력해 주세요', 'BAD_CASH_RECEIPT');
  }

  const merged = pricing.mergeLines(input.lines).sort((a, b) => a.productId - b.productId);
  if (!merged.length) throw new PolicyError('장바구니가 비어 있습니다', 'EMPTY_CART');
  if (merged.length > 50) throw new PolicyError('한 번에 주문할 수 있는 상품 수를 초과했습니다', 'TOO_MANY');

  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      return await db.tx(async (conn) => {
        const ids = merged.map((l) => l.productId);
        const [prows] = await conn.query(
          `SELECT id, name, price, stock, use_open_option, is_visible, is_soldout, deleted_at
             FROM products WHERE id IN (?)`,
          [ids]
        );
        const pmap = new Map(prows.map((p) => [p.id, p]));

        const lines = [];
        for (const l of merged) {
          const p = pmap.get(l.productId);
          if (!p || p.deleted_at || !p.is_visible) throw new PolicyError('판매하지 않는 상품이 포함되어 있습니다', 'GONE');
          if (p.is_soldout) throw new PolicyError(`${p.name} 상품은 품절입니다`, 'SOLDOUT');
          const n = pricing.normalizeLine(l, p);
          lines.push({ product: p, unitPrice: p.price, ...n });
        }

        // 상품 id 순서로 차감 (교착 방지)
        for (const l of lines) {
          const ok = await reserveStock(conn, l.product.id, l.qty);
          if (!ok) {
            const [[cur]] = await conn.query('SELECT stock FROM products WHERE id = ?', [l.product.id]);
            const left = cur ? Math.max(0, cur.stock) : 0;
            throw new PolicyError(
              left > 0 ? `${l.product.name} 재고가 부족합니다 (남은 수량 ${left}개)` : `${l.product.name} 상품이 방금 품절되었습니다`,
              'OUT_OF_STOCK'
            );
          }
        }

        const [[user]] = await conn.query(
          'SELECT id, point_balance, youtube_nickname FROM users WHERE id = ? FOR UPDATE',
          [userId]
        );
        if (!user) throw new PolicyError('회원 정보를 찾을 수 없습니다', 'NO_USER');

        const pre = pricing.computeOrder({ lines, deliveryType }, s);
        const pointUsed = pricing.validatePointUse(
          input.pointUse,
          { balance: user.point_balance, itemsAmount: pre.itemsAmount, shippingFee: pre.shippingFee },
          s
        );
        const amounts = pricing.computeOrder({ lines, deliveryType, pointUsed }, s);

        const now = new Date();
        let expires = null;
        if (paymentMethod === 'card') expires = addMinutes(now, s.card_hold_minutes || 10);
        else if (s.bank_auto_cancel_hours > 0) expires = addMinutes(now, s.bank_auto_cancel_hours * 60);

        // 포인트로 전액 결제된 경우 즉시 결제완료
        const fullyPaid = amounts.total === 0;
        const status = fullyPaid ? paidStatusFor(deliveryType) : 'pending';
        const orderNo = newNo('G');

        const [ins] = await conn.query(
           `INSERT INTO orders (order_no, user_id, status, payment_method, delivery_type,
              items_amount, shipping_fee, point_used, total_amount,
              recipient_name, recipient_phone, zipcode, address1, address2, memo,
              depositor_name, cash_receipt_type, cash_receipt_value, cash_receipt_status,
              youtube_nickname, reserve_expires_at, paid_at)
           VALUES (
             ?, ?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?
           )`,
          [
            orderNo, userId, status, paymentMethod, deliveryType,
            amounts.itemsAmount, amounts.shippingFee, amounts.pointUsed, amounts.total,
            recipient.name, recipient.phone, recipient.zipcode, recipient.address1, recipient.address2, recipient.memo,
            depositorName, cashReceiptType, cashReceiptValue, cashReceiptType === 'none' ? 'not_requested' : 'requested',
            user.youtube_nickname, fullyPaid ? null : expires, fullyPaid ? now : null,
          ]
        );
        const orderId = ins.insertId;

        for (const l of lines) {
          await conn.query(
            `INSERT INTO order_items (order_id, product_id, product_name, unit_price, qty, qty_opened, qty_unopened, line_amount)
             VALUES (?,?,?,?,?,?,?,?)`,
            [orderId, l.product.id, l.product.name, l.unitPrice, l.qty, l.opened, l.unopened, l.unitPrice * l.qty]
          );
        }

        if (pointUsed > 0) {
          await applyPoints(conn, { userId, type: 'use', delta: -pointUsed, orderId, memo: `주문 ${orderNo}` });
        }

        let paymentId = null;
        if (paymentMethod === 'card' && !fullyPaid) {
          paymentId = require('./portone').newPaymentId(orderNo);
          await conn.query(
            `INSERT INTO payments (target_type, target_id, method, pg_payment_id, amount, status)
             VALUES ('order', ?, 'card', ?, ?, 'ready')`,
            [orderId, paymentId, amounts.total]
          );
        }

        return { id: orderId, orderNo, status, paymentMethod, deliveryType, ...amounts, paymentId, expiresAt: expires };
      });
    } catch (err) {
      // 주문번호 중복 시 재시도
      if (err && err.code === 'ER_DUP_ENTRY' && /order_no/.test(err.message)) continue;
      throw err;
    }
  }
  throw new Error('주문번호 생성 실패');
}

// ===== 입금 확인 (계좌이체) =====

async function confirmDeposit(orderId) {
  return db.tx(async (conn) => {
    const [[o]] = await conn.query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [orderId]);
    if (!o) throw new PolicyError('주문을 찾을 수 없습니다', 'NOT_FOUND');
    if (o.payment_method !== 'bank') throw new PolicyError('계좌이체 주문이 아닙니다', 'NOT_BANK');
    if (o.status !== 'pending') throw new PolicyError(`이미 처리된 주문입니다 (${statusLabel(o)})`, 'NOT_PENDING');

    const now = new Date();
    await conn.query(
      'UPDATE orders SET status = ?, paid_at = ?, reserve_expires_at = NULL, point_earned = 0 WHERE id = ?',
      [paidStatusFor(o.delivery_type), now, o.id]
    );
    await conn.query(
      `INSERT INTO payments (target_type, target_id, method, amount, status, paid_at)
       VALUES ('order', ?, 'bank', ?, 'paid', ?)`,
      [o.id, o.total_amount, now]
    );
    const policy = settings.pointEarnPolicy();
    let earned = 0;
    if (policy.mode === 'tier') {
      const result = await reconcileDailyTierPoints(conn, o.user_id, now, policy);
      earned = result.earnedById.get(o.id) || 0;
    } else if (policy.mode === 'flat') {
      earned = pricing.earnPoints(
        { paymentMethod: 'bank', itemsAmount: o.items_amount, pointUsed: o.point_used },
        policy.rate
      );
      await conn.query('UPDATE orders SET point_earned = ? WHERE id = ?', [earned, o.id]);
      if (earned > 0) {
        await applyPoints(conn, { userId: o.user_id, type: 'earn', delta: earned, orderId: o.id, memo: `주문 ${o.order_no} 계좌이체 적립` });
      }
    }
    return { ...o, status: paidStatusFor(o.delivery_type), point_earned: earned };
  });
}

// ===== 카드 결제 반영 =====

/**
 * 포트원에서 조회한 결제 정보로 주문/출고요청을 결제완료 처리합니다.
 * 결제 완료 콜백, 모바일 리다이렉트, 웹훅, 만료 스윕에서 모두 이 함수를 사용하므로 중복 호출에 안전해야 합니다.
 * @returns { result: 'paid'|'already'|'refund', target, reason? }
 */
async function applyCardPayment(pg) {
  const paymentId = pg.id;
  const outcome = await db.tx(async (conn) => {
    const [[pay]] = await conn.query('SELECT * FROM payments WHERE pg_payment_id = ? FOR UPDATE', [paymentId]);
    if (!pay) throw new PolicyError('결제 정보를 찾을 수 없습니다', 'NO_PAYMENT');
    if (pay.status === 'paid') return { result: 'already', pay };

    const paidTotal = pg.amount && pg.amount.total;
    if (pg.status !== 'PAID') return { result: 'not_paid', pay, pgStatus: pg.status };
    if (paidTotal !== pay.amount) {
      // 금액 위변조: 결제 취소 대상
      await conn.query("UPDATE payments SET status = 'failed', raw_json = ? WHERE id = ?", [JSON.stringify(pg), pay.id]);
      return { result: 'refund', pay, reason: '결제 금액 불일치' };
    }

    const now = new Date();
    const paidAt = pg.paidAt ? new Date(pg.paidAt) : now;

    if (pay.target_type === 'order') {
      const [[o]] = await conn.query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [pay.target_id]);
      if (o.status === 'pending') {
        await conn.query('UPDATE orders SET status = ?, paid_at = ?, reserve_expires_at = NULL WHERE id = ?', [paidStatusFor(o.delivery_type), paidAt, o.id]);
      } else if (o.status === 'cancelled' && o.cancel_reason === 'timeout') {
        // 결제 시간 초과로 취소된 직후 결제가 들어온 경우: 재고를 다시 잡아보고, 안 되면 환불
        const [items] = await conn.query('SELECT product_id, qty FROM order_items WHERE order_id = ? ORDER BY product_id', [o.id]);
        for (const it of items) {
          const ok = await reserveStockForce(conn, it.product_id, it.qty);
          if (!ok) {
            // 예외로 전체 롤백(앞에서 잡은 재고 포함) 후 바깥에서 환불 처리
            throw Object.assign(new Error('RESTOCK_FAIL'), { restockFail: true, pay });
          }
        }
        if (o.point_used > 0) {
          await applyPoints(conn, { userId: o.user_id, type: 'use', delta: -o.point_used, orderId: o.id, memo: `주문 ${o.order_no} (결제 지연 복구)` });
        }
        await conn.query(
          'UPDATE orders SET status = ?, paid_at = ?, cancelled_at = NULL, cancel_reason = NULL, reserve_expires_at = NULL WHERE id = ?',
          [paidStatusFor(o.delivery_type), paidAt, o.id]
        );
      } else if (o.status !== 'cancelled') {
        // 이미 결제완료 이후 상태
      } else {
        await conn.query("UPDATE payments SET status = 'failed', raw_json = ? WHERE id = ?", [JSON.stringify(pg), pay.id]);
        return { result: 'refund', pay, reason: '취소된 주문' };
      }
    } else if (pay.target_type === 'shipment') {
      const [[sr]] = await conn.query('SELECT * FROM shipment_requests WHERE id = ? FOR UPDATE', [pay.target_id]);
      if (sr.status === 'pending_payment') {
        await conn.query("UPDATE shipment_requests SET status = 'requested', paid_at = ?, reserve_expires_at = NULL WHERE id = ?", [paidAt, sr.id]);
        await conn.query("UPDATE orders SET status = 'preparing' WHERE shipment_request_id = ? AND status = 'kept'", [sr.id]);
      } else if (sr.status === 'cancelled') {
        await conn.query("UPDATE payments SET status = 'failed', raw_json = ? WHERE id = ?", [JSON.stringify(pg), pay.id]);
        return { result: 'refund', pay, reason: '취소된 출고 요청' };
      }
    }

    await conn.query("UPDATE payments SET status = 'paid', paid_at = ?, raw_json = ? WHERE id = ?", [paidAt, JSON.stringify(pg), pay.id]);
    return { result: 'paid', pay };
  }).catch(async (err) => {
    if (err && err.restockFail) {
      await db.query("UPDATE payments SET status = 'failed', raw_json = ? WHERE id = ?", [JSON.stringify(pg), err.pay.id]);
      return { result: 'refund', pay: err.pay, reason: '재고 소진' };
    }
    throw err;
  });

  if (outcome.result === 'refund') {
    try {
      await require('./portone').cancelPayment(paymentId, `자동 환불: ${outcome.reason}`);
      await db.query("UPDATE payments SET status = 'cancelled' WHERE id = ?", [outcome.pay.id]);
    } catch (e) {
      console.error('[portone] 자동 환불 실패', paymentId, e.message);
      outcome.refundError = e.message;
    }
  }
  return outcome;
}

// 결제 지연 복구용: 노출/품절 여부와 무관하게 재고만 확인
async function reserveStockForce(conn, productId, qty) {
  const [r] = await conn.query('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?', [qty, productId, qty]);
  return r.affectedRows === 1;
}

async function completeCardPayment(paymentId) {
  const pg = await require('./portone').getPayment(paymentId);
  return applyCardPayment(pg);
}

// ===== 취소 =====

/**
 * @param by 'user' | 'admin' | 'system'
 * @param reason timeout | auto_cancel | user | admin
 */
async function cancelOrder(orderId, { by = 'admin', reason = 'admin', userId = null } = {}) {
  const pre = await db.one('SELECT * FROM orders WHERE id = ?', [orderId]);
  if (!pre) throw new PolicyError('주문을 찾을 수 없습니다', 'NOT_FOUND');
  if (userId && pre.user_id !== userId) throw new PolicyError('주문을 찾을 수 없습니다', 'NOT_FOUND');
  if (pre.status === 'cancelled') return pre;
  if (by === 'user' && pre.status !== 'pending') throw new PolicyError('결제가 완료된 주문은 고객센터로 취소를 요청해 주세요', 'CANNOT_CANCEL');
  if (pre.status === 'shipped') throw new PolicyError('발송완료된 주문은 취소할 수 없습니다', 'SHIPPED');
  if (pre.shipment_request_id && ['kept', 'preparing'].includes(pre.status)) {
    throw new PolicyError('출고 요청에 포함된 주문입니다. 출고 요청을 먼저 취소해 주세요', 'IN_SHIPMENT');
  }

  // 결제완료된 카드 주문은 PG 취소를 먼저 성공시킨 뒤 DB 반영
  if (pre.payment_method === 'card' && pre.status !== 'pending') {
    const pay = await db.one("SELECT * FROM payments WHERE target_type = 'order' AND target_id = ? AND status = 'paid'", [pre.id]);
    if (pay && pay.pg_payment_id) {
      await require('./portone').cancelPayment(pay.pg_payment_id, '주문 취소');
      await db.query("UPDATE payments SET status = 'cancelled' WHERE id = ?", [pay.id]);
    }
  }

  return db.tx(async (conn) => {
    const [[o]] = await conn.query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [orderId]);
    if (o.status === 'cancelled') return o;
    if (by === 'user' && o.status !== 'pending') throw new PolicyError('이미 결제가 완료되었습니다', 'CANNOT_CANCEL');
    if (by === 'system' && o.status !== 'pending') return o; // 스윕 도중 결제됨

    await releaseOrderStock(conn, o.id);
    if (o.point_used > 0) {
      await applyPoints(conn, { userId: o.user_id, type: 'refund', delta: o.point_used, orderId: o.id, memo: `주문 ${o.order_no} 취소` });
    }
    if (o.point_earned > 0) {
      await applyPoints(conn, { userId: o.user_id, type: 'revoke', delta: -o.point_earned, orderId: o.id, memo: `주문 ${o.order_no} 취소 회수` });
    }
    await conn.query(
      "UPDATE orders SET status = 'cancelled', cancelled_at = ?, cancel_reason = ?, reserve_expires_at = NULL, point_earned = 0 WHERE id = ?",
      [new Date(), reason, o.id]
    );
    if (o.payment_method === 'bank' && o.paid_at && settings.pointEarnPolicy().mode === 'tier') {
      await reconcileDailyTierPoints(conn, o.user_id, o.paid_at, settings.pointEarnPolicy());
    }
    await conn.query("UPDATE payments SET status = 'cancelled' WHERE target_type = 'order' AND target_id = ? AND status = 'ready'", [o.id]);
    return { ...o, status: 'cancelled' };
  });
}

// 완전 삭제는 취소된 주문에만 허용한다. 포인트 원장은 보존하고 주문 연결만 해제한다.
async function deleteOrder(orderId) {
  return db.tx(async (conn) => {
    const [[o]] = await conn.query('SELECT id, order_no, status, shipment_request_id FROM orders WHERE id = ? FOR UPDATE', [orderId]);
    if (!o) throw new PolicyError('주문을 찾을 수 없습니다', 'NOT_FOUND');
    if (o.status !== 'cancelled') {
      throw new PolicyError('취소된 주문만 삭제할 수 있습니다. 먼저 주문을 취소해 주세요', 'DELETE_ONLY_CANCELLED');
    }
    if (o.shipment_request_id) {
      throw new PolicyError('출고 이력이 연결된 주문은 삭제할 수 없습니다', 'HAS_SHIPMENT');
    }

    await conn.query('UPDATE point_ledger SET order_id = NULL WHERE order_id = ?', [o.id]);
    await conn.query("DELETE FROM payments WHERE target_type = 'order' AND target_id = ?", [o.id]);
    const [result] = await conn.query("DELETE FROM orders WHERE id = ? AND status = 'cancelled'", [o.id]);
    if (result.affectedRows !== 1) throw new PolicyError('주문 삭제에 실패했습니다. 다시 시도해 주세요', 'DELETE_FAILED');
    return o;
  });
}

// ===== 상태 변경 (관리자) =====

// 관리자가 직접 바꿀 수 있는 전이. 입금 확인/취소/킵 출고는 별도 함수.
const ADMIN_TRANSITIONS = {
  paid: ['preparing', 'shipped'],
  preparing: ['shipped', 'paid'],
  shipped: ['preparing'],
  kept: [],
};

async function setStatus(orderId, next) {
  return db.tx(async (conn) => {
    const [[o]] = await conn.query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [orderId]);
    if (!o) throw new PolicyError('주문을 찾을 수 없습니다', 'NOT_FOUND');
    if (o.status === next) return o;
    const allowed = ADMIN_TRANSITIONS[o.status] || [];
    if (!allowed.includes(next)) {
      throw new PolicyError(`${o.order_no}: ${statusLabel(o)} → ${STATUS_LABELS[next] || next} 변경 불가`, 'BAD_TRANSITION');
    }
    if (o.shipment_request_id) throw new PolicyError(`${o.order_no}: 킵 출고 요청 건은 킵 관리에서 처리해 주세요`, 'IN_SHIPMENT');
    await conn.query('UPDATE orders SET status = ?, shipped_at = ? WHERE id = ?', [next, next === 'shipped' ? new Date() : o.shipped_at, o.id]);
    return { ...o, status: next };
  });
}

async function setTracking(orderId, courier, trackingNo) {
  await db.query('UPDATE orders SET courier = ?, tracking_no = ? WHERE id = ?', [courier || null, trackingNo || null, orderId]);
}

// ===== 만료 처리 (1분 주기) =====

async function expireStale({ now = new Date() } = {}) {
  const portoneReady = require('../config').portone.enabled;
  const rows = await db.query(
    "SELECT id, payment_method FROM orders WHERE status = 'pending' AND reserve_expires_at IS NOT NULL AND reserve_expires_at < ? LIMIT 200",
    [now]
  );
  let cancelled = 0;
  for (const r of rows) {
    try {
      if (r.payment_method === 'card' && portoneReady) {
        // 웹훅이 늦거나 누락된 경우를 대비해 취소 전 실제 결제 여부 확인
        const pay = await db.one("SELECT pg_payment_id FROM payments WHERE target_type = 'order' AND target_id = ? AND status = 'ready'", [r.id]);
        if (pay) {
          try {
            const pg = await require('./portone').getPayment(pay.pg_payment_id);
            if (pg.status === 'PAID') {
              await applyCardPayment(pg);
              continue;
            }
          } catch (e) {
            if (e.status !== 404) throw e; // 결제창을 열지 않은 경우 404
          }
        }
      }
      await cancelOrder(r.id, { by: 'system', reason: r.payment_method === 'card' ? 'timeout' : 'auto_cancel' });
      cancelled++;
    } catch (e) {
      console.error('[expire] order', r.id, e.message);
    }
  }
  return cancelled;
}

async function getOrderWithItems(orderId) {
  const o = await db.one('SELECT * FROM orders WHERE id = ?', [orderId]);
  if (!o) return null;
  o.items = await db.query('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [orderId]);
  return o;
}

module.exports = {
  STATUS_LABELS,
  PAYMENT_LABELS,
  DELIVERY_LABELS,
  CANCEL_REASON_LABELS,
  statusLabel,
  newNo,
  reserveStock,
  releaseStock,
  createOrder,
  confirmDeposit,
  applyCardPayment,
  completeCardPayment,
  cancelOrder,
  deleteOrder,
  setStatus,
  setTracking,
  expireStale,
  getOrderWithItems,
  ADMIN_TRANSITIONS,
};
