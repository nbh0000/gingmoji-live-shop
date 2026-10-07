/* 정적 데모 런타임: 서버 API를 가짜 응답으로 대체하고 링크를 데모 파일로 바꿉니다.
   실제 주문·결제·저장은 일어나지 않습니다. */
(function () {
  'use strict';
  var D = window.DEMO || { products: [], stats: null };
  var map = window.demoMapPath;
  var realFetch = window.fetch.bind(window);

  function json(obj, status) {
    return Promise.resolve(new Response(JSON.stringify(obj), { status: status || 200, headers: { 'Content-Type': 'application/json' } }));
  }
  function product(id) {
    for (var i = 0; i < D.products.length; i++) if (D.products[i].id === Number(id)) return D.products[i];
    return null;
  }

  function quote(body) {
    var lines = (body && body.lines) || [];
    var out = [];
    var items = 0;
    lines.forEach(function (l) {
      var p = product(l.productId);
      if (!p) return;
      var qty = p.useOpenOption ? (l.opened || 0) + (l.unopened || 0) : (l.qty || 0);
      if (qty < 1) return;
      var problem = p.soldout ? '품절' : qty > p.stock ? '재고 ' + p.stock + '개 남음' : null;
      if (!problem) items += p.price * qty;
      out.push({ productId: p.id, name: p.name, imageId: p.images[0] || null, useOpenOption: p.useOpenOption, unitPrice: p.price, stock: p.stock,
        qty: qty, opened: p.useOpenOption ? l.opened || 0 : 0, unopened: p.useOpenOption ? l.unopened || 0 : 0, lineAmount: p.price * qty, problem: problem });
    });
    var delivery = body.deliveryType === 'keep' ? 'keep' : 'direct';
    var fee = delivery === 'keep' ? 0 : items >= D.freeShip ? 0 : D.shipFee;
    return {
      ok: true, live: true, showPrice: true, lines: out,
      hasProblem: out.some(function (l) { return l.problem; }),
      summary: { itemsAmount: items, shippingFee: fee, pointUsed: 0, total: items + fee, deliveryType: delivery, maxPoints: 0, remainingForFree: delivery === 'direct' ? Math.max(0, D.freeShip - items) : 0 },
      pointBalance: 0,
    };
  }

  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : input.url;
    var method = ((init && init.method) || 'GET').toUpperCase();
    var path = url.replace(location.origin, '').split('?')[0];
    var body = {};
    try { body = init && init.body && typeof init.body === 'string' ? JSON.parse(init.body) : {}; } catch (e) { body = {}; }
    var m;
    if (path === '/api/live') return json({ ok: true, live: true, showPrice: true, notice: '', products: D.products.map(function (p) { return { id: p.id, stock: p.stock, soldout: p.soldout, price: p.price }; }) });
    if ((m = path.match(/^\/api\/products\/(\d+)$/))) { var p = product(m[1]); return p ? json({ ok: true, live: true, product: p }) : json({ ok: false, message: '판매하지 않는 상품입니다' }, 404); }
    if (path === '/api/cart/quote') return json(quote(body));
    if (path === '/api/orders' && method === 'POST') { toast('데모: 실제 주문은 생성되지 않아요'); return json({ ok: true, orderNo: 'DEMO', status: 'pending', redirect: 'order.html' }); }
    if (path === '/api/keep/release') return json({ ok: true, status: 'pending_payment', redirect: 'shipment.html' });
    if (/^\/api\/(orders|shipments)\/[^/]+\/(cancel|pay)$/.test(path)) { toast('데모 화면이라 처리되지 않아요'); return json({ ok: false, message: '데모 화면이라 처리되지 않아요' }, 400); }
    if (path === '/admin/api/stats' && D.stats) return json(D.stats);
    if (/^\/admin\//.test(path) && method === 'POST') { toast('데모 화면이라 저장되지 않아요'); return json({ ok: true }); }
    return realFetch(input, init);
  };

  // 데모 안내 토스트
  var t;
  function toast(msg) {
    if (window.GMShop) return window.GMShop.toast(msg);
    var el = document.getElementById('demo-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'demo-toast';
      el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:999;background:#141011;color:#f7ede0;padding:12px 18px;border-radius:999px;font-weight:700;font-size:14px;box-shadow:0 10px 30px -10px rgba(0,0,0,.5);max-width:92vw';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.style.display = 'block';
    clearTimeout(t);
    t = setTimeout(function () { el.style.display = 'none'; }, 2400);
  }

  // JS가 나중에 만든 링크/이미지도 데모 경로로 변환
  function fix(root) {
    var nodes = (root.querySelectorAll ? root.querySelectorAll('a[href^="/"], img[src^="/"]') : []);
    Array.prototype.forEach.call(nodes, function (n) {
      if (n.tagName === 'A') n.setAttribute('href', map(n.getAttribute('href')));
      else n.setAttribute('src', map(n.getAttribute('src')));
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    fix(document);
    new MutationObserver(function (list) {
      list.forEach(function (rec) {
        rec.addedNodes.forEach(function (n) { if (n.nodeType === 1) { fix(n); if (n.matches && n.matches('a[href^="/"], img[src^="/"]')) fix(n.parentNode); } });
        if (rec.type === 'attributes') fix(rec.target.parentNode || document);
      });
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['href', 'src'] });

    // 장바구니 예시 (처음 방문 시 1회)
    try {
      if (!localStorage.getItem('gm_demo_seeded')) {
        localStorage.setItem('gm_demo_seeded', '1');
        var first = D.products.filter(function (p) { return !p.soldout && p.useOpenOption; })[0];
        if (first) localStorage.setItem('gm_cart_v1', JSON.stringify([{ productId: first.id, name: first.name, price: first.price, imageId: first.images[0] || null, option: true, opened: 1, unopened: 1, qty: 0 }]));
      }
    } catch (e) { /* 저장 불가 환경 */ }

    addGuide();
  });

  // 폼 제출: 로그인류는 다음 화면으로 이동, 나머지는 안내만
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var action = f.getAttribute('data-orig-action') || '';
    e.preventDefault();
    if (/^\/(login|signup)/.test(action)) { location.href = 'index.html'; return; }
    if (action === '/admin/login') { location.href = 'admin.html'; return; }
    if (action === '/logout' || action === '/admin/logout') { location.href = action === '/logout' ? 'index.html' : 'admin-login.html'; return; }
    toast('데모 화면이라 저장되지 않아요');
  }, true);

  // 화면 목록 버튼
  function addGuide() {
    var pages = [
      ['고객 화면', [['index.html', '홈 · 상품 목록'], ['index.html?p=' + ((D.products[0] || {}).id || 1), '상품 담기 (바텀시트)'], ['checkout.html', '주문서'], ['order.html?placed=1', '주문 완료 · 계좌 안내'], ['my.html', '마이페이지'], ['my-keep.html', '킵 보관함 · 출고 요청'], ['shipment.html', '출고 요청 상세'], ['my-orders.html', '주문 내역'], ['my-points.html', '포인트'], ['login.html', '로그인'], ['signup.html', '회원가입'], ['product.html', '상품 상세 (PG 심사용)'], ['page-terms.html', '이용약관'], ['page-privacy.html', '개인정보처리방침'], ['page-refund.html', '교환·환불 정책']]],
      ['관리자 화면', [['admin-login.html', '관리자 로그인'], ['admin.html', '대시보드 · 방송 토글'], ['admin-orders.html', '주문 관리'], ['admin-order.html', '주문 상세 · 입금 확인'], ['admin-keep.html', '킵 출고 요청'], ['admin-keep-members.html', '회원별 킵 보관'], ['admin-shipment.html', '출고 처리 · 송장'], ['admin-products.html', '상품·재고'], ['admin-product.html', '상품 수정'], ['admin-users.html', '회원'], ['admin-user.html', '회원 상세 · 포인트'], ['admin-settings.html', '설정'], ['admin-pages.html', '약관·정책 편집']]],
    ];
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'DEMO · 화면 목록';
    btn.style.cssText = 'position:fixed;right:10px;top:calc(70px + env(safe-area-inset-top,0px));z-index:998;border:0;border-radius:999px;padding:7px 12px;background:#f4a6b4;color:#141011;font:800 12px/1 Pretendard,system-ui,sans-serif;box-shadow:0 6px 18px -8px rgba(0,0,0,.5);cursor:pointer';
    var panel = document.createElement('div');
    panel.style.cssText = 'position:fixed;right:10px;top:calc(104px + env(safe-area-inset-top,0px));z-index:998;width:min(300px,calc(100vw - 20px));max-height:70vh;overflow:auto;background:#fff;color:#241b1d;border-radius:20px;padding:14px 16px;box-shadow:0 16px 40px -12px rgba(0,0,0,.45);font:14px/1.5 Pretendard,system-ui,sans-serif;display:none';
    panel.innerHTML = '<div style="font-size:12.5px;color:#8b7b7e;margin-bottom:8px">실제 주문·결제·저장은 되지 않는 미리보기입니다.</div>' + pages.map(function (g) {
      return '<div style="font-weight:800;margin:10px 0 4px">' + g[0] + '</div>' + g[1].map(function (l) {
        return '<a href="' + l[0] + '" style="display:block;padding:5px 0;color:#c95a73;text-decoration:none;font-weight:600">' + l[1] + '</a>';
      }).join('');
    }).join('');
    btn.addEventListener('click', function () { panel.style.display = panel.style.display === 'none' ? 'block' : 'none'; });
    document.body.appendChild(btn);
    document.body.appendChild(panel);
  }
})();
