// 정적 데모(GitHub Pages)용 경로 변환: 서버 경로 → 데모 HTML 파일
// 빌드 스크립트(Node)와 데모 런타임(브라우저)에서 같이 사용
(function (root) {
  function mapPath(href) {
    if (!href || href.charAt(0) !== '/' || href.indexOf('//') === 0) return href;
    var hashIdx = href.indexOf('#');
    if (hashIdx >= 0) href = href.slice(0, hashIdx);
    var q = '';
    var qi = href.indexOf('?');
    if (qi >= 0) { q = href.slice(qi); href = href.slice(0, qi); }
    var p = href.replace(/\/+$/, '') || '/';
    var m;
    if (p.indexOf('/static/') === 0) return p.slice(1);
    if ((m = p.match(/^\/img\/(\d+)$/))) return 'img/' + m[1] + '.jpg';
    if (p === '/') return 'index.html' + (/^\?p=\d+$/.test(q) ? q : '');
    if (p === '/checkout') return 'checkout.html';
    if (p === '/login') return 'login.html';
    if (p === '/signup') return 'signup.html';
    if (p === '/signup/profile') return 'my-profile.html';
    if (p === '/my') return 'my.html';
    if (p === '/my/orders') return 'my-orders.html';
    if (p === '/my/keep') return 'my-keep.html';
    if (p === '/my/points') return 'my-points.html';
    if (p === '/my/profile') return 'my-profile.html';
    if (/^\/my\/shipments\//.test(p)) return 'shipment.html';
    if (/^\/orders\//.test(p)) return 'order.html' + (q.indexOf('placed=1') >= 0 ? '?placed=1' : '');
    if (/^\/p\/\d+$/.test(p)) return 'product.html';
    if ((m = p.match(/^\/page\/(terms|privacy|refund)$/))) return 'page-' + m[1] + '.html';
    if (p === '/admin') return 'admin.html';
    if (p === '/admin/login') return 'admin-login.html';
    if (p === '/admin/orders/export.csv') return '#';
    if (p === '/admin/orders') return 'admin-orders.html';
    if (/^\/admin\/orders\/\d+$/.test(p)) return 'admin-order.html';
    if (p === '/admin/keep') return q.indexOf('tab=members') >= 0 ? 'admin-keep-members.html' : 'admin-keep.html';
    if (/^\/admin\/keep\/user\/\d+$/.test(p)) return 'admin-keep-user.html';
    if (/^\/admin\/shipments\/\d+$/.test(p)) return 'admin-shipment.html';
    if (p === '/admin/products') return 'admin-products.html';
    if (p === '/admin/products/new') return 'admin-product-new.html';
    if (/^\/admin\/products\/\d+$/.test(p)) return 'admin-product.html';
    if (p === '/admin/users') return 'admin-users.html';
    if (/^\/admin\/users\/\d+$/.test(p)) return 'admin-user.html';
    if (p === '/admin/settings') return 'admin-settings.html';
    if (p === '/admin/pages') return 'admin-pages.html';
    if (/^\/admin\/pages\//.test(p)) return 'admin-page.html';
    return '#';
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = mapPath;
  else root.demoMapPath = mapPath;
})(this);
