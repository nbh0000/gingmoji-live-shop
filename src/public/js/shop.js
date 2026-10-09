/* 깅모지 라이브샵 공통 스크립트: 장바구니(브라우저 저장), 바텀시트, 방송 상태 폴링, 주소 찾기, 복사 */
(function () {
  'use strict';
  var GM = window.GM || {};
  var OPT = GM.opt || { opened: '라이브 개봉', unopened: '미개봉 발송' };
  var KEY = 'gm_cart_v1';
  var footerVisible = false;
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var won = function (n) { return Number(n || 0).toLocaleString('ko-KR') + '원'; };
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var csrf = ($('meta[name="csrf"]') || {}).content || '';

  // ===== API =====
  function api(method, url, body) {
    return fetch(url, {
      method: method,
      headers: { 'Content-Type': 'application/json', 'x-csrf-token': csrf, Accept: 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: '서버 응답을 읽을 수 없습니다' }; }).then(function (j) {
        if (r.status === 401 && j.login) {
          location.href = '/login?next=' + encodeURIComponent(location.pathname);
          throw new Error(j.message);
        }
        if (r.status === 400 && j.profile) {
          location.href = '/signup/profile';
          throw new Error(j.message);
        }
        if (!r.ok || j.ok === false) throw new Error(j.message || '요청을 처리하지 못했습니다');
        return j;
      });
    });
  }

  // ===== 토스트 =====
  var toastEl = null, toastTimer = null;
  function toast(msg, type) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.className = 'toast' + (type === 'error' ? ' error' : '');
    requestAnimationFrame(function () { toastEl.classList.add('show'); });
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2600);
  }
  var flashEl = $('[data-flash]');
  if (flashEl) setTimeout(function () { flashEl.classList.remove('show'); }, 3200);

  // ===== 장바구니 저장소 =====
  function load() {
    try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) { return []; }
  }
  function save(cart) {
    try { localStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) { /* 사파리 사생활 보호 모드 등 */ }
    renderBadge();
  }
  function lineQty(l) { return l.option ? (l.opened || 0) + (l.unopened || 0) : (l.qty || 0); }
  function cartCount(cart) { return (cart || load()).reduce(function (s, l) { return s + lineQty(l); }, 0); }
  function cartTotal(cart) { return (cart || load()).reduce(function (s, l) { return s + (l.price || 0) * lineQty(l); }, 0); }
  function toLines(cart) {
    return (cart || load()).map(function (l) {
      return { productId: l.productId, packageType: l.packageType || 'standard', opened: l.opened || 0, unopened: l.unopened || 0, qty: l.option ? 0 : (l.qty || 0) };
    });
  }

  function renderBadge() {
    var n = cartCount();
    $$('[data-cart-count]').forEach(function (el) { el.textContent = n; el.hidden = n === 0; });
    var bar = $('[data-cartbar]');
    if (bar) {
      bar.classList.toggle('show', n > 0 && !footerVisible);
      var t = $('[data-cartbar-text]', bar);
      if (t) t.textContent = n + '개' + (GM.showPrice ? ' · ' + won(cartTotal()) : '');
    }
  }

  // ===== 바텀시트 =====
  function watchFooter() {
    var footer = $('.foot');
    if (!footer) return;
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        footerVisible = !!(entries[0] && entries[0].isIntersecting);
        renderBadge();
      }, { rootMargin: '0px 0px 80px 0px', threshold: 0 }).observe(footer);
      return;
    }
    var check = function () {
      var rect = footer.getBoundingClientRect();
      footerVisible = rect.top < window.innerHeight + 80 && rect.bottom > 0;
      renderBadge();
    };
    window.addEventListener('scroll', check, { passive: true });
    check();
  }

  document.addEventListener('gm:cart-updated', renderBadge);
  watchFooter();
  var backdrop = $('[data-backdrop]');
  var openSheetEl = null;
  function openSheet(el) {
    if (openSheetEl && openSheetEl !== el) closeSheet();
    el.removeAttribute('hidden');
    el.hidden = false;
    openSheetEl = el;
    requestAnimationFrame(function () {
      el.classList.add('open');
      if (backdrop) backdrop.classList.add('open');
    });
    document.documentElement.style.overflow = 'hidden';
  }
  function closeSheet() {
    if (!openSheetEl) return;
    var el = openSheetEl;
    openSheetEl = null;
    el.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
    document.documentElement.style.overflow = '';
    setTimeout(function () { if (!el.classList.contains('open')) { el.hidden = true; el.setAttribute('hidden', ''); } }, 320);
  }
  if (backdrop) backdrop.addEventListener('click', closeSheet);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });
  $$('[data-close-sheet]').forEach(function (b) { b.addEventListener('click', closeSheet); });

  // 아래로 끌어서 닫기 (손잡이/헤더 영역)
  $$('.sheet').forEach(function (sheet) {
    var startY = null, dy = 0;
    var handle = function (e) { return e.target.closest('.sheet-grab, .sheet-head'); };
    sheet.addEventListener('pointerdown', function (e) {
      if (!handle(e) || e.target.closest('button')) return;
      startY = e.clientY; dy = 0;
      sheet.style.transition = 'none';
      sheet.setPointerCapture(e.pointerId);
    });
    sheet.addEventListener('pointermove', function (e) {
      if (startY === null) return;
      dy = Math.max(0, e.clientY - startY);
      sheet.style.transform = 'translateY(' + dy + 'px)';
    });
    var end = function () {
      if (startY === null) return;
      startY = null;
      sheet.style.transition = '';
      sheet.style.transform = '';
      if (dy > 90) closeSheet();
    };
    sheet.addEventListener('pointerup', end);
    sheet.addEventListener('pointercancel', end);
  });

  // ===== 스테퍼 =====
  function stepper(value, min, max, onChange, small) {
    var wrap = document.createElement('div');
    wrap.className = 'stepper' + (small ? ' sm' : '');
    wrap.innerHTML = '<button type="button" class="minus" aria-label="빼기">−</button><output>' + value + '</output><button type="button" class="plus" aria-label="더하기">+</button>';
    var out = $('output', wrap), minus = $('.minus', wrap), plus = $('.plus', wrap);
    function sync() {
      out.textContent = value;
      minus.disabled = value <= min;
      plus.disabled = value >= max();
    }
    minus.addEventListener('click', function () { if (value > min) { value--; sync(); onChange(value); } });
    plus.addEventListener('click', function () {
      if (value < max()) { value++; sync(); onChange(value); }
      else toast('더 담을 수 없는 상품이에요', 'error');
    });
    wrap.sync = sync;
    sync();
    return wrap;
  }

  // ===== 상품 시트 =====
  var ps = $('#productSheet');
  var psState = null;

  function openProduct(id) {
    if (!ps) return;
    api('GET', '/api/products/' + id).then(function (j) {
      var p = j.product;
      GM.live = j.live;
      var inCart = load().filter(function (l) { return l.productId === p.id; })[0];
      var already = inCart ? lineQty(inCart) : 0;
      psState = { p: p, packageType: 'full', opened: 0, unopened: 0, qty: p.useOpenOption ? 0 : 1, already: already };
      var body = $('[data-ps-body]', ps);
      var packagePrice = function () {
        if (!p.usePackageOption) return p.price;
        return psState.packageType === 'full' ? (p.fullBoxPrice || p.price) : (p.loosePrice || p.price);
      };
      var imgs = p.images.length
        ? p.images.map(function (i) { return '<div class="thumb"><img src="/img/' + i + '" alt=""></div>'; }).join('')
        : '<div class="thumb"><span class="ph">?</span></div>';
      var packageHtml = p.usePackageOption
        ? '<div class="detail-package-box"><div class="detail-option-label"><strong>상품 구성을 골라 주세요</strong><span>풀박 또는 낱박</span></div><div class="detail-package-options"><label class="detail-package-option"><input type="radio" name="sheetPackage" value="full" checked><span class="package-option-content"><span class="package-option-image">' + (p.fullBoxImageId ? '<img src="/img/' + p.fullBoxImageId + '" alt="풀박">' : '풀박') + '</span><span><strong>풀박</strong><small>풀박스 구성</small><b>' + won(p.fullBoxPrice || p.price) + '</b></span></span></label><label class="detail-package-option"><input type="radio" name="sheetPackage" value="loose"><span class="package-option-content"><span class="package-option-image">' + (p.looseImageId ? '<img src="/img/' + p.looseImageId + '" alt="낱박">' : '낱박') + '</span><span><strong>낱박</strong><small>패키지 내 개별 상품</small><b>' + won(p.loosePrice || p.price) + '</b></span></span></label></div></div>'
        : '';
      body.innerHTML =
        '<div class="p-gallery">' + imgs + '</div>' +
        '<div class="p-name" id="ps-name">' + esc(p.name) + '</div>' +
        (p.price != null ? '<div class="p-price" data-sheet-price>' + won(packagePrice()) + '</div>' : '<div class="price hidden">가격은 방송 중에 공개돼요</div>') +
        '<div class="p-meta">' +
          (p.soldout ? '<span class="chip gray">품절</span>' : (p.showStock ? '<span class="chip">재고 ' + p.stock + '개</span>' : '')) +
          (already ? '<span class="chip gray">장바구니에 ' + already + '개</span>' : '') +
        '</div>' +
        packageHtml +
        (p.expectedShippingText ? '<p class="expected-shipping">예상 배송일 <strong>' + esc(p.expectedShippingText) + '</strong></p>' : '') +
        (p.description ? '<div class="p-desc">' + esc(p.description) + '</div>' : '') +
        '<div class="opt-box" data-ps-opts></div>';
      var opts = $('[data-ps-opts]', body);
      $$('input[name="sheetPackage"]', body).forEach(function (radio) {
        radio.addEventListener('change', function () {
          if (!this.checked) return;
          psState.packageType = this.value;
          var priceEl = $('[data-sheet-price]', body);
          if (priceEl) priceEl.textContent = won(packagePrice());
          syncAdd();
        });
      });
      var left = function () { return Math.max(0, p.stock - already); };
      var total = function () { return p.useOpenOption ? psState.opened + psState.unopened : psState.qty; };
      if (!p.soldout && j.live) {
        if (p.useOpenOption) {
          opts.innerHTML = '<div class="opt-label">개봉 방법을 골라 주세요 <small>수량별로 나눠 담을 수 있어요</small></div>';
          [['opened', OPT.opened, '방송에서 바로 개봉해 드려요'], ['unopened', OPT.unopened, '포장 그대로 보내 드려요']].forEach(function (o) {
            var row = document.createElement('div');
            row.className = 'opt-row';
            row.innerHTML = '<div class="lbl">' + o[1] + '<small>' + o[2] + '</small></div>';
            var st = stepper(0, 0, function () { return psState[o[0]] + (left() - total()); }, function (v) { psState[o[0]] = v; syncAdd(); syncSteppers(); });
            row.appendChild(st);
            opts.appendChild(row);
          });
        } else {
          var row = document.createElement('div');
          row.className = 'opt-row';
          row.innerHTML = '<div class="lbl">수량</div>';
          row.appendChild(stepper(Math.min(1, left()), Math.min(1, left()), function () { return left(); }, function (v) { psState.qty = v; syncAdd(); }));
          psState.qty = Math.min(1, left());
          opts.appendChild(row);
        }
      }
      function syncSteppers() { $$('.stepper', opts).forEach(function (s) { s.sync(); }); }
      syncAdd();
      openSheet(ps);
    }).catch(function (e) { toast(e.message, 'error'); });
  }

  function syncAdd() {
    var btn = $('[data-ps-add]', ps);
    if (!psState) return;
    var p = psState.p;
    var n = p.useOpenOption ? psState.opened + psState.unopened : psState.qty;
    btn.disabled = false;
    if (!GM.live) { btn.textContent = '방송 중에만 주문할 수 있어요'; btn.disabled = true; return; }
    if (p.soldout) { btn.textContent = '품절된 상품이에요'; btn.disabled = true; return; }
    if (psState.already >= p.stock) { btn.textContent = '더 담을 수 없는 상품이에요'; btn.disabled = true; return; }
    if (n < 1) { btn.textContent = p.useOpenOption ? '개봉 방법과 수량을 골라 주세요' : '수량을 골라 주세요'; btn.disabled = true; return; }
    var unitPrice = p.usePackageOption ? (psState.packageType === 'full' ? (p.fullBoxPrice || p.price) : (p.loosePrice || p.price)) : p.price;
    btn.innerHTML = n + '개 담기' + (unitPrice != null ? ' <span class="sub">' + won(unitPrice * n) + '</span>' : '');
  }

  if (ps) {
    $('[data-ps-add]', ps).addEventListener('click', function () {
      if (!psState) return;
      var p = psState.p;
      var cart = load();
       var packageType = p.usePackageOption ? psState.packageType : 'standard';
       var unitPrice = p.usePackageOption ? (packageType === 'full' ? (p.fullBoxPrice || p.price) : (p.loosePrice || p.price)) : p.price;
       var line = cart.filter(function (l) { return l.productId === p.id && (l.packageType || 'standard') === packageType; })[0];
       if (!line) {
         line = { productId: p.id, name: p.name, price: unitPrice, packageType: packageType, imageId: p.images[0] || null, option: p.useOpenOption, opened: 0, unopened: 0, qty: 0 };
         cart.push(line);
       }
       line.price = unitPrice;
      line.name = p.name;
      line.price = p.price;
      if (p.useOpenOption) {
        line.opened = (line.opened || 0) + psState.opened;
        line.unopened = (line.unopened || 0) + psState.unopened;
      } else {
        line.qty = (line.qty || 0) + psState.qty;
      }
      save(cart);
      closeSheet();
      toast('장바구니에 담았어요 ♥');
    });
  }

  $$('[data-product]').forEach(function (card) {
    // 상품은 항상 독립 상세 페이지로 이동합니다. 상품 모달을 열지 않습니다.
    var id = Number(card.getAttribute('data-product'));
    if (!id) return;
    card.addEventListener('click', function (e) {
      if (card.tagName.toLowerCase() === 'a') return;
      e.preventDefault();
      location.href = '/p/' + id;
    });
  });

  // ===== 장바구니 시트 =====
  var cs = $('#cartSheet');
  function renderCart(quote) {
    if (!cs) return;
    var cart = load();
    var body = $('[data-cart-body]', cs);
    var foot = $('[data-cart-foot]', cs);
    if (!cart.length) {
      body.innerHTML = '<div class="empty"><div class="big">?</div><p>장바구니가 비어 있어요</p></div>';
      foot.hidden = true;
      return;
    }
    foot.hidden = false;
    var problems = {};
    if (quote) quote.lines.forEach(function (l) { problems[l.productId + ':' + (l.packageType || 'standard')] = l; });
    body.innerHTML = '';
    cart.forEach(function (l, idx) {
      var q = problems[l.productId + ':' + (l.packageType || 'standard')];
      var row = document.createElement('div');
      row.className = 'cart-line';
      row.innerHTML =
        '<div class="thumb">' + (l.imageId ? '<img src="/img/' + l.imageId + '" alt="">' : '<span class="ph">?</span>') + '</div>' +
          '<div class="info">' +
          '<div class="nm">' + esc(l.name) + '</div>' +
          (l.packageType && l.packageType !== 'standard' ? '<div class="opt">' + (l.packageType === 'full' ? '풀박 · 풀박스 구성' : '낱박 · 패키지 내 개별 상품') + '</div>' : '') +
          (q && q.problem ? '<div class="problem">' + esc(q.problem) + '</div>' : '') +
          '<div class="ctrl" data-ctrl></div>' +
          '<div class="ctrl"><span class="amt num">' + (l.price != null ? won(l.price * lineQty(l)) : '가격 방송 중 공개') + '</span><button type="button" class="rm">삭제</button></div>' +
        '</div>';
      var ctrl = $('[data-ctrl]', row);
      var stock = q && q.stock != null ? q.stock : 99;
      var update = function () { save(cart); renderCart(quote); };
      if (l.option) {
        var split = document.createElement('div');
        split.className = 'split';
        [['opened', OPT.opened], ['unopened', OPT.unopened]].forEach(function (o) {
          var s = document.createElement('div');
          s.className = 's';
          s.innerHTML = '<span>' + o[1] + '</span>';
          s.appendChild(stepper(l[o[0]] || 0, 0, function () { return (l[o[0]] || 0) + Math.max(0, stock - lineQty(l)); }, function (v) {
            l[o[0]] = v;
            if (lineQty(l) === 0) cart.splice(idx, 1);
            update();
          }, true));
          split.appendChild(s);
        });
        ctrl.appendChild(split);
      } else {
        ctrl.appendChild(stepper(l.qty || 1, 1, function () { return stock; }, function (v) { l.qty = v; update(); }, true));
      }
      $('.rm', row).addEventListener('click', function () { cart.splice(idx, 1); update(); });
      body.appendChild(row);
    });
    $('[data-cart-total]', cs).textContent = GM.showPrice ? won(cartTotal(cart)) : '방송 중 공개';
    var hint = $('[data-cart-hint]', cs);
    if (hint) hint.textContent = won(GM.freeShip) + ' 이상 바로배송 무료 · 킵은 배송비를 지금 결제하지 않아요';
    var go = $('[data-go-checkout]', cs);
    var bad = quote && quote.hasProblem;
    go.classList.toggle('disabled', !GM.live || bad);
    go.textContent = !GM.live ? '방송 중에만 주문할 수 있어요' : bad ? '품절·재고 부족 상품을 정리해 주세요' : '장바구니 상품 바로 구매';
    go.href = GM.loggedIn ? '/checkout' : '/login?next=/checkout';
    var direct = $('[data-cart-direct]');
    if (direct) {
      direct.href = GM.loggedIn ? '/checkout' : '/login?next=/checkout';
      direct.classList.toggle('disabled', !GM.live || bad);
      direct.setAttribute('aria-disabled', (!GM.live || bad) ? 'true' : 'false');
    }
  }

  function refreshQuote() {
    var cart = load();
    if (!cart.length) return Promise.resolve(null);
    return api('POST', '/api/cart/quote', { lines: toLines(cart) }).then(function (q) {
      // 최신 가격 반영
      var byId = {};
      q.lines.forEach(function (l) { byId[l.productId + ':' + (l.packageType || 'standard')] = l; });
      // 삭제·비공개 처리된 상품은 서버 견적에 포함되지 않으므로 브라우저 장바구니에서도 제거한다.
      cart = cart.filter(function (l) { return !!byId[l.productId + ':' + (l.packageType || 'standard')]; });
      cart.forEach(function (l) {
        var x = byId[l.productId + ':' + (l.packageType || 'standard')];
        if (x) { l.price = x.unitPrice; if (x.name) l.name = x.name; if (x.imageId) l.imageId = x.imageId; }
      });
      save(cart);
      return q;
    }).catch(function () { return null; });
  }

  $$('[data-open-cart]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault();
      if (!cs) return;
      renderCart(null);
      openSheet(cs);
      refreshQuote().then(function (q) { renderCart(q); });
    });
  });

  document.addEventListener('click', function (e) {
    var direct = e.target.closest('[data-cart-direct]');
    if (!direct || !direct.classList.contains('disabled')) return;
    e.preventDefault();
    toast(!GM.live ? '방송 중에만 바로 구매할 수 있어요' : '품절·재고 부족 상품을 먼저 정리해 주세요', 'error');
  });

  // ===== 방송 상태 / 재고 폴링 (5초) =====
  function applyLive(j) {
    var changed = GM.live !== j.live;
    GM.live = j.live;
    GM.showPrice = j.showPrice;
    $$('[data-live-pill]').forEach(function (el) { el.classList.toggle('on', j.live); el.textContent = j.live ? 'LIVE' : 'OFF'; });
    var banner = $('[data-live-banner]');
    if (banner) {
      banner.classList.toggle('off', !j.live);
      $('.heart', banner).textContent = j.live ? '♥' : '✦';
      $('[data-live-text]', banner).textContent = j.live ? '지금 방송 중! 담고 바로 주문하세요' : '방송 중에만 주문할 수 있어요. 방송 때 만나요!';
    }
    var map = {};
    j.products.forEach(function (p) { map[p.id] = p; });
    $$('[data-product]').forEach(function (card) {
      var p = map[card.getAttribute('data-product')];
      if (!p) { card.hidden = true; return; }
      card.hidden = false;
      card.classList.toggle('soldout', p.soldout);
      var pl = $('[data-price-label]', card);
      if (pl) {
        pl.classList.toggle('hidden', p.price == null);
        pl.textContent = p.price != null ? won(p.price) : '방송 중 공개';
      }
      var sl = $('[data-stock-label]', card);
      if (sl) {
        sl.textContent = p.soldout ? '품절' : (j.showStock && p.stock <= 3 ? '남은 ' + p.stock + '개' : '');
        sl.classList.toggle('low', j.showStock && !p.soldout && p.stock <= 3);
      }
    });
    if (changed) {
      if (psState) syncAdd();
      document.dispatchEvent(new CustomEvent('gm:live', { detail: j }));
      toast(j.live ? '방송이 시작됐어요! 지금 주문할 수 있어요' : '방송이 끝나 주문이 마감됐어요', j.live ? '' : 'error');
    }
    renderBadge();
  }
  // 상품이 새로 추가되면 목록을 다시 받아야 하므로 새로고침
  var isHome = document.body.classList.contains('has-cartbar');
  var knownIds = $$('[data-product]').map(function (c) { return c.getAttribute('data-product'); }).sort().join(',');
  function poll() {
    if (document.hidden) return;
    fetch('/api/live', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      if (isHome && !openSheetEl) {
        var ids = j.products.map(function (p) { return String(p.id); }).sort().join(',');
        if (ids !== knownIds) { location.reload(); return; }
      }
      applyLive(j);
    }).catch(function () { /* 네트워크 일시 오류 무시 */ });
  }
  setInterval(poll, 5000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

  // ===== 주소 찾기 (다음 우편번호, 바텀시트 임베드) =====
  var pcSheet = $('#postcodeSheet');
  var pcBackdrop = $('[data-pc-backdrop]');
  var pcTarget = null;
  function closePostcode() {
    if (!pcSheet) return;
    pcSheet.classList.remove('open');
    if (pcBackdrop) pcBackdrop.classList.remove('open');
    setTimeout(function () { pcSheet.hidden = true; }, 300);
  }
  $$('[data-postcode]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!window.daum || !window.daum.Postcode) { toast('주소 검색을 불러오는 중이에요. 잠시 후 다시 눌러 주세요', 'error'); return; }
      pcTarget = btn.closest('form, [data-addr-form], [data-release], .panel') || document;
      var wrap = $('[data-pc-wrap]', pcSheet);
      wrap.innerHTML = '';
      new window.daum.Postcode({
        width: '100%',
        height: '100%',
        oncomplete: function (d) {
          var addr = d.roadAddress || d.jibunAddress;
          if (d.buildingName && d.apartment === 'Y') addr += ' (' + d.buildingName + ')';
          var z = $('[data-zip]', pcTarget), a1 = $('[data-addr1]', pcTarget), a2 = $('[data-addr2]', pcTarget);
          if (z) z.value = d.zonecode;
          if (a1) a1.value = addr;
          closePostcode();
          if (a2) setTimeout(function () { a2.value = ''; a2.focus(); }, 320);
        },
      }).embed(wrap);
      pcSheet.hidden = false;
      requestAnimationFrame(function () { pcSheet.classList.add('open'); if (pcBackdrop) pcBackdrop.classList.add('open'); });
    });
  });
  $$('[data-close-postcode]').forEach(function (b) { b.addEventListener('click', closePostcode); });
  if (pcBackdrop) pcBackdrop.addEventListener('click', closePostcode);

  // ===== 복사 버튼 =====
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = b.getAttribute('data-copy');
      var done = function () { toast('복사했어요'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
      } else { fallbackCopy(text); done(); }
    });
  });
  function fallbackCopy(text) {
    var t = document.createElement('textarea');
    t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); } catch (e) { /* ignore */ }
    document.body.removeChild(t);
  }

  window.addEventListener("storage", function (e) { if (e.key === KEY) renderBadge(); });
  renderBadge();

  window.GMShop = { api: api, toast: toast, load: load, save: save, toLines: toLines, won: won, esc: esc, lineQty: lineQty, openSheet: openSheet, closeSheet: closeSheet, refreshQuote: refreshQuote, OPT: OPT };
})();
