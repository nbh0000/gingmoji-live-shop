// 관리자 설정값. DB settings 테이블(key/value)에 저장하고 메모리에 캐시합니다.
// 새 설정을 추가할 때는 DEFAULTS 에만 넣으면 됩니다(DB에 없으면 기본값 사용).
const db = require('./db');

const DEFAULTS = {
  // ===== 방송 =====
  live_on: false,
  // PG 심사 기간용: 방송과 무관하게 가격만 노출 (주문은 여전히 방송 중에만)
  always_show_price: false,

  // ===== 입금 계좌 =====
  bank_name: '케이뱅크',
  bank_account: '100301443318',
  bank_holder: '김민정(김모지)',

  // ===== 결제 대기 / 재고 선점 =====
  card_hold_minutes: 10, // 카드 미결제 시 재고 자동 해제(분)
  // TODO(정책 확인): 계좌이체 자동 취소 시간. 0이면 자동 취소하지 않음
  bank_auto_cancel_hours: 24,

  // ===== 배송 =====
  free_shipping_threshold: 80000,
  shipping_fee: 4000,

  // ===== 포인트 =====
  // TODO(정책 확인): 적립률(%). 비어 있으면 적립하지 않음. 계좌이체 결제 건에만 적립
  point_earn_rate: '',
  // TODO(정책 확인): 포인트 사용 허용 여부와 조건
  point_use_enabled: false,
  point_min_balance: 0, // 보유 포인트가 이 값 이상일 때만 사용 가능
  point_use_unit: 1, // 사용 단위(예: 100이면 100P 단위로만 사용)
  point_max_ratio: 100, // 상품금액 대비 최대 사용 비율(%)

  // ===== 사업자 정보 (PG 심사용, 하단 노출) =====
  biz_name: '깅모지',
  biz_owner: '김민정',
  biz_reg_no: '326-23-02307',
  biz_mail_order_no: '제0000-서울서초-0000호 (신고 후 입력)',
  biz_address: '서울특별시 서초구 방배천로2길 21, 4층 495호(방배동)',
  biz_phone: '010-0000-0000',
  biz_email: 'gingmoji@example.com',
  biz_privacy_officer: '김민정',

  // ===== 안내 문구 =====
  notice_text: '',
};

const TYPES = {};
for (const [k, v] of Object.entries(DEFAULTS)) TYPES[k] = typeof v;

let cache = { ...DEFAULTS };
let loadedAt = 0;

function decode(key, raw) {
  const type = TYPES[key];
  if (raw === null || raw === undefined) return DEFAULTS[key];
  if (type === 'boolean') return raw === '1' || raw === 'true';
  if (type === 'number') {
    const n = Number(raw);
    return Number.isFinite(n) ? n : DEFAULTS[key];
  }
  return String(raw);
}

function encode(key, value) {
  const type = TYPES[key];
  if (type === 'boolean') return value === true || value === '1' || value === 'true' || value === 'on' ? '1' : '0';
  if (type === 'number') {
    const n = Number(value);
    return String(Number.isFinite(n) ? n : DEFAULTS[key]);
  }
  return value === null || value === undefined ? '' : String(value);
}

async function load() {
  const rows = await db.query('SELECT `key`, `value` FROM settings');
  const next = { ...DEFAULTS };
  for (const r of rows) {
    if (r.key in DEFAULTS) next[r.key] = decode(r.key, r.value);
  }
  cache = next;
  loadedAt = Date.now();
  return cache;
}

function all() {
  return cache;
}

function get(key) {
  return cache[key];
}

// 다른 프로세스에서 바뀐 값을 반영하기 위해 30초마다 재조회 (단일 프로세스면 사실상 불필요)
async function fresh() {
  if (Date.now() - loadedAt > 30000) await load();
  return cache;
}

async function set(values) {
  const entries = Object.entries(values).filter(([k]) => k in DEFAULTS);
  for (const [k, v] of entries) {
    await db.query(
      'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
      [k, encode(k, v)]
    );
  }
  await load();
}

// 적립률: 빈 값이면 null (적립 안 함)
function pointEarnRate() {
  const raw = cache.point_earn_rate;
  if (raw === '' || raw === null || raw === undefined) return null;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

module.exports = { DEFAULTS, TYPES, load, all, get, set, fresh, pointEarnRate };
