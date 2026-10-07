// 통합 테스트: 재고 동시 차감, 킵 누적 배송비, 계좌이체만 포인트 적립, 만료/취소
// 필요: .env.test 의 테스트 DB (README 참고)
const { resetDb, makeProduct, makeUser, recipient } = require('./helpers');
const test = require('node:test');
const assert = require('node:assert/strict');
const db = require('../src/db');
const settings = require('../src/settings');
const orders = require('../src/services/orders');
const shipments = require('../src/services/shipments');

const order = (userId, lines, extra = {}) =>
  orders.createOrder(userId, { lines, deliveryType: 'direct', paymentMethod: 'bank', recipient, ...extra }, { cardEnabled: true });

const stockOf = async (id) => (await db.one('SELECT stock FROM products WHERE id = ?', [id])).stock;
const balanceOf = async (id) => (await db.one('SELECT point_balance FROM users WHERE id = ?', [id])).point_balance;

// 포트원 결제 조회 결과 흉내
const pgPaid = (paymentId, total) => ({ id: paymentId, status: 'PAID', amount: { total }, paidAt: new Date().toISOString() });

test.before(async () => {
  await resetDb();
  await settings.set({ live_on: true, point_earn_rate: '', point_use_enabled: false, free_shipping_threshold: 80000, shipping_fee: 4000, card_hold_minutes: 10, bank_auto_cancel_hours: 24 });
});

test.after(async () => {
  await db.pool.end();
});

test('재고 5개에 동시 주문 20건 → 정확히 5건만 성공, 재고 0', async () => {
  const pid = await makeProduct(db, { stock: 5 });
  const users = [];
  for (let i = 0; i < 20; i++) users.push(await makeUser(db));
  const results = await Promise.allSettled(users.map((u) => order(u, [{ productId: pid, opened: 1, unopened: 0 }])));
  const ok = results.filter((r) => r.status === 'fulfilled');
  const fail = results.filter((r) => r.status === 'rejected');
  assert.equal(ok.length, 5);
  assert.equal(fail.length, 15);
  for (const f of fail) assert.equal(f.reason.code, 'OUT_OF_STOCK');
  assert.equal(await stockOf(pid), 0);
  const [[c]] = await db.pool.query("SELECT COALESCE(SUM(oi.qty),0) AS q FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.product_id = ? AND o.status <> 'cancelled'", [pid]);
  assert.equal(Number(c.q), 5);
});

test('동시에 2개씩 주문해도 초과판매 없음 (재고 7 → 3건 성공, 1개 남음)', async () => {
  const pid = await makeProduct(db, { stock: 7 });
  const users = [];
  for (let i = 0; i < 10; i++) users.push(await makeUser(db));
  const results = await Promise.allSettled(users.map((u) => order(u, [{ productId: pid, opened: 1, unopened: 1 }])));
  assert.equal(results.filter((r) => r.status === 'fulfilled').length, 3);
  assert.equal(await stockOf(pid), 1);
});

test('여러 상품 중 하나라도 재고 부족이면 전체 롤백', async () => {
  const a = await makeProduct(db, { stock: 10 });
  const b = await makeProduct(db, { stock: 1 });
  const u = await makeUser(db);
  await assert.rejects(order(u, [{ productId: a, opened: 2 }, { productId: b, unopened: 2 }]), (e) => e.code === 'OUT_OF_STOCK');
  assert.equal(await stockOf(a), 10);
  assert.equal(await stockOf(b), 1);
});

test('개봉/미개봉 수량이 주문 상품에 저장되고, 재고는 합계로 차감', async () => {
  const pid = await makeProduct(db, { stock: 10, price: 37000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 1, unopened: 2 }]);
  const it = await db.one('SELECT * FROM order_items WHERE order_id = ?', [o.id]);
  assert.equal(it.qty, 3);
  assert.equal(it.qty_opened, 1);
  assert.equal(it.qty_unopened, 2);
  assert.equal(o.itemsAmount, 111000);
  assert.equal(o.shippingFee, 0); // 80,000원 이상 무료
  assert.equal(await stockOf(pid), 7);
});

test('개봉 옵션 상품은 선택 없이 주문 불가', async () => {
  const pid = await makeProduct(db, { stock: 10 });
  const u = await makeUser(db);
  await assert.rejects(order(u, [{ productId: pid, qty: 2 }]), (e) => e.code === 'NEED_OPTION');
  assert.equal(await stockOf(pid), 10);
});

