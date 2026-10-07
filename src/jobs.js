// 주기 작업: 결제 대기 주문/출고요청 만료 처리 (재고 선점 해제)
// 카페24 MariaDB 는 EVENT/트리거 권한이 없을 수 있어 앱에서 1분마다 처리합니다.
const orders = require('./services/orders');
const shipments = require('./services/shipments');
const live = require('./services/live');

let running = false;

async function tick() {
  if (running) return;
  running = true;
  try {
    const a = await orders.expireStale();
    const b = await shipments.expireStale();
    if (a || b) {
      console.log(`[jobs] 만료 처리: 주문 ${a}건, 출고요청 ${b}건`);
      live.invalidate();
    }
  } catch (e) {
    console.error('[jobs]', e.message);
  } finally {
    running = false;
  }
}

function start() {
  setTimeout(tick, 5000).unref();
  setInterval(tick, 60 * 1000).unref();
}

module.exports = { start, tick };
