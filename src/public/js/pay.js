/* 포트원 V2 카드결제, 주문/출고요청 취소, 킵 출고 요청 */
(function () {
  'use strict';
  var S = window.GMShop;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /**
   * 카드 결제창 호출. 모바일은 redirectUrl 로 이동(서버 /pay/return 에서 검증),
   * PC는 Promise 로 결과를 받아 서버에 완료 검증을 요청.
   */
  function requestCard(payment, afterUrl) {
    if (!window.PortOne) {
      S.toast('결제 모듈을 불러오지 못했어요. 주문 내역에서 다시 결제해 주세요', 'error');
      setTimeout(function () { location.href = afterUrl; }, 1200);
      return Promise.resolve();
    }
    return window.PortOne.requestPayment(payment).then(function (res) {
      if (!res) return; // 모바일 리다이렉트 방식
      if (res.code !== undefined && res.code !== null) {
        S.toast(res.message || '결제가 취소되었어요', 'error');
        setTimeout(function () { location.href = afterUrl; }, 900);
        return;
      }
      return S.api('POST', '/api/payments/complete', { paymentId: res.paymentId || payment.paymentId }).then(function () {
        location.href = afterUrl + (afterUrl.indexOf('/orders/') === 0 ? '?placed=1' : '');
      });
    }).catch(function (e) {
      S.toast(e.message || '결제 처리 중 문제가 생겼어요', 'error');
      setTimeout(function () { location.href = afterUrl; }, 1500);
    });
  }
  window.GMPay = { requestCard: requestCard };

  // 결제 대기 건 다시 결제
  $$('[data-repay]').forEach(function (b) {
    b.addEventListener('click', function () {
      var no = b.getAttribute('data-repay');
      var kind = b.getAttribute('data-kind') === 'shipment' ? 'shipments' : 'orders';
      var after = kind === 'orders' ? '/orders/' + no : '/my/shipments/' + no;
      b.disabled = true;
      S.api('POST', '/api/' + kind + '/' + encodeURIComponent(no) + '/pay').then(function (r) {
        return requestCard(r.payment, after);
      }).catch(function (e) { S.toast(e.message, 'error'); }).then(function () { b.disabled = false; });
    });
  });

  // 확인 단계: 버튼을 한 번 더 눌러야 실행 (브라우저 confirm 대신)
  function twoStep(btn, label, run) {
    btn.addEventListener('click', function () {
      if (!btn.dataset.armed) {
        btn.dataset.armed = '1';
        btn.dataset.orig = btn.textContent;
        btn.textContent = label;
        btn.classList.add('soft');
        setTimeout(function () { if (btn.dataset.armed) { delete btn.dataset.armed; btn.textContent = btn.dataset.orig; btn.classList.remove('soft'); } }, 4000);
        return;
      }
      delete btn.dataset.armed;
      btn.disabled = true;
      run().catch(function (e) { S.toast(e.message, 'error'); btn.disabled = false; btn.textContent = btn.dataset.orig; });
    });
  }

  $$('[data-cancel-order]').forEach(function (b) {
    twoStep(b, '한 번 더 누르면 주문이 취소돼요', function () {
      return S.api('POST', '/api/orders/' + encodeURIComponent(b.getAttribute('data-cancel-order')) + '/cancel').then(function () { location.reload(); });
    });
  });
  $$('[data-cancel-shipment]').forEach(function (b) {
    twoStep(b, '한 번 더 누르면 출고 요청이 취소돼요', function () {
      return S.api('POST', '/api/shipments/' + encodeURIComponent(b.getAttribute('data-cancel-shipment')) + '/cancel').then(function () { location.href = '/my/keep'; });
    });
  });

  // 킵 출고 요청
  var rel = $('[data-release]');
  if (rel) {
    $$('input[name="payment"]', rel).forEach(function (r) {
      r.addEventListener('change', function () {
        var dep = $('[data-depositor]', rel);
        if (dep) dep.hidden = r.value !== 'bank' || !r.checked;
      });
    });
    var go = $('[data-release-go]', rel);
    go.addEventListener('click', function () {
      var v = function (n) { var el = $('[name="' + n + '"]', rel); return el ? el.value : ''; };
      var pm = ($('input[name="payment"]:checked', rel) || {}).value || null;
      go.disabled = true;
      S.api('POST', '/api/keep/release', {
        paymentMethod: pm,
        depositorName: v('depositorName'),
        recipient: { name: v('name'), phone: v('phone'), zipcode: v('zipcode'), address1: v('address1'), address2: v('address2'), memo: v('memo') },
      }).then(function (r) {
        if (r.payment) return requestCard(r.payment, r.redirect);
        location.href = r.redirect;
      }).catch(function (e) { S.toast(e.message, 'error'); go.disabled = false; });
    });
  }
})();