test('바로배송 8만원 미만은 배송비 4,000원', async () => {
  const pid = await makeProduct(db, { stock: 10, price: 30000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 1 }]);
  assert.equal(o.shippingFee, 4000);
  assert.equal(o.total, 34000);
});

test('방송 종료 중에는 주문 불가', async () => {
  const pid = await makeProduct(db);
  const u = await makeUser(db);
  await settings.set({ live_on: false });
  await assert.rejects(order(u, [{ productId: pid, opened: 1 }]), (e) => e.code === 'NOT_LIVE');
  await settings.set({ live_on: true });
});

test('주문 취소 시 재고 복구', async () => {
  const pid = await makeProduct(db, { stock: 3 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 2 }]);
  assert.equal(await stockOf(pid), 1);
  await orders.cancelOrder(o.id, { by: 'user', reason: 'user', userId: u });
  assert.equal(await stockOf(pid), 3);
  // 두 번 취소해도 재고가 두 번 늘지 않음
  await orders.cancelOrder(o.id, { by: 'admin' });
  assert.equal(await stockOf(pid), 3);
});

test('포인트: 계좌이체 입금 확인 시 적립, 카드결제는 적립 없음, 취소 시 회수', async () => {
  await settings.set({ point_earn_rate: '5' });
  const pid = await makeProduct(db, { stock: 10, price: 50000 });
  const u = await makeUser(db);

  const bank = await order(u, [{ productId: pid, opened: 1 }]);
  assert.equal(await balanceOf(u), 0, '입금 확인 전에는 적립 없음');
  const confirmed = await orders.confirmDeposit(bank.id);
  assert.equal(confirmed.point_earned, 2500);
  assert.equal(await balanceOf(u), 2500);
  await assert.rejects(orders.confirmDeposit(bank.id), (e) => e.code === 'NOT_PENDING'); // 중복 확인 방지
  assert.equal(await balanceOf(u), 2500);

  const card = await order(u, [{ productId: pid, unopened: 1 }], { paymentMethod: 'card' });
  const r = await orders.applyCardPayment(pgPaid(card.paymentId, card.total));
  assert.equal(r.result, 'paid');
  const co = await db.one('SELECT * FROM orders WHERE id = ?', [card.id]);
  assert.equal(co.status, 'paid');
  assert.equal(co.point_earned, 0);
  assert.equal(await balanceOf(u), 2500, '카드결제는 적립 없음');

  await orders.cancelOrder(bank.id, { by: 'admin' });
  assert.equal(await balanceOf(u), 0, '취소 시 적립 회수');
  const ledger = await db.query('SELECT type, amount FROM point_ledger WHERE user_id = ? ORDER BY id', [u]);
  assert.deepEqual(ledger.map((l) => [l.type, l.amount]), [['earn', 2500], ['revoke', -2500]]);
  await settings.set({ point_earn_rate: '' });
});

test('적립률 미설정이면 입금 확인해도 적립 없음', async () => {
  const pid = await makeProduct(db, { stock: 10, price: 50000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 1 }]);
  await orders.confirmDeposit(o.id);
  assert.equal(await balanceOf(u), 0);
});

test('포인트 사용: 차감 후 취소하면 환원', async () => {
  await settings.set({ point_use_enabled: true, point_use_unit: 100, point_max_ratio: 100, point_min_balance: 0 });
  const pid = await makeProduct(db, { stock: 10, price: 30000 });
  const u = await makeUser(db, { points: 5000 });
  const o = await order(u, [{ productId: pid, opened: 1 }], { pointUse: 3000 });
  assert.equal(o.pointUsed, 3000);
  assert.equal(o.total, 30000 + 4000 - 3000);
  assert.equal(await balanceOf(u), 2000);
  await assert.rejects(order(u, [{ productId: pid, opened: 1 }], { pointUse: 1050 }), (e) => e.code === 'POINT_UNIT');
  await orders.cancelOrder(o.id, { by: 'admin' });
  assert.equal(await balanceOf(u), 5000);
  await settings.set({ point_use_enabled: false });
});

test('카드 결제 금액 위변조는 결제완료 처리하지 않음', async () => {
  const pid = await makeProduct(db, { stock: 10, price: 20000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 1 }], { paymentMethod: 'card' });
  const r = await orders.applyCardPayment(pgPaid(o.paymentId, 100));
  assert.equal(r.result, 'refund');
  assert.equal((await db.one('SELECT status FROM orders WHERE id = ?', [o.id])).status, 'pending');
});

