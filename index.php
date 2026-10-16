<?php
declare(strict_types=1);

require __DIR__ . '/php/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($requestPath, '/static/')) {
    $publicRoot = realpath(__DIR__ . '/src/public');
    $relativePath = rawurldecode(substr($requestPath, strlen('/static/')));
    $assetPath = $publicRoot && $relativePath !== ''
        ? realpath($publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))
        : false;
    if ($publicRoot && $assetPath && str_starts_with($assetPath, $publicRoot . DIRECTORY_SEPARATOR) && is_file($assetPath)) {
        $contentTypes = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        $extension = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . (string)filesize($assetPath));
        readfile($assetPath);
        exit;
    }
    http_response_code(404);
    exit;
}

try {
    migrate_schema();
} catch (Throwable $error) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>DB 설정 확인</title><style>body{font-family:system-ui;padding:40px;line-height:1.7}code,pre{background:#f3f3f3;padding:8px;white-space:pre-wrap}</style><h1>DB 연결을 확인해 주세요</h1><p>.env의 Cafe24 DB 정보와 마이그레이션 상태를 확인해 주세요.</p><pre>' . e($error->getMessage()) . '</pre>';
    exit;
}

check_csrf();
$path = $requestPath;
$path = '/' . trim($path, '/');
$path = preg_replace('#^/index\.php(?=/|$)#', '', $path) ?: '/';
if ($path !== '/') {
    $path = rtrim($path, '/');
}

if (str_starts_with($path, '/api/')) {
    handle_api($path);
}
if (preg_match('#^/img/(\d+)$#', $path, $imageMatch)) {
    handle_image((int)$imageMatch[1]);
}
if (preg_match('#^/review-img/(\d+)$#', $path, $reviewImageMatch)) {
    handle_review_image((int)$reviewImageMatch[1]);
}
if ($path === '/reviews' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    submit_review();
}
if (preg_match('#^/reviews/(\d+)$#', $path, $reviewMatch) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    update_review((int)$reviewMatch[1]);
}
if (str_starts_with($path, '/admin')) {
    handle_admin($path);
}
handle_shop($path);

function page(string $title, string $body, bool $admin = false, array $scripts = [], bool $shell = true): never
{
    $s = setting_values();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $css = $admin ? '/static/css/admin.css?v=php11' : '/static/css/shop.css?v=php20';
    $extra = '';
    foreach ($scripts as $script) {
        $extra .= '<script src="' . e($script) . '"></script>';
    }
    $adminNewOrderMarker = ($admin && $shell && admin_unchecked_order_count() > 0)
        ? '<span class="admin-new-order-marker" hidden aria-label="확인하지 않은 신규 주문이 있습니다"></span>'
        : '';

    if ($admin && !$shell) {
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . ' · 깅모지 관리자</title><link rel="stylesheet" href="' . $css . '"></head><body>' . $body . '</body></html>';
        exit;
    }

    if ($admin) {
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . ' · 깅모지 관리자</title><link rel="stylesheet" href="' . $css . '"></head><body><div class="shell"><aside class="side"><a class="logo" href="/admin">깅모지<small>ADMIN</small></a><nav class="nav"><a href="/admin">대시보드</a><a href="/admin/orders">주문 관리</a><a href="/admin/users">회원 관리</a><a href="/admin/reviews">리뷰 관리</a><a href="/admin/products">상품·재고</a><a href="/admin/settings">사이트 설정</a><a href="/admin/pages">약관·정책</a></nav><div class="side-foot"><a href="/" target="_blank">고객 화면 열기 ↗</a><form method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button class="btn sm ghost">로그아웃</button></form></div></aside><main class="main">';
        if ($flash) {
            echo '<div class="flash ' . ($flash[0] === 'error' ? 'error' : '') . '" role="status" aria-live="polite">' . e($flash[1]) . '</div>';
        }
        echo $adminNewOrderMarker . $body . '</main></div><script src="/static/js/admin.js?v=php10"></script></body></html>';
        exit;
    }

    $user = current_user();
    $gm = [
        'live' => (bool)$s['live_on'],
        'showPrice' => true,
        'loggedIn' => (bool)$user,
        'freeShip' => (int)$s['free_shipping_threshold'],
        'shipFee' => (int)$s['shipping_fee'],
        'opt' => ['opened' => '라이브 개봉', 'unopened' => '미개봉 발송'],
    ];
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#141011"><title>' . ($title ? e($title) . ' · ' : '') . '깅모지 LIVE</title><meta name="description" content="깅모지 라이브 상품 주문 사이트"><link rel="stylesheet" href="' . $css . '"><meta name="csrf" content="' . e(csrf_token()) . '"></head><body class="' . ($title === '' ? 'has-cartbar' : '') . '">';
    $accountLinks = $user ? '<a class="top-link" href="/my">마이페이지</a><a class="top-link" href="/logout">로그아웃</a>' : '<a class="top-link" href="/login">로그인</a><a class="top-link" href="/signup">회원가입</a>';
    echo '<header class="top"><div class="wrap top-in"><a class="brand" href="/">깅모지<span class="q">♥</span></a><div class="top-actions"><div class="account-links">' . $accountLinks . '</div><span class="live-pill ' . ($s['live_on'] ? 'on' : '') . '" data-live-pill>' . ($s['live_on'] ? 'LIVE' : 'OFF') . '</span><button class="icon-btn" type="button" data-open-cart aria-label="장바구니">🛒<span class="badge" data-cart-count hidden>0</span></button></div></div></header>';
    if ($flash) {
        echo '<div class="toast show ' . ($flash[0] === 'error' ? 'error' : '') . '" data-flash role="status">' . e($flash[1]) . '</div>';
    }
    echo $body;
    echo '<footer class="foot"><div class="wrap"><div class="brand">깅모지</div><nav class="foot-links"><a href="/page/terms">이용약관</a><a href="/page/privacy">개인정보처리방침</a><a href="/page/refund">교환·환불 정책</a></nav><div class="biz"><span>상호 ' . e($s['biz_name']) . '</span><span>대표자 ' . e($s['biz_owner']) . '</span><br><span>사업자등록번호 ' . e($s['biz_reg_no']) . '</span><br><span>주소 ' . e($s['biz_address']) . '</span><br><span>연락처 ' . e($s['biz_phone']) . '</span></div></div></footer>';
    echo '<script>window.GM=' . json_encode($gm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script><script src="/static/js/shop.js?v=php17"></script>' . $extra . '</body></html>';
    exit;
}

function admin_unchecked_order_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM orders WHERE admin_checked_at IS NULL AND status <> 'cancelled'")->fetchColumn();
}

function shop_body(string $content, bool $withCart = false): string
{
    $cart = $withCart ? '<div class="cartbar" data-cartbar><div class="cartbar-inner"><button class="btn big" type="button" data-open-cart>장바구니 보기 <span class="count num" data-cartbar-text>0개</span></button><a class="btn big pink cartbar-buy" href="/checkout" data-cart-direct>바로 구매</a></div></div><section class="sheet" id="cartSheet" hidden><div class="sheet-head"><h2>장바구니</h2><button type="button" data-close-sheet>닫기</button></div><div class="sheet-body" data-cart-body></div><div class="sheet-foot" data-cart-foot><div class="sum-row total"><span>상품금액</span><span data-cart-total>0원</span></div><p class="hint" data-cart-hint></p><a class="btn big block" href="/checkout" data-go-checkout>장바구니 상품 바로 구매</a></div></section><div class="sheet-backdrop" data-backdrop></div>' : '';
    return $content . $cart;
}

function handle_shop(string $path): never
{
    $s = setting_values();

    if ($path === '/') {
        $products = product_rows();
        $showPrice = true;
        $body = '<section class="hero"><img src="/static/img/logo-wide.jpg" alt="GINGMOJI" width="1536" height="614"></section><main class="wrap"><div class="banner ' . (!$s['live_on'] ? 'off' : '') . '" data-live-banner><span class="heart">' . ($s['live_on'] ? '♥' : '✦') . '</span><span data-live-text>' . ($s['live_on'] ? '지금 방송 중! 담고 바로 주문하세요' : '방송 중에만 주문할 수 있어요. 방송 때 만나요!') . '</span></div>';
        if ($s['notice_text']) {
            $body .= '<div class="notice">' . nl2br(e($s['notice_text'])) . '</div>';
        }
        if ($s['event_notice_text']) {
            $body .= '<div class="notice">' . e($s['event_notice_text']) . '</div>';
        }
        foreach (['live' => '라이브 상품', 'sample' => '샘플'] as $section => $label) {
            $items = array_values(array_filter($products, static fn(array $p): bool => ($p['section'] ?? 'live') === $section));
            $body .= '<h2 class="section-title ' . ($section === 'sample' ? 'sample' : '') . '">' . $label . ' <small>' . count($items) . '개</small></h2><div class="grid" data-grid>';
            if (!$items) {
                $body .= '<div class="empty"><p>상품을 준비하고 있어요.</p></div>';
            } else {
                foreach ($items as $product) {
                    $body .= product_card($product, $s, $showPrice);
                }
            }
            $body .= '</div>';
        }
        $body .= render_reviews_section(recent_reviews());
        $body .= '</main>';
        page('', shop_body($body, true));
    }

    if (preg_match('#^/p/(\d+)$#', $path, $match)) {
        $product = one_product((int)$match[1]);
        if (!$product || !(int)$product['is_visible']) {
            http_response_code(404);
            page('상품 없음', '<main class="wrap page"><div class="panel"><h1>상품을 찾을 수 없습니다.</h1><a class="btn" href="/">홈으로</a></div></main>');
        }
        $showPrice = true;
        $gallery = '';
        $imageSplit = product_image_split($product);
        $mainImage = $imageSplit['main'];
        $detailGalleryImages = $imageSplit['details'];
        $galleryImages = $mainImage ? [$mainImage] : [];
        if ($mainImage) {
            $gallery = '<div class="thumb"><img src="/img/' . (int)$mainImage['id'] . '" alt="' . e($product['name']) . '"></div>';
        }
        if (!$gallery) {
            $gallery = '<div class="thumb"><span class="ph">이미지 없음</span></div>';
        }
        $hasPackageOption = (bool)$product['use_package_option'];
        $fullBoxPrice = (int)$product['full_box_price'] > 0 ? (int)$product['full_box_price'] : (int)$product['price'];
        $loosePrice = (int)$product['loose_price'] > 0 ? (int)$product['loose_price'] : (int)$product['price'];
        $compactPackagePrice = static function (int $value): string {
            $man = rtrim(rtrim(number_format($value / 10000, 1, '.', ''), '0'), '.');
            return $man . '만원';
        };
        $packageHtml = '';
        $fullBoxQty = package_units($product, 'full');
        $fullBoxAvailable = !$hasPackageOption || (int)$product['stock'] >= $fullBoxQty;
        if ($hasPackageOption) {
            $fullImage = !empty($product['full_box_image_id']) ? '<img src="/img/' . (int)$product['full_box_image_id'] . '" alt="풀박">' : '<span class="package-image-placeholder">풀박</span>';
            $looseImage = !empty($product['loose_image_id']) ? '<img src="/img/' . (int)$product['loose_image_id'] . '" alt="낱박">' : '<span class="package-image-placeholder">낱박</span>';
            $packageHtml = '<div class="detail-package-box"><div class="detail-option-label"><strong>상품 구성을 골라 주세요</strong><span>풀박 또는 낱박</span></div><div class="detail-package-options"><label class="detail-package-option"><input type="radio" name="detailPackage" value="full"' . ($fullBoxAvailable ? ' checked' : ' disabled') . '><span class="package-option-content"><span class="package-option-image">' . $fullImage . '</span><span><strong>풀박</strong><small>풀박스 구성' . ($fullBoxQty > 1 ? ' · ' . $fullBoxQty . '개입' : '') . ($fullBoxAvailable ? '' : ' · 재고 부족') . '</small><b>' . $compactPackagePrice($fullBoxPrice) . '</b></span></span></label><label class="detail-package-option"><input type="radio" name="detailPackage" value="loose"' . ($fullBoxAvailable ? '' : ' checked') . '><span class="package-option-content"><span class="package-option-image">' . $looseImage . '</span><span><strong>낱박</strong><small>패키지 내 개별 상품</small><b>' . $compactPackagePrice($loosePrice) . '</b></span></span></label></div></div>';
        }
        $detailImages = '';
        foreach ($detailGalleryImages as $image) {
            $detailImages .= '<img class="detail-image" src="/img/' . (int)$image['id'] . '" alt="' . e($product['name']) . ' 상세 이미지" loading="eager" decoding="async">';
        }
        $detailImages = $detailImages ? '<div class="detail-images" aria-label="상품 상세 이미지">' . $detailImages . '</div>' : '';
        $body = '<main class="detail-page"><div class="detail-breadcrumb"><a href="/">HOME</a><span>/</span><span>' . e($product['name']) . '</span></div><div class="detail-layout"><section class="detail-media"><div class="p-gallery detail-gallery">' . $gallery . '</div></section><section class="detail-info"><p class="detail-kicker">GINGMOJI COLLECTION</p><h1 class="p-name">' . e($product['name']) . '</h1><div class="' . ($showPrice ? 'p-price' : 'price hidden') . '">' . ($showPrice ? won($product['price']) : '가격은 방송 중 공개됩니다') . '</div><div class="p-meta">';
        if ($hasPackageOption && $showPrice) {
            $body = str_replace('<div class="p-price">' . won($product['price']) . '</div>', '<div class="p-price">구성별 가격</div>', $body);
        }
        if ((int)$product['is_soldout'] || (int)$product['stock'] <= 0) {
            $body .= '<span class="chip gray">품절</span>';
        } elseif ($s['show_stock']) {
            $body .= '<span class="chip">재고 ' . (int)$product['stock'] . '개</span>';
        }
        $body .= '</div>';
        $body .= $packageHtml;
        if (!empty($product['expected_shipping_text'])) $body .= '<p class="expected-shipping">예상 배송일 <strong>' . e($product['expected_shipping_text']) . '</strong></p>';
        if (!empty($product['short_description'])) {
            $body .= '<div class="p-desc">' . nl2br(e($product['short_description'])) . '</div>';
        }
        $optionHtml = '';
        if ($product['use_open_option']) {
            $optionHtml = '<div class="detail-option-label"><strong>개봉 방법을 골라 주세요</strong><span>수량별로 나눠 담을 수 있어요</span></div><div class="detail-option-row"><div><strong>라이브 개봉</strong><small>방송에서 바로 개봉해 드려요</small></div><div class="detail-stepper"><button type="button" id="detailOpenedMinus" aria-label="라이브 개봉 수량 줄이기">−</button><output id="detailOpenedQty">0</output><button type="button" id="detailOpenedPlus" aria-label="라이브 개봉 수량 늘리기">+</button></div></div><div class="detail-option-row"><div><strong>미개봉 발송</strong><small>포장 그대로 보내 드려요</small></div><div class="detail-stepper"><button type="button" id="detailUnopenedMinus" aria-label="미개봉 발송 수량 줄이기">−</button><output id="detailUnopenedQty">0</output><button type="button" id="detailUnopenedPlus" aria-label="미개봉 발송 수량 늘리기">+</button></div></div>';
        } else {
            $optionHtml = '<div class="detail-field"><span>수량</span><div class="detail-qty"><button type="button" id="detailMinus" aria-label="수량 줄이기">−</button><input id="detailQty" type="number" min="1" max="' . max(1, (int)$product['stock']) . '" value="1"><button type="button" id="detailPlus" aria-label="수량 늘리기">+</button></div></div>';
        }
        $body .= '<div class="detail-option-box">' . $optionHtml . '</div><div class="detail-total"><span>TOTAL</span><strong id="detailTotal">' . ($showPrice ? won($product['price']) : '방송 중 공개') . '</strong></div><div class="detail-actions"><button class="btn big soft" type="button" id="detailCartAdd">장바구니 담기</button><button class="btn big" type="button" id="detailBuyNow">바로 구매</button></div></section></div><nav class="detail-tabs" aria-label="상품 상세 메뉴"><a class="on" href="#detail-description">DETAIL</a></nav><section class="detail-description" id="detail-description"><h2>상품 상세정보</h2>' . $detailImages . ($product['description'] ? '<div class="p-desc detail-description-text">' . nl2br(e($product['description'])) . '</div>' : '<p class="detail-empty">상품 상세 설명을 준비 중입니다.</p>') . '</section></main>';
        if ($detailImages !== '') {
            $legacyDetailMarkup = $product['description'] ? '<div class="p-desc detail-description-text">' . nl2br(e($product['description'])) . '</div>' : '<p class="detail-empty">상품 상세 설명을 준비 중입니다.</p>';
            $orderedDetailMarkup = $product['description'] ? '<div class="p-desc detail-description-text">' . nl2br(e($product['description'])) . '</div>' . $detailImages : '<p class="detail-empty">상품 상세 설명을 준비 중입니다.</p>' . $detailImages;
            $body = str_replace($detailImages . $legacyDetailMarkup, $orderedDetailMarkup, $body);
        }
        $detailId = (int)$product['id'];
        $detailName = json_encode($product['name'], JSON_UNESCAPED_UNICODE);
        $detailImage = !empty($galleryImages[0]['id']) ? (int)$galleryImages[0]['id'] : 'null';
        $detailPrice = (int)$product['price'];
        $detailMax = max(1, (int)$product['stock']);
        $fullBoxMax = max(0, intdiv((int)$product['stock'], $fullBoxQty));
        $detailHasOption = $product['use_open_option'] ? 'true' : 'false';
        $detailScript = <<<'HTML'
<script>
(function () {
  var gallery = document.querySelector('.detail-gallery');
  if (gallery) {
    var slides = Array.prototype.slice.call(gallery.querySelectorAll('.thumb'));
    if (slides.length > 1) {
      var viewport = document.createElement('div');
      viewport.className = 'detail-gallery-viewport';
      gallery.parentNode.insertBefore(viewport, gallery);
      viewport.appendChild(gallery);
      var prev = document.createElement('button');
      var next = document.createElement('button');
      prev.type = 'button';
      next.type = 'button';
      prev.className = 'gallery-nav prev';
      next.className = 'gallery-nav next';
      prev.setAttribute('aria-label', 'Previous image');
      next.setAttribute('aria-label', 'Next image');
      prev.textContent = String.fromCharCode(8249);
      next.textContent = String.fromCharCode(8250);
      gallery.parentNode.parentNode.appendChild(prev);
      gallery.parentNode.parentNode.appendChild(next);
      var galleryIndex = 0;
      var syncGallery = function () {
        gallery.style.transform = 'translateX(-' + (galleryIndex * 100) + '%)';
        viewport.style.height = slides[galleryIndex].offsetHeight + 'px';
        prev.disabled = galleryIndex === 0;
        next.disabled = galleryIndex === slides.length - 1;
        gallery.setAttribute('aria-label', 'Product image ' + (galleryIndex + 1) + ' of ' + slides.length);
      };
      prev.addEventListener('click', function () {
        if (galleryIndex > 0) { galleryIndex -= 1; syncGallery(); }
      });
      next.addEventListener('click', function () {
        if (galleryIndex < slides.length - 1) { galleryIndex += 1; syncGallery(); }
      });
      window.addEventListener('resize', syncGallery);
      slides.forEach(function (slide) {
        var image = slide.querySelector('img');
        if (image) image.addEventListener('load', syncGallery);
      });
      syncGallery();
    }
  }
  var cartAdd = document.getElementById('detailCartAdd');
  var buyNow = document.getElementById('detailBuyNow');
  if (!cartAdd || !buyNow) return;
  var price = __PRICE__;
  var max = __MAX__;
  var live = __LIVE__;
  var hasOption = __OPTION__;
  var hasPackage = __PACKAGE__;
  var packageType = hasPackage ? (__FULL_MAX__ > 0 ? 'full' : 'loose') : 'standard';
  var packageMax = { full: __FULL_MAX__, loose: max, standard: max };
  var stockMax = max;
  var packagePrices = { full: __FULL_PRICE__, loose: __LOOSE_PRICE__, standard: price };
  var total = document.getElementById('detailTotal');
  var opened = 0;
  var unopened = 0;
  var qty = 1;
  function sync() {
    max = Math.max(hasPackage ? 0 : 1, packageMax[packageType] != null ? packageMax[packageType] : stockMax);
    if (hasOption) {
      if (opened + unopened > max) { unopened = Math.max(0, max - opened); opened = Math.min(opened, max); }
    } else {
      qty = Math.max(1, Math.min(Math.max(1, max), qty));
    }
    var count = hasOption ? opened + unopened : qty;
    if (hasOption) {
      document.getElementById('detailOpenedQty').textContent = opened;
      document.getElementById('detailUnopenedQty').textContent = unopened;
      document.getElementById('detailOpenedMinus').disabled = opened <= 0;
      document.getElementById('detailUnopenedMinus').disabled = unopened <= 0;
      document.getElementById('detailOpenedPlus').disabled = count >= max;
      document.getElementById('detailUnopenedPlus').disabled = count >= max;
    } else {
      var input = document.getElementById('detailQty');
      input.value = qty;
      document.getElementById('detailMinus').disabled = qty <= 1;
      document.getElementById('detailPlus').disabled = qty >= max;
    }
    total.textContent = ((packagePrices[packageType] || price) * count).toLocaleString('ko-KR') + '원';
    cartAdd.disabled = !live || count < 1 || count > max;
    buyNow.disabled = !live || count < 1 || count > max;
    cartAdd.textContent = !live ? '방송 중에만 구매 가능' : (count ? '장바구니 담기' : '수량을 골라 주세요');
    buyNow.textContent = !live ? '방송 중에만 구매 가능' : (count ? '바로 구매' : '수량을 골라 주세요');
  }
  function change(kind, delta) {
    if (kind === 'opened') opened = Math.max(0, Math.min(max - unopened, opened + delta));
    if (kind === 'unopened') unopened = Math.max(0, Math.min(max - opened, unopened + delta));
    sync();
  }
  if (hasPackage) {
    document.querySelectorAll('input[name="detailPackage"]').forEach(function (radio) {
      radio.addEventListener('change', function () { if (this.checked) { packageType = this.value; sync(); } });
    });
  }
  if (hasOption) {
    document.getElementById('detailOpenedMinus').addEventListener('click', function () { change('opened', -1); });
    document.getElementById('detailOpenedPlus').addEventListener('click', function () { change('opened', 1); });
    document.getElementById('detailUnopenedMinus').addEventListener('click', function () { change('unopened', -1); });
    document.getElementById('detailUnopenedPlus').addEventListener('click', function () { change('unopened', 1); });
  } else {
    document.getElementById('detailMinus').addEventListener('click', function () { qty = Math.max(1, qty - 1); sync(); });
    document.getElementById('detailPlus').addEventListener('click', function () { qty = Math.min(max, qty + 1); sync(); });
    document.getElementById('detailQty').addEventListener('input', function () { qty = Math.max(1, Math.min(max, parseInt(this.value || '1', 10) || 1)); sync(); });
  }
  function flyToCart(source) {
    var target = document.querySelector('[data-open-cart]');
    if (!target) return;
    var from = source.getBoundingClientRect();
    var to = target.getBoundingClientRect();
    var fly = document.createElement('span');
    var startX = from.left + from.width / 2 - 14;
    var startY = from.top + from.height / 2 - 14;
    fly.className = 'cart-fly';
    fly.textContent = '+';
    fly.style.left = startX + 'px';
    fly.style.top = startY + 'px';
    fly.style.setProperty('--fly-x', (to.left + to.width / 2 - 14 - startX) + 'px');
    fly.style.setProperty('--fly-y', (to.top + to.height / 2 - 14 - startY) + 'px');
    document.body.appendChild(fly);
    requestAnimationFrame(function () { fly.classList.add('is-flying'); });
    setTimeout(function () { fly.remove(); }, 700);
  }

  function makeLine() {
    var line = { productId: __ID__, name: __NAME__, price: packagePrices[packageType] || price, packageType: packageType, imageId: __IMAGE__, option: hasOption, opened: 0, unopened: 0, qty: 0 };
    if (hasOption) {
      line.opened = opened;
      line.unopened = unopened;
    } else {
      line.qty = qty;
    }
    return line;
  }

  function addToCart(redirect, source) {
    if (!live) return;
    var count = hasOption ? opened + unopened : qty;
    if (!count) return;
    var cart = [];
    try { cart = JSON.parse(localStorage.getItem('gm_cart_v1')) || []; } catch (e) {}
    var line = makeLine();
    if (redirect) {
      cart = [line];
    } else {
      var existing = cart.find(function (item) { return Number(item.productId) === __ID__ && (item.packageType || 'standard') === packageType; });
      if (existing) {
        existing.price = line.price;
        existing.name = line.name;
        existing.imageId = line.imageId;
        if (hasOption) {
          existing.opened = (existing.opened || 0) + line.opened;
          existing.unopened = (existing.unopened || 0) + line.unopened;
        } else {
          existing.qty = (existing.qty || 0) + line.qty;
        }
      } else {
        cart.push(line);
      }
    }
    localStorage.setItem('gm_cart_v1', JSON.stringify(cart));
    document.dispatchEvent(new CustomEvent('gm:cart-updated'));
    if (redirect) {
      location.href = '/checkout';
      return;
    }
    flyToCart(source || cartAdd);
    cartAdd.textContent = '장바구니에 담았어요';
    setTimeout(sync, 1400);
  }

  cartAdd.addEventListener('click', function () { addToCart(false, cartAdd); });
  buyNow.addEventListener('click', function () { addToCart(true, buyNow); });
  sync();
})();
</script>
HTML;
        $detailScript = str_replace(['__PRICE__', '__MAX__', '__LIVE__', '__OPTION__', '__PACKAGE__', '__FULL_PRICE__', '__LOOSE_PRICE__', '__SHOW_PRICE__', '__ID__', '__NAME__', '__IMAGE__', '__FULL_MAX__'], [(string)$detailPrice, (string)$detailMax, $s['live_on'] ? 'true' : 'false', $detailHasOption, $hasPackageOption ? 'true' : 'false', (string)$fullBoxPrice, (string)$loosePrice, 'true', (string)$detailId, $detailName, (string)$detailImage, (string)$fullBoxMax], $detailScript);
        $body .= $detailScript;
        page($product['name'], shop_body($body, true));
    }

    if ($path === '/login') {
        render_login();
    }
    if ($path === '/signup') {
        render_signup();
    }
    if ($path === '/logout') {
        session_destroy();
        redirect_to('/');
    }
    if ($path === '/checkout') {
        render_checkout();
    }
    if (preg_match('#^/orders/([^/]+)$#', $path, $match)) {
        render_order(rawurldecode($match[1]));
    }
    if ($path === '/my' || $path === '/my/orders' || $path === '/my/points') {
        render_my($path);
    }
    if (preg_match('#^/page/([a-z0-9_-]+)$#', $path, $match)) {
        render_policy($match[1]);
    }

    http_response_code(404);
    page('페이지 없음', '<main class="wrap page"><div class="panel"><h1>페이지를 찾을 수 없습니다.</h1><a class="btn" href="/">홈으로</a></div></main>');
}

