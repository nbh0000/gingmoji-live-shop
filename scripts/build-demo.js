// GitHub Pages 용 정적 데모 생성
// 사용: 로컬 서버를 켜 둔 상태(npm start)에서
//   node scripts/build-demo.js
// 환경변수: DEMO_BASE(기본 http://localhost:8001), DEMO_ADMIN_ID/PW(기본 .env 의 ADMIN_ID/PASSWORD),
//          DEMO_USER_ID/PW(데모용 고객 계정, 기본 live_fan1 / pass1234)
// 결과: docs/ (GitHub Pages: main 브랜치 /docs 폴더로 설정)
const fs = require('fs');
const path = require('path');
const config = require('../src/config');
const mapPath = require('./demo/demo-map');

const BASE = process.env.DEMO_BASE || 'http://localhost:8001';
const OUT = path.join(__dirname, '..', 'docs');
const ADMIN = { id: process.env.DEMO_ADMIN_ID || config.admin.id, password: process.env.DEMO_ADMIN_PW || config.admin.password };
const USER = { login_id: process.env.DEMO_USER_ID || 'live_fan1', password: process.env.DEMO_USER_PW || 'pass1234' };

function client() {
  const jar = {};
  let csrf = '';
  return async function req(method, p, { form, raw } = {}) {
    const headers = { Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ') };
    let body;
    if (form) {
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      body = new URLSearchParams({ _csrf: csrf, ...form }).toString();
    }
    const res = await fetch(BASE + p, { method, headers, body, redirect: 'manual' });
    for (const c of res.headers.getSetCookie()) {
      const [kv] = c.split(';');
      const i = kv.indexOf('=');
      jar[kv.slice(0, i)] = kv.slice(i + 1);
    }
    if (raw) return res;
    const text = await res.text();
    const m = text.match(/name="csrf" content="([^"]+)"/) || text.match(/name="_csrf" value="([^"]+)"/);
    if (m) csrf = m[1];
    return { status: res.status, text };
  };
}

function rewrite(html) {
  return html
    .replace(/(href|src)="(\/[^"]*)"/g, (m, attr, url) => `${attr}="${mapPath(url).replace(/"/g, '&quot;')}"`)
    .replace(/action="(\/[^"]*)"/g, (m, url) => `action="#" data-orig-action="${url}"`)
    .replace(/formaction="[^"]*"/g, '')
    .replace(/\/img\/(\d+)/g, 'img/$1.jpg')
    .replace('</head>', '<script src="static/js/demo-data.js"></script>\n<script src="static/js/demo-map.js"></script>\n<script src="static/js/demo.js"></script>\n</head>');
}

function copyDir(src, dst) {
  fs.mkdirSync(dst, { recursive: true });
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    const s = path.join(src, e.name);
    const d = path.join(dst, e.name);
    if (e.isDirectory()) copyDir(s, d);
    else fs.copyFileSync(s, d);
  }
}

(async () => {
  fs.rmSync(OUT, { recursive: true, force: true });
  fs.mkdirSync(path.join(OUT, 'img'), { recursive: true });
  copyDir(path.join(__dirname, '..', 'src', 'public'), path.join(OUT, 'static'));
  for (const f of ['demo.js', 'demo-map.js']) fs.copyFileSync(path.join(__dirname, 'demo', f), path.join(OUT, 'static', 'js', f));
  fs.writeFileSync(path.join(OUT, '.nojekyll'), '');

  const guest = client();
  const user = client();
  const admin = client();
  await user('GET', '/login');
  let r = await user('POST', '/login', { form: USER });
  if (r.status !== 302) throw new Error('데모 고객 로그인 실패');
  await admin('GET', '/admin/login');
  r = await admin('POST', '/admin/login', { form: ADMIN });
  if (r.status !== 302) throw new Error('관리자 로그인 실패');

  // 데모 데이터 (상품/재고, 대시보드 통계)
  const live = JSON.parse((await user('GET', '/api/live')).text);
  const products = [];
  for (const p of live.products) {
    const j = JSON.parse((await user('GET', `/api/products/${p.id}`)).text);
    if (j.ok) products.push(j.product);
  }
  const stats = JSON.parse((await admin('GET', '/admin/api/stats')).text);
  const s = require('../src/settings');
  await s.load();
  const demoData = { products, stats, freeShip: s.get('free_shipping_threshold'), shipFee: s.get('shipping_fee') };
  fs.writeFileSync(path.join(OUT, 'static', 'js', 'demo-data.js'), `window.DEMO = ${JSON.stringify(demoData)};\n`);

  // 화면에 쓰인 대표 id 찾기
  const db = require('../src/db');
  const one = async (sql) => (await db.query(sql))[0] || {};
  const pendingOrder = await one("SELECT o.order_no, o.id FROM orders o JOIN users u ON u.id = o.user_id WHERE u.login_id = '" + USER.login_id + "' AND o.status = 'pending' ORDER BY o.id DESC LIMIT 1");
  const anyOrder = pendingOrder.id ? pendingOrder : await one('SELECT order_no, id FROM orders ORDER BY id DESC LIMIT 1');
  const ship = await one('SELECT request_no, id FROM shipment_requests ORDER BY id DESC LIMIT 1');
  const prod = await one('SELECT id FROM products WHERE deleted_at IS NULL ORDER BY sort_order, id LIMIT 1');
  const usr = await one("SELECT id FROM users WHERE login_id = '" + USER.login_id + "'");
  const images = await db.query('SELECT id FROM product_images');

  const pages = [
    [guest, '/login', 'login.html'],
    [guest, '/signup', 'signup.html'],
    [guest, '/page/terms', 'page-terms.html'],
    [guest, '/page/privacy', 'page-privacy.html'],
    [guest, '/page/refund', 'page-refund.html'],
    [user, '/', 'index.html'],
    [user, '/checkout', 'checkout.html'],
    [user, `/orders/${anyOrder.order_no}?placed=1`, 'order.html'],
    [user, '/my', 'my.html'],
    [user, '/my/orders', 'my-orders.html'],
    [user, '/my/keep', 'my-keep.html'],
    [user, '/my/points', 'my-points.html'],
    [user, '/my/profile', 'my-profile.html'],
    [user, `/p/${prod.id}`, 'product.html'],
    [guest, '/admin/login', 'admin-login.html'],
    [admin, '/admin', 'admin.html'],
    [admin, '/admin/orders', 'admin-orders.html'],
    [admin, `/admin/orders/${anyOrder.id}`, 'admin-order.html'],
    [admin, '/admin/keep?status=all', 'admin-keep.html'],
    [admin, '/admin/keep?tab=members', 'admin-keep-members.html'],
    [admin, `/admin/keep/user/${usr.id}`, 'admin-keep-user.html'],
    [admin, '/admin/products', 'admin-products.html'],
    [admin, '/admin/products/new', 'admin-product-new.html'],
    [admin, `/admin/products/${prod.id}`, 'admin-product.html'],
    [admin, '/admin/users', 'admin-users.html'],
    [admin, `/admin/users/${usr.id}`, 'admin-user.html'],
    [admin, '/admin/settings', 'admin-settings.html'],
    [admin, '/admin/pages', 'admin-pages.html'],
    [admin, '/admin/pages/terms', 'admin-page.html'],
  ];
  if (ship.id) {
    pages.push([user, `/my/shipments/${ship.request_no}`, 'shipment.html']);
    pages.push([admin, `/admin/shipments/${ship.id}`, 'admin-shipment.html']);
  }
  for (const [c, p, file] of pages) {
    const res = await c('GET', p);
    if (res.status !== 200) throw new Error(`${p} → ${res.status}`);
    fs.writeFileSync(path.join(OUT, file), rewrite(res.text));
    console.log(`✔ ${file}`);
  }
  for (const img of images) {
    const res = await guest('GET', `/img/${img.id}`, { raw: true });
    fs.writeFileSync(path.join(OUT, 'img', `${img.id}.jpg`), Buffer.from(await res.arrayBuffer()));
  }
  console.log(`이미지 ${images.length}개, 완료 → docs/`);
  await db.pool.end();
})().catch((e) => {
  console.error('데모 생성 실패:', e.message);
  process.exit(1);
});
