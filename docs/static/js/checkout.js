/* 주문서: 장바구니 견적 → 주문 생성(재고 선점) → 계좌이체 안내 / 포트원 카드결제 */
(function () {
  'use strict';
  var S = window.GMShop, GM = window.GM;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var placeBtn = $('[data-place-order]');
  var quote = null, busy = false;

  function delivery() { return ($('input[name="delivery"]:checked') || {}).value || 'direct'; }
  function payment() { return ($('input[name="payment"]:checked') || {}).value || ''; }
  function pointUse() { var el = $('[data-point-input]'); return el ? parseInt(el.value.replace(/[^0-9]/g, ''), 10) || 0 : 0; }

  function renderLines(q) {
    var box = $('[data-co-lines]');
    if (!q || !q.lines.length) {
      box.innerHTML = '<div class="empty" style="padding:20px 0"><p>장바구니가 비어 있어요</p><a class="btn small" href="/">상품 보러 가기</a></div>';
      return;
    }
    box.innerHTML = q.lines.map(function (l) {
      var opt = l.useOpenOption
        ? [l.opened ? S.OPT.opened + ' ' + l.opened : '', l.unopened ? S.OPT.unopened + ' ' + l.unopened : ''].filter(Boolean).join(' · ')
        : '수량 ' + l.qty;
      return '<div class="cart-line">' +
        '<div class="thumb">' + (l.imageId ? '<img src="/img/' + l.imageId + '" alt="">' : '<span class="ph">?</span>') + '</div>' +
        '<div class="info"><div class="nm">' + S.esc(l.name) + '</div><div class="opt">' + S.esc(opt) + '</div>' +
        (l.problem ? '<div class="problem">' + S.esc(l.problem) + '</div>' : '') + '</div>' +
        '<div class="amt num">' + (l.lineAmount != null ? S.won(l.lineAmount) : '') + '</div></div>';
    }).join('');
  }

  function renderSummary(q) {
    var sm = q && q.summary;
    $('[data-sum-items]').textContent = sm ? S.won(sm.itemsAmount) : '-';
    $('[data-sum-ship]').textContent = sm ? (sm.deliveryType === 'keep' ? '킵 (출고 시 결정)' : (sm.shippingFee ? S.won(sm.shippingFee) : '무료')) : '-';
    var pr = $('[data-sum-point-row]');
    if (pr) { pr.hidden = !(sm && sm.pointUsed); $('[data-sum-point]').textContent = sm ? '-' + sm.pointUsed.toLocaleString('ko-KR') + 'P' : ''; }
    $('[data-sum-total]').textContent = sm ? S.won(sm.total) : '-';
    var hint = $('[data-free-hint]');
    if (sm && sm.deliveryType === 'direct' && sm.remainingForFree > 0) {
      hint.hidden = false;
      hint.innerHTML = '<b style="color:var(--pink-dd)">' + S.won(sm.remainingForFree) + '</b> 더 담으면 무료배송이에요';
    } else if (sm && sm.deliveryType === 'keep') {
      hint.hidden = false;
      hint.textContent = '킵 상품은 보관함에 모였다가, 출고 요청할 때 누적 금액이 ' + S.won(GM.freeShip) + ' 이상이면 무료로 보내드려요';
    } else hint.hidden = true;
    var ph = $('[data-point-hint]');
    if (ph && q) ph.textContent = sm && sm.maxPoints ? '이번 주문에서 최대 ' + sm.maxPoints.toLocaleString('ko-KR') + 'P 사용할 수 있어요' : '사용 조건을 충족하지 않아요';
    var pi = $('[data-point-input]');
    if (pi && sm && pointUse() > sm.pointUsed) pi.value = sm.pointUsed || '';
    syncButton();
  }

  function syncButton() {
    var sm = quote && quote.summary;
    var ok = quote && quote.lines.length && !quote.hasProblem && GM.live && payment() && !busy;
    placeBtn.disabled = !ok;
    if (!GM.live) placeBtn.textContent = '방송 중에만 주문할 수 있어요';
    else if (quote && quote.hasProblem) placeBtn.textContent = '품절·재고 부족 상품을 정리해 주세요';
    else if (busy) placeBtn.textContent = '처리 중…';
    else placeBtn.textContent = sm ? S.won(sm.total) + (payment() === 'card' ? ' 카드 결제하기' : ' 주문하기') : '결제하기';
    var nl = $('[data-not-live]');
    if (nl) nl.hidden = GM.live;
  }

  function refresh() {
    var cart = S.load();
    if (!cart.length) { quote = { lines: [] }; renderLines(quote); renderSummary(null); return; }
    S.api('POST', '/api/cart/quote', { lines: S.toLines(cart), deliveryType: delivery(), pointUse: pointUse() })
      .then(function (q) { quote = q; GM.live = q.live; renderLines(q); renderSummary(q); })
      .catch(function (e) { S.toast(e.message, 'error'); });
  }

  $$('input[name="delivery"]').forEach(function (r) { r.addEventListener('change', refresh); });
  $$('input[name="payment"]').forEach(function (r) {
    r.addEventListener('change', function () {
      var dep = $('[data-depositor]');
      if (dep) dep.hidden = payment() !== 'bank';
      syncButton();
    });
  });
  var pi = $('[data-point-input]');
  if (pi) {
    var t;
    pi.addEventListener('input', function () { clearTimeout(t); t = setTimeout(refresh, 400); });
    $('[data-point-max]').addEventListener('click', function () {
      if (quote && quote.summary) { pi.value = quote.summary.maxPoints || ''; refresh(); }
    });
  }
  $('[data-edit-addr]').addEventListener('click', function () {
    $('[data-addr-form]').hidden = false;
    $('[data-addr-view]').hidden = true;
    this.hidden = true;
  });
  document.addEventListener('gm:live', function (e) { GM.live = e.detail.live; syncButton(); });
  // 장바구니 시트에서 수량을 바꾸고 닫으면 다시 계산
  window.addEventListener('storage', refresh);
  $$('[data-close-sheet]').forEach(function (b) { b.addEventListener('click', function () { setTimeout(refresh, 50); }); });
  var bd = $('[data-backdrop]');
  if (bd) bd.addEventListener('click', function () { setTimeout(refresh, 50); });

  function val(name) { var el = $('[name="' + name + '"]'); return el ? el.value : ''; }

  placeBtn.addEventListener('click', function () {
    if (busy) return;
    if (!val('address1')) { S.toast('주소를 입력해 주세요', 'error'); return; }
    busy = true; syncButton();
    var body = {
      lines: S.toLines(),
      deliveryType: delivery(),
      paymentMethod: payment(),
      pointUse: pointUse(),
      depositorName: val('depositorName'),
      saveAddress: ($('[name="saveAddress"]') || {}).checked || false,
      recipient: { name: val('name'), phone: val('phone'), zipcode: val('zipcode'), address1: val('address1'), address2: val('address2'), memo: val('memo') },
    };
    S.api('POST', '/api/orders', body).then(function (r) {
      // 주문이 만들어졌으므로 장바구니 비우기 (재고는 선점됨)
      S.save([]);
      if (r.payment) return window.GMPay.requestCard(r.payment, r.redirect);
      location.href = r.redirect + '?placed=1';
    }).catch(function (e) {
      busy = false;
      S.toast(e.message, 'error');
      refresh();
    });
  });

  refresh();
})();