function product_card(array $product, array $settings, bool $showPrice): string
{
    $soldout = (int)$product['is_soldout'] || (int)$product['stock'] <= 0;
    $stockLabel = $soldout ? '품절' : (($settings['show_stock'] && (int)$product['stock'] <= 3) ? '남은 ' . (int)$product['stock'] . '개' : '');
    return '<a class="card ' . ($soldout ? 'soldout' : '') . '" href="/p/' . (int)$product['id'] . '" data-product="' . (int)$product['id'] . '"><div class="thumb">' . (!empty($product['image_id']) ? '<img src="/img/' . (int)$product['image_id'] . '" alt="" loading="lazy">' : '<span class="ph">이미지</span>') . '</div><div class="name">' . e($product['name']) . '</div><div class="row"><div><div class="price ' . ($showPrice ? '' : 'hidden') . '" data-price-label>' . ($showPrice ? won($product['price']) : '방송 중 공개') . '</div><div class="stock-tag" data-stock-label>' . e($stockLabel) . '</div></div></div></a>';
}

function handle_image(int $id): never
{
    $stmt = db()->prepare('SELECT mime, data FROM product_images WHERE id = ?');
    $stmt->execute([$id]);
    $image = $stmt->fetch();
    if (!$image) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $image['mime']);
    header('Cache-Control: public, max-age=604800');
    echo $image['data'];
    exit;
}

function handle_review_image(int $id): never
{
    $stmt = db()->prepare('SELECT mime, data FROM product_review_images WHERE id = ?');
    $stmt->execute([$id]);
    $image = $stmt->fetch();
    if (!$image) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $image['mime']);
    header('Cache-Control: public, max-age=604800');
    echo $image['data'];
    exit;
}

function review_is_eligible(string $status): bool
{
    return in_array($status, ['paid', 'kept', 'preparing', 'shipped'], true);
}

function review_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function review_author_label(string $name): string
{
    $name = trim($name);
    if ($name === '') return '구매 고객';
    return (function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1)) . '**';
}

function uploaded_review_images(): array
{
    $files = $_FILES['photos'] ?? null;
    if (!$files || !is_array($files['name'] ?? null)) return [];
    $names = $files['name'];
    if (count($names) > 3) throw new RuntimeException('사진은 최대 3장까지 올릴 수 있어요.');
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $images = [];
    foreach (array_keys($names) as $index) {
        $error = (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('사진 업로드에 실패했어요.');
        $tmp = (string)($files['tmp_name'][$index] ?? '');
        $size = (int)($files['size'][$index] ?? 0);
        if (!is_uploaded_file($tmp) || $size < 1 || $size > 5 * 1024 * 1024) {
            throw new RuntimeException('사진은 장당 5MB 이하로 올려 주세요.');
        }
        $info = @getimagesize($tmp);
        $mime = (string)($info['mime'] ?? '');
        if (!$info || !in_array($mime, $allowed, true)) {
            throw new RuntimeException('JPG, PNG, WEBP, GIF 사진만 올릴 수 있어요.');
        }
        $data = file_get_contents($tmp);
        if ($data === false || $data === '') throw new RuntimeException('사진을 읽을 수 없어요.');
        $images[] = ['mime' => $mime, 'data' => $data];
    }
    return $images;
}

function recent_reviews(): array
{
    $stmt = db()->query('SELECT r.id,r.product_id,r.rating,r.content,r.created_at,p.name AS product_name,u.name AS reviewer_name FROM product_reviews r JOIN products p ON p.id=r.product_id JOIN users u ON u.id=r.user_id ORDER BY r.created_at DESC,r.id DESC LIMIT 30');
    $reviews = $stmt->fetchAll();
    if (!$reviews) return [];

    $marks = implode(',', array_fill(0, count($reviews), '?'));
    $ids = array_map(static fn(array $review): int => (int)$review['id'], $reviews);
    $imageStmt = db()->prepare('SELECT id,review_id FROM product_review_images WHERE review_id IN (' . $marks . ') ORDER BY review_id,sort_order,id');
    $imageStmt->execute($ids);
    $imagesByReview = [];
    foreach ($imageStmt->fetchAll() as $image) $imagesByReview[(int)$image['review_id']][] = $image;
    foreach ($reviews as &$review) $review['images'] = $imagesByReview[(int)$review['id']] ?? [];
    unset($review);
    return $reviews;
}

function review_stars(int $rating): string
{
    $rating = max(1, min(5, $rating));
    return '<span class="review-stars" aria-label="5점 만점 중 ' . $rating . '점">' . str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) . '</span>';
}

function review_write_targets(): array
{
    $user = current_user();
    if (!$user) return [];
    $stmt = db()->prepare("SELECT o.id AS order_id,o.order_no,o.status,oi.product_id,oi.product_name,MIN(oi.id) AS item_id FROM orders o JOIN order_items oi ON oi.order_id=o.id LEFT JOIN product_reviews r ON r.order_id=o.id AND r.product_id=oi.product_id WHERE o.user_id=? AND o.status IN ('paid','kept','preparing','shipped') AND r.id IS NULL GROUP BY o.id,o.order_no,o.status,oi.product_id,oi.product_name ORDER BY o.id DESC,item_id LIMIT 12");
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll();
}

function render_review_photos(array $images, string $altPrefix): string
{
    if (!$images) return '';
    $count = min(3, count($images));
    $html = '<div class="review-photos review-photos-' . $count . '" aria-label="후기 사진">';
    foreach ($images as $index => $image) {
        $html .= '<a href="/review-img/' . (int)$image['id'] . '" target="_blank" rel="noreferrer"><img src="/review-img/' . (int)$image['id'] . '" alt="' . e($altPrefix . ' 후기 사진 ' . ($index + 1)) . '" width="640" height="480" loading="lazy"></a>';
    }
    return $html . '</div>';
}

function render_reviews_section(array $reviews): string
{
    return render_reviews_section_modal($reviews);
    $entries = '';
    foreach ($reviews as $review) {
        $rating = (int)$review['rating'];
        $entries .= '<article class="review-entry"><div class="review-entry-head"><div>' . review_stars($rating) . '<span class="review-rating">' . $rating . '.0</span></div><time datetime="' . e((string)$review['created_at']) . '">' . e(dt($review['created_at'])) . '</time></div><div class="review-author"><span>' . e(review_author_label((string)$review['reviewer_name'])) . '</span><span class="review-verified">구매 인증</span></div><p class="review-content">' . nl2br(e($review['content'])) . '</p>' . render_review_photos((array)$review['images'], (string)$review['product_name']) . '<div class="review-purchase"><span class="review-purchase-label">구매한 품목</span><span class="review-item-chip">' . e($review['product_name']) . '</span></div></article>';
    }
    if ($entries === '') $entries = '<p class="review-empty">첫 리뷰를 기다리고 있어요.</p>';
    $writeTargets = review_write_targets();
    $writeCards = '';
    foreach ($writeTargets as $target) {
        $ratingOptions = '<option value="">별점을 선택해 주세요</option>';
        for ($rating = 5; $rating >= 1; $rating--) $ratingOptions .= '<option value="' . $rating . '">' . str_repeat('★', $rating) . ' ' . $rating . '점</option>';
        $writeCards .= '<article class="review-write-card"><div class="review-write-card-head"><div><span class="review-write-kicker">구매 인증</span><h3>' . e($target['product_name']) . '</h3><p>주문 ' . e($target['order_no']) . '</p></div></div><form class="review-form" method="post" action="/reviews" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="order_id" value="' . (int)$target['order_id'] . '"><input type="hidden" name="product_id" value="' . (int)$target['product_id'] . '"><fieldset><label class="field"><span>별점</span><select name="rating" required>' . $ratingOptions . '</select></label><label class="field"><span>후기</span><textarea name="content" minlength="5" maxlength="1000" required placeholder="상품을 받아본 느낌을 적어 주세요."></textarea></label><label class="field"><span>사진 <small>(선택, 최대 3장 · 장당 5MB)</small></span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-review-photo-input><small class="field-help">사진을 최대 3장까지 선택할 수 있어요.</small><div class="review-photo-preview" data-review-photo-preview hidden aria-live="polite"></div></label><button class="btn pink small" type="submit">리뷰 등록</button></fieldset></form></article>';
    }
    $writeBlock = $writeCards ? '<div class="review-write-panel"><div class="review-write-panel-head"><div><strong>구매한 상품에 리뷰를 남겨 주세요</strong><p>입금 완료된 주문의 상품마다 한 번씩 작성할 수 있어요.</p></div><span class="review-write-count">작성 가능 ' . count($writeTargets) . '개</span></div><div class="review-write-list">' . $writeCards . '</div></div>' : '';
    $viewer = current_user();
    if (!$viewer && !$writeBlock) $writeBlock = '<p class="review-login-prompt"><a class="link" href="/login">로그인</a>하면 구매한 상품의 후기를 남길 수 있어요.</p>';
    return '<section class="review-section" aria-labelledby="review-title"><div class="review-section-head"><div><h2 id="review-title">구매 고객 리뷰</h2><p>고객이 구매한 상품과 사진, 후기를 한눈에 볼 수 있어요.</p></div><div class="review-section-tools"><span class="review-count">' . count($reviews) . '개</span></div></div>' . $writeBlock . '<div class="review-feed">' . $entries . '</div></section>';
}

function render_reviews_section_modal(array $reviews): string
{
    $entries = '';
    foreach ($reviews as $review) {
        $rating = (int)$review['rating'];
        $entries .= '<article class="review-entry"><div class="review-entry-head"><div>' . review_stars($rating) . '<span class="review-rating">' . $rating . '.0</span></div><time datetime="' . e((string)$review['created_at']) . '">' . e(dt($review['created_at'])) . '</time></div><div class="review-author"><span>' . e(review_author_label((string)$review['reviewer_name'])) . '</span><span class="review-verified">구매 인증</span></div><p class="review-content">' . nl2br(e($review['content'])) . '</p>' . render_review_photos((array)$review['images'], (string)$review['product_name']) . '<div class="review-purchase"><span class="review-purchase-label">구매한 품목</span><span class="review-item-chip">' . e($review['product_name']) . '</span></div></article>';
    }
    if ($entries === '') $entries = '<p class="review-empty">첫 리뷰를 기다리고 있어요.</p>';

    $writeTargets = review_write_targets();
    $writeCards = '';
    $reviewSheets = '';
    foreach ($writeTargets as $target) {
        $modalId = 'reviewModal' . (int)$target['order_id'] . '_' . (int)$target['product_id'];
        $titleId = $modalId . 'Title';
        $fileId = $modalId . 'Photos';
        $ratingOptions = '<option value="">별점을 선택해 주세요</option>';
        for ($rating = 5; $rating >= 1; $rating--) $ratingOptions .= '<option value="' . $rating . '">' . str_repeat('★', $rating) . ' ' . $rating . '점</option>';

        $writeCards .= '<article class="review-write-card"><div class="review-write-card-head"><div><span class="review-write-kicker">구매 인증</span><h3>' . e($target['product_name']) . '</h3><p>주문 ' . e($target['order_no']) . '</p></div><button class="btn pink small" type="button" data-open-review-sheet="' . e($modalId) . '" aria-haspopup="dialog" aria-controls="' . e($modalId) . '">리뷰 작성</button></div></article>';

        $reviewSheets .= '<section class="sheet review-modal" id="' . e($modalId) . '" data-review-sheet hidden role="dialog" aria-modal="true" aria-labelledby="' . e($titleId) . '"><div class="sheet-grab" aria-hidden="true"></div><div class="sheet-head"><h2 id="' . e($titleId) . '">리뷰 작성</h2><button type="button" class="sheet-close" data-close-sheet aria-label="리뷰 작성 닫기">×</button></div><div class="sheet-body"><div class="review-modal-product"><span class="review-write-kicker">구매 인증</span><strong>' . e($target['product_name']) . '</strong><small>주문 ' . e($target['order_no']) . '</small></div><form class="review-form review-modal-form" method="post" action="/reviews" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="order_id" value="' . (int)$target['order_id'] . '"><input type="hidden" name="product_id" value="' . (int)$target['product_id'] . '"><fieldset><legend class="sr-only">' . e($target['product_name']) . ' 리뷰 입력</legend><label class="field"><span>별점</span><select name="rating" required>' . $ratingOptions . '</select></label><label class="field"><span>후기</span><textarea name="content" minlength="5" maxlength="1000" required placeholder="상품을 받아본 느낌을 적어 주세요."></textarea></label><div class="field review-file-field" data-review-photo-field><span>사진 <small>(선택, 최대 3장 · 장당 5MB)</small></span><div class="review-file-picker"><label class="review-file-button" for="' . e($fileId) . '"><span class="review-file-button-icon" aria-hidden="true">＋</span><span><strong>사진 추가</strong><small>후기에 사진을 함께 올려 보세요</small></span></label><input class="review-file-input" id="' . e($fileId) . '" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-review-photo-input><span class="review-file-name" data-review-file-name>선택된 사진 없음</span></div><small class="field-help">JPG, PNG, WEBP, GIF · 사진마다 5MB 이하</small><div class="review-photo-preview" data-review-photo-preview hidden aria-live="polite"></div></div><button class="btn pink block" type="submit">리뷰 등록</button></fieldset></form></div></section>';
    }

    $writeBlock = $writeCards ? '<div class="review-write-panel"><div class="review-write-panel-head"><div><strong>구매한 상품에 리뷰를 남겨 주세요</strong><p>입금 완료된 주문의 상품마다 한 번씩 작성할 수 있어요.</p></div><span class="review-write-count">작성 가능 ' . count($writeTargets) . '개</span></div><div class="review-write-list">' . $writeCards . '</div></div>' . $reviewSheets : '';
    $viewer = current_user();
    if (!$viewer && !$writeBlock) $writeBlock = '<p class="review-login-prompt"><a class="link" href="/login">로그인</a>하면 구매한 상품의 후기를 남길 수 있어요.</p>';
    return '<section class="review-section" aria-labelledby="review-title"><div class="review-section-head"><div><h2 id="review-title">구매 고객 리뷰</h2><p>고객이 구매한 상품과 사진, 후기를 한눈에 볼 수 있어요.</p></div><div class="review-section-tools"><span class="review-count">' . count($reviews) . '개</span></div></div>' . $writeBlock . '<div class="review-feed">' . $entries . '</div></section>';
}

function submit_review(): never
{
    $user = require_user();
    $orderId = (int)post_value('order_id');
    $productId = (int)post_value('product_id');
    $rating = (int)post_value('rating');
    $content = trim((string)post_value('content'));
    $orderStmt = db()->prepare('SELECT id,order_no,status FROM orders WHERE id=? AND user_id=?');
    $orderStmt->execute([$orderId, $user['id']]);
    $order = $orderStmt->fetch();
    $back = $order ? '/orders/' . rawurlencode((string)$order['order_no']) : '/my/orders';
    if (!$order || !review_is_eligible((string)$order['status'])) {
        flash('error', '결제가 완료된 주문만 리뷰를 작성할 수 있어요.');
        redirect_to($back);
    }
    if ($productId < 1 || $rating < 1 || $rating > 5 || $content === '' || review_text_length($content) < 5 || review_text_length($content) > 1000) {
        flash('error', '별점과 5~1,000자 후기를 입력해 주세요.');
        redirect_to($back);
    }
    $itemStmt = db()->prepare('SELECT product_name FROM order_items WHERE order_id=? AND product_id=? LIMIT 1');
    $itemStmt->execute([$orderId, $productId]);
    $item = $itemStmt->fetch();
    if (!$item) {
        flash('error', '구매한 상품만 리뷰를 작성할 수 있어요.');
        redirect_to($back);
    }
    $existingStmt = db()->prepare('SELECT id FROM product_reviews WHERE order_id=? AND product_id=? LIMIT 1');
    $existingStmt->execute([$orderId, $productId]);
    if ($existingStmt->fetch()) {
        flash('error', '이 주문의 상품 리뷰는 이미 작성했어요.');
        redirect_to($back);
    }
    try {
        $images = uploaded_review_images();
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO product_reviews (user_id,order_id,product_id,rating,content) VALUES (?,?,?,?,?)')->execute([$user['id'], $orderId, $productId, $rating, $content]);
        $reviewId = (int)$pdo->lastInsertId();
        $imageStmt = $pdo->prepare('INSERT INTO product_review_images (review_id,mime,data,sort_order) VALUES (?,?,?,?)');
        foreach ($images as $sort => $image) $imageStmt->execute([$reviewId, $image['mime'], $image['data'], $sort]);
        $pdo->commit();
        flash('ok', '상품 후기를 남겼어요.');
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        $duplicate = $error instanceof PDOException && $error->getCode() === '23000';
        flash('error', $duplicate ? '이 주문의 상품 리뷰는 이미 작성했어요.' : ($error->getMessage() ?: '리뷰를 저장하지 못했어요.'));
    }
    redirect_to($back);
}

function update_review(int $reviewId): never
{
    $user = require_user();
    $stmt = db()->prepare('SELECT r.*,o.order_no,p.name AS product_name FROM product_reviews r JOIN orders o ON o.id=r.order_id JOIN products p ON p.id=r.product_id WHERE r.id=? AND r.user_id=?');
    $stmt->execute([$reviewId, $user['id']]);
    $review = $stmt->fetch();
    $back = '/my#my-reviews';
    if (!$review) {
        flash('error', '내가 작성한 리뷰만 수정하거나 삭제할 수 있어요.');
        redirect_to($back);
    }
    if (post_value('action') === 'delete') {
        db()->prepare('DELETE FROM product_reviews WHERE id=? AND user_id=?')->execute([$reviewId, $user['id']]);
        flash('ok', '리뷰를 삭제했습니다.');
        redirect_to($back);
    }
    $rating = (int)post_value('rating');
    $content = trim((string)post_value('content'));
    if ($rating < 1 || $rating > 5 || $content === '' || review_text_length($content) < 5 || review_text_length($content) > 1000) {
        flash('error', '별점과 5~1,000자 후기를 입력해 주세요.');
        redirect_to($back);
    }
    try {
        $images = uploaded_review_images();
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE product_reviews SET rating=?,content=? WHERE id=? AND user_id=?')->execute([$rating, $content, $reviewId, $user['id']]);
        if ($images) {
            $pdo->prepare('DELETE FROM product_review_images WHERE review_id=?')->execute([$reviewId]);
            $imageStmt = $pdo->prepare('INSERT INTO product_review_images (review_id,mime,data,sort_order) VALUES (?,?,?,?)');
            foreach ($images as $sort => $image) $imageStmt->execute([$reviewId, $image['mime'], $image['data'], $sort]);
        }
        $pdo->commit();
        flash('ok', '리뷰를 수정했습니다.');
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        flash('error', $error->getMessage() ?: '리뷰를 수정하지 못했어요.');
    }
    redirect_to($back);
}

