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
  function includeKept() { var el = $('[name="includeKept"]'); return !!(el && el.checked && delivery() === 'direct'); }
  function mergeOption() { var el = $('input[name="mergeOrderId"]:checked'); return el && delivery() === 'direct' && parseInt(el.value, 10) > 0 ? el : null; }
  function mergeOrderId() { var el = mergeOption(); return el ? parseInt(el.value, 10) : 0; }
  var ADDR_KEYS = ['name', 'phone', 'zipcode', 'address1', 'address2'];
  var addrOriginal = {}, addrReadOnly = {};
  ADDR_KEYS.forEach(function (k) {
    var i = $('[name="' + k + '"]');
    addrOriginal[k] = i ? i.value : '';
    addrReadOnly[k] = i ? i.readOnly : false;
  });
  var saveAddress = $('[name="saveAddress"]');
  var saveAddressChoice = saveAddress ? saveAddress.checked : false;
  var mergeWasActive = false;
  function pointUse() { var el = $('[data-point-input]'); return el ? parseInt(el.value.replace(/[^0-9]/g, ''), 10) || 0 : 0; }
  function cashReceipt() {
    var type = ($('input[name="cashReceiptType"]:checked') || {}).value || 'none';
    return { type: type, value: type === 'none' ? '' : val('cashReceiptValue') };
  }

  function syncCashReceipt() {
    var box = $('[data-cash-receipt]');
    var type = ($('input[name="cashReceiptType"]:checked') || {}).value || 'none';
    var value = $('[data-cash-receipt-value]', box);
    if (box) box.hidden = payment() !== 'bank';
    if (value) value.hidden = payment() !== 'bank' || type === 'none';
    var depositor = $('[name="depositorName"]');
    if (depositor) depositor.required = payment() === 'bank';
  }

  function renderLines(q) {
    var box = $('[data-co-lines]');
    if (!q || !q.lines.length) {
      box.innerHTML = '<div class="empty" style="padding:20px 0"><p>장바구니가 비어 있어요</p><a class="btn small" href="/">상품 보러 가기</a></div>';
      return;
    }
    box.innerHTML = q.lines.map(function (l) {
      var opt = (l.packageLabel ? l.packageLabel + ' · ' : '') + (l.useOpenOption
        ? [l.opened ? S.OPT.opened + ' ' + l.opened : '', l.unopened ? S.OPT.unopened + ' ' + l.unopened : ''].filter(Boolean).join(' · ')
        : '수량 ' + l.qty);
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
    var mo = sm && sm.mergeOrder;
    $('[data-sum-ship]').textContent = sm ? (sm.deliveryType === 'keep' ? '킵 (지금 무료)' : (mo ? '무료 (합배송)' : (sm.shippingFee ? S.won(sm.shippingFee) : '무료'))) : '-';
    var pr = $('[data-sum-point-row]');
    if (pr) { pr.hidden = !(sm && sm.pointUsed); $('[data-sum-point]').textContent = sm ? '-' + sm.pointUsed.toLocaleString('ko-KR') + 'P' : ''; }
    $('[data-sum-total]').textContent = sm ? S.won(sm.total) : '-';
    var hint = $('[data-free-hint]');
    if (mo) {
      hint.hidden = false;
      hint.textContent = '배송 대기 중인 ‘' + mo.productLabel + '’ 상품에 함께 담아 보내드려요. 추가 배송비는 없어요.';
    } else if (sm && sm.deliveryType === 'direct' && sm.remainingForFree > 0) {
      hint.hidden = false;
      hint.innerHTML = (sm.includeKept && sm.keptAmount ? '킵 ' + S.won(sm.keptAmount) + '을 합산해도 ' : '') + '<b style="color:var(--pink-dd)">' + S.won(sm.remainingForFree) + '</b> 더 담으면 무료배송이에요';
    } else if (sm && sm.deliveryType === 'keep') {
      hint.hidden = false;
      hint.textContent = '킵 상품은 배송비 없이 보관되며, 다음 주문에서 같이 배송받을 수 있어요.';
    } else hint.hidden = true;
    var ph = $('[data-point-hint]');
    if (ph && q) ph.textContent = sm && sm.maxPoints ? '이번 주문에서 최대 ' + sm.maxPoints.toLocaleString('ko-KR') + 'P 사용할 수 있어요' : '사용 조건을 충족하지 않아요';
    var pi = $('[data-point-input]');
    if (pi && sm && pointUse() > sm.pointUsed) pi.value = sm.pointUsed || '';
    syncButton();
  }

  function syncKeepMerge() {
    var box = $('[data-keep-merge]');
    if (!box) return;
    var enabled = delivery() === 'direct' && !mergeOption();
    box.hidden = !enabled;
    if (!enabled) {
      var input = $('[name="includeKept"]', box);
      if (input) input.checked = false;
    }
  }

  // 발송 대기 주문에 추가: 바로배송일 때만 보이고, 선택하면 받는 분 정보를 그 주문과 맞춘다
  function syncOrderMerge() {
    var box = $('[data-order-merge]');
    if (box) box.hidden = delivery() !== 'direct';
    var opt = mergeOption();
    var hint = $('[data-merge-addr-hint]');
    var addressSearch = $('[data-address-search]');
    if (box) {
      ADDR_KEYS.forEach(function (k) {
        var i = $('[name="' + k + '"]');
        if (i) {
          i.value = opt ? (opt.getAttribute('data-merge-' + k) || '') : addrOriginal[k];
          i.readOnly = opt ? true : addrReadOnly[k];
        }
      });
    }
    if (opt && !mergeWasActive && saveAddress) saveAddressChoice = saveAddress.checked;
    if (saveAddress) {
      saveAddress.checked = opt ? false : saveAddressChoice;
      saveAddress.disabled = !!opt;
      saveAddress.closest('label').hidden = !!opt;
    }
    if (addressSearch) addressSearch.hidden = !!opt;
    mergeWasActive = !!opt;
    if (hint) {
      hint.hidden = !opt;
      if (opt) hint.textContent = '‘' + opt.getAttribute('data-merge-label') + '’ 상품을 받을 주소로 함께 보내드려요.';
    }
    syncKeepMerge();
  }

  function resetMergeChoice() {
    var none = $('input[name="mergeOrderId"][value="0"]');
    if (none) none.checked = true;
    syncOrderMerge();
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
    var wantedMerge = mergeOrderId();
    S.api('POST', '/api/cart/quote', { lines: S.toLines(cart), deliveryType: delivery(), includeKept: includeKept(), mergeOrderId: wantedMerge, pointUse: pointUse() })
      .then(function (q) {
        if (wantedMerge && !(q.summary && q.summary.mergeOrder)) {
          S.toast('선택한 주문이 더 이상 발송 대기 상태가 아니에요', 'error');
          resetMergeChoice();
          refresh();
          return;
        }
        var byId = {};
        q.lines.forEach(function (l) { byId[l.productId + ':' + (l.packageType || 'standard')] = l; });
        cart = cart.filter(function (l) { return !!byId[l.productId + ':' + (l.packageType || 'standard')]; });
        S.save(cart);
        quote = q;
        GM.live = q.live;
        renderLines(q);
        renderSummary(q);
      })
      .catch(function (e) { S.toast(e.message, 'error'); });
  }

  $$('input[name="delivery"]').forEach(function (r) { r.addEventListener('change', function () { syncOrderMerge(); refresh(); }); });
  $$('input[name="mergeOrderId"]').forEach(function (r) { r.addEventListener('change', function () { syncOrderMerge(); refresh(); }); });
  var keptToggle = $('[name="includeKept"]');
  if (keptToggle) keptToggle.addEventListener('change', refresh);
  $$('input[name="payment"]').forEach(function (r) {
    r.addEventListener('change', function () {
      var dep = $('[data-depositor]');
      if (dep) dep.hidden = payment() !== 'bank';
      syncCashReceipt();
      syncButton();
    });
  });
  $$('input[name="cashReceiptType"]').forEach(function (r) { r.addEventListener('change', syncCashReceipt); });
  syncCashReceipt();
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
    var form = $('[data-addr-form]');
    if (form && !form.checkValidity()) {
      form.reportValidity();
      return;
    }
    if (!val('address1')) { S.toast('주소를 입력해 주세요', 'error'); return; }
    busy = true; syncButton();
    var body = {
      lines: S.toLines(),
      deliveryType: delivery(),
      includeKept: includeKept(),
      mergeOrderId: mergeOrderId(),
      paymentMethod: payment(),
      pointUse: pointUse(),
      depositorName: val('depositorName'),
      cashReceipt: cashReceipt(),
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

  syncOrderMerge();
  refresh();
})();
