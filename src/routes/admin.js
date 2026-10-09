const crypto = require('crypto');
const express = require('express');
const multer = require('multer');
const config = require('../config');
const db = require('../db');
const settings = require('../settings');
const orders = require('../services/orders');
const shipments = require('../services/shipments');
const productsSvc = require('../services/products');
const { applyPoints, TYPE_LABELS } = require('../services/points');
const live = require('../services/live');
const fmt = require('../views/helpers');
const { wrap, flash, requireAdmin, loginLimiter } = require('../middleware/common');

const router = express.Router();
const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 4 * 1024 * 1024, files: 10 } });

router.use((req, res, next) => {
  res.locals.path = req.path; // /admin 기준 상대 경로 (메뉴 활성화용)
  res.locals.L = {
    status: orders.STATUS_LABELS,
    payment: orders.PAYMENT_LABELS,
    delivery: orders.DELIVERY_LABELS,
    cancel: orders.CANCEL_REASON_LABELS,
    ship: shipments.STATUS_LABELS,
    point: TYPE_LABELS,
  };
  res.locals.orderStatus = orders.statusLabel;
  res.locals.shipStatus = shipments.statusLabel;
  next();
});

// ===== 로그인 =====

function safeEqual(a, b) {
  const x = crypto.createHash('sha256').update(String(a)).digest();
  const y = crypto.createHash('sha256').update(String(b)).digest();
  return crypto.timingSafeEqual(x, y);
}

router.get('/login', (req, res) => {
  if (req.session.isAdmin) return res.redirect('/admin');
  res.render('admin/login', { title: '관리자 로그인', error: null });
});

router.post('/login', loginLimiter, (req, res, next) => {
  const ok = config.admin.password && safeEqual(req.body.id, config.admin.id) && safeEqual(req.body.password, config.admin.password);
  if (!ok) return res.status(400).render('admin/login', { title: '관리자 로그인', error: '아이디 또는 비밀번호가 맞지 않습니다' });
  const userId = req.session.userId;
  req.session.regenerate((err) => {
    if (err) return next(err);
    req.session.isAdmin = true;
    if (userId) req.session.userId = userId;
    req.session.save(() => res.redirect('/admin'));
  });
});

router.post('/logout', (req, res) => {
  req.session.isAdmin = false;
  res.redirect('/admin/login');
});

router.use(requireAdmin);

// 메뉴 배지: 입금 대기, 처리할 출고 요청
router.use(wrap(async (req, res, next) => {
  const [[c]] = await db.pool.query("SELECT (SELECT COUNT(*) FROM orders WHERE status = 'pending' AND payment_method = 'bank') AS bank, (SELECT COUNT(*) FROM shipment_requests WHERE status IN ('requested','pending_payment')) AS ship, (SELECT COUNT(*) FROM orders WHERE status = 'paid') AS prep");
  res.locals.navCounts = c;
  next();
}));

function back(req, fallback) {
  const ref = req.get('referer');
  return ref && ref.includes('/admin') ? ref : fallback;
}

function policyFlash(req, e) {
  if (e && e.name === 'PolicyError') {
    flash(req, 'error', e.message);
    return true;
  }
  if (e && /PortOne API/.test(e.message)) {
    flash(req, 'error', `포트원 처리 실패: ${e.message}`);
    return true;
  }
  return false;
}

// ===== 대시보드 =====

async function stats() {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const [[o]] = await db.pool.query(
    "SELECT COUNT(*) AS cnt FROM orders WHERE created_at >= ? AND status <> 'cancelled'",
    [today]
  );
  const [[rev]] = await db.pool.query(
    "SELECT COALESCE(SUM(total_amount),0) AS amount, COUNT(*) AS cnt FROM orders WHERE paid_at >= ? AND status <> 'cancelled'",
    [today]
  );
  const [[pend]] = await db.pool.query("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amount FROM orders WHERE status = 'pending' AND payment_method = 'bank'");
  const [[cardPend]] = await db.pool.query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'pending' AND payment_method = 'card'");
  const [[ship]] = await db.pool.query("SELECT COUNT(*) AS cnt FROM shipment_requests WHERE status IN ('requested','pending_payment')");
  const [[prep]] = await db.pool.query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'paid'");
  return {
    todayOrders: o.cnt,
    todayRevenue: rev.amount,
    todayPaid: rev.cnt,
    bankPending: pend.cnt,
    bankPendingAmount: pend.amount,
    cardPending: cardPend.cnt,
    shipRequests: ship.cnt,
    toPrepare: prep.cnt,
  };
}