function handle_api(string $path): never
{
    if ($path === '/api/live') {
        $s = setting_values();
        $products = [];
        foreach (product_rows() as $product) {
            $products[] = ['id' => (int)$product['id'], 'price' => (int)$product['price'], 'stock' => (int)$product['stock'], 'soldout' => (bool)$product['is_soldout'] || (int)$product['stock'] <= 0];
        }
        json_out(['ok' => true, 'live' => (bool)$s['live_on'], 'showPrice' => true, 'showStock' => (bool)$s['show_stock'], 'products' => $products]);
    }

    if (preg_match('#^/api/products/(\d+)$#', $path, $match)) {
        $s = setting_values();
        $product = one_product((int)$match[1]);
        if (!$product || !(int)$product['is_visible']) {
            json_out(['ok' => false, 'message' => '상품을 찾을 수 없습니다.'], 404);
        }
        json_out(['ok' => true, 'live' => (bool)$s['live_on'], 'product' => ['id' => (int)$product['id'], 'name' => $product['name'], 'description' => $product['description'] ?? '', 'price' => (int)$product['price'], 'stock' => (int)$product['stock'], 'showStock' => (bool)$s['show_stock'], 'soldout' => (bool)$product['is_soldout'] || (int)$product['stock'] <= 0, 'usePackageOption' => (bool)$product['use_package_option'], 'fullBoxPrice' => (int)$product['full_box_price'], 'loosePrice' => (int)$product['loose_price'], 'fullBoxImageId' => (int)($product['full_box_image_id'] ?? 0), 'looseImageId' => (int)($product['loose_image_id'] ?? 0), 'expectedShippingText' => (string)($product['expected_shipping_text'] ?? ''), 'useOpenOption' => (bool)$product['use_open_option'], 'images' => array_map(static fn(array $image): int => (int)$image['id'], $product['images'])]]);
    }

    if ($path === '/api/cart/quote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        json_out(cart_quote(request_json()));
    }
    if ($path === '/api/orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        create_order(request_json());
    }
    json_out(['ok' => false, 'message' => 'API를 찾을 수 없습니다.'], 404);
}

function active_kept_orders(int $userId): array
{
    $stmt = db()->prepare("SELECT * FROM orders WHERE user_id=? AND status='kept' AND shipment_request_id IS NULL ORDER BY paid_at,id");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function active_kept_amount(int $userId): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(items_amount),0) FROM orders WHERE user_id=? AND status='kept' AND shipment_request_id IS NULL");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function cart_quote(array $input): array
{
    $settings = setting_values();
    $products = product_rows();
    $byId = [];
    foreach ($products as $product) {
        $byId[(int)$product['id']] = $product;
    }
    $lines = [];
    $itemsAmount = 0;
    $hasProblem = false;
    $unitsByProduct = [];
    foreach ((array)($input['lines'] ?? []) as $line) {
        $id = (int)($line['productId'] ?? 0);
        if (!isset($byId[$id])) continue;
        $product = $byId[$id];
        $packageType = in_array((string)($line['packageType'] ?? 'standard'), ['full', 'loose'], true) ? (string)$line['packageType'] : 'standard';
        if (!(int)$product['use_package_option']) $packageType = 'standard';
        $unitPrice = (int)$product['price'];
        if ($packageType === 'full' && (int)$product['full_box_price'] > 0) $unitPrice = (int)$product['full_box_price'];
        if ($packageType === 'loose' && (int)$product['loose_price'] > 0) $unitPrice = (int)$product['loose_price'];
        $packageLabel = ['full' => '풀박', 'loose' => '낱박', 'standard' => ''][ $packageType ];
        $qty = (int)($product['use_open_option'] ? ($line['opened'] ?? 0) + ($line['unopened'] ?? 0) : ($line['qty'] ?? 0));
        if ($qty < 1) continue;
        $unitsPer = package_units($product, $packageType);
        $stockQty = $qty * $unitsPer;
        $unitsByProduct[$id] = ($unitsByProduct[$id] ?? 0) + $stockQty;
        $problem = '';
        if (!$settings['live_on']) $problem = '방송이 종료되었습니다.';
        elseif ((int)$product['is_soldout']) $problem = '품절된 상품입니다.';
        elseif ($unitsByProduct[$id] > (int)$product['stock']) $problem = '재고가 부족합니다.';
        if ($problem) $hasProblem = true;
        $imageId = !empty($product['image_id']) ? (int)$product['image_id'] : null;
        $lineAmount = $unitPrice * $qty;
        $itemsAmount += $lineAmount;
        $lines[] = ['productId' => $id, 'name' => $product['name'], 'unitPrice' => $unitPrice, 'packageType' => $packageType, 'packageLabel' => $packageLabel, 'qty' => $qty, 'opened' => (int)($line['opened'] ?? 0), 'unopened' => (int)($line['unopened'] ?? 0), 'useOpenOption' => (bool)$product['use_open_option'], 'imageId' => $imageId, 'lineAmount' => $lineAmount, 'stockQty' => $stockQty, 'stock' => intdiv((int)$product['stock'], $unitsPer), 'problem' => $problem];
    }
    $user = current_user();
    $delivery = ($input['deliveryType'] ?? 'direct') === 'keep' ? 'keep' : 'direct';
    // 발송 대기 중인 결제완료 주문에 추가: 같은 택배로 나가므로 배송비를 다시 받지 않는다.
    $mergeOrder = null;
    $mergeOrderId = (int)($input['mergeOrderId'] ?? 0);
    if ($delivery === 'direct' && $mergeOrderId > 0 && $user) {
        foreach (mergeable_paid_orders((int)$user['id']) as $candidate) {
            if ((int)$candidate['id'] === $mergeOrderId) { $mergeOrder = $candidate; break; }
        }
    }
    $includeKept = $delivery === 'direct' && !$mergeOrder && !empty($input['includeKept']) && $user;
    $keptAmount = $includeKept ? active_kept_amount((int)$user['id']) : 0;
    $shippingBase = $itemsAmount + $keptAmount + ($mergeOrder ? (int)$mergeOrder['items_amount'] : 0);
    $shipping = ($delivery === 'keep' || $mergeOrder) ? 0 : ($shippingBase >= (int)$settings['free_shipping_threshold'] ? 0 : (int)$settings['shipping_fee']);
    $pointUse = 0;
    $maxPoints = 0;
    if ($user) {
        $pointUnit = 10000;
        $maxPoints = min((int)$user['point_balance'], max(0, $itemsAmount + $shipping));
        $maxPoints = (int)(floor($maxPoints / $pointUnit) * $pointUnit);
        $pointUse = $maxPoints;
    }
    $total = max(0, $itemsAmount + $shipping - $pointUse);
    return ['ok' => true, 'live' => (bool)$settings['live_on'], 'lines' => $lines, 'hasProblem' => $hasProblem, 'summary' => ['itemsAmount' => $itemsAmount, 'keptAmount' => $keptAmount, 'includeKept' => (bool)$includeKept, 'shippingBase' => $shippingBase, 'shippingFee' => $shipping, 'deliveryType' => $delivery, 'pointUsed' => $pointUse, 'total' => $total, 'maxPoints' => $maxPoints, 'mergeOrder' => $mergeOrder ? ['id' => (int)$mergeOrder['id'], 'orderNo' => (string)$mergeOrder['order_no']] : null, 'remainingForFree' => $mergeOrder ? 0 : max(0, (int)$settings['free_shipping_threshold'] - $shippingBase)]];
}

function create_order(array $input): never
{
    $user = require_user();
    $settings = setting_values();
    if (!$settings['live_on']) {
        json_out(['ok' => false, 'message' => '방송 중에만 주문할 수 있습니다.'], 400);
    }
    $liveSessionStartedAt = active_live_session_start($settings);
    $payment = ($input['paymentMethod'] ?? '') === 'bank' ? 'bank' : '';
    if ($payment !== 'bank') {
        json_out(['ok' => false, 'message' => '현재 계좌이체 주문만 가능합니다.'], 400);
    }
    $depositorName = trim((string)($input['depositorName'] ?? ''));
    if ($depositorName === '') {
        json_out(['ok' => false, 'message' => '입금자명을 입력해 주세요.'], 400);
    }
    if (function_exists('mb_strlen') ? mb_strlen($depositorName, 'UTF-8') > 50 : strlen($depositorName) > 150) {
        json_out(['ok' => false, 'message' => '입금자명은 50자 이내로 입력해 주세요.'], 400);
    }
    $recipient = (array)($input['recipient'] ?? []);
    $name = trim((string)($recipient['name'] ?? $user['name'] ?? ''));
    $phone = trim((string)($recipient['phone'] ?? $user['phone'] ?? ''));
    $address1 = trim((string)($recipient['address1'] ?? $user['address1'] ?? ''));
    if ($name === '' || $phone === '' || $address1 === '') {
        json_out(['ok' => false, 'message' => '받는 분 성함, 휴대폰, 주소를 입력해 주세요.'], 400);
    }
    $quote = cart_quote($input);
    if ($quote['hasProblem'] || !$quote['lines']) {
        json_out(['ok' => false, 'message' => '상품 재고를 다시 확인해 주세요.'], 400);
    }
    $mergeTarget = $quote['summary']['mergeOrder'] ?? null;
    if (!$mergeTarget && (int)($input['mergeOrderId'] ?? 0) > 0) {
        json_out(['ok' => false, 'message' => '선택한 주문이 더 이상 발송 대기 상태가 아니에요. 배송비를 다시 확인해 주세요.'], 400);
    }
    $receipt = (array)($input['cashReceipt'] ?? []);
    $receiptType = in_array(($receipt['type'] ?? 'none'), ['none', 'income', 'expense'], true) ? $receipt['type'] : 'none';
    $receiptValue = $receiptType === 'none' ? null : trim((string)($receipt['value'] ?? ''));
    if ($settings['cash_receipt_enabled'] && $receiptType !== 'none' && $receiptValue === '') {
        json_out(['ok' => false, 'message' => '현금영수증 번호를 입력해 주세요.'], 400);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $orderNo = 'GM' . date('ymdHis') . random_int(10, 99);
        $mergeOrderId = null;
        $parent = null;
        if ($mergeTarget) {
            $parentStmt = $pdo->prepare('SELECT * FROM orders WHERE id=? AND user_id=? FOR UPDATE');
            $parentStmt->execute([(int)$mergeTarget['id'], (int)$user['id']]);
            $parent = $parentStmt->fetch();
            if (!$parent || $parent['status'] !== 'paid' || $parent['delivery_type'] !== 'direct' || !empty($parent['merge_order_id'])) throw new RuntimeException('선택한 주문이 더 이상 발송 대기 상태가 아니에요. 배송비를 다시 확인해 주세요.');
            $mergeOrderId = (int)$parent['id'];
            // 같은 택배로 나가므로 받는 분 정보는 대표 주문을 따른다.
            $name = (string)$parent['recipient_name'];
            $phone = (string)$parent['recipient_phone'];
            $address1 = (string)$parent['address1'];
            $recipient['zipcode'] = (string)($parent['zipcode'] ?? '');
            $recipient['address2'] = (string)($parent['address2'] ?? '');
        }
        $delivery = $quote['summary']['deliveryType'];
        $includeKept = (bool)$quote['summary']['includeKept'];
        $keptAmount = (int)$quote['summary']['keptAmount'];
        $expires = date('Y-m-d H:i:s', time() + ((int)$settings['bank_auto_cancel_hours'] * 3600));
        $pointUsed = (int)$quote['summary']['pointUsed'];
        if ($pointUsed > 0) {
            $balanceStmt = $pdo->prepare('SELECT point_balance FROM users WHERE id = ? FOR UPDATE');
            $balanceStmt->execute([(int)$user['id']]);
            $balance = (int)$balanceStmt->fetchColumn();
            if ($balance < $pointUsed) throw new RuntimeException('포인트 잔액이 부족합니다.');
            $after = $balance - $pointUsed;
            $pdo->prepare('UPDATE users SET point_balance = ? WHERE id = ?')->execute([$after, $user['id']]);
            $pdo->prepare('INSERT INTO point_ledger (user_id,type,amount,balance_after,memo) VALUES (?,?,?,?,?)')->execute([$user['id'], 'use', -$pointUsed, $after, '주문 사용']);
        }
        foreach ($quote['lines'] as $line) {
            $stock = $pdo->prepare('SELECT stock,is_soldout FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $stock->execute([$line['productId']]);
            $row = $stock->fetch();
            if (!$row || $row['is_soldout'] || (int)$row['stock'] < (int)$line['stockQty']) throw new RuntimeException('재고가 변경되어 주문을 다시 시도해 주세요.');
            $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$line['stockQty'], $line['productId']]);
        }
        $stmt = $pdo->prepare('INSERT INTO orders (order_no,user_id,live_session_started_at,status,payment_method,delivery_type,keep_merge_requested,keep_merge_amount,merge_order_id,items_amount,shipping_fee,point_used,total_amount,recipient_name,recipient_phone,zipcode,address1,address2,memo,depositor_name,cash_receipt_type,cash_receipt_value,cash_receipt_status,youtube_nickname,reserve_expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$orderNo, $user['id'], $liveSessionStartedAt, 'pending', 'bank', $delivery, $includeKept ? 1 : 0, $keptAmount, $mergeOrderId, $quote['summary']['itemsAmount'], $quote['summary']['shippingFee'], $pointUsed, $quote['summary']['total'], $name, $phone, (string)($recipient['zipcode'] ?? ''), $address1, trim((string)($recipient['address2'] ?? '')), trim((string)($recipient['memo'] ?? '')), $depositorName, $receiptType, $receiptValue, $receiptType === 'none' ? 'none' : 'requested', $user['youtube_nickname'] ?? '', $expires]);
        $orderId = (int)$pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,package_type,unit_price,qty,stock_qty,qty_opened,qty_unopened,line_amount) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($quote['lines'] as $line) {
            $itemStmt->execute([$orderId, $line['productId'], $line['name'], $line['packageType'], $line['unitPrice'], $line['qty'], $line['stockQty'], $line['opened'], $line['unopened'], $line['lineAmount']]);
        }
        $pdo->prepare('INSERT INTO payments (target_type,target_id,method,amount,status) VALUES (?,?,?,?,?)')->execute(['order', $orderId, 'bank', $quote['summary']['total'], 'ready']);
        log_order_event($pdo, $orderId, 'created', null, 'pending', '주문 생성 · 입금 대기' . ($parent ? ' · 주문 ' . $parent['order_no'] . '에 추가 (합배송 · 배송비 없음)' : ''), 0, 'system', (int)$user['id']);
        if ($parent) log_order_event($pdo, $mergeOrderId, 'merge_added', null, null, '추가 주문 ' . $orderNo . ' 연결 · 입금 확인 후 함께 발송', 0, 'system', (int)$user['id']);
        // 합배송은 대표 주문의 과거 배송지를 따르므로 회원의 현재 기본 주소를 덮어쓰지 않는다.
        if (!empty($input['saveAddress']) && !$parent) {
            $pdo->prepare('UPDATE users SET name=?,phone=?,zipcode=?,address1=?,address2=? WHERE id=?')->execute([$name, $phone, $recipient['zipcode'] ?? '', $address1, $recipient['address2'] ?? '', $user['id']]);
        }
        $pdo->commit();
        json_out(['ok' => true, 'redirect' => '/orders/' . rawurlencode($orderNo)]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'message' => $error->getMessage()], 400);
    }
}

function render_login(): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $login = strtolower(trim((string)post_value('login_id')));
        $password = (string)post_value('password');
        $stmt = db()->prepare('SELECT * FROM users WHERE login_id = ?');
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, (string)$user['password_hash'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            unset($GLOBALS['user']);
            redirect_to((string)($_GET['next'] ?? '/'));
        }
        flash('error', '아이디 또는 비밀번호가 올바르지 않습니다.');
    }
    $body = '<main class="wrap page"><div class="panel auth"><h1>로그인</h1><form method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><label class="field"><span>아이디</span><input name="login_id" required autocomplete="username"></label><label class="field"><span>비밀번호</span><input name="password" type="password" required autocomplete="current-password"></label><button class="btn big block">로그인</button></form><p><a href="/signup">회원가입</a></p></div></main>';
    page('로그인', $body);
}

function render_signup(): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $login = strtolower(trim((string)post_value('login_id')));
        $password = (string)post_value('password');
        $name = trim((string)post_value('name'));
        $zipcode = trim((string)post_value('zipcode'));
        $address1 = trim((string)post_value('address1'));
        if (!preg_match('/^[a-z0-9_]{4,50}$/', $login) || strlen($password) < 8 || $name === '') {
            flash('error', '아이디는 영문·숫자·밑줄(_) 4~50자, 비밀번호는 8자 이상 입력해 주세요.');
        } elseif ($zipcode === '' || $address1 === '') {
            flash('error', '주소 검색으로 우편번호와 주소를 입력해 주세요.');
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO users (login_id,password_hash,name,phone,zipcode,address1,address2,youtube_nickname,profile_completed) VALUES (?,?,?,?,?,?,?,?,1)');
                $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT), $name, post_value('phone'), $zipcode, $address1, post_value('address2'), post_value('youtube_nickname')]);
                $_SESSION['user_id'] = (int)db()->lastInsertId();
                redirect_to('/');
            } catch (Throwable $error) {
                flash('error', '이미 사용 중인 아이디일 수 있습니다.');
            }
        }
    }
    $body = '<main class="wrap page"><div class="panel auth"><h1>회원가입</h1><p class="hint">아이디는 영문·숫자·밑줄(_) 4~50자, 비밀번호는 8자 이상 입력해 주세요. 카카오·구글 가입은 OAuth 키를 받은 뒤 연결합니다.</p><form method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><label class="field"><span>아이디</span><input name="login_id" pattern="[A-Za-z0-9_]{4,50}" title="영문, 숫자, 밑줄(_)을 사용해 4~50자로 입력해 주세요." autocomplete="username" required></label><label class="field"><span>비밀번호</span><input name="password" type="password" minlength="8" autocomplete="new-password" required></label><label class="field"><span>성함</span><input name="name" required></label><label class="field"><span>휴대폰</span><input name="phone" required></label><label class="field"><span>유튜브 닉네임</span><input name="youtube_nickname" required></label><label class="field"><span>주소</span><input name="zipcode"><input name="address1" placeholder="주소"><input name="address2" placeholder="상세주소"></label><button class="btn big block">가입하기</button></form></div></main>';
    $body = str_replace('<label class="field"><span>주소</span><input name="zipcode"><input name="address1" placeholder="주소"><input name="address2" placeholder="상세주소"></label>', '<label class="field address-field"><span>주소</span><div class="field-row address-search-row"><input class="grow" name="zipcode" data-signup-zipcode placeholder="우편번호" readonly required><button type="button" class="btn small ghost" data-address-search>주소 검색</button></div><input name="address1" data-signup-address placeholder="주소 검색으로 입력" readonly required><input name="address2" placeholder="상세주소" autocomplete="street-address"></label>', $body);
    page('회원가입', $body, false, ['https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js', '/static/js/signup.js?v=php1']);
}

function render_checkout(): never
{
    $user = require_user();
    $settings = setting_values();
    $pointGuide = e((string)$settings['point_guide_text']);
    $keptAmount = active_kept_amount((int)$user['id']);
    $keepMerge = $keptAmount > 0 ? '<div class="keep-merge" data-keep-merge hidden><label class="check"><input type="checkbox" name="includeKept" data-include-kept> 킵 보관 상품도 같이 배송받기 <span class="meta">(' . won($keptAmount) . ' 보관 중)</span></label><p class="hint">보관 중인 상품금액과 이번 주문을 합산해 8만 원 이상이면 배송비가 무료예요.</p></div>' : '';
    $mergeOptions = '';
    foreach (mergeable_paid_orders((int)$user['id']) as $index => $paidOrder) {
        $attrs = ' data-merge-no="' . e($paidOrder['order_no']) . '" data-merge-name="' . e($paidOrder['recipient_name']) . '" data-merge-phone="' . e($paidOrder['recipient_phone']) . '" data-merge-zipcode="' . e($paidOrder['zipcode']) . '" data-merge-address1="' . e($paidOrder['address1']) . '" data-merge-address2="' . e($paidOrder['address2']) . '"';
        $mergeOptions .= '<label class="check merge-option"><input type="radio" name="mergeOrderId" value="' . (int)$paidOrder['id'] . '"' . $attrs . ($index === 0 ? ' checked' : '') . '><span class="merge-option-body"><strong>' . e($paidOrder['order_no']) . ' <span class="meta">' . e(dt($paidOrder['paid_at'] ?: $paidOrder['created_at'])) . ' 결제</span></strong><small>' . e($paidOrder['item_names'] ?: '상품') . ' · ' . won($paidOrder['items_amount']) . '</small></span></label>';
    }
    $orderMerge = $mergeOptions !== '' ? '<div class="keep-merge order-merge-box" data-order-merge><p class="merge-title">발송 대기 중인 주문에 추가하기</p><p class="hint merge-hint">아직 발송 전인 주문과 한 택배로 보내드려요. 배송비가 다시 붙지 않고, 받는 분 정보는 그 주문과 같아요.</p>' . $mergeOptions . '<label class="check merge-option"><input type="radio" name="mergeOrderId" value="0"><span class="merge-option-body"><strong>따로 받기</strong><small>새 주문으로 배송비를 따로 계산해요</small></span></label></div>' : '';
    $body = '<main class="wrap page"><div class="page-title"><h1>주문서</h1></div><div class="panel"><div data-co-lines></div><div class="summary"><div class="sum-row"><span>상품금액</span><span data-sum-items>-</span></div><div class="sum-row"><span>배송비</span><span data-sum-ship>-</span></div><div class="sum-row" data-sum-point-row hidden><span>포인트</span><span data-sum-point>-</span></div><div class="sum-row total"><span>결제금액</span><span data-sum-total>-</span></div></div><p class="hint" data-free-hint></p><form data-addr-form><h2>배송 정보</h2><p class="hint" data-merge-addr-hint hidden></p><label class="field"><span>받는 분</span><input name="name" value="' . e($user['name']) . '"></label><label class="field"><span>휴대폰</span><input name="phone" value="' . e($user['phone']) . '"></label><label class="field"><span>우편번호</span><input name="zipcode" value="' . e($user['zipcode']) . '"></label><label class="field"><span>주소</span><input name="address1" value="' . e($user['address1']) . '"><input name="address2" value="' . e($user['address2']) . '" placeholder="상세주소"></label><label class="field"><span>배송 메모</span><input name="memo"></label><label class="check"><input type="checkbox" name="saveAddress" checked> 다음에도 이 주소 사용</label><button type="button" class="btn sm" data-edit-addr hidden>주소 수정</button><h2>배송 방법</h2><div class="choices delivery-choices"><label class="choice"><input type="radio" name="delivery" value="direct" checked><span class="tile"><span class="t">바로배송</span><span class="d">8만 원 이상 무료 · 미만 4,000원</span></span></label><label class="choice"><input type="radio" name="delivery" value="keep"><span class="tile"><span class="t">킵(보관)</span><span class="d">배송비 없이 보관 · 나중에 함께 배송</span></span></label></div>' . $orderMerge . $keepMerge . '<h2>결제 수단</h2><label class="check"><input type="radio" name="payment" value="bank" checked> 계좌이체</label><label class="field payment-depositor"><span>입금자명 <em class="required-mark">필수</em></span><input name="depositorName" maxlength="50" autocomplete="name" required></label><div data-cash-receipt><h3>현금영수증</h3><label class="check"><input type="radio" name="cashReceiptType" value="none" checked> 신청 안 함</label><label class="check"><input type="radio" name="cashReceiptType" value="income"> 소득공제용</label><label class="check"><input type="radio" name="cashReceiptType" value="expense"> 지출증빙용</label><input name="cashReceiptValue" data-cash-receipt-value placeholder="휴대폰 번호 또는 사업자등록번호" hidden></div><h2>포인트</h2><p class="hint">' . $pointGuide . '</p><p class="hint">보유 포인트가 10,000P 이상이면 결제 시 10,000P 단위로 자동 사용됩니다.</p><p class="hint" data-point-hint></p><button type="button" class="btn big block pink" data-place-order>주문하기</button></form></div></main>';
    $body = str_replace('<label class="field"><span>우편번호</span><input name="zipcode" value="' . e($user['zipcode']) . '"></label><label class="field"><span>주소</span><input name="address1" value="' . e($user['address1']) . '"><input name="address2" value="' . e($user['address2']) . '" placeholder="상세주소"></label>', '<label class="field"><span>주소</span><div class="field-row address-search-row"><input class="grow" name="zipcode" data-signup-zipcode value="' . e($user['zipcode']) . '" placeholder="우편번호" readonly required><button type="button" class="btn small ghost" data-address-search>주소 검색</button></div><input name="address1" data-signup-address value="' . e($user['address1']) . '" placeholder="주소 검색으로 입력" readonly required><input name="address2" value="' . e($user['address2']) . '" placeholder="상세주소" autocomplete="street-address"></label>', $body);
    page('주문서', $body, false, ['https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js', '/static/js/signup.js?v=php1', '/static/js/checkout.js?v=php7']);
}