test('같은 카드 결제가 여러 번 들어와도(웹훅 중복) 한 번만 처리', async () => {
  const pid = await makeProduct(db, { stock: 10, price: 20000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 1 }], { paymentMethod: 'card' });
  const pg = pgPaid(o.paymentId, o.total);
  const rs = await Promise.all([orders.applyCardPayment(pg), orders.applyCardPayment(pg), orders.applyCardPayment(pg)]);
  assert.equal(rs.filter((r) => r.result === 'paid').length, 1);
  assert.equal(rs.filter((r) => r.result === 'already').length, 2);
});

test('카드 미결제 만료 시 자동 취소·재고 복구, 직후 결제가 들어오면 재고 재확보', async () => {
  const pid = await makeProduct(db, { stock: 2, price: 15000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 2 }], { paymentMethod: 'card' });
  assert.equal(await stockOf(pid), 0);
  await db.query('UPDATE orders SET reserve_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [o.id]);
  const n = await orders.expireStale();
  assert.ok(n >= 1);
  const row = await db.one('SELECT status, cancel_reason FROM orders WHERE id = ?', [o.id]);
  assert.equal(row.status, 'cancelled');
  assert.equal(row.cancel_reason, 'timeout');
  assert.equal(await stockOf(pid), 2);

  const r = await orders.applyCardPayment(pgPaid(o.paymentId, o.total));
  assert.equal(r.result, 'paid');
  assert.equal((await db.one('SELECT status FROM orders WHERE id = ?', [o.id])).status, 'paid');
  assert.equal(await stockOf(pid), 0);
});