router.get('/', wrap(async (req, res) => {
  const recent = await db.query('SELECT * FROM orders ORDER BY id DESC LIMIT 15');
  res.render('admin/dashboard', { title: '대시보드', st: await stats(), recent });
}));

router.get('/api/stats', wrap(async (req, res) => {
  const recent = await db.query('SELECT id, order_no, youtube_nickname, recipient_name, total_amount, status, payment_method, delivery_type, created_at FROM orders ORDER BY id DESC LIMIT 15');
  res.json({
    ok: true,
    st: await stats(),
    recent: recent.map((o) => ({ ...o, statusLabel: orders.statusLabel(o), created: fmt.dt(o.created_at) })),
  });
}));

router.post('/live', wrap(async (req, res) => {
  await settings.set({ live_on: req.body.on === '1' });
  live.invalidate();
  if (req.xhr || (req.get('accept') || '').includes('json')) return res.json({ ok: true, live: settings.get('live_on') });
  flash(req, 'ok', settings.get('live_on') ? '방송을 시작했습니다. 주문을 받습니다' : '방송을 종료했습니다. 주문이 막혔습니다');
  res.redirect(back(req, '/admin'));
}));

router.post('/price-always', wrap(async (req, res) => {
  await settings.set({ always_show_price: req.body.on === '1' });
  live.invalidate();
  flash(req, 'ok', settings.get('always_show_price') ? '가격 항상 노출을 켰습니다' : '가격 항상 노출을 껐습니다');
  res.redirect(back(req, '/admin'));
}));

// ===== 주문 =====

function orderFilter(q) {
  const where = [];
  const params = [];
  if (q.status === 'bank_pending') where.push("o.status = 'pending' AND o.payment_method = 'bank'");
  else if (q.status === 'card_pending') where.push("o.status = 'pending' AND o.payment_method = 'card'");
  else if (q.status && orders.STATUS_LABELS[q.status]) { where.push('o.status = ?'); params.push(q.status); }
  else if (!q.status) where.push("o.status <> 'cancelled'");
  if (q.method === 'card' || q.method === 'bank') { where.push('o.payment_method = ?'); params.push(q.method); }
  if (q.delivery === 'direct' || q.delivery === 'keep') { where.push('o.delivery_type = ?'); params.push(q.delivery); }
  if (q.q) {
    const like = `%${String(q.q).trim()}%`;
    where.push('(o.youtube_nickname LIKE ? OR o.recipient_name LIKE ? OR u.name LIKE ? OR u.youtube_nickname LIKE ? OR o.order_no LIKE ? OR o.recipient_phone LIKE ? OR o.depositor_name LIKE ?)');
    params.push(like, like, like, like, like, like, like);
  }
  if (q.from) { where.push('o.created_at >= ?'); params.push(`${q.from} 00:00:00`); }
  if (q.to) { where.push('o.created_at <= ?'); params.push(`${q.to} 23:59:59`); }
  return { sql: where.length ? `WHERE ${where.join(' AND ')}` : '', params };
}