function render_order(string $orderNo): never
{
    $user = require_user();
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_no = ? AND user_id = ?');
    $stmt->execute([$orderNo, $user['id']]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        page('주문 없음', '<main class="wrap page"><div class="panel"><h1>주문을 찾을 수 없습니다.</h1></div></main>');
    }
    $items = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $items->execute([$order['id']]);
    $itemRows = $items->fetchAll();
    $reviewStmt = db()->prepare('SELECT r.*,p.name AS product_name FROM product_reviews r JOIN products p ON p.id=r.product_id WHERE r.order_id=? AND r.user_id=? ORDER BY r.id');
    $reviewStmt->execute([$order['id'], $user['id']]);
    $reviewsByProduct = [];
    foreach ($reviewStmt->fetchAll() as $review) {
        $imageStmt = db()->prepare('SELECT id FROM product_review_images WHERE review_id=? ORDER BY sort_order,id');
        $imageStmt->execute([(int)$review['id']]);
        $review['images'] = $imageStmt->fetchAll();
        $reviewsByProduct[(int)$review['product_id']] = $review;
    }
    $rows = '';
    $reviewProducts = [];
    foreach ($itemRows as $item) {
        $rows .= '<li>' . e($item['product_name']) . ($item['package_type'] === 'full' ? ' · 풀박' : ($item['package_type'] === 'loose' ? ' · 낱박' : '')) . ' × ' . (int)$item['qty'] . ' <span>' . won($item['line_amount']) . '</span></li>';
        $reviewProducts[(int)$item['product_id']] = ['id' => (int)$item['product_id'], 'name' => (string)$item['product_name']];
    }
    $reviewCards = '';
    foreach ($reviewProducts as $productId => $product) {
        $review = $reviewsByProduct[$productId] ?? null;
        if ($review) {
            $reviewCards .= '<article class="order-review-card review-written"><div class="order-review-title"><h3>' . e($product['name']) . '</h3><span class="review-verified">작성 완료</span></div><div>' . review_stars((int)$review['rating']) . '</div><p class="review-content">' . nl2br(e($review['content'])) . '</p>' . render_review_photos((array)$review['images'], $product['name']) . '</article>';
        } elseif (review_is_eligible((string)$order['status'])) {
            $reviewCards .= '<article class="order-review-card"><form class="review-form" method="post" action="/reviews" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="order_id" value="' . (int)$order['id'] . '"><input type="hidden" name="product_id" value="' . $productId . '"><fieldset><legend>' . e($product['name']) . ' 후기 남기기</legend><label class="field"><span>별점</span><select name="rating" required><option value="">별점을 선택해 주세요</option><option value="5">★★★★★ 아주 좋아요</option><option value="4">★★★★ 좋아요</option><option value="3">★★★ 괜찮아요</option><option value="2">★★ 아쉬워요</option><option value="1">★ 별로예요</option></select></label><label class="field"><span>후기</span><textarea name="content" minlength="5" maxlength="1000" required placeholder="상품을 받아본 느낌을 적어 주세요."></textarea></label><label class="field"><span>사진 <small>(선택, 최대 3장 · 장당 5MB)</small></span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-review-photo-input><small class="field-help">사진을 최대 3장까지 선택할 수 있어요. 선택한 사진은 아래에서 미리 볼 수 있어요.</small><div class="review-photo-preview" data-review-photo-preview hidden aria-live="polite"></div></label><button class="btn pink small" type="submit">리뷰 등록</button></fieldset></form></article>';
        } else {
            $reviewCards .= '<article class="order-review-card review-locked"><h3>' . e($product['name']) . '</h3><p>결제가 완료된 뒤 상품 후기를 작성할 수 있어요.</p></article>';
        }
    }
    $reviewBlock = '<section class="order-reviews" aria-labelledby="order-review-title"><h2 id="order-review-title">상품 후기</h2><p class="hint">구매한 상품마다 한 번씩 후기를 남길 수 있어요.</p><div class="order-review-list">' . $reviewCards . '</div></section>';
    $settings = setting_values();
    $receipt = $order['cash_receipt_type'] === 'none' ? '신청 안 함' : ($order['cash_receipt_type'] === 'income' ? '소득공제용' : '지출증빙용');
    $deliveryLabel = $order['delivery_type'] === 'keep' ? '킵(보관)' : '바로배송';
    $mergeNotice = !empty($order['keep_merge_requested']) ? '<br>킵 보관 상품 ' . won($order['keep_merge_amount']) . ' 같이 배송' : '';
    foreach (merge_group_orders(db(), $order) as $member) {
        if ((int)$member['id'] === (int)$order['id']) continue;
        $mergeNotice .= empty($member['merge_order_id'])
            ? '<br>주문 ' . e($member['order_no']) . '에 추가 · 함께 배송 (배송비 없음)'
            : '<br>추가 주문 ' . e($member['order_no']) . ' · ' . e(order_status_label($member)) . ' · 함께 배송';
    }
    $body = '<main class="wrap page"><div class="panel"><h1>주문 완료</h1><p>주문번호 <strong>' . e($order['order_no']) . '</strong></p><p>상태: ' . e(order_status_label($order)) . '<br>배송 방식: ' . e($deliveryLabel) . $mergeNotice . '</p><ul class="order-items">' . $rows . '</ul>' . $reviewBlock . '<div class="sum-row total"><span>결제금액</span><span>' . won($order['total_amount']) . '</span></div><div class="notice">입금 계좌: ' . e($settings['bank_name']) . ' ' . e($settings['bank_account']) . ' (' . e($settings['bank_holder']) . ')<br>입금자명: ' . e($order['depositor_name']) . '<br>현금영수증: ' . e($receipt) . '</div><a class="btn block" href="/my/orders">주문 내역 보기</a></div></main>';
    page('주문 완료', $body);
}

