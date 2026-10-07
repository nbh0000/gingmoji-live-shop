// 금액 계산 순수 함수 모음 (DB 접근 없음, 테스트 대상)

class PolicyError extends Error {
  constructor(message, code) {
    super(message);
    this.name = 'PolicyError';
    this.code = code || 'POLICY';
    this.status = 400;
  }
}

/**
 * 바로배송 배송비: 해당 주문 상품금액이 기준 이상이면 무료
 * 킵: 주문 시 배송비 없음
 */
function orderShippingFee(itemsAmount, deliveryType, s) {
  if (deliveryType === 'keep') return 0;
  return itemsAmount >= s.free_shipping_threshold ? 0 : s.shipping_fee;
}

/**
 * 킵 출고 요청 배송비: 보관 중인 주문 상품금액 누적 합계로 판단
 */
function keepReleaseFee(keptAmount, s) {
  return keptAmount >= s.free_shipping_threshold ? 0 : s.shipping_fee;
}

/** 무료배송까지 남은 금액 (0이면 이미 무료) */
function remainingForFree(amount, s) {
  return Math.max(0, s.free_shipping_threshold - amount);
}

/**
 * 장바구니 한 줄 검증 및 정규화
 * - 개봉/미개봉 옵션 사용 상품: opened + unopened = qty, 둘 다 0 이상, 합계 1 이상
 * - 옵션 미사용 상품: qty 만 사용
 */
function normalizeLine(line, product) {
  const toInt = (v) => {
    const n = Number(v);
    return Number.isInteger(n) && n >= 0 ? n : NaN;
  };
  if (product.use_open_option) {
    const opened = toInt(line.opened || 0);
    const unopened = toInt(line.unopened || 0);
    if (Number.isNaN(opened) || Number.isNaN(unopened)) {
      throw new PolicyError(`${product.name}: 수량이 올바르지 않습니다`, 'BAD_QTY');
    }
    const qty = opened + unopened;
    if (qty < 1) throw new PolicyError(`${product.name}: 라이브 개봉 / 미개봉 발송 수량을 선택해 주세요`, 'NEED_OPTION');
    return { qty, opened, unopened };
  }
  const qty = toInt(line.qty);
  if (Number.isNaN(qty) || qty < 1) throw new PolicyError(`${product.name}: 수량이 올바르지 않습니다`, 'BAD_QTY');
  return { qty, opened: 0, unopened: 0 };
}

/**
 * 같은 상품이 여러 줄로 들어오면 합칩니다(재고 차감은 상품당 1회).
 * lines: [{productId, opened, unopened, qty}]
 */
function mergeLines(lines) {
  const map = new Map();
  for (const l of lines || []) {
    const id = Number(l.productId);
    if (!Number.isInteger(id) || id <= 0) continue;
    const prev = map.get(id) || { productId: id, opened: 0, unopened: 0, qty: 0 };
    prev.opened += Number(l.opened) || 0;
    prev.unopened += Number(l.unopened) || 0;
    prev.qty += Number(l.qty) || 0;
    map.set(id, prev);
  }
  return [...map.values()];
}

/**
 * 포인트 사용 가능 최대치 계산
 * TODO(정책 확인): 사용 조건(최소 보유, 단위, 최대 비율)은 관리자 설정값
 */
function maxUsablePoints({ balance, itemsAmount, shippingFee }, s) {
  if (!s.point_use_enabled) return 0;
  if (balance <= 0 || balance < (s.point_min_balance || 0)) return 0;
  const ratio = Math.min(100, Math.max(0, Number(s.point_max_ratio) || 0));
  let cap = Math.floor((itemsAmount * ratio) / 100);
  cap = Math.min(cap, balance, itemsAmount + shippingFee);
  const unit = Math.max(1, Number(s.point_use_unit) || 1);
  return Math.floor(cap / unit) * unit;
}

function validatePointUse(requested, ctx, s) {
  const want = Number(requested) || 0;
  if (want <= 0) return 0;
  if (!Number.isInteger(want)) throw new PolicyError('포인트는 정수로 입력해 주세요', 'BAD_POINT');
  const max = maxUsablePoints(ctx, s);
  if (want > max) throw new PolicyError(`사용 가능한 포인트는 최대 ${max.toLocaleString('ko-KR')}P 입니다`, 'POINT_LIMIT');
  const unit = Math.max(1, Number(s.point_use_unit) || 1);
  if (want % unit !== 0) throw new PolicyError(`포인트는 ${unit}P 단위로 사용할 수 있습니다`, 'POINT_UNIT');
  return want;
}

/**
 * 적립 포인트: 계좌이체 결제 건에만. 카드는 0.
 * 기준 금액 = 상품금액 - 사용 포인트 (배송비 제외)
 * TODO(정책 확인): 적립 기준 금액 정의
 */
function earnPoints({ paymentMethod, itemsAmount, pointUsed }, rate) {
  if (paymentMethod !== 'bank') return 0;
  if (!rate || rate <= 0) return 0;
  const base = Math.max(0, itemsAmount - (pointUsed || 0));
  return Math.floor((base * rate) / 100);
}

/** 주문 금액 일괄 계산 */
function computeOrder({ lines, deliveryType, pointUsed = 0 }, s) {
  const itemsAmount = lines.reduce((sum, l) => sum + l.unitPrice * l.qty, 0);
  const shippingFee = orderShippingFee(itemsAmount, deliveryType, s);
  const total = itemsAmount + shippingFee - pointUsed;
  return { itemsAmount, shippingFee, pointUsed, total };
}

module.exports = {
  PolicyError,
  orderShippingFee,
  keepReleaseFee,
  remainingForFree,
  normalizeLine,
  mergeLines,
  maxUsablePoints,
  validatePointUse,
  earnPoints,
  computeOrder,
};