router.get('/orders', wrap(async (req, res) => {
  const q = { status: req.query.status === undefined ? '' : String(req.query.status), method: req.query.method || '', delivery: req.query.delivery || '', q: req.query.q || '', from: req.query.from || '', to: req.query.to || '' };
  const page = Math.max(1, parseInt(req.query.page, 10) || 1);
  const per = 50;
  const f = orderFilter(q);
  const [[cnt]] = await db.pool.query(`SELECT COUNT(*) AS n FROM orders o JOIN users u ON u.id = o.user_id ${f.sql}`, f.params);
  const list = await db.query(
    `SELECT o.*, u.name AS user_name, u.youtube_nickname AS user_nick FROM orders o JOIN users u ON u.id = o.user_id ${f.sql}
      ORDER BY o.id DESC LIMIT ? OFFSET ?`,
    [...f.params, per, (page - 1) * per]
  );
  const ids = list.map((o) => o.id);
  const items = ids.length ? await db.query('SELECT * FROM order_items WHERE order_id IN (?) ORDER BY id', [ids]) : [];
  for (const o of list) o.items = items.filter((i) => i.order_id === o.id);
  res.render('admin/orders', { title: '주문 관리', list, q, page, pages: Math.max(1, Math.ceil(cnt.n / per)), total: cnt.n, auto: req.query.auto === '1' });
}));