function render_my(string $path): never
{
    $user = require_user();
    if ($path === '/my/points') {
        $stmt = db()->prepare('SELECT * FROM point_ledger WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$user['id']]);
        $rows = '';
        foreach ($stmt as $entry) $rows .= '<tr><td>' . e(dt($entry['created_at'])) . '</td><td>' . e($entry['memo']) . '</td><td>' . num($entry['amount']) . 'P</td><td>' . num($entry['balance_after']) . 'P</td></tr>';
        page('포인트', '<main class="wrap page"><div class="panel"><h1>포인트 ' . num($user['point_balance']) . 'P</h1><p class="hint">' . e(setting_values()['point_guide_text']) . '</p><table><tbody>' . $rows . '</tbody></table></div></main>');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_value('action') === 'profile') {
        $name = trim((string)post_value('name'));
        $phone = trim((string)post_value('phone'));
        $youtube = trim((string)post_value('youtube_nickname'));
        $newPassword = (string)post_value('new_password');
        $zipcode = trim((string)post_value('zipcode'));
        $address1 = trim((string)post_value('address1'));
        $address2 = trim((string)post_value('address2'));
        if ($name === '' || $phone === '' || $youtube === '') {
            flash('error', '성함, 휴대폰, 유튜브 닉네임을 입력해 주세요.');
        } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
            flash('error', '새 비밀번호는 8자 이상 입력해 주세요.');
        } else {
            if ($newPassword !== '') {
                db()->prepare('UPDATE users SET password_hash=?,name=?,phone=?,youtube_nickname=?,zipcode=?,address1=?,address2=?,profile_completed=1 WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $name, $phone, $youtube, $zipcode, $address1, $address2, $user['id']]);
            } else {
                db()->prepare('UPDATE users SET name=?,phone=?,youtube_nickname=?,zipcode=?,address1=?,address2=?,profile_completed=1 WHERE id=?')->execute([$name, $phone, $youtube, $zipcode, $address1, $address2, $user['id']]);
            }
            flash('ok', '회원 정보가 수정되었습니다.');
            redirect_to('/my');
        }
        $user = array_merge($user, ['name' => $name, 'phone' => $phone, 'youtube_nickname' => $youtube, 'zipcode' => $zipcode, 'address1' => $address1, 'address2' => $address2]);
    }

    $settings = setting_values();
    $keptStmt = db()->prepare("SELECT * FROM orders WHERE user_id=? AND status='kept' AND shipment_request_id IS NULL ORDER BY paid_at,id");
    $keptStmt->execute([$user['id']]);
    $keptRows = '';
    $keptAmount = 0;
    foreach ($keptStmt as $order) {
        $keptAmount += (int)$order['items_amount'];
        $keptRows .= '<a class="item my-order-item" href="/orders/' . e($order['order_no']) . '"><div class="top-row"><strong>킵 보관 · ' . e($order['order_no']) . '</strong><span class="meta">' . e(dt($order['paid_at'] ?: $order['created_at'])) . '</span></div><div class="my-order-row"><span>' . e(order_status_label($order)) . '</span><b>' . won($order['items_amount']) . '</b></div></a>';
    }

    $orderStmt = db()->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 30');
    $orderStmt->execute([$user['id']]);
    $orderRows = '';
    foreach ($orderStmt as $order) {
        $deliveryLabel = $order['delivery_type'] === 'keep' ? '킵(보관)' : (!empty($order['merge_order_id']) ? '바로배송 · 추가 주문' : '바로배송');
        $orderRows .= '<a class="item my-order-item" href="/orders/' . e($order['order_no']) . '"><div class="top-row"><strong>' . e($order['order_no']) . '</strong><span class="meta">' . e(dt($order['created_at'])) . '</span></div><div class="my-order-row"><span>' . e($deliveryLabel . ' · ' . order_status_label($order)) . '</span><b>' . won($order['total_amount']) . '</b></div></a>';
    }

    $reviewStmt = db()->prepare('SELECT r.*,p.name AS product_name,o.order_no FROM product_reviews r JOIN products p ON p.id=r.product_id JOIN orders o ON o.id=r.order_id WHERE r.user_id=? ORDER BY r.created_at DESC,r.id DESC LIMIT 30');
    $reviewStmt->execute([$user['id']]);
    $reviewImageStmt = db()->prepare('SELECT id FROM product_review_images WHERE review_id=? ORDER BY sort_order,id');
    $myReviewRows = '';
    $myReviewData = $reviewStmt->fetchAll();
    foreach ($myReviewData as $review) {
        $reviewImageStmt->execute([(int)$review['id']]);
        $reviewImages = $reviewImageStmt->fetchAll();
        $ratingOptions = '';
        for ($rating = 5; $rating >= 1; $rating--) $ratingOptions .= '<option value="' . $rating . '" ' . ((int)$review['rating'] === $rating ? 'selected' : '') . '>' . str_repeat('★', $rating) . ' ' . $rating . '점</option>';
        $editForm = '<form class="review-edit-form" method="post" action="/reviews/' . (int)$review['id'] . '" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="update"><label class="field"><span>별점</span><select name="rating" required>' . $ratingOptions . '</select></label><label class="field"><span>후기</span><textarea name="content" minlength="5" maxlength="1000" required>' . e($review['content']) . '</textarea></label><label class="field"><span>사진 수정 <small>(선택 시 기존 사진을 교체)</small></span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-review-photo-input><small class="field-help">최대 3장 · 장당 5MB</small><div class="review-photo-preview" data-review-photo-preview hidden aria-live="polite"></div></label><button class="btn pink small" type="submit">리뷰 수정</button></form>';
        $deleteForm = '<form class="review-delete-form" method="post" action="/reviews/' . (int)$review['id'] . '" onsubmit="return confirm(\'이 리뷰를 삭제할까요?\');"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="delete"><button class="btn ghost small" type="submit">리뷰 삭제</button></form>';
        $myReviewRows .= '<article class="my-review-card"><div class="my-review-head"><div><h3>' . e($review['product_name']) . '</h3><p>주문 ' . e($review['order_no']) . ' · ' . e(dt($review['created_at'])) . '</p></div>' . review_stars((int)$review['rating']) . '</div>' . render_review_photos($reviewImages, (string)$review['product_name']) . $editForm . $deleteForm . '</article>';
    }
    $myReviews = '<section class="panel my-section my-reviews" id="my-reviews"><div class="section-head"><div><h2>내가 남긴 리뷰</h2><p class="page-sub">작성한 상품 후기와 사진을 확인하고 수정할 수 있어요.</p></div><span class="review-count">' . num(count($myReviewData)) . '개</span></div>' . ($myReviewRows ?: '<p class="my-empty">아직 작성한 리뷰가 없어요. 주문 완료 후 상품 후기를 남겨 보세요.</p>') . '</section>';

    $profile = '<form method="post" class="profile-form"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="profile"><div class="profile-grid"><label class="field"><span>아이디</span><input value="' . e($user['login_id']) . '" readonly></label><label class="field"><span>성함</span><input name="name" value="' . e($user['name']) . '" required></label><label class="field"><span>휴대폰</span><input name="phone" value="' . e($user['phone']) . '" required></label><label class="field"><span>유튜브 닉네임</span><input name="youtube_nickname" value="' . e($user['youtube_nickname']) . '" required></label><label class="field"><span>새 비밀번호</span><input name="new_password" type="password" minlength="8" placeholder="변경할 때만 입력" autocomplete="new-password"></label></div><label class="field"><span>주소</span><div class="field-row address-search-row"><input class="grow" name="zipcode" data-signup-zipcode value="' . e($user['zipcode']) . '" placeholder="우편번호" readonly><button type="button" class="btn small ghost" data-address-search>주소 검색</button></div><input name="address1" data-signup-address value="' . e($user['address1']) . '" placeholder="주소 검색으로 입력" readonly><input name="address2" value="' . e($user['address2']) . '" placeholder="상세주소" autocomplete="street-address"></label><button class="btn big block pink">회원 정보 저장</button></form>';
    $keepSummary = $keptRows ? '<p class="hint">현재 <strong>' . won($keptAmount) . '</strong> 보관 중이에요. 상품을 더 담은 뒤 주문서에서 <strong>킵 상품 같이 배송받기</strong>를 선택할 수 있어요. 누적 ' . won($settings['free_shipping_threshold']) . ' 이상이면 배송비가 무료예요.</p>' : '<p class="my-empty">현재 보관 중인 킵 상품이 없어요.</p>';
    $body = '<main class="wrap page"><div class="page-title"><h1>마이페이지</h1></div><section class="panel profile-panel"><div class="my-heading"><div><h2>' . e($user['name'] ?: $user['login_id']) . '님</h2><p class="hint">가입 정보를 확인하고 수정할 수 있어요.</p></div><div class="my-heading-actions"><a class="btn sm soft" href="/my/points">포인트 ' . num($user['point_balance']) . 'P</a><a class="btn sm" href="/logout">로그아웃</a></div></div>' . $profile . '</section><section class="panel my-section"><div class="section-head"><h2>킵 보관함</h2><a class="btn sm ghost" href="/">상품 더 담기</a></div>' . $keepSummary . '<div class="list">' . ($keptRows ?: '') . '</div></section>' . $myReviews . '<section class="panel my-section"><h2>최근 주문</h2><div class="list">' . ($orderRows ?: '<p class="my-empty">아직 주문 내역이 없어요.</p>') . '</div></section></main>';
    page('마이페이지', shop_body($body, true), false, ['https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js', '/static/js/signup.js?v=php1']);
}

function render_policy(string $slug): never
{
    $stmt = db()->prepare('SELECT * FROM pages WHERE slug = ?');
    $stmt->execute([$slug]);
    $page = $stmt->fetch();
    if (!$page) {
        http_response_code(404);
        page('페이지 없음', '<main class="wrap page"><div class="panel"><h1>페이지를 찾을 수 없습니다.</h1></div></main>');
    }
    $content = nl2br(e(str_replace(['{{상호}}', '{{연락처}}'], [setting_values()['biz_name'], setting_values()['biz_phone']], (string)$page['content'])));
    page($page['title'], '<main class="wrap page"><div class="panel"><h1>' . e($page['title']) . '</h1><div class="p-desc">' . $content . '</div></div></main>');
}

function admin_shell(string $title, string $content): never
{
    page($title, $content, true);
}

function admin_date_value(mixed $value, string $fallback): string
{
    $value = (string)$value;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function admin_period_label(string $key, string $group): string
{
    if ($group === 'week') return $key . ' 주차';
    if ($group === 'month') {
        $date = DateTimeImmutable::createFromFormat('!Y-m', $key);
        return $date ? $date->format('Y년 m월') : $key;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $key);
    return $date ? $date->format('Y.m.d') : $key;
}

function admin_dashboard(): never
{
    $pdo = db();
    $settings = setting_values();
    $today = date('Y-m-d');
    $firstOrderDate = (string)$pdo->query("SELECT COALESCE(MIN(DATE(COALESCE(paid_at,created_at))), DATE_SUB(CURRENT_DATE, INTERVAL 29 DAY)) FROM orders")->fetchColumn();
    $from = admin_date_value($_GET['from'] ?? '', $firstOrderDate ?: $today);
    $to = admin_date_value($_GET['to'] ?? '', $today);
    if ($from > $to) [$from, $to] = [$to, $from];
    $group = in_array((string)($_GET['group'] ?? 'day'), ['day', 'week', 'month'], true) ? (string)$_GET['group'] : 'day';
    $productId = max(0, (int)($_GET['product_id'] ?? 0));
    $paidStatuses = "'paid','kept','preparing','shipped'";
    $where = "o.paid_at IS NOT NULL AND o.status IN ($paidStatuses) AND o.paid_at >= ? AND o.paid_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $params = [$from, $to];
    if ($productId > 0) {
        $where .= ' AND EXISTS (SELECT 1 FROM order_items filter_items WHERE filter_items.order_id=o.id AND filter_items.product_id=?)';
        $params[] = $productId;
    }
    $periodExpression = $group === 'month' ? "DATE_FORMAT(o.paid_at,'%Y-%m')" : ($group === 'week' ? "DATE_FORMAT(o.paid_at,'%x-W%v')" : 'DATE(o.paid_at)');
    $periodStmt = $pdo->prepare("SELECT $periodExpression AS period_key, COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(oi.qty),0) AS item_qty, COALESCE(SUM(oi.line_amount),0) AS revenue FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id WHERE $where GROUP BY period_key ORDER BY period_key DESC");
    $periodStmt->execute($params);
    $periodRows = '';
    foreach ($periodStmt as $row) {
        $periodRows .= '<tr><td><strong>' . e(admin_period_label((string)$row['period_key'], $group)) . '</strong></td><td class="right num">' . num($row['order_count']) . '건</td><td class="right num">' . num($row['item_qty']) . '개</td><td class="right num amount">' . won($row['revenue']) . '</td></tr>';
    }
    if ($periodRows === '') $periodRows = '<tr><td colspan="4" class="empty-cell">조회된 결제 완료 판매 기록이 없습니다.</td></tr>';
    $summaryStmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(o.total_amount),0) AS revenue, COALESCE(SUM(oi.qty),0) AS item_qty FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id WHERE $where");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: ['order_count' => 0, 'revenue' => 0, 'item_qty' => 0];
    $cancelWhere = "o.paid_at IS NOT NULL AND o.status='cancelled' AND o.cancelled_at >= ? AND o.cancelled_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $cancelParams = [$from, $to];
    if ($productId > 0) {
        $cancelWhere .= ' AND EXISTS (SELECT 1 FROM order_items filter_cancel_items WHERE filter_cancel_items.order_id=o.id AND filter_cancel_items.product_id=?)';
        $cancelParams[] = $productId;
    }
    $cancelStmt = $pdo->prepare("SELECT COUNT(*) AS order_count, COALESCE(SUM(o.total_amount),0) AS amount FROM orders o WHERE $cancelWhere");
    $cancelStmt->execute($cancelParams);
    $cancelled = $cancelStmt->fetch() ?: ['order_count' => 0, 'amount' => 0];
    $productStmt = $pdo->prepare("SELECT oi.product_id, oi.product_name, SUM(oi.qty) AS quantity, SUM(oi.line_amount) AS amount, COUNT(DISTINCT o.id) AS order_count FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE $where GROUP BY oi.product_id,oi.product_name ORDER BY amount DESC,quantity DESC");
    $productStmt->execute($params);
    $productRows = '';
    foreach ($productStmt as $row) $productRows .= '<tr><td><strong>' . e($row['product_name']) . '</strong><span class="table-sub">' . num($row['order_count']) . '건 주문</span></td><td class="right num">' . num($row['quantity']) . '개</td><td class="right num amount">' . won($row['amount']) . '</td></tr>';
    if ($productRows === '') $productRows = '<tr><td colspan="3" class="empty-cell">조회된 판매 상품이 없습니다.</td></tr>';
    $counts = ['products' => (int)$pdo->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL')->fetchColumn(), 'orders' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(), 'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()];
    $productOptions = '<option value="0">전체 상품</option>';
    foreach (product_rows(true) as $product) $productOptions .= '<option value="' . (int)$product['id'] . '" ' . ($productId === (int)$product['id'] ? 'selected' : '') . '>' . e($product['name']) . '</option>';
    $dashboard = '<div class="page-head"><div><h1>판매 대시보드</h1><p class="page-sub">결제 완료 주문은 취소·반품 여부와 관계없이 주문 기록으로 남아 있습니다.</p></div><span class="chip ' . ($settings['live_on'] ? 'paid' : '') . '">' . ($settings['live_on'] ? 'LIVE 방송 중' : '방송 OFF') . '</span></div>'
        . '<form class="card history-filters" method="get" action="/admin"><div class="filter-title"><div><h2>판매 기록 조회</h2><p>전체 기간을 일별·주간·월간 또는 상품별로 다시 확인할 수 있어요.</p></div><a class="btn sm ghost" href="/admin/orders">주문별 원장 보기</a></div><div class="filter-grid"><label class="field"><span>시작일</span><input type="date" name="from" value="' . e($from) . '"></label><label class="field"><span>종료일</span><input type="date" name="to" value="' . e($to) . '"></label><label class="field"><span>집계 단위</span><select name="group"><option value="day" ' . ($group === 'day' ? 'selected' : '') . '>일별</option><option value="week" ' . ($group === 'week' ? 'selected' : '') . '>주간</option><option value="month" ' . ($group === 'month' ? 'selected' : '') . '>월간</option></select></label><label class="field"><span>상품</span><select name="product_id">' . $productOptions . '</select></label><div class="filter-submit"><button class="btn pink">조회하기</button><a class="btn ghost" href="/admin">전체 기간 초기화</a></div></div></form>'
        . '<div class="stats"><div class="stat alert"><span class="k">조회 매출</span><b class="num">' . won($summary['revenue']) . '</b><span class="s">결제 완료 기준</span></div><div class="stat"><span class="k">판매 수량</span><b class="num">' . num($summary['item_qty']) . '개</b><span class="s">상품 수량 합계</span></div><div class="stat"><span class="k">결제 완료 주문</span><b class="num">' . num($summary['order_count']) . '건</b><span class="s">조회 기간 기준</span></div><div class="stat"><span class="k">취소·반품</span><b class="num">' . num($cancelled['order_count']) . '건</b><span class="s">' . won($cancelled['amount']) . '</span></div></div>'
        . '<div class="dashboard-analytics"><section class="card analytics-card"><div class="card-heading"><div><h2>' . ($group === 'day' ? '일별' : ($group === 'week' ? '주간' : '월간')) . ' 매출</h2><p>' . e($from) . ' ~ ' . e($to) . ' 결제 완료 기록</p></div><span class="analytics-icon">₩</span></div><div class="table-wrap"><table class="analytics-table"><thead><tr><th>기간</th><th class="right">주문</th><th class="right">수량</th><th class="right">매출</th></tr></thead><tbody>' . $periodRows . '</tbody></table></div></section><section class="card analytics-card"><div class="card-heading"><div><h2>상품별 판매</h2><p>선택한 기간에 결제 완료된 모든 상품 기록</p></div><span class="analytics-icon">TOP</span></div><div class="table-wrap"><table class="analytics-table"><thead><tr><th>상품</th><th class="right">수량</th><th class="right">판매액</th></tr></thead><tbody>' . $productRows . '</tbody></table></div></section></div>'
        . '<section class="card live-card"><div><h2>주문 처리 기준</h2><p>계좌이체는 입금 대기 → 입금 확인·결제 완료 → 배송준비중 → 배송완료 순서로 처리합니다. 결제 완료 주문은 주문 원장에 계속 보관됩니다.</p></div><a class="btn soft" href="/admin/orders">전체 주문 원장 열기</a></section><p class="dashboard-footnote">등록 상품 ' . num($counts['products']) . '개 · 회원 ' . num($counts['users']) . '명 · 입금 대기 ' . num($counts['orders']) . '건</p>';
    admin_shell('판매 대시보드', $dashboard);
}

function handle_admin(string $path): never
{
    if ($path === '/admin/login') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = (string)post_value('admin_id');
            $password = (string)post_value('admin_password');
            if (hash_equals(cfg('ADMIN_ID', 'admin'), $id) && hash_equals(cfg('ADMIN_PASSWORD'), $password)) {
                $_SESSION['is_admin'] = true;
                redirect_to('/admin');
            }
            flash('error', '관리자 아이디 또는 비밀번호가 올바르지 않습니다.');
        }
        page('관리자 로그인', '<main class="login-wrap"><div class="login-card"><a class="logo" href="/admin/login">깅모지<small>ADMIN</small></a><h1>관리자 로그인</h1><form method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><label class="field"><span>아이디</span><input name="admin_id" required autocomplete="username"></label><label class="field"><span>비밀번호</span><input type="password" name="admin_password" required autocomplete="current-password"></label><button class="btn block">로그인</button></form></div></main>', true, [], false);
    }
    if ($path === '/admin/logout') {
        unset($_SESSION['is_admin']);
        redirect_to('/admin/login');
    }
    require_admin();

    if ($path === '/admin') {
        admin_dashboard();
    }

    if ($path === '/admin') {
        $counts = ['products' => (int)db()->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL')->fetchColumn(), 'orders' => (int)db()->query('SELECT COUNT(*) FROM orders WHERE status = "pending"')->fetchColumn(), 'users' => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()];
        $s = setting_values();
        $paidStatuses = "'paid','kept','preparing','shipped'";
        $dailyStmt = db()->query("SELECT DATE(paid_at) AS sale_date, COUNT(*) AS order_count, COALESCE(SUM(total_amount),0) AS revenue, COALESCE(SUM(items_amount),0) AS items_amount FROM orders WHERE paid_at IS NOT NULL AND status IN ($paidStatuses) AND paid_at >= CURRENT_DATE - INTERVAL 6 DAY GROUP BY DATE(paid_at) ORDER BY sale_date DESC");
        $dailyMap = [];
        foreach ($dailyStmt as $row) $dailyMap[$row['sale_date']] = $row;
        $dailyRows = '';
        $todayRevenue = 0;
        $todayOrders = 0;
        $todayItems = 0;
        $today = new DateTimeImmutable('today');
        for ($i = 0; $i < 7; $i++) {
            $date = $today->modify('-' . $i . ' days');
            $key = $date->format('Y-m-d');
            $row = $dailyMap[$key] ?? ['order_count' => 0, 'revenue' => 0, 'items_amount' => 0];
            if ($i === 0) {
                $todayRevenue = (int)$row['revenue'];
                $todayOrders = (int)$row['order_count'];
                $todayItems = (int)$row['items_amount'];
            }
            $dailyRows .= '<tr><td><strong>' . ($i === 0 ? '오늘' : e($date->format('m월 d일'))) . '</strong><span class="table-sub">' . e($date->format('Y.m.d')) . '</span></td><td class="right num">' . num($row['order_count']) . '건</td><td class="right num amount">' . won($row['revenue']) . '</td></tr>';
        }
        $productStmt = db()->query("SELECT oi.product_name, SUM(oi.qty) AS quantity, SUM(oi.line_amount) AS amount FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.paid_at IS NOT NULL AND o.status IN ($paidStatuses) GROUP BY oi.product_id,oi.product_name ORDER BY quantity DESC,amount DESC LIMIT 10");
        $productRows = '';
        foreach ($productStmt as $row) $productRows .= '<tr><td><strong>' . e($row['product_name']) . '</strong></td><td class="right num">' . num($row['quantity']) . '개</td><td class="right num amount">' . won($row['amount']) . '</td></tr>';
        if ($productRows === '') $productRows = '<tr><td colspan="3" class="empty-cell">아직 판매 완료된 상품이 없습니다.</td></tr>';
        $dashboard = '<div class="page-head"><div><h1>대시보드</h1><p class="page-sub">입금 확인 완료된 주문 기준 · 최근 7일</p></div><span class="chip ' . ($s['live_on'] ? 'paid' : '') . '">' . ($s['live_on'] ? 'LIVE 방송 중' : '방송 OFF') . '</span></div><div class="stats"><div class="stat alert"><span class="k">오늘 매출</span><b class="num">' . won($todayRevenue) . '</b><span class="s">결제 완료 기준</span></div><div class="stat"><span class="k">오늘 판매량</span><b class="num">' . num($todayItems) . '개</b><span class="s">상품 수량 합계</span></div><div class="stat"><span class="k">오늘 주문</span><b class="num">' . num($todayOrders) . '건</b><span class="s">결제 완료 기준</span></div><div class="stat"><span class="k">입금 대기</span><b class="num">' . num($counts['orders']) . '건</b><span class="s">확인 필요</span></div></div><div class="dashboard-analytics"><section class="card analytics-card"><div class="card-heading"><div><h2>일별 매출</h2><p>최근 7일의 주문 수와 매출을 확인해요.</p></div><span class="analytics-icon">₩</span></div><div class="table-wrap"><table class="analytics-table"><thead><tr><th>날짜</th><th class="right">주문</th><th class="right">매출</th></tr></thead><tbody>' . $dailyRows . '</tbody></table></div></section><section class="card analytics-card"><div class="card-heading"><div><h2>판매 상품</h2><p>결제 완료된 상품을 많이 팔린 순서로 보여줘요.</p></div><span class="analytics-icon">TOP</span></div><div class="table-wrap"><table class="analytics-table"><thead><tr><th>상품</th><th class="right">수량</th><th class="right">판매액</th></tr></thead><tbody>' . $productRows . '</tbody></table></div></section></div><section class="card live-card"><div><h2>방송 상태</h2><p>' . ($s['live_on'] ? '현재 고객이 상품을 주문할 수 있어요.' : '방송을 시작하면 고객이 상품을 주문할 수 있어요.') . '</p></div><form method="post" action="/admin/live"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="on" value="' . ($s['live_on'] ? '0' : '1') . '"><button class="btn pink">' . ($s['live_on'] ? '방송 종료' : '방송 시작') . '</button></form></section><p class="dashboard-footnote">등록 상품 ' . num($counts['products']) . '개 · 회원 ' . num($counts['users']) . '명</p>';
        admin_shell('대시보드', $dashboard);
    }

    if ($path === '/admin/live' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        save_settings(['live_on' => post_value('on') === '1']);
        flash('ok', '방송 상태를 저장했습니다.');
        redirect_to('/admin');
    }

    if ($path === '/admin/users') {
        admin_users();
    }

    if ($path === '/admin/reviews' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_review_action();
    }

    if ($path === '/admin/reviews') {
        admin_reviews();
    }

    if (preg_match('#^/admin/users/(\d+)/(points|memo)$#', $path, $match) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_user_action((int)$match[1], (string)$match[2]);
    }

    if (preg_match('#^/admin/users/(\d+)$#', $path, $match)) {
        admin_user_detail((int)$match[1]);
    }

    if ($path === '/admin/products/order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_product_order();
    }

    if ($path === '/admin/products') {
        $products = product_rows(true);
        $rows = '';
        foreach ($products as $index => $product) {
            $id = (int)$product['id'];
            $up = '<form method="post" action="/admin/products/order"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="product_id" value="' . $id . '"><input type="hidden" name="direction" value="up"><button class="btn sm ghost order-move" type="submit" aria-label="' . e($product['name']) . ' 위로 이동"' . ($index === 0 ? ' disabled' : '') . '>↑</button></form>';
            $down = '<form method="post" action="/admin/products/order"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="product_id" value="' . $id . '"><input type="hidden" name="direction" value="down"><button class="btn sm ghost order-move" type="submit" aria-label="' . e($product['name']) . ' 아래로 이동"' . ($index === count($products) - 1 ? ' disabled' : '') . '>↓</button></form>';
            $rows .= '<tr><td>' . e($product['name']) . '</td><td>' . num($product['price']) . '원</td><td>' . (int)$product['stock'] . '</td><td>' . ($product['is_visible'] ? '노출' : '숨김') . '</td><td class="product-order-cell"><div class="product-order-actions">' . $up . $down . '</div></td><td><a class="btn sm" href="/admin/products/' . $id . '">수정</a></td></tr>';
        }
        $savedModal = isset($_GET['saved']) && $_GET['saved'] === '1' ? '<div class="admin-modal" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="saved-title"><div class="admin-modal-card"><div class="admin-modal-icon">✓</div><h2 id="saved-title">저장되었습니다.</h2><p>상품·재고 목록으로 이동했습니다.</p><button type="button" class="btn pink" data-close-admin-modal>확인</button></div></div>' : '';
        admin_shell('상품 관리', $savedModal . '<div class="page-head"><div><h1>상품·재고</h1><p class="page-sub">고객 화면에 보이는 순서는 위·아래 버튼으로 바꿀 수 있어요.</p></div><a class="btn pink" href="/admin/products/new">상품 등록</a></div><div class="table-wrap"><table><thead><tr><th>상품</th><th>가격</th><th>재고</th><th>노출</th><th>순서</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    }

    if ($path === '/admin/products/new' || preg_match('#^/admin/products/(\d+)$#', $path, $match)) {
        admin_product_v2($path === '/admin/products/new' ? 0 : (int)$match[1]);
    }

    if ($path === '/admin/settings') {
        admin_settings();
    }

    if ($path === '/admin/orders') {
        $status = (string)($_GET['status'] ?? 'all');
        $statusOptions = ['all' => '전체 주문', 'pending' => '입금 대기', 'paid' => '결제 완료 · 발송 대기', 'kept' => '결제 완료 · 킵 보관', 'preparing' => '배송 준비중', 'shipped' => '배송 완료 · 판매 완료', 'cancelled' => '취소 · 반품'];
        if (!array_key_exists($status, $statusOptions)) $status = 'all';
        $search = trim((string)($_GET['q'] ?? ''));
        $productId = max(0, (int)($_GET['product_id'] ?? 0));
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
        $where = [];
        $params = [];
        if ($status !== 'all') {
            $where[] = 'o.status=?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(o.order_no LIKE ? OR u.login_id LIKE ? OR u.name LIKE ? OR o.recipient_name LIKE ? OR o.youtube_nickname LIKE ? OR u.youtube_nickname LIKE ? OR o.depositor_name LIKE ?)';
            for ($i = 0; $i < 7; $i++) $params[] = '%' . $search . '%';
        }
        if ($productId > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM order_items filter_items WHERE filter_items.order_id=o.id AND filter_items.product_id=?)';
            $params[] = $productId;
        }
        if ($from !== '') {
            $where[] = 'o.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $to . ' 00:00:00';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = db()->prepare("SELECT o.*, u.login_id, u.name AS user_name, u.youtube_nickname AS user_youtube_nickname, (SELECT GROUP_CONCAT(DISTINCT oi.product_name ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id=o.id) AS item_names, (SELECT p.order_no FROM orders p WHERE p.id=o.merge_order_id) AS merge_parent_no, (SELECT COUNT(*) FROM orders c WHERE c.merge_order_id=o.id AND c.status<>'cancelled') AS merged_child_count FROM orders o JOIN users u ON u.id=o.user_id $whereSql ORDER BY o.id DESC");
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
        $rows = '';
        foreach ($orders as $order) {
            $paymentLabel = $order['payment_method'] === 'card' ? '카드결제' : '계좌이체';
            $dateLabel = $order['paid_at'] ? '결제 ' . dt($order['paid_at']) : '주문 ' . dt($order['created_at']);
            $cancelLabel = $order['status'] === 'cancelled' && $order['cancel_reason'] ? '<span class="table-sub">' . e($order['cancel_reason']) . '</span>' : '';
            $memberName = trim((string)($order['user_name'] ?: $order['login_id']));
            $youtubeName = trim((string)($order['youtube_nickname'] ?: $order['user_youtube_nickname']));
            $depositorName = trim((string)$order['depositor_name']);
            $memberCell = '<strong>회원 · ' . e($memberName ?: '-') . '</strong><span class="table-sub">유튜브 · ' . e($youtubeName ?: '-') . '</span><span class="table-sub">입금자 · ' . e($depositorName ?: '-') . '</span><span class="table-sub">상품 · ' . e($order['item_names'] ?: '상품 정보 없음') . '</span>';
            if (!empty($order['merge_parent_no'])) $memberCell .= '<span class="table-sub merge-sub">합배송 · ' . e($order['merge_parent_no']) . ' 주문에 추가 (배송비 없음)</span>';
            if ((int)$order['merged_child_count'] > 0) $memberCell .= '<span class="table-sub merge-sub">추가 주문 ' . (int)$order['merged_child_count'] . '건 합배송</span>';
            if (empty($order['admin_checked_at']) && $order['status'] !== 'cancelled') $memberCell = '<span class="order-new-indicator"><i class="new-order-dot" aria-hidden="true"></i>신규 주문</span>' . $memberCell;
            $actionCell = '';
            if ($order['status'] === 'pending' && $order['payment_method'] === 'bank') {
                $actionCell = '<form method="post" action="/admin/orders/' . (int)$order['id'] . '" class="table-action"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="return_to" value="/admin/orders"><button class="btn sm ok" type="submit" name="action" value="confirm">' . (!empty($order['merge_parent_no']) ? '추가 주문 입금 확인' : '입금 완료') . '</button></form>';
            } elseif ($order['status'] === 'cancelled') {
                $actionCell = '<form method="post" action="/admin/orders/' . (int)$order['id'] . '" class="table-action"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="return_to" value="/admin/orders"><button class="btn sm danger" type="submit" name="action" value="delete" data-confirm-dialog="' . e($order['order_no']) . ' 주문을 완전히 삭제할까요? 삭제 후에는 되돌릴 수 없습니다">주문 삭제</button></form>';
            }
            $rows .= '<tr><td><a class="order-no" href="/admin/orders/' . (int)$order['id'] . '">' . e($order['order_no']) . '</a><span class="table-sub">' . e($paymentLabel) . '</span></td><td class="member-cell">' . $memberCell . '</td><td><span class="chip ' . e($order['status']) . '">' . e(order_status_label($order)) . '</span>' . $cancelLabel . '</td><td class="amount">' . won($order['total_amount']) . '</td><td><span class="table-sub">' . e($dateLabel) . '</span>' . ($order['cancelled_at'] ? '<span class="table-sub">취소 ' . e(dt($order['cancelled_at'])) . '</span>' : '') . '</td><td>' . $actionCell . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="6" class="empty-cell">조건에 맞는 주문 기록이 없습니다.</td></tr>';
        $productOptions = '<option value="0">전체 상품</option>';
        foreach (product_rows(true) as $product) $productOptions .= '<option value="' . (int)$product['id'] . '" ' . ($productId === (int)$product['id'] ? 'selected' : '') . '>' . e($product['name']) . '</option>';
        $statusOptionsHtml = '';
        foreach ($statusOptions as $value => $label) $statusOptionsHtml .= '<option value="' . e($value) . '" ' . ($status === $value ? 'selected' : '') . '>' . e($label) . '</option>';
        $filterHtml = '<form class="card history-filters" method="get" action="/admin/orders"><div class="filter-title"><div><h2>판매 원장</h2><p>결제 완료·배송 완료·취소·반품 기록을 주문별로 보관합니다. 상세 화면에서 처리 이력과 취소를 확인할 수 있어요.</p></div><span class="chip paid">총 ' . num(count($orders)) . '건</span></div><div class="filter-grid order-filter-grid"><label class="field"><span>상태</span><select name="status">' . $statusOptionsHtml . '</select></label><label class="field"><span>상품</span><select name="product_id">' . $productOptions . '</select></label><label class="field"><span>검색</span><input type="search" name="q" value="' . e($search) . '" placeholder="주문번호·아이디·받는 분"></label><label class="field"><span>주문 시작일</span><input type="date" name="from" value="' . e($from) . '"></label><label class="field"><span>주문 종료일</span><input type="date" name="to" value="' . e($to) . '"></label><div class="filter-submit"><button class="btn pink">필터 적용</button><a class="btn ghost" href="/admin/orders">초기화</a></div></div></form>';
        admin_shell('주문 관리', '<div class="page-head"><div><h1>주문 관리</h1><p class="page-sub">입금 대기부터 판매 완료, 취소·반품까지 하나의 ERP 원장으로 관리합니다.</p></div></div>' . $filterHtml . '<div class="table-wrap"><table class="orders-table"><thead><tr><th>주문번호</th><th>회원 · 유튜브 · 입금자 · 상품</th><th>상태</th><th>금액</th><th>처리 일시</th><th>처리</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    }

    if (preg_match('#^/admin/orders/(\d+)$#', $path, $match)) {
        admin_order((int)$match[1]);
    }

    if ($path === '/admin/pages') {
        $pages = db()->query('SELECT * FROM pages ORDER BY slug')->fetchAll();
        $rows = '';
        foreach ($pages as $item) $rows .= '<tr><td>' . e($item['title']) . '</td><td><a class="btn sm" href="/admin/pages/' . e($item['slug']) . '">편집</a></td></tr>';
        admin_shell('약관·정책', '<div class="page-head"><h1>약관·정책</h1></div><table><tbody>' . $rows . '</tbody></table>');
    }

    if (preg_match('#^/admin/pages/([a-z0-9_-]+)$#', $path, $match)) {
        admin_page_edit($match[1]);
    }

    http_response_code(404);
    admin_shell('관리자', '<div class="card"><h1>페이지를 찾을 수 없습니다.</h1></div>');
}

function admin_reviews(): never
{
    $stmt = db()->query('SELECT r.id,r.rating,r.content,r.created_at,p.name AS product_name,u.name AS user_name,u.login_id,o.id AS order_id,o.order_no FROM product_reviews r JOIN products p ON p.id=r.product_id JOIN users u ON u.id=r.user_id JOIN orders o ON o.id=r.order_id ORDER BY r.created_at DESC,r.id DESC');
    $reviews = $stmt->fetchAll();
    $imageStmt = db()->prepare('SELECT id FROM product_review_images WHERE review_id=? ORDER BY sort_order,id');
    $rows = '';
    foreach ($reviews as $review) {
        $imageStmt->execute([(int)$review['id']]);
        $photos = '';
        foreach ($imageStmt->fetchAll() as $index => $image) {
            $photos .= '<a href="/review-img/' . (int)$image['id'] . '" target="_blank" rel="noreferrer"><img src="/review-img/' . (int)$image['id'] . '" width="84" height="63" loading="lazy" alt="' . e($review['product_name'] . ' 리뷰 사진 ' . ($index + 1)) . '"></a>';
        }
        $photoHtml = $photos ? '<div class="admin-review-photos">' . $photos . '</div>' : '<span class="table-sub">사진 없음</span>';
        $stars = '<span class="admin-review-stars" aria-label="5점 만점 중 ' . (int)$review['rating'] . '점">' . str_repeat('★', (int)$review['rating']) . str_repeat('☆', 5 - (int)$review['rating']) . '</span>';
        $member = trim((string)($review['user_name'] ?: $review['login_id']));
        $rows .= '<tr><td><span class="table-sub">' . e(dt($review['created_at'])) . '</span><span class="table-sub">리뷰 #' . (int)$review['id'] . '</span><a class="order-no" href="/admin/orders/' . (int)$review['order_id'] . '">주문 보기</a></td><td><strong class="admin-review-product">' . e($review['product_name']) . '</strong><span class="table-sub">주문 ' . e($review['order_no']) . '</span></td><td><strong>' . e($member ?: '-') . '</strong><span class="table-sub">' . e($review['login_id'] ?: '-') . '</span></td><td>' . $stars . '</td><td><div class="admin-review-content">' . nl2br(e($review['content'])) . '</div>' . $photoHtml . '</td><td><form method="post" action="/admin/reviews"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="review_id" value="' . (int)$review['id'] . '"><button class="btn sm danger" type="submit" name="action" value="delete" data-confirm-dialog="이 리뷰를 삭제할까요? 사진도 함께 삭제됩니다.">삭제</button></form></td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="6" class="empty-cell">아직 작성된 리뷰가 없습니다.</td></tr>';
    $body = '<div class="page-head"><div><h1>리뷰 관리</h1><p class="page-sub">고객이 남긴 구매 상품·사진·후기를 확인하고 관리합니다.</p></div><span class="chip paid">총 ' . num(count($reviews)) . '개</span></div><div class="table-wrap"><table class="admin-review-table"><thead><tr><th>작성</th><th>구매 상품</th><th>회원</th><th>별점</th><th>후기·사진</th><th>관리</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    admin_shell('리뷰 관리', $body);
}

function admin_review_action(): never
{
    if (post_value('action') !== 'delete') redirect_to('/admin/reviews');
    $id = (int)post_value('review_id');
    if ($id > 0) {
        db()->prepare('DELETE FROM product_reviews WHERE id=?')->execute([$id]);
        flash('ok', '리뷰와 첨부 사진을 삭제했습니다.');
    }
    redirect_to('/admin/reviews');
}

function admin_users(): never
{
    $pdo = db();
    $search = trim((string)($_GET['q'] ?? ''));
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = 'WHERE u.login_id LIKE ? OR u.name LIKE ? OR u.phone LIKE ? OR u.youtube_nickname LIKE ? OR u.email LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like, $like];
    }

    $stmt = $pdo->prepare("SELECT u.*,
        (SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id AND o.status<>'cancelled') AS order_count,
        (SELECT COALESCE(SUM(o.total_amount),0) FROM orders o WHERE o.user_id=u.id AND o.status<>'cancelled') AS order_total
        FROM users u $where ORDER BY u.id DESC LIMIT 200");
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    $rows = '';
    foreach ($users as $user) {
        $id = (int)$user['id'];
        $displayName = trim((string)($user['youtube_nickname'] ?: $user['name'] ?: $user['login_id']));
        if ($displayName === '') $displayName = '회원 ' . $id;
        $identity = trim((string)$user['name']);
        $identityLine = $identity !== '' && $identity !== $displayName ? '이름 · ' . $identity : '회원번호 · ' . $id;
        $login = trim((string)$user['login_id']);
        $provider = $login !== '' ? '아이디 로그인' : (!empty($user['kakao_id']) ? '카카오 로그인' : (!empty($user['google_id']) ? '구글 로그인' : '소셜 계정'));
        $phone = trim((string)$user['phone']);
        $rows .= '<tr><td><a class="user-name" href="/admin/users/' . $id . '">' . e($displayName) . '</a><span class="table-sub">' . e($identityLine) . '</span></td><td><strong>' . e($phone !== '' ? $phone : '-') . '</strong><span class="table-sub">' . e($user['email'] ?: '-') . '</span></td><td><strong>' . e($provider) . '</strong><span class="table-sub">' . e($login !== '' ? $login : '가입 계정 확인') . '</span></td><td class="right"><strong class="point-balance num">' . num($user['point_balance']) . 'P</strong></td><td class="right"><strong class="num">' . num($user['order_count']) . '건</strong><span class="table-sub num">' . won($user['order_total']) . '</span></td><td><span class="table-sub">' . e(dt($user['created_at'])) . '</span><a class="btn sm ghost" href="/admin/users/' . $id . '">상세 보기</a></td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="6" class="empty-cell">' . ($search !== '' ? '검색 결과가 없습니다.' : '가입한 회원이 없습니다.') . '</td></tr>';

    $filter = '<form class="card history-filters" method="get" action="/admin/users"><div class="filter-title"><div><h2>회원 원장</h2><p>포인트 잔액, 주문 합계와 가입 정보를 회원별로 확인합니다.</p></div><span class="chip paid">총 ' . num(count($users)) . '명</span></div><div class="filter-grid user-filter-grid"><label class="field"><span>회원 검색</span><input type="search" name="q" value="' . e($search) . '" placeholder="이름·아이디·휴대폰·유튜브 닉네임" autocomplete="off"></label><div class="filter-submit"><button class="btn pink" type="submit">검색</button><a class="btn ghost" href="/admin/users">초기화</a></div></div></form>';
    $body = '<div class="page-head"><div><h1>회원 관리</h1><p class="page-sub">가입 회원의 구매·포인트·연락처 정보를 한 곳에서 확인해요.</p></div></div>' . $filter . '<div class="table-wrap"><table class="users-table"><thead><tr><th>회원</th><th>연락처</th><th>가입 계정</th><th class="right">포인트</th><th class="right">주문</th><th>가입일</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    admin_shell('회원 관리', $body);
}

function admin_user_detail(int $id): never
{
    $pdo = db();
    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $userStmt->execute([$id]);
    $user = $userStmt->fetch();
    if (!$user) {
        http_response_code(404);
        admin_shell('회원 없음', '<div class="card"><h1>회원을 찾을 수 없습니다.</h1><p class="page-sub">삭제되었거나 잘못된 회원 번호입니다.</p><a class="btn ghost" href="/admin/users">회원 목록으로</a></div>');
    }

    $summaryStmt = $pdo->prepare("SELECT COUNT(*) AS order_count, COALESCE(SUM(total_amount),0) AS order_total, COALESCE(SUM(point_used),0) AS points_used, COALESCE(SUM(point_earned),0) AS points_earned FROM orders WHERE user_id=? AND status<>'cancelled'");
    $summaryStmt->execute([$id]);
    $summary = $summaryStmt->fetch() ?: ['order_count' => 0, 'order_total' => 0, 'points_used' => 0, 'points_earned' => 0];
    $keptStmt = $pdo->prepare("SELECT COALESCE(SUM(items_amount),0) FROM orders WHERE user_id=? AND status='kept' AND shipment_request_id IS NULL");
    $keptStmt->execute([$id]);
    $keptAmount = (int)$keptStmt->fetchColumn();

    $ordersStmt = $pdo->prepare("SELECT o.*, (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' × ', oi.qty) ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id=o.id) AS item_names FROM orders o WHERE o.user_id=? ORDER BY o.id DESC LIMIT 50");
    $ordersStmt->execute([$id]);
    $orders = $ordersStmt->fetchAll();
    $orderRows = '';
    foreach ($orders as $order) {
        $paymentLabel = $order['payment_method'] === 'card' ? '카드결제' : '계좌이체';
        $deliveryLabel = $order['delivery_type'] === 'keep' ? '킵 보관' : '바로배송';
        $itemNames = trim((string)$order['item_names']);
        if ($itemNames === '') $itemNames = '상품 정보 없음';
        $pointMeta = [];
        if ((int)$order['point_used'] > 0) $pointMeta[] = '포인트 사용 ' . num($order['point_used']) . 'P';
        if ((int)$order['point_earned'] > 0) $pointMeta[] = '적립 예정 ' . num($order['point_earned']) . 'P';
        $orderRows .= '<tr><td><a class="order-no" href="/admin/orders/' . (int)$order['id'] . '">' . e($order['order_no']) . '</a><span class="table-sub">' . e(dt($order['created_at'])) . '</span></td><td class="member-order-items"><strong>' . e($itemNames) . '</strong>' . ($pointMeta ? '<span class="table-sub">' . e(implode(' · ', $pointMeta)) . '</span>' : '') . '</td><td class="member-order-meta"><span class="table-sub">' . e($paymentLabel) . '</span><span class="table-sub">' . e($deliveryLabel) . '</span></td><td class="right"><strong class="num">' . won($order['total_amount']) . '</strong><span class="table-sub">상품 ' . won($order['items_amount']) . '</span></td><td><span class="chip ' . e($order['status']) . '">' . e(order_status_label($order)) . '</span></td></tr>';
    }
    if ($orderRows === '') $orderRows = '<tr><td colspan="5" class="empty-cell">아직 주문한 상품이 없습니다.</td></tr>';

    $ledgerStmt = $pdo->prepare('SELECT l.*, o.order_no FROM point_ledger l LEFT JOIN orders o ON o.id=l.order_id WHERE l.user_id=? ORDER BY l.id DESC LIMIT 100');
    $ledgerStmt->execute([$id]);
    $ledgerLabels = ['earn' => '주문 적립', 'adjust' => '적립 조정', 'use' => '주문 사용', 'revoke' => '적립 회수', 'refund' => '사용 취소', 'admin_add' => '관리자 지급', 'admin_sub' => '관리자 차감'];
    $ledgerRows = '';
    foreach ($ledgerStmt->fetchAll() as $ledger) {
        $amount = (int)$ledger['amount'];
        $amountLabel = ($amount > 0 ? '+' : '') . num($amount) . 'P';
        $amountClass = $amount >= 0 ? 'point-positive' : 'point-negative';
        $orderLink = !empty($ledger['order_id']) ? '<a href="/admin/orders/' . (int)$ledger['order_id'] . '">' . e($ledger['order_no'] ?: '주문 보기') . '</a>' : '-';
        $ledgerRows .= '<tr><td><span class="table-sub">' . e(dt($ledger['created_at'])) . '</span></td><td><strong>' . e($ledgerLabels[$ledger['type']] ?? $ledger['type']) . '</strong><span class="table-sub">' . e($ledger['memo'] ?: '-') . '</span></td><td>' . $orderLink . '</td><td class="right ' . $amountClass . ' num">' . e($amountLabel) . '</td><td class="right num">' . num($ledger['balance_after']) . 'P</td></tr>';
    }
    if ($ledgerRows === '') $ledgerRows = '<tr><td colspan="5" class="empty-cell">포인트 변동 기록이 없습니다.</td></tr>';

    $memberName = trim((string)($user['youtube_nickname'] ?: $user['name'] ?: $user['login_id']));
    if ($memberName === '') $memberName = '회원 ' . $id;
    $loginMethods = [];
    if (trim((string)$user['login_id']) !== '') $loginMethods[] = '아이디 ' . trim((string)$user['login_id']);
    if (!empty($user['kakao_id'])) $loginMethods[] = '카카오';
    if (!empty($user['google_id'])) $loginMethods[] = '구글';
    $address = implode(' ', array_filter([(string)$user['zipcode'], (string)$user['address1'], (string)$user['address2']], static fn($value): bool => trim($value) !== ''));
    $body = '<div class="page-head"><div><a class="back-link" href="/admin/users">← 회원 목록</a><h1>' . e($memberName) . '</h1><p class="page-sub">회원번호 ' . $id . ' · 가입 ' . e(dt($user['created_at'])) . '</p></div><span class="chip paid">회원 상세</span></div>';
    $body .= '<div class="stats member-summary"><div class="stat alert"><span class="k">현재 포인트</span><b class="v num">' . num($user['point_balance']) . 'P</b><span class="s">사용 가능한 잔액</span></div><div class="stat"><span class="k">유효 주문</span><b class="v num">' . num($summary['order_count']) . '건</b><span class="s">취소 주문 제외</span></div><div class="stat"><span class="k">주문 합계</span><b class="v num">' . won($summary['order_total']) . '</b><span class="s">결제 금액 합계</span></div><div class="stat soft"><span class="k">킵 보관</span><b class="v num">' . won($keptAmount) . '</b><span class="s">현재 보관 중인 상품</span></div></div>';
    $body .= '<div class="two"><section class="card"><h2>회원 정보</h2><dl class="kv"><dt>이름</dt><dd>' . e($user['name'] ?: '-') . '</dd><dt>유튜브 닉네임</dt><dd>' . e($user['youtube_nickname'] ?: '-') . '</dd><dt>휴대폰</dt><dd>' . e($user['phone'] ?: '-') . '</dd><dt>주소</dt><dd>' . e($address ?: '-') . '</dd><dt>가입 계정</dt><dd>' . e($loginMethods ? implode(' · ', $loginMethods) : '-') . '</dd><dt>이메일</dt><dd>' . e($user['email'] ?: '-') . '</dd></dl></section><section class="card"><h2>관리자 메모</h2><form method="post" action="/admin/users/' . $id . '/memo"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><label class="field"><span>회원 응대·배송 참고 메모</span><textarea name="admin_memo" maxlength="2000" placeholder="관리자만 볼 수 있는 메모를 남겨 보세요." autocomplete="off">' . e($user['admin_memo']) . '</textarea></label><button class="btn pink" type="submit">메모 저장</button></form></section></div>';
    $body .= '<section class="card"><div class="member-section-head"><div><h2>최근 주문</h2><p class="page-sub">최대 50건 · 취소 주문도 함께 표시합니다.</p></div><a class="btn sm ghost" href="/admin/orders?q=' . e(rawurlencode((string)($user['login_id'] ?: $user['name']))) . '">전체 주문에서 보기</a></div><div class="table-wrap"><table class="member-order-table"><thead><tr><th>주문번호</th><th>주문 상품</th><th>결제·배송</th><th class="right">결제 금액</th><th>상태</th></tr></thead><tbody>' . $orderRows . '</tbody></table></div></section>';
    $body .= '<div class="two"><section class="card"><h2>포인트 조정</h2><p class="action-help">현재 잔액 <strong class="num">' . num($user['point_balance']) . 'P</strong>에 즉시 반영되고 변동 원장에 남습니다.</p><form method="post" action="/admin/users/' . $id . '/points" class="form-grid"><label class="field"><span>구분</span><select name="mode"><option value="add">지급</option><option value="sub">차감</option></select></label><label class="field"><span>포인트</span><input type="number" name="amount" min="1" max="1000000000" step="1" inputmode="numeric" required></label><label class="field full"><span>사유</span><input name="memo" maxlength="200" placeholder="예: 이벤트 보상, CS 보정" autocomplete="off"></label><div class="full"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button class="btn pink" type="submit">포인트 적용</button></div></form></section><section class="card"><h2>포인트 요약</h2><dl class="kv"><dt>현재 잔액</dt><dd class="point-balance num">' . num($user['point_balance']) . 'P</dd><dt>주문 적립</dt><dd class="num">' . num($summary['points_earned']) . 'P</dd><dt>주문 사용</dt><dd class="num">' . num($summary['points_used']) . 'P</dd><dt>가입일</dt><dd>' . e(dt($user['created_at'])) . '</dd></dl></section></div>';
    $body .= '<section class="card"><h2>포인트 변동 원장</h2><div class="table-wrap"><table><thead><tr><th>일시</th><th>구분·사유</th><th>연결 주문</th><th class="right">변동</th><th class="right">변동 후 잔액</th></tr></thead><tbody>' . $ledgerRows . '</tbody></table></div></section>';
    admin_shell($memberName . ' · 회원 정보', $body);
}

function admin_user_action(int $id, string $action): never
{
    $pdo = db();
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id=?');
    $userStmt->execute([$id]);
    if (!$userStmt->fetchColumn()) {
        http_response_code(404);
        admin_shell('회원 없음', '<div class="card"><h1>회원을 찾을 수 없습니다.</h1><a class="btn ghost" href="/admin/users">회원 목록으로</a></div>');
    }

    if ($action === 'memo') {
        $memo = trim((string)post_value('admin_memo'));
        if (function_exists('mb_substr')) $memo = mb_substr($memo, 0, 2000);
        else $memo = substr($memo, 0, 2000);
        $pdo->prepare('UPDATE users SET admin_memo=? WHERE id=?')->execute([$memo, $id]);
        flash('ok', '관리자 메모를 저장했습니다.');
        redirect_to('/admin/users/' . $id);
    }

    $amountText = trim((string)post_value('amount'));
    if (!preg_match('/^\d+$/', $amountText) || strlen($amountText) > 10 || (int)$amountText < 1) {
        flash('error', '포인트는 1P 이상 숫자로 입력해 주세요.');
        redirect_to('/admin/users/' . $id);
    }
    $amount = (int)$amountText;
    $isSub = post_value('mode') === 'sub';
    $delta = $isSub ? -$amount : $amount;
    $type = $isSub ? 'admin_sub' : 'admin_add';
    $memo = trim((string)post_value('memo'));
    if (function_exists('mb_substr')) $memo = mb_substr($memo, 0, 200);
    else $memo = substr($memo, 0, 200);
    if ($memo === '') $memo = $isSub ? '관리자 포인트 차감' : '관리자 포인트 지급';

    try {
        $pdo->beginTransaction();
        $balanceStmt = $pdo->prepare('SELECT point_balance FROM users WHERE id=? FOR UPDATE');
        $balanceStmt->execute([$id]);
        $before = (int)$balanceStmt->fetchColumn();
        $after = $before + $delta;
        if ($after < 0) throw new RuntimeException('현재 잔액보다 많이 차감할 수 없습니다.');
        $pdo->prepare('UPDATE users SET point_balance=? WHERE id=?')->execute([$after, $id]);
        $pdo->prepare('INSERT INTO point_ledger (user_id,type,amount,balance_after,memo) VALUES (?,?,?,?,?)')->execute([$id, $type, $delta, $after, $memo]);
        $pdo->commit();
        flash('ok', num($amount) . 'P를 ' . ($isSub ? '차감' : '지급') . '했습니다.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $error instanceof RuntimeException ? $error->getMessage() : '포인트를 저장하지 못했습니다. 다시 시도해 주세요.');
    }
    redirect_to('/admin/users/' . $id);
}

function admin_product_order(): never
{
    $productId = (int)post_value('product_id');
    $direction = (string)post_value('direction');
    if ($productId < 1 || !in_array($direction, ['up', 'down'], true)) {
        flash('error', '상품 순서를 변경하지 못했어요.');
        redirect_to('/admin/products');
    }

    $products = product_rows(true);
    $ids = array_map(static fn(array $product): int => (int)$product['id'], $products);
    $index = array_search($productId, $ids, true);
    if ($index === false) {
        flash('error', '상품을 찾을 수 없어요.');
        redirect_to('/admin/products');
    }
    $nextIndex = $direction === 'up' ? $index - 1 : $index + 1;
    if ($nextIndex < 0 || $nextIndex >= count($products)) {
        redirect_to('/admin/products');
    }

    [$products[$index], $products[$nextIndex]] = [$products[$nextIndex], $products[$index]];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE products SET sort_order=? WHERE id=?');
        foreach ($products as $position => $product) $stmt->execute([($position + 1) * 10, (int)$product['id']]);
        $pdo->commit();
        flash('ok', '상품 노출 순서를 변경했어요.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', '상품 순서를 저장하지 못했어요.');
    }
    redirect_to('/admin/products');
}

function admin_product_v2(int $id): never
{
    $product = $id ? one_product($id) : ['id' => 0, 'name' => '', 'short_description' => '', 'description' => '', 'section' => 'live', 'price' => 0, 'full_box_price' => 0, 'loose_price' => 0, 'full_box_qty' => 1, 'main_image_id' => 0, 'full_box_image_id' => 0, 'loose_image_id' => 0, 'expected_shipping_text' => '', 'stock' => 0, 'use_package_option' => 0, 'use_open_option' => 1, 'is_visible' => 1, 'is_soldout' => 0, 'images' => []];
    if (!$product) {
        http_response_code(404);
        admin_shell('상품 없음', '<div class="card">상품을 찾을 수 없습니다.</div>');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['delete']) && $id) {
            db()->prepare('UPDATE products SET deleted_at = NOW(), is_visible = 0 WHERE id = ?')->execute([$id]);
            flash('ok', '상품을 삭제했습니다.');
            redirect_to('/admin/products');
        }
        if ($id && isset($_POST['image_action'])) {
            $parts = explode(':', (string)$_POST['image_action'], 2);
            manage_product_gallery_image($id, (string)($parts[0] ?? ''), (int)($parts[1] ?? 0));
            flash('ok', '이미지 구성이 저장되었습니다.');
            redirect_to('/admin/products/' . $id);
        }
        $data = [trim((string)post_value('name')), trim((string)post_value('short_description')), trim((string)post_value('description')), post_value('section') === 'sample' ? 'sample' : 'live', max(0, (int)post_value('price')), max(0, (int)post_value('full_box_price')), max(0, (int)post_value('loose_price')), max(1, (int)post_value('full_box_qty')), trim((string)post_value('expected_shipping_text')), max(0, (int)post_value('stock')), isset($_POST['use_package_option']) ? 1 : 0, isset($_POST['use_open_option']) ? 1 : 0, isset($_POST['is_visible']) ? 1 : 0, isset($_POST['is_soldout']) ? 1 : 0];
        if ($id) {
            db()->prepare('UPDATE products SET name=?,short_description=?,description=?,section=?,price=?,full_box_price=?,loose_price=?,full_box_qty=?,expected_shipping_text=?,stock=?,use_package_option=?,use_open_option=?,is_visible=?,is_soldout=? WHERE id=?')->execute([...$data, $id]);
        } else {
            $stmt = db()->prepare('INSERT INTO products (name,short_description,description,section,price,full_box_price,loose_price,full_box_qty,expected_shipping_text,stock,use_package_option,use_open_option,is_visible,is_soldout,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([...$data, (int)db()->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM products')->fetchColumn()]);
            $id = (int)db()->lastInsertId();
        }
        if (isset($_POST['replace_images'])) {
            replace_product_images_v2($id);
        }
        save_main_image_v2($id);
        save_detail_images_v2($id);
        save_package_image_v2($id, 'full_box_image', 'full_box_image_id');
        save_package_image_v2($id, 'loose_image', 'loose_image_id');
        redirect_to('/admin/products?saved=1');
    }

    $imageSplit = product_image_split($product);
    $mainImage = $imageSplit['main'];
    $detailAdminImages = $imageSplit['details'];
    $mainPreview = $mainImage
        ? '<figure class="upload-preview-card main"><img src="/img/' . (int)$mainImage['id'] . '" alt="현재 대표 이미지"><figcaption>현재 대표 이미지</figcaption></figure>'
        : '<p class="upload-empty">등록된 대표 이미지가 없습니다.</p>';
    $detailPreview = '';
    foreach ($detailAdminImages as $index => $image) {
        $imageId = (int)$image['id'];
        $detailPreview .= '<figure class="upload-preview-card media-manager-card"><img src="/img/' . $imageId . '" alt="현재 상세 이미지"><figcaption>' . ($index + 1) . '번째 이미지</figcaption><div class="media-manager-actions"><button class="btn sm ghost" type="submit" name="image_action" value="up:' . $imageId . '"' . ($index === 0 ? ' disabled' : '') . '>위로</button><button class="btn sm ghost" type="submit" name="image_action" value="down:' . $imageId . '"' . ($index === count($detailAdminImages) - 1 ? ' disabled' : '') . '>아래로</button><button class="btn sm ghost" type="submit" name="image_action" value="main:' . $imageId . '">대표로</button><button class="btn sm danger" type="submit" name="image_action" value="delete:' . $imageId . '" data-confirm="이 상세 이미지를 삭제할까요?">삭제</button></div></figure>';
    }
    if ($detailPreview === '') $detailPreview = '<p class="upload-empty">등록된 상세 이미지가 없습니다.</p>';
    $fullBoxPreview = !empty($product['full_box_image_id']) ? '<figure class="upload-preview-card"><img src="/img/' . (int)$product['full_box_image_id'] . '" alt="현재 풀박 이미지"><figcaption>현재 풀박 이미지</figcaption></figure>' : '<p class="upload-empty">등록된 풀박 이미지가 없습니다.</p>';
    $loosePreview = !empty($product['loose_image_id']) ? '<figure class="upload-preview-card"><img src="/img/' . (int)$product['loose_image_id'] . '" alt="현재 낱박 이미지"><figcaption>현재 낱박 이미지</figcaption></figure>' : '<p class="upload-empty">등록된 낱박 이미지가 없습니다.</p>';

    $html = '<div class="page-head"><h1><a href="/admin/products">상품·재고</a> · ' . ($id ? '수정' : '등록') . '</h1></div><form method="post" enctype="multipart/form-data" class="card"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><label class="field"><span>상품명</span><input name="name" value="' . e($product['name']) . '" required></label><label class="field"><span>기본 가격</span><input type="number" name="price" value="' . (int)$product['price'] . '" min="0"></label><label class="field"><span>재고 (낱개 기준)</span><input type="number" name="stock" value="' . (int)$product['stock'] . '" min="0"><small>풀박/낱박 상품은 낱개 수로 입력합니다. 예: 60</small></label><label class="field"><span>구분</span><select name="section"><option value="live" ' . ($product['section'] !== 'sample' ? 'selected' : '') . '>라이브</option><option value="sample" ' . ($product['section'] === 'sample' ? 'selected' : '') . '>샘플</option></select></label><label><input type="checkbox" name="use_package_option" ' . ($product['use_package_option'] ? 'checked' : '') . '> 풀박/낱박 선택 사용</label><label class="field"><span>풀박 가격 (원)</span><input type="number" name="full_box_price" value="' . (int)$product['full_box_price'] . '" min="0"><small>고객 화면에는 19.9만원처럼 간결하게 표시됩니다.</small></label><label class="field"><span>낱박 가격 (원)</span><input type="number" name="loose_price" value="' . (int)$product['loose_price'] . '" min="0"><small>고객 화면에는 2.2만원처럼 간결하게 표시됩니다.</small></label><label class="field"><span>풀박 1개당 낱개 수</span><input type="number" name="full_box_qty" value="' . max(1, (int)($product['full_box_qty'] ?? 1)) . '" min="1" data-package-price><small>풀박 1개 주문 시 이 개수만큼, 낱박 1개 주문 시 1개가 재고에서 빠집니다. 예: 재고 60 · 9개입이면 풀박은 최대 6개.</small></label><label class="field"><span>예상 배송일</span><input name="expected_shipping_text" value="' . e($product['expected_shipping_text'] ?? '') . '" placeholder="예: 결제 후 3~5일"><small>상품 상세페이지에 고객에게 표시됩니다.</small></label><label><input type="checkbox" name="use_open_option" ' . ($product['use_open_option'] ? 'checked' : '') . '> 개봉/미개봉 옵션</label><label><input type="checkbox" name="is_visible" ' . ($product['is_visible'] ? 'checked' : '') . '> 고객에게 노출</label><label><input type="checkbox" name="is_soldout" ' . ($product['is_soldout'] ? 'checked' : '') . '> 품절 처리</label><section class="upload-section"><h2 class="form-section">상품 이미지</h2><label class="field"><span>메인 이미지 (1장)</span><input type="file" name="main_image" accept="image/*" data-image-upload="main"><small>상품 목록과 상세 상단에 1장만 노출됩니다. 새로 올리면 기존 대표 이미지가 바뀝니다.</small></label><div class="upload-preview" data-upload-preview="main">' . $mainPreview . '</div><div class="package-media-grid"><div><label class="field"><span>풀박 선택 이미지 (1장)</span><input type="file" name="full_box_image" accept="image/*" data-image-upload="full-box"><small>상세페이지 풀박 선택지 앞에 표시됩니다.</small></label><div class="upload-preview" data-upload-preview="full-box">' . $fullBoxPreview . '</div></div><div><label class="field"><span>낱박 선택 이미지 (1장)</span><input type="file" name="loose_image" accept="image/*" data-image-upload="loose"><small>상세페이지 낱박 선택지 앞에 표시됩니다.</small></label><div class="upload-preview" data-upload-preview="loose">' . $loosePreview . '</div></div></div><label class="field"><span>상세 이미지 (여러 장)</span><input type="file" name="images[]" accept="image/*" multiple data-image-upload="detail"><small>여러 장을 한 번에 선택할 수 있습니다. 기존 이미지 아래에 계속 추가됩니다.</small></label><div class="upload-preview" data-upload-preview="detail">' . $detailPreview . '</div></section><label class="field"><span>상세 설명 (이미지 아래 줄글)</span><textarea name="description" rows="8" placeholder="상품 크기, 구성, 특징 등을 입력해 주세요.">' . e($product['description']) . '</textarea></label><div class="save-bar">' . ($id ? '<button class="btn danger" name="delete" value="1">삭제</button>' : '') . '<button class="btn pink">저장</button></div></form>';
    $summaryField = '<label class="field"><span>메인 상품 설명</span><textarea name="short_description" rows="5" placeholder="상품명 아래에 보여줄 짧은 설명을 입력해 주세요.">' . e($product['short_description'] ?? '') . '</textarea><small>상품 상세 상단에서 옵션과 수량 선택 전에 보여집니다.</small></label>';
    $html = preg_replace('/<label class="field"><span>[^<]*<\/span><textarea name="description"/', '<label class="field"><span>상세 설명</span><textarea name="description"', $html, 1) ?? $html;
    $html = str_replace('<section class="upload-section">', $summaryField . '<section class="upload-section">', $html);
    $html = str_replace('name="use_package_option"', 'name="use_package_option" data-package-toggle', $html);
    $html = str_replace('<label class="field"><span>메인 이미지 (1장)</span>', '<label><input type="checkbox" name="replace_images"> 기존 대표·상세 이미지를 모두 지우고 새로 저장</label><label class="field"><span>대표 이미지 (1장)</span>', $html);
    $html = str_replace('name="full_box_price"', 'name="full_box_price" data-package-price', $html);
    $html = str_replace('name="loose_price"', 'name="loose_price" data-package-price', $html);
    admin_shell($id ? $product['name'] : '상품 등록', $html);
}

function replace_product_images_v2(int $productId): void
{
    db()->prepare('UPDATE products SET main_image_id = NULL, full_box_image_id = NULL, loose_image_id = NULL WHERE id = ?')->execute([$productId]);
    db()->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
}

function manage_product_gallery_image(int $productId, string $action, int $imageId): void
{
    if ($imageId < 1 || !in_array($action, ['up', 'down', 'delete', 'main'], true)) return;
    $product = one_product($productId);
    if (!$product) return;
    $split = product_image_split($product);
    $gallery = $split['details'];
    $ids = array_map(static fn(array $image): int => (int)$image['id'], $gallery);
    $index = array_search($imageId, $ids, true);
    if ($index === false) return;
    if ($action === 'delete') {
        db()->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
        return;
    }
    if ($action === 'main') {
        db()->prepare('UPDATE products SET main_image_id = ? WHERE id = ?')->execute([$imageId, $productId]);
        return;
    }
    $next = $action === 'up' ? $index - 1 : $index + 1;
    if ($next < 0 || $next >= count($gallery)) return;
    [$gallery[$index], $gallery[$next]] = [$gallery[$next], $gallery[$index]];
    $galleryIds = array_map(static fn(array $image): int => (int)$image['id'], $gallery);
    $otherImages = array_values(array_filter($product['images'], static fn(array $image): bool => !in_array((int)$image['id'], $galleryIds, true)));
    $ordered = array_merge($otherImages, $gallery);
    $stmt = db()->prepare('UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ?');
    foreach ($ordered as $sort => $image) $stmt->execute([$sort, (int)$image['id'], $productId]);
}

function uploaded_image_v2(string $field, int $index = 0): ?array
{
    if (!isset($_FILES[$field])) return null;
    $file = $_FILES[$field];
    $value = static fn(string $key): mixed => is_array($file[$key] ?? null) ? ($file[$key][$index] ?? null) : ($file[$key] ?? null);
    $error = (int)$value('error');
    $tmp = (string)$value('tmp_name');
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) return null;
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return null;
    $data = file_get_contents($tmp);
    if ($data === false || strlen($data) > 8 * 1024 * 1024) return null;
    return ['mime' => $info['mime'], 'data' => $data];
}

function insert_uploaded_image_v2(int $productId, array $image, int $sort): int
{
    db()->prepare('INSERT INTO product_images (product_id,mime,data,sort_order) VALUES (?,?,?,?)')->execute([$productId, $image['mime'], $image['data'], $sort]);
    return (int)db()->lastInsertId();
}

function save_main_image_v2(int $productId): void
{
    $image = uploaded_image_v2('main_image');
    if (!$image) return;
    $oldMainId = (int)db()->query('SELECT COALESCE(main_image_id,0) FROM products WHERE id=' . $productId)->fetchColumn();
    $imageId = insert_uploaded_image_v2($productId, $image, -1);
    db()->prepare('UPDATE products SET main_image_id = ? WHERE id = ?')->execute([$imageId, $productId]);
    if ($oldMainId) db()->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?')->execute([$oldMainId, $productId]);
}

function save_detail_images_v2(int $productId): void
{
    if (empty($_FILES['images']['name']) || !is_array($_FILES['images']['name'])) return;
    $sort = (int)db()->query('SELECT COALESCE(MAX(sort_order),-1)+1 FROM product_images WHERE product_id=' . $productId)->fetchColumn();
    foreach (array_keys($_FILES['images']['name']) as $index) {
        $image = uploaded_image_v2('images', (int)$index);
        if (!$image) continue;
        insert_uploaded_image_v2($productId, $image, $sort++);
    }
}

function save_package_image_v2(int $productId, string $field, string $column): void
{
    if (!in_array($column, ['full_box_image_id', 'loose_image_id'], true)) return;
    $image = uploaded_image_v2($field);
    if (!$image) return;
    $sort = (int)db()->query('SELECT COALESCE(MAX(sort_order),-1)+1 FROM product_images WHERE product_id=' . $productId)->fetchColumn();
    $imageId = insert_uploaded_image_v2($productId, $image, $sort);
    db()->prepare("UPDATE products SET $column=? WHERE id=?")->execute([$imageId, $productId]);
}

function admin_product(int $id): never
{
    $product = $id ? one_product($id) : ['id' => 0, 'name' => '', 'description' => '', 'section' => 'live', 'price' => 0, 'full_box_price' => 0, 'loose_price' => 0, 'stock' => 0, 'use_package_option' => 0, 'use_open_option' => 1, 'is_visible' => 1, 'is_soldout' => 0, 'images' => []];
    if (!$product) {
        http_response_code(404);
        admin_shell('상품 없음', '<div class="card">상품을 찾을 수 없습니다.</div>');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['delete']) && $id) {
            db()->prepare('UPDATE products SET deleted_at = NOW(), is_visible = 0 WHERE id = ?')->execute([$id]);
            flash('ok', '상품을 삭제했습니다.');
            redirect_to('/admin/products');
        }
        $data = [trim((string)post_value('name')), trim((string)post_value('description')), post_value('section') === 'sample' ? 'sample' : 'live', max(0, (int)post_value('price')), max(0, (int)post_value('full_box_price')), max(0, (int)post_value('loose_price')), max(0, (int)post_value('stock')), isset($_POST['use_package_option']) ? 1 : 0, isset($_POST['use_open_option']) ? 1 : 0, isset($_POST['is_visible']) ? 1 : 0, isset($_POST['is_soldout']) ? 1 : 0];
        if ($id) {
            db()->prepare('UPDATE products SET name=?,description=?,section=?,price=?,full_box_price=?,loose_price=?,stock=?,use_package_option=?,use_open_option=?,is_visible=?,is_soldout=? WHERE id=?')->execute([...$data, $id]);
        } else {
            $stmt = db()->prepare('INSERT INTO products (name,description,section,price,full_box_price,loose_price,stock,use_package_option,use_open_option,is_visible,is_soldout,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([...$data, (int)db()->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM products')->fetchColumn()]);
            $id = (int)db()->lastInsertId();
        }
        save_product_images($id);
        flash('ok', '상품을 저장했습니다.');
        redirect_to('/admin/products/' . $id);
    }
    $imageHtml = '';
    foreach ($product['images'] as $image) $imageHtml .= '<img src="/img/' . (int)$image['id'] . '" alt="" style="max-width:120px;margin:4px">';
    $html = '<div class="page-head"><h1><a href="/admin/products">상품·재고</a> · ' . ($id ? '수정' : '등록') . '</h1></div><form method="post" enctype="multipart/form-data" class="card"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><label class="field"><span>상품명</span><input name="name" value="' . e($product['name']) . '" required></label><label class="field"><span>가격</span><input type="number" name="price" value="' . (int)$product['price'] . '" min="0"></label><label class="field"><span>재고</span><input type="number" name="stock" value="' . (int)$product['stock'] . '" min="0"></label><label class="field"><span>구분</span><select name="section"><option value="live" ' . ($product['section'] !== 'sample' ? 'selected' : '') . '>라이브</option><option value="sample" ' . ($product['section'] === 'sample' ? 'selected' : '') . '>샘플</option></select></label><label class="field"><span>상세 설명</span><textarea name="description" rows="8">' . e($product['description']) . '</textarea></label><label><input type="checkbox" name="use_open_option" ' . ($product['use_open_option'] ? 'checked' : '') . '> 개봉/미개봉 옵션</label><label><input type="checkbox" name="is_visible" ' . ($product['is_visible'] ? 'checked' : '') . '> 고객에게 노출</label><label><input type="checkbox" name="is_soldout" ' . ($product['is_soldout'] ? 'checked' : '') . '> 품절 처리</label><label class="field"><span>상세 이미지 여러 장</span><input type="file" name="images[]" accept="image/*" multiple></label><div>' . $imageHtml . '</div><div class="save-bar">' . ($id ? '<button class="btn danger" name="delete" value="1">삭제</button>' : '') . '<button class="btn pink">저장</button></div></form>';
    $html = str_replace('<label><input type="checkbox" name="use_open_option"', '<label><input type="checkbox" name="use_package_option" ' . ($product['use_package_option'] ? 'checked' : '') . '> 풀박/낱박 선택 사용</label><label class="field"><span>풀박 가격</span><input type="number" name="full_box_price" value="' . (int)$product['full_box_price'] . '" min="0"><small>풀박스 구성 · 패키지에 모든 상품</small></label><label class="field"><span>낱박 가격</span><input type="number" name="loose_price" value="' . (int)$product['loose_price'] . '" min="0"><small>패키지 내 개별 상품</small></label><label><input type="checkbox" name="use_open_option"', $html);
    admin_shell($id ? $product['name'] : '상품 등록', $html);
}

