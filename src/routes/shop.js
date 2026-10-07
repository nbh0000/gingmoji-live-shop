const express = require('express');
const config = require('../config');
const db = require('../db');
const settings = require('../settings');
const productsSvc = require('../services/products');
const orders = require('../services/orders');
const live = require('../services/live');
const { wrap, requireUser, flash } = require('../middleware/common');

const router = express.Router();

router.get('/', wrap(async (req, res) => {
  const s = settings.all();
  const list = await productsSvc.listForShop();
  const showPrice = live.priceVisible(s);
  res.render('shop/home', { title: null, products: list, showPrice });
}));

// 상품 상세 (PG 심사: 가격·상품정보 확인용, 공유 링크용). 홈에서는 바텀시트로 열림
router.get('/p/:id', wrap(async (req, res, next) => {
  const p = await productsSvc.get(parseInt(req.params.id, 10));
  if (!p || !p.is_visible) return next();
  res.render('shop/product', { title: p.name, p, showPrice: live.priceVisible(settings.all()) });
}));

router.get('/checkout', requireUser, (req, res) => {
  res.render('shop/checkout', { title: '주문하기' });
});

router.get('/orders/:no', requireUser, wrap(async (req, res, next) => {
  const row = await db.one('SELECT id FROM orders WHERE order_no = ? AND user_id = ?', [req.params.no, req.user.id]);
  if (!row) return next();
  const o = await orders.getOrderWithItems(row.id);
  res.render('shop/order', { title: '주문 내역', o, justPlaced: req.query.placed === '1' });
}));

// 모바일 카드결제 리다이렉트 복귀: ?paymentId=...&code=...&message=...
router.get('/pay/return', requireUser, wrap(async (req, res) => {
  const type = req.query.type === 'shipment' ? 'shipment' : 'order';
  const no = String(req.query.no || '');
  const back = type === 'order' ? `/orders/${encodeURIComponent(no)}` : `/my/shipments/${encodeURIComponent(no)}`;
  if (req.query.code) {
    flash(req, 'error', req.query.message ? `결제가 완료되지 않았습니다: ${req.query.message}` : '결제가 취소되었습니다');
    return res.redirect(back);
  }
  const paymentId = String(req.query.paymentId || '');
  const pay = await db.one('SELECT * FROM payments WHERE pg_payment_id = ?', [paymentId]);
  if (!pay) {
    flash(req, 'error', '결제 정보를 찾을 수 없습니다');
    return res.redirect(back);
  }
  const owner = await db.one(`SELECT user_id FROM ${pay.target_type === 'order' ? 'orders' : 'shipment_requests'} WHERE id = ?`, [pay.target_id]);
  if (!owner || owner.user_id !== req.user.id) return res.redirect('/');
  try {
    const out = await orders.completeCardPayment(paymentId);
    live.invalidate();
    if (out.result === 'paid' || out.result === 'already') flash(req, 'ok', '결제가 완료되었습니다');
    else if (out.result === 'refund') flash(req, 'error', `결제가 취소되었습니다: ${out.reason}`);
    else flash(req, 'error', '결제가 아직 완료되지 않았습니다');
  } catch (e) {
    console.error('[pay/return]', e.message);
    flash(req, 'error', '결제 확인 중 문제가 생겼습니다. 잠시 후 주문 내역을 확인해 주세요');
  }
  res.redirect(type === 'order' ? `${back}?placed=1` : back);
}));

router.get('/page/:slug', wrap(async (req, res, next) => {
  const page = await db.one('SELECT * FROM pages WHERE slug = ?', [req.params.slug]);
  if (!page) return next();
  res.render('shop/page', { title: page.title, page });
}));

// 카드결제 SDK 설정 (클라이언트에서 사용)
router.get('/api/pay-config', (req, res) => {
  res.json({ enabled: config.portone.enabled });
});

module.exports = router;
