// 고객용 JSON API: 방송 상태 폴링, 장바구니 견적, 주문 생성, 카드결제 완료, 킵 출고 요청
const express = require('express');
const config = require('../config');
const db = require('../db');
const settings = require('../settings');
const pricing = require('../services/pricing');
const orders = require('../services/orders');
const shipments = require('../services/shipments');
const live = require('../services/live');
const { wrap, requireUser } = require('../middleware/common');

const router = express.Router();

function fail(res, err) {
  if (err && err.name === 'PolicyError') return res.status(400).json({ ok: false, message: err.message, code: err.code });
  throw err;
}

// 방송 상태 + 재고 (5초 폴링)
router.get('/api/live', wrap(async (req, res) => {
  res.set('Cache-Control', 'no-store');
  res.json({ ok: true, ...(await live.snapshot()) });
}));

// 상품 바텀시트용 상세
router.get('/api/products/:id', wrap(async (req, res) => {
  const p = await db.one(
    'SELECT id, name, description, price, stock, use_open_option, is_soldout FROM products WHERE id = ? AND deleted_at IS NULL AND is_visible = 1',
    [parseInt(req.params.id, 10)]
  );
  if (!p) return res.status(404).json({ ok: false, message: '판매하지 않는 상품입니다' });
  const s = await settings.fresh();
  const images = await db.query('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [p.id]);
  res.json({
    ok: true,
    live: Boolean(s.live_on),
    product: {
      id: p.id,
      name: p.name,
      description: p.description || '',
      price: live.priceVisible(s) ? p.price : null,
      stock: Math.max(0, p.stock),
      showStock: Boolean(s.show_stock),
      soldout: Boolean(p.is_soldout) || p.stock <= 0,
      useOpenOption: Boolean(p.use_open_option),
      images: images.map((i) => i.id),
    },
  });
}));

/**
 * 장바구니/주문서 견적: 현재 가격·재고로 금액 계산 (재고 차감 없음)
 * body: { lines, deliveryType, pointUse }
 */
router.post('/api/cart/quote', wrap(async (req, res) => {
  const s = await settings.fresh();
  const showPrice = live.priceVisible(s);
  const merged = pricing.mergeLines(req.body.lines);
  const ids = merged.map((l) => l.productId);
  const rows = ids.length
    ? await db.query(
        `SELECT p.id, p.name, p.price, p.stock, p.use_open_option, p.is_visible, p.is_soldout, p.deleted_at,
                (SELECT i.id FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_id
           FROM products p WHERE p.id IN (?)`,
        [ids]
      )
    : [];
  const pmap = new Map(rows.map((p) => [p.id, p]));
  const lines = [];
  for (const l of merged) {
    const p = pmap.get(l.productId);
    const line = { productId: l.productId, opened: l.opened, unopened: l.unopened, qty: l.qty };
    if (!p || p.deleted_at || !p.is_visible) {
      lines.push({ ...line, name: '판매 종료된 상품', problem: '판매 종료' });
      continue;
    }
    let n;
    try {
      n = pricing.normalizeLine(l, p);
    } catch (e) {
      lines.push({ ...line, name: p.name, problem: e.message });
      continue;
    }
    const problem = p.is_soldout || p.stock <= 0 ? '품절' : n.qty > p.stock ? '주문 가능한 수량을 초과했어요' : null;
    lines.push({
      productId: p.id,
      name: p.name,
      imageId: p.image_id,
      useOpenOption: Boolean(p.use_open_option),
      unitPrice: showPrice ? p.price : null,
      stock: p.stock,
      ...n,
      lineAmount: showPrice ? p.price * n.qty : null,
      problem,
    });
  }
  const valid = lines.filter((l) => !l.problem);
  let summary = null;
  if (showPrice) {
    const deliveryType = req.body.deliveryType === 'keep' ? 'keep' : 'direct';
    const pre = pricing.computeOrder({ lines: valid, deliveryType }, s);
    let maxPoints = 0;
    if (req.user) {
      maxPoints = pricing.maxUsablePoints({ balance: req.user.point_balance, itemsAmount: pre.itemsAmount, shippingFee: pre.shippingFee }, s);
    }
    const pointUse = Math.min(Math.max(0, parseInt(req.body.pointUse, 10) || 0), maxPoints);
    const unit = Math.max(1, s.point_use_unit || 1);
    const pu = Math.floor(pointUse / unit) * unit;
    summary = {
      ...pricing.computeOrder({ lines: valid, deliveryType, pointUsed: pu }, s),
      deliveryType,
      maxPoints,
      remainingForFree: deliveryType === 'direct' ? pricing.remainingForFree(pre.itemsAmount, s) : 0,
    };
  }
  res.json({
    ok: true,
    live: Boolean(s.live_on),
    showPrice,
    lines,
    hasProblem: lines.some((l) => l.problem),
    summary,
    pointBalance: req.user ? req.user.point_balance : 0,
  });
}));

// 주문 생성 (재고 선점)
router.post('/api/orders', requireUser, wrap(async (req, res) => {
  try {
    const b = req.body;
    const order = await orders.createOrder(
      req.user.id,
      {
        lines: b.lines,
        deliveryType: b.deliveryType,
        paymentMethod: b.paymentMethod,
        recipient: b.recipient,
        depositorName: b.depositorName,
        cashReceipt: b.cashReceipt,
        pointUse: b.pointUse,
      },
      { cardEnabled: config.portone.enabled }
    );
    if (b.saveAddress && b.recipient) {
      const r = b.recipient;
      await db.query('UPDATE users SET zipcode = ?, address1 = ?, address2 = ? WHERE id = ?', [
        String(r.zipcode || '').slice(0, 10), String(r.address1 || '').slice(0, 255), String(r.address2 || '').slice(0, 255), req.user.id,
      ]);
    }
    live.invalidate();
    const resp = { ok: true, orderNo: order.orderNo, status: order.status, total: order.total, redirect: `/orders/${order.orderNo}` };
    if (order.paymentId) {
      resp.payment = paymentRequest({
        paymentId: order.paymentId,
        orderName: await orderName(order.id),
        amount: order.total,
        user: req.user,
        redirectUrl: `${config.baseUrl}/pay/return?type=order&no=${order.orderNo}`,
      });
    }
    res.json(resp);
  } catch (e) {
    fail(res, e);
  }
}));

async function orderName(orderId) {
  const items = await db.query('SELECT product_name, qty FROM order_items WHERE order_id = ? ORDER BY id', [orderId]);
  if (!items.length) return '깅모지 주문';
  const first = items[0].product_name.slice(0, 30);
  return items.length > 1 ? `${first} 외 ${items.length - 1}건` : first;
}

function paymentRequest({ paymentId, orderName: name, amount, user, redirectUrl }) {
  return {
    storeId: config.portone.storeId,
    channelKey: config.portone.channelKey,
    paymentId,
    orderName: name,
    totalAmount: amount,
    currency: 'CURRENCY_KRW',
    payMethod: 'CARD',
    redirectUrl,
    customer: {
      fullName: user.name || undefined,
      phoneNumber: user.phone || undefined,
      email: user.email || undefined,
    },
  };
}

// 결제 대기 중인 주문의 결제창 다시 열기
router.post('/api/orders/:no/pay', requireUser, wrap(async (req, res) => {
  const o = await db.one('SELECT * FROM orders WHERE order_no = ? AND user_id = ?', [req.params.no, req.user.id]);
  if (!o || o.status !== 'pending' || o.payment_method !== 'card') return res.status(400).json({ ok: false, message: '결제할 수 없는 주문입니다' });
  const pay = await db.one("SELECT * FROM payments WHERE target_type = 'order' AND target_id = ? AND status = 'ready' ORDER BY id DESC LIMIT 1", [o.id]);
  if (!pay) return res.status(400).json({ ok: false, message: '결제 정보를 찾을 수 없습니다' });
  res.json({
    ok: true,
    payment: paymentRequest({
      paymentId: pay.pg_payment_id,
      orderName: await orderName(o.id),
      amount: pay.amount,
      user: req.user,
      redirectUrl: `${config.baseUrl}/pay/return?type=order&no=${o.order_no}`,
    }),
  });
}));

// 고객 취소 (결제 전만)
router.post('/api/orders/:no/cancel', requireUser, wrap(async (req, res) => {
  const o = await db.one('SELECT id FROM orders WHERE order_no = ? AND user_id = ?', [req.params.no, req.user.id]);
  if (!o) return res.status(404).json({ ok: false, message: '주문을 찾을 수 없습니다' });
  try {
    await orders.cancelOrder(o.id, { by: 'user', reason: 'user', userId: req.user.id });
    live.invalidate();
    res.json({ ok: true });
  } catch (e) {
    fail(res, e);
  }
}));

// 카드결제 완료 (PC 팝업/iframe 방식에서 SDK 응답 후 호출)
router.post('/api/payments/complete', requireUser, wrap(async (req, res) => {
  const paymentId = String(req.body.paymentId || '');
  const pay = await db.one('SELECT * FROM payments WHERE pg_payment_id = ?', [paymentId]);
  if (!pay) return res.status(404).json({ ok: false, message: '결제 정보를 찾을 수 없습니다' });
  if (!(await ownsPayment(pay, req.user.id))) return res.status(404).json({ ok: false, message: '결제 정보를 찾을 수 없습니다' });
  const out = await orders.completeCardPayment(paymentId);
  live.invalidate();
  if (out.result === 'paid' || out.result === 'already') return res.json({ ok: true });
  if (out.result === 'refund') return res.status(400).json({ ok: false, message: `결제가 취소되었습니다: ${out.reason}` });
  res.status(400).json({ ok: false, message: '결제가 완료되지 않았습니다' });
}));

async function ownsPayment(pay, userId) {
  const table = pay.target_type === 'order' ? 'orders' : 'shipment_requests';
  const row = await db.one(`SELECT user_id FROM ${table} WHERE id = ?`, [pay.target_id]);
  return row && row.user_id === userId;
}

// ===== 킵 출고 요청 =====

router.post('/api/keep/release', requireUser, wrap(async (req, res) => {
  try {
    const sr = await shipments.requestRelease(
      req.user.id,
      { recipient: req.body.recipient, paymentMethod: req.body.paymentMethod, depositorName: req.body.depositorName },
      { cardEnabled: config.portone.enabled }
    );
    const resp = { ok: true, requestNo: sr.requestNo, status: sr.status, fee: sr.fee, redirect: `/my/shipments/${sr.requestNo}` };
    if (sr.paymentId) {
      resp.payment = paymentRequest({
        paymentId: sr.paymentId,
        orderName: `킵 출고 배송비 (${sr.orderCount}건)`,
        amount: sr.fee,
        user: req.user,
        redirectUrl: `${config.baseUrl}/pay/return?type=shipment&no=${sr.requestNo}`,
      });
    }
    res.json(resp);
  } catch (e) {
    fail(res, e);
  }
}));

router.post('/api/shipments/:no/pay', requireUser, wrap(async (req, res) => {
  const sr = await db.one('SELECT * FROM shipment_requests WHERE request_no = ? AND user_id = ?', [req.params.no, req.user.id]);
  if (!sr || sr.status !== 'pending_payment' || sr.payment_method !== 'card') return res.status(400).json({ ok: false, message: '결제할 수 없는 요청입니다' });
  const pay = await db.one("SELECT * FROM payments WHERE target_type = 'shipment' AND target_id = ? AND status = 'ready' ORDER BY id DESC LIMIT 1", [sr.id]);
  if (!pay) return res.status(400).json({ ok: false, message: '결제 정보를 찾을 수 없습니다' });
  res.json({
    ok: true,
    payment: paymentRequest({
      paymentId: pay.pg_payment_id,
      orderName: '킵 출고 배송비',
      amount: pay.amount,
      user: req.user,
      redirectUrl: `${config.baseUrl}/pay/return?type=shipment&no=${sr.request_no}`,
    }),
  });
}));

router.post('/api/shipments/:no/cancel', requireUser, wrap(async (req, res) => {
  const sr = await db.one('SELECT id FROM shipment_requests WHERE request_no = ? AND user_id = ?', [req.params.no, req.user.id]);
  if (!sr) return res.status(404).json({ ok: false, message: '출고 요청을 찾을 수 없습니다' });
  try {
    await shipments.cancelRequest(sr.id, { by: 'user', userId: req.user.id, reason: 'user' });
    res.json({ ok: true });
  } catch (e) {
    fail(res, e);
  }
}));

module.exports = router;