function save_product_images(int $productId): void
{
    if (empty($_FILES['images']['name']) || !is_array($_FILES['images']['name'])) return;
    $sort = (int)db()->query('SELECT COALESCE(MAX(sort_order),-1)+1 FROM product_images WHERE product_id=' . $productId)->fetchColumn();
    foreach ($_FILES['images']['tmp_name'] as $index => $tmp) {
        if ((int)($_FILES['images']['error'][$index] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) continue;
        $data = file_get_contents($tmp);
        if ($data === false || strlen($data) > 8 * 1024 * 1024) continue;
        db()->prepare('INSERT INTO product_images (product_id,mime,data,sort_order) VALUES (?,?,?,?)')->execute([$productId, $info['mime'], $data, $sort++]);
    }
}

function admin_settings(): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $keys = array_keys(default_settings());
        $values = [];
        foreach ($keys as $key) {
            if (isset($_POST[$key])) $values[$key] = is_array($_POST[$key]) ? reset($_POST[$key]) : $_POST[$key];
        }
        $values['live_on'] = isset($_POST['live_on']);
        $values['always_show_price'] = isset($_POST['always_show_price']);
        $values['show_stock'] = isset($_POST['show_stock']);
        $values['point_use_enabled'] = isset($_POST['point_use_enabled']);
        $values['cash_receipt_enabled'] = isset($_POST['cash_receipt_enabled']);
        save_settings($values);
        flash('ok', '설정을 저장했습니다.');
        redirect_to('/admin/settings');
    }
    $s = setting_values();
    $checkbox = static fn(string $key): string => $s[$key] ? 'checked' : '';
    $html = '<div class="page-head"><h1>사이트 설정</h1></div><form method="post" class="card"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><h2>고객 화면</h2><label><input type="checkbox" name="live_on" ' . $checkbox('live_on') . '> 방송 중</label><label><input type="checkbox" name="always_show_price" ' . $checkbox('always_show_price') . '> 방송 전에도 가격 표시</label><label><input type="checkbox" name="show_stock" ' . $checkbox('show_stock') . '> 고객에게 재고 수량 표시</label><label class="field"><span>공지 문구</span><textarea name="notice_text">' . e($s['notice_text']) . '</textarea></label><label class="field"><span>이벤트 문구</span><input name="event_notice_text" value="' . e($s['event_notice_text']) . '"></label><h2>배송</h2><label class="field"><span>무료배송 기준</span><input type="number" name="free_shipping_threshold" value="' . (int)$s['free_shipping_threshold'] . '"></label><label class="field"><span>배송비</span><input type="number" name="shipping_fee" value="' . (int)$s['shipping_fee'] . '"></label><h2>포인트</h2><label class="field"><span>적립 방식</span><select name="point_earn_mode"><option value="off" ' . ($s['point_earn_mode'] === 'off' ? 'selected' : '') . '>사용 안 함</option><option value="flat" ' . ($s['point_earn_mode'] === 'flat' ? 'selected' : '') . '>단일 비율</option><option value="tier" ' . ($s['point_earn_mode'] === 'tier' ? 'selected' : '') . '>라이브 합산 2단계</option></select></label><label class="field"><span>라이브 누적 30만원 미만 적립률</span><input name="point_earn_under_rate" type="number" step="0.1" value="' . e($s['point_earn_under_rate']) . '"></label><label class="field"><span>라이브 누적 30만원 이상 적립률</span><input name="point_earn_over_rate" type="number" step="0.1" value="' . e($s['point_earn_over_rate']) . '"></label><label class="field"><span>라이브 누적 기준 금액</span><input name="point_earn_threshold" type="number" value="' . e($s['point_earn_threshold']) . '"></label><label><input type="checkbox" name="point_use_enabled" ' . $checkbox('point_use_enabled') . '> 포인트 사용 허용</label><label class="field"><span>최소 보유 포인트</span><input name="point_min_balance" type="number" value="' . e($s['point_min_balance']) . '"></label><label class="field"><span>사용 단위</span><input name="point_use_unit" type="number" value="' . e($s['point_use_unit']) . '"></label><label class="field"><span>포인트 안내</span><textarea name="point_guide_text">' . e($s['point_guide_text']) . '</textarea></label><h2>현금영수증</h2><label><input type="checkbox" name="cash_receipt_enabled" ' . $checkbox('cash_receipt_enabled') . '> 신청 기능 사용</label><h2>사업자 정보</h2><label class="field"><span>상호</span><input name="biz_name" value="' . e($s['biz_name']) . '"></label><label class="field"><span>대표자</span><input name="biz_owner" value="' . e($s['biz_owner']) . '"></label><label class="field"><span>사업자등록번호</span><input name="biz_reg_no" value="' . e($s['biz_reg_no']) . '"></label><label class="field"><span>주소</span><input name="biz_address" value="' . e($s['biz_address']) . '"></label><label class="field"><span>전화</span><input name="biz_phone" value="' . e($s['biz_phone']) . '"></label><label class="field"><span>이메일</span><input name="biz_email" value="' . e($s['biz_email']) . '"></label><button class="btn pink">저장</button></form>';
    $html = str_replace('<h2>배송</h2>', '<h2>입금 안내</h2><label class="field"><span>은행명</span><input name="bank_name" value="' . e($s['bank_name']) . '"></label><label class="field"><span>계좌번호</span><input name="bank_account" value="' . e($s['bank_account']) . '"></label><label class="field"><span>예금주</span><input name="bank_holder" value="' . e($s['bank_holder']) . '"></label><h2>배송</h2>', $html);
    admin_shell('사이트 설정', $html);
}

