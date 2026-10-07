/* 깅모지 관리자 스크립트 */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var csrf = ($('meta[name="csrf"]') || {}).content || '';
  var won = function (n) { return Number(n || 0).toLocaleString('ko-KR') + '원'; };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  function post(url, data) {
    var body = new URLSearchParams(data);
    return fetch(url, { method: 'POST', headers: { 'x-csrf-token': csrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  // 위험한 버튼은 두 번 눌러야 실행 (브라우저 확인창 대신 버튼 문구로 확인)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-confirm]');
    if (!btn) return;
    if (btn.dataset.armed === '1') { delete btn.dataset.armed; return; }
    e.preventDefault();
    btn.dataset.armed = '1';
    var orig = btn.innerHTML;
    btn.innerHTML = '한 번 더 눌러 확인';
    btn.title = btn.getAttribute('data-confirm');
    var tip = document.createElement('div');
    tip.className = 'flash';
    tip.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99;max-width:92vw;background:#141011;color:#f7ede0';
    tip.textContent = btn.getAttribute('data-confirm');
    document.body.appendChild(tip);
    setTimeout(function () { delete btn.dataset.armed; btn.innerHTML = orig; tip.remove(); }, 3500);
  }, true);

  // 일괄 선택
  var all = $('[data-check-all]');
  function syncSel() {
    var n = $$('[data-check]:checked').length;
    var c = $('[data-sel-count]');
    if (c) c.textContent = n;
    $$('[data-need-sel]').forEach(function (b) { b.disabled = n === 0; });
  }
  if (all) all.addEventListener('change', function () { $$('[data-check]').forEach(function (c) { c.checked = all.checked; }); syncSel(); });
  $$('[data-check]').forEach(function (c) { c.addEventListener('change', syncSel); });
  syncSel();

  // 상품 빠른 수정 (재고, 노출, 품절)
  $$('[data-quick]').forEach(function (el) {
    el.addEventListener('change', function () {
      var data = {};
      data[el.dataset.field] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
      el.disabled = true;
      post('/admin/products/' + el.dataset.quick + '/quick', data).then(function (r) {
        el.disabled = false;
        if (!r.ok) throw new Error(r.message);
        el.style.boxShadow = '0 0 0 3px #bfe6cf';
        setTimeout(function () { el.style.boxShadow = ''; }, 800);
      }).catch(function () { el.disabled = false; alertBar('저장하지 못했어요. 새로고침 후 다시 시도해 주세요'); });
    });
  });

  function alertBar(msg) {
    var d = document.createElement('div');
    d.className = 'flash error';
    d.textContent = msg;
    var main = $('.main');
    main.insertBefore(d, main.firstChild);
    setTimeout(function () { d.remove(); }, 4000);
  }

  // 상품 순서 드래그 (마우스·터치 공통 pointer 이벤트)
  var tbody = $('[data-sortable]');
  if (tbody) {
    var dragRow = null;
    tbody.addEventListener('pointerdown', function (e) {
      var h = e.target.closest('.drag');
      if (!h) return;
      dragRow = h.closest('tr');
      dragRow.classList.add('dragging');
      h.setPointerCapture(e.pointerId);
      e.preventDefault();
    });
    tbody.addEventListener('pointermove', function (e) {
      if (!dragRow) return;
      var rows = $$('tr[data-id]', tbody);
      for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        if (r === dragRow) continue;
        var rect = r.getBoundingClientRect();
        if (e.clientY > rect.top && e.clientY < rect.bottom) {
          if (e.clientY < rect.top + rect.height / 2) tbody.insertBefore(dragRow, r);
          else tbody.insertBefore(dragRow, r.nextSibling);
          break;
        }
      }
    });
    var end = function () {
      if (!dragRow) return;
      dragRow.classList.remove('dragging');
      dragRow = null;
      var ids = $$('tr[data-id]', tbody).map(function (r) { return r.dataset.id; }).join(',');
      post('/admin/products/reorder', { ids: ids }).catch(function () { alertBar('순서를 저장하지 못했어요'); });
    };
    tbody.addEventListener('pointerup', end);
    tbody.addEventListener('pointercancel', end);
  }

  // 이미지 업로드 전 브라우저에서 축소 (긴 변 1200px, JPEG 85%) → 저사양 서버/DB 부담 최소화
  $$('input[data-resize]').forEach(function (input) {
    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length || typeof DataTransfer === 'undefined') return;
      var preview = $('[data-preview]');
      if (preview) preview.innerHTML = '<span class="muted">이미지 줄이는 중…</span>';
      Promise.all(files.map(shrink)).then(function (out) {
        var dt = new DataTransfer();
        out.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
        if (preview) {
          preview.innerHTML = '';
          out.forEach(function (f) {
            var d = document.createElement('div');
            d.className = 'img';
            d.innerHTML = '<img alt=""><div class="muted" style="font-size:11px">' + Math.round(f.size / 1024) + 'KB · 저장 시 업로드</div>';
            $('img', d).src = URL.createObjectURL(f);
            preview.appendChild(d);
          });
        }
      });
    });
  });
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return resolve(file); // GIF 등은 원본
      var img = new Image();
      img.onload = function () {
        var max = 1200, w = img.naturalWidth, h = img.naturalHeight;
        var k = Math.min(1, max / Math.max(w, h));
        var c = document.createElement('canvas');
        c.width = Math.round(w * k); c.height = Math.round(h * k);
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) {
          if (!b || b.size >= file.size) return resolve(file);
          resolve(new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.85);
        URL.revokeObjectURL(img.src);
      };
      img.onerror = function () { resolve(file); };
      img.src = URL.createObjectURL(file);
    });
  }

  // 대시보드 10초 갱신
  if (window.ADMIN_DASH) {
    var clock = $('[data-clock]');
    var tick = function () {
      fetch('/admin/api/stats', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) return;
        Object.keys(j.st).forEach(function (k) {
          var el = $('[data-st="' + k + '"]');
          if (!el) return;
          el.textContent = /Revenue|Amount/.test(k) ? won(j.st[k]) : Number(j.st[k]).toLocaleString('ko-KR');
        });
        var tb = $('[data-recent]');
        if (tb) {
          var P = { card: '카드결제', bank: '계좌이체' }, D = { direct: '바로배송', keep: '킵' };
          tb.innerHTML = j.recent.map(function (o) {
            return '<tr><td><a class="nick" style="font-size:18px" href="/admin/orders/' + o.id + '">' + esc(o.youtube_nickname || '-') + '</a><span class="nick-sub">' + esc(o.recipient_name) + ' · ' + D[o.delivery_type] + ' · ' + P[o.payment_method] + '</span></td>' +
              '<td class="right num amount">' + won(o.total_amount) + '</td><td><span class="chip ' + o.status + '">' + esc(o.statusLabel) + '</span></td><td class="num muted nowrap">' + esc(o.created.slice(11)) + '</td></tr>';
          }).join('');
        }
        if (clock) clock.textContent = new Date().toLocaleTimeString('ko-KR') + ' 갱신';
      }).catch(function () {});
    };
    setInterval(function () { if (!document.hidden) tick(); }, 10000);
    if (clock) clock.textContent = new Date().toLocaleTimeString('ko-KR') + ' 기준';
  }

  // 주문 목록 자동 새로고침 (선택 중이면 건너뜀)
  if (window.ADMIN_AUTO_REFRESH) {
    setInterval(function () {
      if (document.hidden || $$('[data-check]:checked').length || document.activeElement && document.activeElement.tagName === 'INPUT') return;
      location.reload();
    }, window.ADMIN_AUTO_REFRESH);
  }
})();
