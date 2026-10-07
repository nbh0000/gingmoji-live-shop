const express = require('express');
const db = require('../db');
const users = require('../services/users');
const shipments = require('../services/shipments');
const { wrap, requireUser, flash } = require('../middleware/common');

const router = express.Router();

router.use('/my', requireUser);

router.get('/my', wrap(async (req, res) => {
  const recent = await db.query('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5', [req.user.id]);
  const box = await shipments.keepBox(req.user.id);
  const pendingShip = await db.one("SELECT * FROM shipment_requests WHERE user_id = ? AND status = 'pending_payment' ORDER BY id DESC LIMIT 1", [req.user.id]);
  res.render('shop/my', { title: '마이페이지', recent, box, pendingShip });
}));

router.get('/my/orders', wrap(async (req, res) => {
  const list = await db.query('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 100', [req.user.id]);
  const ids = list.map((o) => o.id);
  const items = ids.length ? await db.query('SELECT order_id, product_name, qty FROM order_items WHERE order_id IN (?) ORDER BY id', [ids]) : [];
  for (const o of list) o.items = items.filter((i) => i.order_id === o.id);
  res.render('shop/my-orders', { title: '주문 내역', list });
}));

router.get('/my/keep', wrap(async (req, res) => {
  const box = await shipments.keepBox(req.user.id);
  const requests = await db.query('SELECT * FROM shipment_requests WHERE user_id = ? ORDER BY id DESC LIMIT 30', [req.user.id]);
  const pendingShip = requests.find((r) => r.status === 'pending_payment') || null;
  res.render('shop/my-keep', { title: '킵 보관함', box, requests, pendingShip });
}));

router.get('/my/shipments/:no', wrap(async (req, res, next) => {
  const sr = await db.one('SELECT * FROM shipment_requests WHERE request_no = ? AND user_id = ?', [req.params.no, req.user.id]);
  if (!sr) return next();
  const list = await db.query('SELECT * FROM orders WHERE shipment_request_id = ? ORDER BY id', [sr.id]);
  res.render('shop/shipment', { title: '출고 요청', sr, list });
}));

router.get('/my/points', wrap(async (req, res) => {
  const list = await db.query('SELECT l.*, o.order_no FROM point_ledger l LEFT JOIN orders o ON o.id = l.order_id WHERE l.user_id = ? ORDER BY l.id DESC LIMIT 200', [req.user.id]);
  res.render('shop/my-points', { title: '포인트', list });
}));

router.get('/my/profile', (req, res) => {
  res.render('shop/my-profile', { title: '회원 정보', form: req.user, error: null });
});

router.post('/my/profile', wrap(async (req, res) => {
  try {
    await users.updateProfile(req.user.id, req.body);
    flash(req, 'ok', '회원 정보를 저장했습니다');
    res.redirect('/my');
  } catch (e) {
    if (e.name !== 'PolicyError') throw e;
    res.status(400).render('shop/my-profile', { title: '회원 정보', form: { ...req.user, ...req.body }, error: e.message });
  }
}));

router.post('/my/password', wrap(async (req, res) => {
  try {
    await users.changePassword(req.user.id, req.body.current, req.body.next);
    flash(req, 'ok', '비밀번호를 변경했습니다');
  } catch (e) {
    if (e.name !== 'PolicyError') throw e;
    flash(req, 'error', e.message);
  }
  res.redirect('/my/profile');
}));

module.exports = router;