function admin_order(int $id): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_order_action($id, (string)post_value('action'), (string)post_value('cancel_kind', 'cancel'));
    }
    $stmt = db()->prepare('SELECT o.*,u.login_id FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) admin_shell('주문 없음', '<div class="card">주문을 찾을 수 없습니다.</div>');
    if (empty($order['admin_checked_at'])) {
        db()->prepare('UPDATE orders SET admin_checked_at=NOW() WHERE id=? AND admin_checked_at IS NULL')->execute([$id]);
    }
    $items = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
    $items->execute([$id]);
    $list = '';
    $itemCount = 0;
    foreach ($items as $item) {
        $itemCount++;
        $packageLabel = $item['package_type'] === 'full' ? '풀박' : ($item['package_type'] === 'loose' ? '낱박' : '기본 구성');
        $split = ((int)$item['qty_opened'] || (int)$item['qty_unopened']) ? ' · 라이브 개봉 ' . (int)$item['qty_opened'] . '개 · 미개봉 발송 ' . (int)$item['qty_unopened'] . '개' : '';
        $list .= '<div class="order-item"><div><strong>' . e($item['product_name']) . '</strong><span>' . e($packageLabel) . $split . ' · 수량 ' . num($item['qty']) . '</span></div><b class="num">' . won($item['line_amount']) . '</b></div>';
    }
    $eventStmt = db()->prepare('SELECT * FROM order_events WHERE order_id=? ORDER BY id DESC');
    $eventStmt->execute([$id]);
    $history = '';
    $eventCount = 0;
    foreach ($eventStmt as $event) {
        $eventCount++;
        $toLabel = $event['to_status'] ? order_status_label(['status' => $event['to_status'], 'payment_method' => $order['payment_method']]) : '';
        $transition = $event['from_status'] && $event['to_status'] ? '<span class="history-transition">' . e(order_status_label(['status' => $event['from_status'], 'payment_method' => $order['payment_method']])) . ' → ' . e($toLabel) . '</span>' : ($toLabel ? '<span class="history-transition">' . e($toLabel) . '</span>' : '');
        $refund = (int)$event['refund_amount'] > 0 ? '<span class="history-refund">환불 대상 ' . won($event['refund_amount']) . '</span>' : '';
        $history .= '<li class="history-item"><div class="history-dot"></div><div class="history-body"><div class="history-top"><strong>' . e(order_event_label($event)) . '</strong><time>' . e(dt($event['created_at'])) . '</time></div><p>' . e($event['memo'] ?: '처리 기록이 저장되었습니다.') . '</p><div class="history-meta">' . $transition . $refund . '</div></div></li>';
    }
    if ($history === '') $history = '<li class="empty">아직 처리 이력이 없습니다.</li>';
    $deliveryInfo = $order['delivery_type'] === 'keep' ? '킵(보관)' : '바로배송';
    $deliveryClass = $order['delivery_type'] === 'keep' ? 'keep' : 'direct';
    $mergeNotice = !empty($order['keep_merge_requested']) ? '기존 킵 ' . won($order['keep_merge_amount']) . ' 같이 배송' : '';
    $mergeLinks = '';
    $groupOrders = merge_group_orders(db(), $order);
    $activeGroupOrders = array_values(array_filter($groupOrders, static fn(array $member): bool => $member['status'] !== 'cancelled'));
    $activeGroupCount = count($activeGroupOrders);
    $pendingGroupCount = count(array_filter($activeGroupOrders, static fn(array $member): bool => $member['status'] === 'pending'));
    $paidGroupCount = count(array_filter($activeGroupOrders, static fn(array $member): bool => $member['status'] === 'paid'));
    if (count($groupOrders) > 1) {
        $memberRows = '';
        foreach ($groupOrders as $member) {
            if ((int)$member['id'] === (int)$order['id']) continue;
            $isRoot = empty($member['merge_order_id']);
            $warn = '';
            if ($member['status'] === 'pending') $warn = ' <span class="warn">입금 대기 · 확인 후 함께 발송</span>';
            elseif ($isRoot && in_array($member['status'], ['shipped', 'cancelled'], true) && in_array($order['status'], ['pending', 'paid', 'preparing'], true)) $warn = ' <span class="warn">대표 주문이 이미 ' . e(order_status_label($member)) . ' · 이 주문은 배송비 없이 단독 발송되니 확인 필요</span>';
            $quickConfirm = '';
            if ($member['status'] === 'pending' && $member['payment_method'] === 'bank') {
                $quickConfirm = '<form method="post" action="/admin/orders/' . (int)$member['id'] . '" class="merge-quick-action"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="return_to" value="/admin/orders/' . (int)$order['id'] . '"><button class="btn sm ok" type="submit" name="action" value="confirm">입금 확인</button></form>';
            }
            $memberRows .= '<div class="merge-member"><span>' . ($isRoot ? '대표 주문' : '추가 주문') . ' <a href="/admin/orders/' . (int)$member['id'] . '">' . e($member['order_no']) . '</a> · ' . e(order_status_label($member)) . ' · ' . won($member['total_amount']) . $warn . '</span>' . $quickConfirm . '</div>';
        }
        $mergeRole = empty($order['merge_order_id']) ? '대표 주문' : '추가 주문 · 배송비 없음';
        $mergeLinks = '<div class="order-merge-links"><strong>합배송 ' . num($activeGroupCount) . '건 · ' . $mergeRole . '</strong>' . $memberRows . '<span class="muted">모든 추가 주문의 입금 확인이 끝나면 묶음 전체를 한 번에 배송 처리해요.</span></div>';
    }
    $actionButtons = '';
    if ($order['status'] === 'pending') $actionButtons .= '<button class="btn pink" name="action" value="confirm">입금 확인 · 결제 완료</button>';
    if ($order['status'] === 'paid') {
        if ($activeGroupCount > 1 && $pendingGroupCount > 0) $actionButtons .= '<button class="btn pink" type="button" disabled>입금대기 ' . num($pendingGroupCount) . '건 확인 후 배송 준비</button>';
        else $actionButtons .= '<button class="btn pink" name="action" value="prepare">' . ($activeGroupCount > 1 ? '합배송 ' . num($activeGroupCount) . '건 배송 준비' : '배송 준비중으로 변경') . '</button>';
    }
    if ($order['status'] === 'preparing') {
        if ($pendingGroupCount > 0 || $paidGroupCount > 0) $actionButtons .= '<button class="btn pink" type="button" disabled>묶음 주문 상태 확인 후 배송 완료</button>';
        else $actionButtons .= '<button class="btn pink" name="action" value="ship">' . ($activeGroupCount > 1 ? '합배송 ' . num($activeGroupCount) . '건 배송 완료' : '배송 완료 처리') . '</button>';
    }
    if ($order['status'] !== 'cancelled' && $order['status'] === 'shipped') $actionButtons .= '<input type="hidden" name="cancel_kind" value="return"><button class="btn danger" name="action" value="cancel">반품 · 환불 처리</button>';
    if ($order['status'] !== 'cancelled' && $order['status'] !== 'shipped') $actionButtons .= '<label class="action-select"><span>취소 유형</span><select name="cancel_kind"><option value="cancel">주문 취소 · 환불</option><option value="return">반품 · 환불</option></select></label><button class="btn danger" name="action" value="cancel">취소 처리</button>';
    if ($order['status'] === 'cancelled') $actionButtons .= '<button class="btn danger" name="action" value="delete" data-confirm-dialog="' . e($order['order_no']) . ' 주문을 완전히 삭제할까요? 삭제 후에는 되돌릴 수 없습니다">주문 자체 삭제</button>';
    if ($actionButtons === '') $actionButtons = '<span class="hint">추가로 처리할 작업이 없습니다.</span>';
    $receiptLabel = $order['cash_receipt_type'] === 'income' ? '소득공제용' : ($order['cash_receipt_type'] === 'expense' ? '지출증빙용' : '신청 안 함');
    $actionHelp = $activeGroupCount > 1 ? '합배송 주문은 모든 입금 확인이 끝난 뒤 묶음 전체가 함께 배송 준비·완료 처리됩니다.' : '계좌이체는 입금 확인 후 결제 완료로 바꾸고, 배송 준비중을 거쳐 배송 완료로 처리하세요.';
    $html = '<div class="page-head"><div><h1>주문 ' . e($order['order_no']) . '</h1><p class="page-sub">결제부터 배송 완료, 취소·반품까지 한 건의 판매 기록으로 관리합니다.</p></div><a class="btn ghost" href="/admin/orders">주문 원장</a></div><div class="order-layout"><div class="order-main"><section class="card order-card"><div class="order-card-heading"><h2>주문 상품</h2><span class="muted">' . num($itemCount) . '개 상품</span></div><div class="order-items-list">' . ($list ?: '<p class="empty">상품 정보가 없습니다.</p>') . '</div><div class="order-price-list"><div><span>상품금액</span><b class="num">' . won($order['items_amount']) . '</b></div><div><span>배송비</span><b class="num">' . ((int)$order['shipping_fee'] ? won($order['shipping_fee']) : '무료') . '</b></div>' . ((int)$order['point_used'] ? '<div><span>포인트 사용</span><b class="num discount">-' . num($order['point_used']) . 'P</b></div>' : '') . '<div class="total"><span>결제금액</span><strong class="num">' . won($order['total_amount']) . '</strong></div></div></section><section class="card order-card"><div class="order-card-heading"><h2>주문 처리 이력</h2><span class="muted">' . num($eventCount) . '건</span></div><ol class="order-history">' . $history . '</ol></section><section class="card order-card"><div class="order-card-heading"><h2>주문 메모</h2></div><p class="order-memo">' . ($order['memo'] ? nl2br(e($order['memo'])) : '남겨진 배송 메모가 없습니다.') . '</p></section></div><aside class="order-side"><section class="card order-card"><div class="order-status-line"><span class="chip ' . e($order['status']) . '">' . e(order_status_label($order)) . '</span><span class="chip ' . $deliveryClass . '">' . e($deliveryInfo) . '</span></div>' . ($mergeNotice ? '<p class="order-merge">' . e($mergeNotice) . '</p>' : '') . $mergeLinks . '<dl class="order-meta"><div><dt>회원</dt><dd>' . e($order['login_id']) . '</dd></div><div><dt>받는 분</dt><dd>' . e($order['recipient_name']) . '<br>' . e($order['recipient_phone']) . '</dd></div><div><dt>주소</dt><dd>' . e(trim($order['address1'] . ' ' . $order['address2'])) . '</dd></div><div><dt>결제수단</dt><dd>' . e($order['payment_method'] === 'card' ? '카드결제' : '계좌이체') . '</dd></div><div><dt>현금영수증</dt><dd>' . e($receiptLabel) . ($order['cash_receipt_value'] ? '<br>' . e($order['cash_receipt_value']) : '') . '</dd></div></dl></section><section class="card order-card order-action-card"><h2>주문 처리</h2><p class="action-help">' . e($actionHelp) . '</p><form method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><div class="order-actions">' . $actionButtons . '</div></form></section></aside></div>';
    admin_shell('주문 상세', $html);
}

