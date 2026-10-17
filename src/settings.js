// 관리자 설정값. DB settings 테이블(key/value)에 저장하고 메모리에 캐시합니다.
// 새 설정을 추가할 때는 DEFAULTS 에만 넣으면 됩니다(DB에 없으면 기본값 사용).
const db = require('./db');

const DEFAULTS = {
  // ===== 방송 =====
  live_on: false,
  live_started_at: '',
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

  // ===== 고객 화면 =====
  show_stock: false,
  event_notice_text: '5만 원 이상 구매 시 뽑기 1회 제공',

  // ===== 포인트 =====
  // flat은 기존 단일 적립률, tier는 라이브 세션 누적 금액 기준 적립률입니다.
  point_earn_mode: 'tier',
  point_earn_rate: '',
  point_earn_threshold: 300000,
  point_earn_under_rate: 1,
  point_earn_over_rate: 3,
  point_use_enabled: false,
  point_min_balance: 10000,
  point_use_unit: 10000,
  point_max_ratio: 100, // 상품금액 대비 최대 사용 비율(%)
  point_guide_text: '라이브 누적 결제금액 30만 원 미만은 1%, 30만 원 이상이면 해당 라이브의 결제금액 전체에 3%가 자동 적립됩니다. 보유 10,000P 이상부터 결제 시 사용할 수 있습니다.',

  // ===== 현금영수증 =====
  cash_receipt_enabled: true,

  // ===== 사업자 정보 (PG 심사용, 하단 노출) =====
  biz_name: '깅모지',
  biz_owner: '김민정',
  biz_reg_no: '326-23-02307',
  biz_mail_order_no: '제0000-서울서초-0000호 (신고 후 입력)',
  biz_address: '서울특별시 서초구 방배천로2길 21, 4층 495호(방배동)',
  biz_phone: '010-0000-0000',
  biz_email: 'gingmoji@example.com',
  biz_privacy_officer: '김민정',

  // ===== 고객 상담 =====
  kakao_channel_url: 'https://pf.kakao.com/_MszFX',
  kakao_chat_url: 'https://pf.kakao.com/_MszFX/chat',

  // ===== 안내 문구 =====
  notice_text: '',
};

const TYPES = {};
for (const [k, v] of Object.entries(DEFAULTS)) TYPES[k] = typeof v;

let cache = { ...DEFAULTS };
let loadedAt = 0;

function sqlDateTime(date = new Date()) {
  const kst = new Date(date.getTime() + (9 * 60 * 60 * 1000));
  return kst.toISOString().slice(0, 19).replace('T', ' ');
}

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
  const nextValues = { ...values };
  if (Object.prototype.hasOwnProperty.call(values, 'live_on')) {
    const nextLive = values.live_on === true || values.live_on === '1' || values.live_on === 'on' || values.live_on === 'true';
    if (nextLive && !cache.live_on) nextValues.live_started_at = sqlDateTime();
    else if (!nextLive && cache.live_on) nextValues.live_started_at = '';
    else if (nextLive && !String(cache.live_started_at || '').trim()) nextValues.live_started_at = sqlDateTime();
  }
  // 기존 API/테스트에서 point_earn_rate만 보내는 경우에도 이전 동작을 유지합니다.
  if (Object.prototype.hasOwnProperty.call(values, 'point_earn_rate') && !Object.prototype.hasOwnProperty.call(values, 'point_earn_mode')) {
    nextValues.point_earn_mode = values.point_earn_rate === '' ? 'off' : 'flat';
  }
  const entries = Object.entries(nextValues).filter(([k]) => k in DEFAULTS);
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

function pointEarnPolicy() {
  const mode = ['off', 'flat', 'tier'].includes(cache.point_earn_mode) ? cache.point_earn_mode : 'off';
  if (mode === 'flat') return { mode, rate: pointEarnRate() };
  if (mode === 'tier') {
    return {
      mode,
      threshold: Math.max(0, Number(cache.point_earn_threshold) || 0),
      underRate: Math.max(0, Number(cache.point_earn_under_rate) || 0),
      overRate: Math.max(0, Number(cache.point_earn_over_rate) || 0),
    };
  }
  return { mode, rate: null };
}

module.exports = { DEFAULTS, TYPES, load, all, get, set, fresh, pointEarnRate, pointEarnPolicy };