router.get('/orders/export.csv', wrap(async (req, res) => {
  const q = { status: req.query.status === undefined ? '' : String(req.query.status), method: req.query.method || '', delivery: req.query.delivery || '', q: req.query.q || '', from: req.query.from || '', to: req.query.to || '' };
  const f = orderFilter(q);
  const rows = await db.query(
    `SELECT o.*, i.product_name, i.unit_price, i.qty, i.qty_opened, i.qty_unopened, i.line_amount, p.use_open_option
       FROM orders o JOIN users u ON u.id = o.user_id
       JOIN order_items i ON i.order_id = o.id
       LEFT JOIN products p ON p.id = i.product_id
       ${f.sql} ORDER BY o.id DESC, i.id LIMIT 20000`,
    f.params
  );
  const head = ['주문번호', '주문일시', '상태', '유튜브 닉네임', '받는 분', '연락처', '우편번호', '주소', '상세주소', '배송메모', '결제수단', '배송방식',
    '상품명', '단가', '수량', fmt.OPT.opened, fmt.OPT.unopened, '상품합계', '주문 상품금액', '배송비', '포인트 사용', '결제금액', '입금자명', '현금영수증 유형', '현금영수증 정보', '결제일시', '택배사', '송장번호'];
  const cell = (v) => {
    const s = v === null || v === undefined ? '' : String(v);
    // 엑셀 수식 주입 방지
    const safe = /^[=+\-@]/.test(s) ? `'${s}` : s;
    return /[",\n\r]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
  };
  const lines = [head.join(',')];
  for (const r of rows) {
    lines.push([
      r.order_no, fmt.dt(r.created_at), orders.statusLabel(r), r.youtube_nickname, r.recipient_name, fmt.phone(r.recipient_phone), r.zipcode, r.address1, r.address2, r.memo,
      orders.PAYMENT_LABELS[r.payment_method], orders.DELIVERY_LABELS[r.delivery_type],
      r.product_name, r.unit_price, r.qty, r.use_open_option ? r.qty_opened : '', r.use_open_option ? r.qty_unopened : '', r.line_amount,
      r.items_amount, r.shipping_fee, r.point_used, r.total_amount, r.depositor_name, r.cash_receipt_type === 'income' ? '소득공제용' : r.cash_receipt_type === 'expense' ? '지출증빙용' : '', r.cash_receipt_value, fmt.dt(r.paid_at), r.courier, r.tracking_no,
    ].map(cell).join(','));
  }
  const stamp = new Date().toISOString().slice(0, 10);
  res.set('Content-Type', 'text/csv; charset=utf-8');
  res.set('Content-Disposition', `attachment; filename="gingmoji-orders-${stamp}.csv"`);
  res.send(`﻿${lines.join('\r\n')}`); // BOM: 엑셀에서 한글 깨짐 방지
}));

router.post('/orders/bulk', wrap(async (req, res) => {
  const ids = [].concat(req.body.ids || []).map((x) => parseInt(x, 10)).filter(Boolean);
  const action = String(req.body.action || '');
  if (!ids.length) {
    flash(req, 'error', '주문을 선택해 주세요');
    return res.redirect(back(req, '/admin/orders'));
  }
  let ok = 0;
  const errors = [];
  for (const id of ids) {
    try {
      if (action === 'confirm') await orders.confirmDeposit(id);
      else if (action === 'cancel') await orders.cancelOrder(id, { by: 'admin', reason: 'admin' });
      else if (['preparing', 'shipped', 'paid'].includes(action)) await orders.setStatus(id, action);
      else throw new Error('알 수 없는 작업');
      ok++;
    } catch (e) {
      errors.push(e.message);
    }
  }
  live.invalidate();
  flash(req, errors.length ? 'error' : 'ok', `${ok}건 처리${errors.length ? ` / ${errors.length}건 실패: ${errors.slice(0, 3).join(', ')}` : ''}`);
  res.redirect(back(req, '/admin/orders'));
}));

router.get('/orders/:id', wrap(async (req, res, next) => {
  const o = await orders.getOrderWithItems(parseInt(req.params.id, 10));
  if (!o) return next();
  const u = await db.one('SELECT * FROM users WHERE id = ?', [o.user_id]);
  const pays = await db.query("SELECT * FROM payments WHERE target_type = 'order' AND target_id = ? ORDER BY id", [o.id]);
  const sr = o.shipment_request_id ? await db.one('SELECT * FROM shipment_requests WHERE id = ?', [o.shipment_request_id]) : null;
  res.render('admin/order', { title: `주문 ${o.order_no}`, o, u, pays, sr });
}));

router.post('/orders/:id/confirm', wrap(async (req, res) => {
  try {
    const o = await orders.confirmDeposit(parseInt(req.params.id, 10));
    flash(req, 'ok', `입금 확인 완료${o.point_earned ? ` · ${fmt.num(o.point_earned)}P 적립` : ''}`);
  } catch (e) {
    if (!policyFlash(req, e)) throw e;
  }
  res.redirect(back(req, '/admin/orders'));
}));

router.post('/orders/:id/cancel', wrap(async (req, res) => {
  try {
    await orders.cancelOrder(parseInt(req.params.id, 10), { by: 'admin', reason: 'admin' });
    live.invalidate();
    flash(req, 'ok', '주문을 취소했습니다. 재고를 되돌렸습니다');
  } catch (e) {
    if (!policyFlash(req, e)) throw e;
  }
  res.redirect(back(req, '/admin/orders'));
}));

router.post('/orders/:id/delete', wrap(async (req, res) => {
  try {
    const deleted = await orders.deleteOrder(parseInt(req.params.id, 10));
    flash(req, 'ok', `${deleted.order_no} 주문을 삭제했습니다`);
  } catch (e) {
    if (!policyFlash(req, e)) throw e;
  }
  res.redirect(back(req, '/admin/orders'));
}));

router.post('/orders/:id/status', wrap(async (req, res) => {
  try {
    await orders.setStatus(parseInt(req.params.id, 10), String(req.body.status));
    flash(req, 'ok', '상태를 변경했습니다');
  } catch (e) {
    if (!policyFlash(req, e)) throw e;
  }
  res.redirect(back(req, '/admin/orders'));
}));

router.post('/orders/:id/tracking', wrap(async (req, res) => {
  const id = parseInt(req.params.id, 10);
  await orders.setTracking(id, String(req.body.courier || '').slice(0, 50), String(req.body.tracking_no || '').trim().slice(0, 50));
  if (req.body.mark_shipped === '1' && req.body.tracking_no) {
    try { await orders.setStatus(id, 'shipped'); } catch (e) { if (!policyFlash(req, e)) throw e; }
  }
  if (!req.session.flash) flash(req, 'ok', '송장 정보를 저장했습니다');
  res.redirect(back(req, '/admin/orders'));
}));

router.post('/orders/:id/memo', wrap(async (req, res) => {
  await db.query('UPDATE orders SET admin_memo = ? WHERE id = ?', [String(req.body.admin_memo || '').slice(0, 2000), parseInt(req.params.id, 10)]);
  flash(req, 'ok', '메모를 저장했습니다');
  res.redirect(back(req, '/admin/orders'));
}));

// ===== 킵 관리 =====

router.get('/keep', wrap(async (req, res) => {
  const tab = req.query.tab === 'members' ? 'members' : 'requests';
  const s = settings.all();
  const members = await db.query(
    `SELECT u.id, u.name, u.youtube_nickname, u.phone, COUNT(o.id) AS order_count, SUM(o.items_amount) AS kept_amount, MIN(o.paid_at) AS since
       FROM orders o JOIN users u ON u.id = o.user_id
      WHERE o.status = 'kept' AND o.shipment_request_id IS NULL
      GROUP BY u.id ORDER BY kept_amount DESC`
  );
  const st = String(req.query.status || 'open');
  let where = "WHERE sr.status IN ('requested','preparing','pending_payment')";
  const params = [];
  if (st === 'all') where = '';
  else if (shipments.STATUS_LABELS[st]) { where = 'WHERE sr.status = ?'; params.push(st); }
  const requests = await db.query(
    `SELECT sr.*, u.name, u.youtube_nickname, (SELECT COUNT(*) FROM orders o WHERE o.shipment_request_id = sr.id) AS order_count
       FROM shipment_requests sr JOIN users u ON u.id = sr.user_id ${where} ORDER BY sr.id DESC LIMIT 200`,
    params
  );
  res.render('admin/keep', { title: '킵 관리', tab, members, requests, st, threshold: s.free_shipping_threshold });
}));

router.get('/keep/user/:id', wrap(async (req, res, next) => {
  const u = await db.one('SELECT * FROM users WHERE id = ?', [parseInt(req.params.id, 10)]);
  if (!u) return next();
  const box = await shipments.keepBox(u.id);
  res.render('admin/keep-user', { title: `킵 · ${u.youtube_nickname || u.name}`, u, box });
}));

router.get('/shipments/:id', wrap(async (req, res, next) => {
  const sr = await db.one('SELECT sr.*, u.name, u.youtube_nickname FROM shipment_requests sr JOIN users u ON u.id = sr.user_id WHERE sr.id = ?', [parseInt(req.params.id, 10)]);
  if (!sr) return next();
  const list = await db.query('SELECT * FROM orders WHERE shipment_request_id = ? ORDER BY id', [sr.id]);
  const ids = list.map((o) => o.id);
  const items = ids.length ? await db.query('SELECT * FROM order_items WHERE order_id IN (?) ORDER BY id', [ids]) : [];
  for (const o of list) o.items = items.filter((i) => i.order_id === o.id);
  res.render('admin/shipment', { title: `출고 ${sr.request_no}`, sr, list });
}));

router.post('/shipments/:id/confirm', wrap(async (req, res) => {
  try {
    await shipments.confirmDeposit(parseInt(req.params.id, 10));
    flash(req, 'ok', '배송비 입금을 확인했습니다. 출고 요청이 접수되었습니다');
  } catch (e) { if (!policyFlash(req, e)) throw e; }
  res.redirect(back(req, '/admin/keep'));
}));

router.post('/shipments/:id/cancel', wrap(async (req, res) => {
  try {
    await shipments.cancelRequest(parseInt(req.params.id, 10), { by: 'admin', reason: 'admin' });
    flash(req, 'ok', '출고 요청을 취소했습니다. 주문은 다시 킵보관 상태입니다');
  } catch (e) { if (!policyFlash(req, e)) throw e; }
  res.redirect(back(req, '/admin/keep'));
}));

router.post('/shipments/:id/status', wrap(async (req, res) => {
  try {
    await shipments.setStatus(parseInt(req.params.id, 10), String(req.body.status), {
      courier: String(req.body.courier || '').slice(0, 50),
      trackingNo: String(req.body.tracking_no || '').trim().slice(0, 50),
    });
    flash(req, 'ok', '출고 상태를 변경했습니다');
  } catch (e) { if (!policyFlash(req, e)) throw e; }
  res.redirect(back(req, '/admin/keep'));
}));

// ===== 상품 =====

router.get('/products', wrap(async (req, res) => {
  res.render('admin/products', { title: '상품·재고', list: await productsSvc.listForAdmin() });
}));

router.get('/products/new', (req, res) => {
  res.render('admin/product-form', { title: '상품 등록', p: { section: req.query.section === 'sample' ? 'sample' : 'live', use_open_option: 1, is_visible: 1, is_soldout: 0, images: [] }, isNew: true });
});

router.post('/products/new', upload.array('images', 10), wrap(async (req, res) => {
  try {
    const id = await productsSvc.create(req.body);
    for (const f of req.files || []) await productsSvc.addImage(id, f);
    live.invalidate();
    flash(req, 'ok', '상품을 등록했습니다');
    res.redirect(`/admin/products/${id}`);
  } catch (e) {
    if (!policyFlash(req, e)) throw e;
    res.redirect('/admin/products/new');
  }
}));

router.post('/products/reorder', wrap(async (req, res) => {
  const ids = String(req.body.ids || '').split(',').map((x) => parseInt(x, 10)).filter(Boolean);
  await productsSvc.reorder(ids);
  live.invalidate();
  res.json({ ok: true });
}));

router.get('/products/:id', wrap(async (req, res, next) => {
  const p = await productsSvc.get(parseInt(req.params.id, 10));
  if (!p) return next();
  res.render('admin/product-form', { title: p.name, p, isNew: false });
}));

router.post('/products/:id', upload.array('images', 10), wrap(async (req, res) => {
  const id = parseInt(req.params.id, 10);
  try {
    await productsSvc.update(id, req.body);
    for (const f of req.files || []) await productsSvc.addImage(id, f);
    live.invalidate();
    flash(req, 'ok', '저장했습니다');
  } catch (e) {
    if (!policyFlash(req, e)) throw e;
  }
  res.redirect(`/admin/products/${id}`);
}));

router.post('/products/:id/quick', wrap(async (req, res) => {
  const id = parseInt(req.params.id, 10);
  if (req.body.stock !== undefined) await productsSvc.setStock(id, req.body.stock);
  for (const f of ['is_visible', 'is_soldout']) {
    if (req.body[f] !== undefined) await productsSvc.setFlag(id, f, req.body[f] === '1');
  }
  live.invalidate();
  if (req.xhr || (req.get('accept') || '').includes('json')) return res.json({ ok: true });
  res.redirect(back(req, '/admin/products'));
}));

router.post('/products/:id/delete', wrap(async (req, res) => {
  await productsSvc.remove(parseInt(req.params.id, 10));
  live.invalidate();
  flash(req, 'ok', '상품을 삭제했습니다 (기존 주문 내역은 유지됩니다)');
  res.redirect('/admin/products');
}));

router.post('/images/:id/delete', wrap(async (req, res) => {
  await productsSvc.removeImage(parseInt(req.params.id, 10));
  res.redirect(back(req, '/admin/products'));
}));

router.post('/images/:id/first', wrap(async (req, res) => {
  await productsSvc.makeImageFirst(parseInt(req.params.id, 10));
  res.redirect(back(req, '/admin/products'));
}));

// ===== 회원 =====

router.get('/users', wrap(async (req, res) => {
  const q = String(req.query.q || '').trim();
  const params = [];
  let where = '';
  if (q) {
    const like = `%${q}%`;
    where = 'WHERE u.name LIKE ? OR u.youtube_nickname LIKE ? OR u.phone LIKE ? OR u.login_id LIKE ? OR u.email LIKE ?';
    params.push(like, like, like, like, like);
  }
  const list = await db.query(
    `SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status <> 'cancelled') AS order_count
       FROM users u ${where} ORDER BY u.id DESC LIMIT 200`,
    params
  );
  res.render('admin/users', { title: '회원 관리', list, q });
}));

router.get('/users/:id', wrap(async (req, res, next) => {
  const u = await db.one('SELECT * FROM users WHERE id = ?', [parseInt(req.params.id, 10)]);
  if (!u) return next();
  const ordersList = await db.query('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50', [u.id]);
  const ledger = await db.query('SELECT l.*, o.order_no FROM point_ledger l LEFT JOIN orders o ON o.id = l.order_id WHERE l.user_id = ? ORDER BY l.id DESC LIMIT 100', [u.id]);
  const box = await shipments.keepBox(u.id);
  res.render('admin/user', { title: u.youtube_nickname || u.name || `회원 ${u.id}`, u, ordersList, ledger, box });
}));

router.post('/users/:id/points', wrap(async (req, res) => {
  const id = parseInt(req.params.id, 10);
  const amount = parseInt(String(req.body.amount || '').replace(/[^0-9]/g, ''), 10) || 0;
  const sign = req.body.mode === 'sub' ? -1 : 1;
  if (!amount) {
    flash(req, 'error', '포인트를 입력해 주세요');
  } else {
    await db.tx((conn) => applyPoints(conn, {
      userId: id,
      type: sign > 0 ? 'admin_add' : 'admin_sub',
      delta: sign * amount,
      memo: String(req.body.memo || '').slice(0, 200) || null,
    }));
    flash(req, 'ok', `${fmt.num(amount)}P ${sign > 0 ? '지급' : '차감'}했습니다`);
  }
  res.redirect(`/admin/users/${id}`);
}));

router.post('/users/:id/memo', wrap(async (req, res) => {
  await db.query('UPDATE users SET admin_memo = ? WHERE id = ?', [String(req.body.admin_memo || '').slice(0, 2000), parseInt(req.params.id, 10)]);
  flash(req, 'ok', '메모를 저장했습니다');
  res.redirect(`/admin/users/${req.params.id}`);
}));

// ===== 설정 =====

router.get('/settings', (req, res) => {
  res.render('admin/settings', { title: '설정' });
});

router.post('/settings', wrap(async (req, res) => {
  const values = {};
  for (const key of Object.keys(settings.DEFAULTS)) {
    if (key === 'live_on') continue;
    if (settings.TYPES[key] === 'boolean') values[key] = req.body[key] === '1' || req.body[key] === 'on';
    else if (req.body[key] !== undefined) values[key] = String(req.body[key]).trim();
  }
  // 적립률은 숫자 또는 빈 값
  if (values.point_earn_rate !== undefined && values.point_earn_rate !== '' && !Number.isFinite(Number(values.point_earn_rate))) {
    flash(req, 'error', '적립률은 숫자로 입력해 주세요');
    return res.redirect('/admin/settings');
  }
  if (!['off', 'flat', 'tier'].includes(values.point_earn_mode)) {
    flash(req, 'error', '적립 방식을 확인해 주세요');
    return res.redirect('/admin/settings');
  }
  for (const key of ['point_earn_threshold', 'point_earn_under_rate', 'point_earn_over_rate']) {
    if (values[key] !== undefined && (!Number.isFinite(Number(values[key])) || Number(values[key]) < 0)) {
      flash(req, 'error', '포인트 적립 기준과 적립률은 0 이상의 숫자로 입력해 주세요');
      return res.redirect('/admin/settings');
    }
  }
  await settings.set(values);
  live.invalidate();
  flash(req, 'ok', '설정을 저장했습니다');
  res.redirect('/admin/settings');
}));

// ===== 약관 =====

router.get('/pages', wrap(async (req, res) => {
  res.render('admin/pages', { title: '약관·정책', list: await db.query('SELECT slug, title, updated_at FROM pages ORDER BY slug') });
}));

router.get('/pages/:slug', wrap(async (req, res, next) => {
  const page = await db.one('SELECT * FROM pages WHERE slug = ?', [req.params.slug]);
  if (!page) return next();
  res.render('admin/page-form', { title: page.title, page });
}));

router.post('/pages/:slug', wrap(async (req, res) => {
  await db.query('UPDATE pages SET title = ?, content = ? WHERE slug = ?', [
    String(req.body.title || '').slice(0, 100), String(req.body.content || ''), req.params.slug,
  ]);
  flash(req, 'ok', '저장했습니다');
  res.redirect(`/admin/pages/${req.params.slug}`);
}));

module.exports = router;