function admin_order_action(int $id, string $action, string $cancelKind = 'cancel'): never
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) throw new RuntimeException('주문을 찾을 수 없습니다.');
        $pdo->prepare('UPDATE orders SET admin_checked_at=COALESCE(admin_checked_at,NOW()) WHERE id=?')->execute([$id]);
        if ($action === 'delete') {
            if ($order['status'] !== 'cancelled') throw new RuntimeException('취소된 주문만 삭제할 수 있습니다. 먼저 주문을 취소해 주세요.');
            if (!empty($order['shipment_request_id'])) throw new RuntimeException('출고 이력이 연결된 주문은 삭제할 수 없습니다.');
            $pdo->prepare('UPDATE point_ledger SET order_id=NULL WHERE order_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM payments WHERE target_type="order" AND target_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM orders WHERE id=? AND status="cancelled"')->execute([$id]);
            $pdo->commit();
            flash('ok', '주문을 삭제했습니다.');
            redirect_to('/admin/orders');
        }
        if ($action === 'confirm' && $order['status'] === 'pending') {
            $policy = point_policy(setting_values());
            $earned = 0;
            $isTier = $policy['mode'] === 'tier';
            if (!$isTier && $policy['mode'] === 'flat') {
                $earned = points_for((float)$order['items_amount'], (float)$order['point_used'], (float)$policy['rate']);
            }
            $nextStatus = $order['delivery_type'] === 'keep' ? 'kept' : 'paid';
            $shipmentRequestId = null;
            if ($nextStatus === 'paid' && !empty($order['merge_order_id'])) {
                $parentStmt = $pdo->prepare('SELECT id,status FROM orders WHERE id=? FOR UPDATE');
                $parentStmt->execute([(int)$order['merge_order_id']]);
                $mergeParent = $parentStmt->fetch();
                // 기존 데이터에서 대표 주문이 먼저 준비 처리된 경우에도 추가 주문을 같은 단계로 맞춘다.
                if ($mergeParent && $mergeParent['status'] === 'preparing') $nextStatus = 'preparing';
            }
            if ($nextStatus === 'paid' && !empty($order['keep_merge_requested'])) {
                $keepStmt = $pdo->prepare("SELECT id,items_amount FROM orders WHERE user_id=? AND status='kept' AND shipment_request_id IS NULL ORDER BY paid_at,id FOR UPDATE");
                $keepStmt->execute([$order['user_id']]);
                $keptOrders = $keepStmt->fetchAll();
                if ($keptOrders) {
                    $keepIds = array_map(static fn(array $kept): int => (int)$kept['id'], $keptOrders);
                    $keptTotal = array_sum(array_map(static fn(array $kept): int => (int)$kept['items_amount'], $keptOrders));
                    $requestNo = 'KS' . date('ymdHis') . random_int(10, 99);
                    $shipStmt = $pdo->prepare('INSERT INTO shipment_requests (request_no,user_id,status,kept_amount,shipping_fee,payment_method,depositor_name,recipient_name,recipient_phone,zipcode,address1,address2,memo,paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
                    $shipStmt->execute([$requestNo, $order['user_id'], 'preparing', $keptTotal, $order['shipping_fee'], 'bank', $order['depositor_name'], $order['recipient_name'], $order['recipient_phone'], $order['zipcode'], $order['address1'], $order['address2'], $order['memo']]);
                    $shipmentRequestId = (int)$pdo->lastInsertId();
                    $marks = implode(',', array_fill(0, count($keepIds), '?'));
                    $pdo->prepare("UPDATE orders SET shipment_request_id=?,status='preparing' WHERE id IN ($marks)")->execute(array_merge([$shipmentRequestId], $keepIds));
                    foreach ($keepIds as $keepId) log_order_event($pdo, $keepId, 'keep_merge', 'kept', 'preparing', '킵 상품 합배송 연결', 0, 'admin');
                    $nextStatus = 'preparing';
                }
            }
            $pdo->prepare('UPDATE orders SET status=?,paid_at=NOW(),reserve_expires_at=NULL,point_earned=?,shipment_request_id=? WHERE id=?')->execute([$nextStatus, $isTier ? 0 : $earned, $shipmentRequestId, $id]);
            if (!$isTier && $earned > 0) {
                $balance = $pdo->prepare('SELECT point_balance FROM users WHERE id=?');
                $balance->execute([$order['user_id']]);
                $after = (int)$balance->fetchColumn() + $earned;
                $pdo->prepare('UPDATE users SET point_balance=? WHERE id=?')->execute([$after,$order['user_id']]);
                $pdo->prepare('INSERT INTO point_ledger (user_id,type,amount,balance_after,order_id,memo) VALUES (?,?,?,?,?,?)')->execute([$order['user_id'],'earn',$earned,$after,$id,'주문 적립']);
            }
            if ($isTier) {
                $reconciled = reconcile_live_session_points($pdo, (int)$order['user_id'], (string)($order['live_session_started_at'] ?? ''), $policy);
                $earned = (int)($reconciled['earned'][$id] ?? 0);
            }
            log_order_event($pdo, $id, 'payment_confirmed', 'pending', $nextStatus, $nextStatus === 'preparing' ? '입금 확인 · 합배송 묶음과 함께 배송 준비중' : '입금 확인 · 결제 완료', 0, 'admin');
            $pdo->prepare('UPDATE payments SET status="paid",paid_at=NOW() WHERE target_type="order" AND target_id=?')->execute([$id]);
        } elseif ($action === 'prepare' && $order['status'] === 'paid') {
            // 합배송 약속이 깨지지 않도록 모든 추가 주문의 입금 확인이 끝난 뒤 묶음 전체를 처리한다.
            $mergeMembers = merge_group_orders($pdo, $order, true);
            $pendingMembers = array_filter($mergeMembers, static fn(array $member): bool => $member['status'] === 'pending');
            if ($pendingMembers) throw new RuntimeException('합배송 묶음에 입금 대기 주문 ' . count($pendingMembers) . '건이 있습니다. 먼저 입금 확인하거나 미입금 주문을 취소해 주세요.');
            foreach ($mergeMembers as $member) {
                $memberId = (int)$member['id'];
                if ($member['status'] !== 'paid') continue;
                $pdo->prepare("UPDATE orders SET status='preparing' WHERE id=?")->execute([$memberId]);
                log_order_event($pdo, $memberId, 'status_changed', 'paid', 'preparing', $memberId === $id ? '배송 준비중으로 변경' : '합배송 묶음과 함께 배송 준비중으로 변경', 0, 'admin');
            }
        } elseif ($action === 'ship' && $order['status'] === 'preparing') {
            $mergeMembers = merge_group_orders($pdo, $order, true);
            $notReadyMembers = array_filter($mergeMembers, static fn(array $member): bool => in_array($member['status'], ['pending', 'paid'], true));
            if ($notReadyMembers) throw new RuntimeException('합배송 묶음에 아직 배송 준비 전인 주문 ' . count($notReadyMembers) . '건이 있습니다. 주문 상태를 먼저 확인해 주세요.');
            foreach ($mergeMembers as $member) {
                $memberId = (int)$member['id'];
                if ($member['status'] !== 'preparing') continue;
                $pdo->prepare("UPDATE orders SET status='shipped',shipped_at=NOW() WHERE id=?")->execute([$memberId]);
                log_order_event($pdo, $memberId, 'shipped', 'preparing', 'shipped', $memberId === $id ? '배송 완료 · 판매 완료' : '합배송 묶음 배송 완료 · 판매 완료', 0, 'admin');
            }
            if (!empty($order['shipment_request_id'])) {
                $linked = $pdo->prepare("SELECT id,status FROM orders WHERE shipment_request_id=? AND id<>? FOR UPDATE");
                $linked->execute([$order['shipment_request_id'], $id]);
                foreach ($linked as $linkedOrder) {
                    if ($linkedOrder['status'] !== 'preparing') continue;
                    $pdo->prepare("UPDATE orders SET status='shipped',shipped_at=NOW() WHERE id=?")->execute([(int)$linkedOrder['id']]);
                    log_order_event($pdo, (int)$linkedOrder['id'], 'shipped', 'preparing', 'shipped', '합배송 배송 완료 · 판매 완료', 0, 'admin');
                }
                $pdo->prepare("UPDATE shipment_requests SET status='shipped',shipped_at=NOW() WHERE id=?")->execute([$order['shipment_request_id']]);
            }
        } elseif ($action === 'cancel' && $order['status'] !== 'cancelled') {
            $cancelKind = $cancelKind === 'return' ? 'return' : 'cancel';
            $oldStatus = (string)$order['status'];
            $items = $pdo->prepare('SELECT product_id,IF(stock_qty>0,stock_qty,qty) AS stock_qty FROM order_items WHERE order_id=?');
            $items->execute([$id]);
            foreach ($items as $item) $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$item['stock_qty'],$item['product_id']]);
            $balance = $pdo->prepare('SELECT point_balance FROM users WHERE id=?');
            $balance->execute([$order['user_id']]);
            $after = (int)$balance->fetchColumn();
            if ((int)$order['point_used'] > 0) {
                $after += (int)$order['point_used'];
                $pdo->prepare('INSERT INTO point_ledger (user_id,type,amount,balance_after,order_id,memo) VALUES (?,?,?,?,?,?)')->execute([$order['user_id'],'refund',$order['point_used'],$after,$id,'주문 취소 포인트 환불']);
            }
            if ((int)$order['point_earned'] > 0) {
                $after = max(0, $after - (int)$order['point_earned']);
                $pdo->prepare('INSERT INTO point_ledger (user_id,type,amount,balance_after,order_id,memo) VALUES (?,?,?,?,?,?)')->execute([$order['user_id'],'revoke',-(int)$order['point_earned'],$after,$id,'주문 취소 적립 회수']);
            }
            $pdo->prepare('UPDATE users SET point_balance=? WHERE id=?')->execute([$after,$order['user_id']]);
            if (!empty($order['shipment_request_id'])) {
                $linked = $pdo->prepare("SELECT id,status FROM orders WHERE shipment_request_id=? AND id<>? FOR UPDATE");
                $linked->execute([$order['shipment_request_id'], $id]);
                foreach ($linked as $linkedOrder) {
                    if (!in_array($linkedOrder['status'], ['preparing', 'shipped'], true)) continue;
                    $pdo->prepare("UPDATE orders SET status='kept',shipment_request_id=NULL WHERE id=?")->execute([(int)$linkedOrder['id']]);
                    log_order_event($pdo, (int)$linkedOrder['id'], 'status_changed', (string)$linkedOrder['status'], 'kept', '합배송 취소로 킵 보관 복원', 0, 'admin');
                }
                $pdo->prepare("UPDATE shipment_requests SET status='cancelled',cancelled_at=NOW(),cancel_reason=? WHERE id=?")->execute(['연결 주문 취소', $order['shipment_request_id']]);
            }
            $refundAmount = in_array($oldStatus, ['paid', 'kept', 'preparing', 'shipped'], true) ? (int)$order['total_amount'] : 0;
            $reason = $cancelKind === 'return' ? '반품·환불 처리' : '주문 취소·환불 처리';
            $eventType = $cancelKind === 'return' ? 'return_completed' : 'cancelled';
            $pdo->prepare('UPDATE payments SET status="cancelled" WHERE target_type="order" AND target_id=? AND status IN ("ready","paid")')->execute([$id]);
            $pdo->prepare("UPDATE orders SET status='cancelled',cancelled_at=NOW(),cancel_reason=?,point_earned=0 WHERE id=?")->execute([$reason, $id]);
            if ($order['payment_method'] === 'bank' && $order['paid_at']) {
                $policy = point_policy(setting_values());
                if ($policy['mode'] === 'tier') reconcile_live_session_points($pdo, (int)$order['user_id'], (string)($order['live_session_started_at'] ?? ''), $policy);
            }
            log_order_event($pdo, $id, $eventType, $oldStatus, 'cancelled', $reason . ($refundAmount > 0 ? ' · 실제 환불 필요' : ''), $refundAmount, 'admin');
        }
        $pdo->commit();
        flash('ok', $action === 'cancel' ? '취소·반품 처리 기록을 저장했습니다.' : ($action === 'confirm' ? '입금 확인 후 결제 완료로 처리했습니다.' : '주문 상태를 변경했습니다.'));
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $error->getMessage());
    }
    $returnTo = (string)post_value('return_to');
    redirect_to(preg_match('#^/admin/orders(?:/\d+)?$#', $returnTo) ? $returnTo : '/admin/orders/' . $id);
}

function admin_page_edit(string $slug): never
{
    $stmt = db()->prepare('SELECT * FROM pages WHERE slug=?');
    $stmt->execute([$slug]);
    $item = $stmt->fetch();
    if (!$item) admin_shell('페이지 없음', '<div class="card">페이지를 찾을 수 없습니다.</div>');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        db()->prepare('UPDATE pages SET title=?,content=? WHERE slug=?')->execute([post_value('title'),post_value('content'),$slug]);
        flash('ok', '저장했습니다.');
        redirect_to('/admin/pages/' . $slug);
    }
    admin_shell($item['title'], '<div class="page-head"><h1>' . e($item['title']) . '</h1></div><form method="post" class="card"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><label class="field"><span>제목</span><input name="title" value="' . e($item['title']) . '"></label><label class="field"><span>본문</span><textarea name="content" rows="20">' . e($item['content']) . '</textarea></label><button class="btn pink">저장</button></form>');
}