test('만료 후 다른 사람이 재고를 가져갔으면 늦은 카드 결제는 환불 대상', async () => {
  const pid = await makeProduct(db, { stock: 1, price: 15000 });
  const u1 = await makeUser(db);
  const u2 = await makeUser(db);
  const o = await order(u1, [{ productId: pid, opened: 1 }], { paymentMethod: 'card' });
  await db.query('UPDATE orders SET reserve_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [o.id]);
  await orders.expireStale();
  await order(u2, [{ productId: pid, opened: 1 }]);
  const r = await orders.applyCardPayment(pgPaid(o.paymentId, o.total));
  assert.equal(r.result, 'refund');
  assert.equal(r.reason, '재고 소진');
  assert.equal(await stockOf(pid), 0);
  assert.equal((await db.one('SELECT status FROM orders WHERE id = ?', [o.id])).status, 'cancelled');
});

test('계좌이체 입금 기한 초과 시 자동 취소 (0이면 기한 없음)', async () => {
  const pid = await makeProduct(db, { stock: 5 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 1 }]);
  await db.query('UPDATE orders SET reserve_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [o.id]);
  await orders.expireStale();
  assert.equal((await db.one('SELECT cancel_reason FROM orders WHERE id = ?', [o.id])).cancel_reason, 'auto_cancel');
  assert.equal(await stockOf(pid), 5);

  await settings.set({ bank_auto_cancel_hours: 0 });
  const o2 = await order(u, [{ productId: pid, opened: 1 }]);
  assert.equal((await db.one('SELECT reserve_expires_at FROM orders WHERE id = ?', [o2.id])).reserve_expires_at, null);
  await settings.set({ bank_auto_cancel_hours: 24 });
});

test('킵: 결제완료된 주문만 보관함에 누적, 누적 금액으로 출고 배송비 결정', async () => {
  const p30 = await makeProduct(db, { stock: 10, price: 30000 });
  const p40 = await makeProduct(db, { stock: 10, price: 40000 });
  const p20 = await makeProduct(db, { stock: 10, price: 20000 });
  const u = await makeUser(db);
  const keep = (pid) => order(u, [{ productId: pid, opened: 1 }], { deliveryType: 'keep' });

  const k1 = await keep(p30);
  assert.equal(k1.shippingFee, 0, '킵 주문은 배송비 없음');
  let box = await shipments.keepBox(u);
  assert.equal(box.orders.length, 0, '입금 전 주문은 보관함에 없음');

  await orders.confirmDeposit(k1.id);
  const k2 = await keep(p40);
  await orders.confirmDeposit(k2.id);
  box = await shipments.keepBox(u);
  assert.equal(box.keptAmount, 70000);
  assert.equal(box.fee, 4000);
  assert.equal(box.remaining, 10000);

  const k3 = await keep(p20);
  await orders.confirmDeposit(k3.id);
  box = await shipments.keepBox(u);
  assert.equal(box.keptAmount, 90000);
  assert.equal(box.fee, 0);

  const sr = await shipments.requestRelease(u, { recipient });
  assert.equal(sr.status, 'requested');
  assert.equal(sr.fee, 0);
  assert.equal(sr.orderCount, 3);
  const st = await db.query('SELECT DISTINCT status FROM orders WHERE shipment_request_id = ?', [sr.id]);
  assert.deepEqual(st.map((r) => r.status), ['preparing']);
  assert.equal((await shipments.keepBox(u)).orders.length, 0);

  await shipments.setStatus(sr.id, 'shipped', { courier: 'CJ대한통운', trackingNo: '123' });
  const shipped = await db.query('SELECT status, tracking_no FROM orders WHERE shipment_request_id = ?', [sr.id]);
  assert.ok(shipped.every((o) => o.status === 'shipped' && o.tracking_no === '123'));
});

test('킵: 8만원 미만 출고는 배송비 4,000원 결제 후 요청 완료, 취소하면 보관함으로 복귀', async () => {
  const pid = await makeProduct(db, { stock: 10, price: 25000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, unopened: 2 }], { deliveryType: 'keep' });
  await orders.confirmDeposit(o.id);

  await assert.rejects(shipments.requestRelease(u, { recipient }), (e) => e.code === 'NEED_PAYMENT');
  const sr = await shipments.requestRelease(u, { recipient, paymentMethod: 'bank', depositorName: '받는분' });
  assert.equal(sr.status, 'pending_payment');
  assert.equal(sr.fee, 4000);
  assert.equal((await db.one('SELECT status FROM orders WHERE id = ?', [o.id])).status, 'kept', '배송비 결제 전에는 아직 보관');
  await assert.rejects(shipments.requestRelease(u, { recipient, paymentMethod: 'bank' }), (e) => e.code === 'HAS_PENDING');

  await shipments.cancelRequest(sr.id, { by: 'user', userId: u, reason: 'user' });
  assert.equal((await shipments.keepBox(u)).orders.length, 1, '취소 후 다시 보관함');

  const sr2 = await shipments.requestRelease(u, { recipient, paymentMethod: 'bank' });
  await shipments.confirmDeposit(sr2.id);
  assert.equal((await db.one('SELECT status FROM shipment_requests WHERE id = ?', [sr2.id])).status, 'requested');
  assert.equal((await db.one('SELECT status FROM orders WHERE id = ?', [o.id])).status, 'preparing');
  // 배송비 입금은 포인트 적립 대상 아님
  assert.equal(await balanceOf(u), 0);
});

test('킵: 배송비 카드결제 완료 시 출고 요청 완료', async () => {
  const pid = await makeProduct(db, { stock: 10, price: 10000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 1 }], { deliveryType: 'keep', paymentMethod: 'card' });
  await orders.applyCardPayment(pgPaid(o.paymentId, o.total));
  assert.equal((await db.one('SELECT status FROM orders WHERE id = ?', [o.id])).status, 'kept');
  const sr = await shipments.requestRelease(u, { recipient, paymentMethod: 'card' }, { cardEnabled: true });
  assert.ok(sr.paymentId);
  const r = await orders.applyCardPayment(pgPaid(sr.paymentId, 4000));
  assert.equal(r.result, 'paid');
  assert.equal((await db.one('SELECT status FROM shipment_requests WHERE id = ?', [sr.id])).status, 'requested');
  assert.equal((await db.one('SELECT status FROM orders WHERE id = ?', [o.id])).status, 'preparing');
});

test('출고 요청에 묶인 주문은 개별 취소 불가', async () => {
  const pid = await makeProduct(db, { stock: 10, price: 90000 });
  const u = await makeUser(db);
  const o = await order(u, [{ productId: pid, opened: 1 }], { deliveryType: 'keep' });
  await orders.confirmDeposit(o.id);
  await shipments.requestRelease(u, { recipient });
  await assert.rejects(orders.cancelOrder(o.id, { by: 'admin' }), (e) => e.code === 'IN_SHIPMENT');
});
