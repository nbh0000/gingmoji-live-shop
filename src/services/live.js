// 고객 화면 폴링용 스냅샷 (방송 상태 + 재고). 2초 메모리 캐시로 동시접속이 늘어도 DB 조회는 2초에 1번.
const db = require('../db');
const settings = require('../settings');

let cache = null;
let cachedAt = 0;
let pending = null;
const TTL = 2000;

function priceVisible(s) {
  return Boolean(s.live_on || s.always_show_price);
}

async function build() {
  const s = await settings.fresh();
  const rows = await db.query(
    'SELECT id, price, stock, is_soldout FROM products WHERE deleted_at IS NULL AND is_visible = 1 ORDER BY sort_order, id'
  );
  const showPrice = priceVisible(s);
  return {
    live: Boolean(s.live_on),
    showPrice,
    notice: s.notice_text || '',
    eventNotice: s.event_notice_text || '',
    showStock: Boolean(s.show_stock),
    products: rows.map((p) => ({
      id: p.id,
      stock: Math.max(0, p.stock),
      soldout: Boolean(p.is_soldout) || p.stock <= 0,
      price: showPrice ? p.price : null,
    })),
    at: Date.now(),
  };
}

async function snapshot() {
  if (cache && Date.now() - cachedAt < TTL) return cache;
  if (!pending) {
    pending = build()
      .then((v) => {
        cache = v;
        cachedAt = Date.now();
        return v;
      })
      .finally(() => {
        pending = null;
      });
  }
  return pending;
}

function invalidate() {
  cachedAt = 0;
}

module.exports = { snapshot, invalidate, priceVisible };
