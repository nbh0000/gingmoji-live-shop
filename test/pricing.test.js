// 금액 계산 순수 함수 테스트 (DB 불필요)
process.env.NODE_ENV = 'test';
const test = require('node:test');
const assert = require('node:assert/strict');
const p = require('../src/services/pricing');

const S = {
  free_shipping_threshold: 80000,
  shipping_fee: 4000,
  point_use_enabled: true,
  point_min_balance: 0,
  point_use_unit: 1,
  point_max_ratio: 100,
};

test('바로배송: 80,000원 이상 무료, 미만 4,000원', () => {
  assert.equal(p.orderShippingFee(80000, 'direct', S), 0);
  assert.equal(p.orderShippingFee(79999, 'direct', S), 4000);
  assert.equal(p.orderShippingFee(0, 'direct', S), 4000);
});

test('킵: 주문 시 배송비 없음', () => {
  assert.equal(p.orderShippingFee(10000, 'keep', S), 0);
});

test('킵 출고: 누적 금액 기준', () => {
  assert.equal(p.keepReleaseFee(30000 + 40000, S), 4000);
  assert.equal(p.keepReleaseFee(30000 + 40000 + 10000, S), 0);
  assert.equal(p.remainingForFree(70000, S), 10000);
  assert.equal(p.remainingForFree(90000, S), 0);
});

test('배송비 기준은 설정값을 따름', () => {
  const s2 = { ...S, free_shipping_threshold: 50000, shipping_fee: 3500 };
  assert.equal(p.orderShippingFee(49000, 'direct', s2), 3500);
  assert.equal(p.keepReleaseFee(50000, s2), 0);
});

test('개봉/미개봉: 합계가 수량, 최소 1개 선택 필수', () => {
  const prod = { name: 'A', use_open_option: 1 };
  assert.deepEqual(p.normalizeLine({ opened: 1, unopened: 1 }, prod), { qty: 2, opened: 1, unopened: 1 });
  assert.deepEqual(p.normalizeLine({ opened: 0, unopened: 3 }, prod), { qty: 3, opened: 0, unopened: 3 });
  assert.throws(() => p.normalizeLine({ opened: 0, unopened: 0, qty: 2 }, prod), /수량을 선택/);
  assert.throws(() => p.normalizeLine({ opened: -1, unopened: 2 }, prod), /올바르지/);
  assert.throws(() => p.normalizeLine({ opened: 1.5, unopened: 0 }, prod), /올바르지/);
});

test('옵션 미사용 상품은 qty 만 사용', () => {
  const prod = { name: 'B', use_open_option: 0 };
  assert.deepEqual(p.normalizeLine({ qty: 2, opened: 5 }, prod), { qty: 2, opened: 0, unopened: 0 });
  assert.throws(() => p.normalizeLine({ qty: 0 }, prod));
});

test('같은 상품 여러 줄은 합쳐짐', () => {
  const m = p.mergeLines([
    { productId: 1, opened: 1 },
    { productId: 1, unopened: 2 },
    { productId: 2, qty: 1 },
    { productId: 'x', qty: 1 },
  ]);
  assert.equal(m.length, 2);
  assert.deepEqual(m.find((l) => l.productId === 1), { productId: 1, opened: 1, unopened: 2, qty: 0 });
});

test('포인트 적립: 계좌이체만, 카드는 0', () => {
  assert.equal(p.earnPoints({ paymentMethod: 'bank', itemsAmount: 50000, pointUsed: 0 }, 5), 2500);
  assert.equal(p.earnPoints({ paymentMethod: 'card', itemsAmount: 50000, pointUsed: 0 }, 5), 0);
  // 기준 = 상품금액 - 사용 포인트
  assert.equal(p.earnPoints({ paymentMethod: 'bank', itemsAmount: 50000, pointUsed: 10000 }, 5), 2000);
  // 적립률 미설정(null)이면 적립 안 함
  assert.equal(p.earnPoints({ paymentMethod: 'bank', itemsAmount: 50000, pointUsed: 0 }, null), 0);
  // 소수점 버림
  assert.equal(p.earnPoints({ paymentMethod: 'bank', itemsAmount: 333, pointUsed: 0 }, 1.5), 4);
});

test('포인트 사용 한도', () => {
  const ctx = { balance: 5000, itemsAmount: 30000, shippingFee: 4000 };
  assert.equal(p.maxUsablePoints(ctx, S), 5000);
  assert.equal(p.maxUsablePoints(ctx, { ...S, point_use_enabled: false }), 0);
  assert.equal(p.maxUsablePoints(ctx, { ...S, point_min_balance: 10000 }), 0);
  assert.equal(p.maxUsablePoints(ctx, { ...S, point_max_ratio: 10 }), 3000);
  assert.equal(p.maxUsablePoints({ ...ctx, balance: 5550 }, { ...S, point_use_unit: 100 }), 5500);
  assert.equal(p.validatePointUse(0, ctx, S), 0);
  assert.throws(() => p.validatePointUse(6000, ctx, S), /최대/);
  assert.throws(() => p.validatePointUse(150, ctx, { ...S, point_use_unit: 100 }), /단위/);
});

test('주문 금액 계산', () => {
  const lines = [{ unitPrice: 37000, qty: 1 }, { unitPrice: 22000, qty: 2 }];
  assert.deepEqual(p.computeOrder({ lines, deliveryType: 'direct' }, S), { itemsAmount: 81000, shippingFee: 0, pointUsed: 0, total: 81000 });
  assert.deepEqual(p.computeOrder({ lines: [lines[0]], deliveryType: 'direct', pointUsed: 1000 }, S), { itemsAmount: 37000, shippingFee: 4000, pointUsed: 1000, total: 40000 });
  assert.deepEqual(p.computeOrder({ lines: [lines[0]], deliveryType: 'keep' }, S), { itemsAmount: 37000, shippingFee: 0, pointUsed: 0, total: 37000 });
});
