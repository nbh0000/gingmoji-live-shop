<?php
declare(strict_types=1);

require __DIR__ . '/php/bootstrap.php';

try {
    migrate_schema();
} catch (Throwable $error) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>DB 설정 확인</title><style>body{font-family:system-ui;padding:40px;line-height:1.7}code,pre{background:#f3f3f3;padding:8px;white-space:pre-wrap}</style><h1>DB 연결을 확인해 주세요</h1><p>.env의 Cafe24 DB 정보와 마이그레이션 상태를 확인해 주세요.</p><pre>' . e($error->getMessage()) . '</pre>';
    exit;
}

check_csrf();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
if ($path !== '/') {
    $path = rtrim($path, '/');
}

if (str_starts_with($path, '/api/')) {
    handle_api($path);
}
if ($path === '/img/' . (int)($_GET['id'] ?? 0)) {
    handle_image((int)($_GET['id'] ?? 0));
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
    $css = $admin ? '/static/css/admin.css?v=php3' : '/static/css/shop.css?v=php3';
    $extra = '';
    foreach ($scripts as $script) {
        $extra .= '<script src="' . e($script) . '"></script>';
    }

    if ($admin && !$shell) {
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . ' · 깅모지 관리자</title><link rel="stylesheet" href="' . $css . '"></head><body>' . $body . '</body></html>';
        exit;
    }

    if ($admin) {
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . ' · 깅모지 관리자</title><link rel="stylesheet" href="' . $css . '"></head><body><div class="shell"><aside class="side"><a class="logo" href="/admin">깅모지<small>ADMIN</small></a><nav class="nav"><a href="/admin">대시보드</a><a href="/admin/orders">주문 관리</a><a href="/admin/products">상품·재고</a><a href="/admin/settings">사이트 설정</a><a href="/admin/pages">약관·정책</a></nav><div class="side-foot"><a href="/" target="_blank">고객 화면 열기 ↗</a><form method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button class="btn sm ghost">로그아웃</button></form></div></aside><main class="main">';
        if ($flash) {
            echo '<div class="flash ' . ($flash[0] === 'error' ? 'error' : '') . '">' . e($flash[1]) . '</div>';
        }
        echo $body . '</main></div><script src="/static/js/admin.js?v=php2"></script></body></html>';
        exit;
    }

    $user = current_user();
    $gm = [
        'live' => (bool)$s['live_on'],
        'showPrice' => (bool)$s['live_on'] || (bool)$s['always_show_price'],
        'loggedIn' => (bool)$user,
        'freeShip' => (int)$s['free_shipping_threshold'],
        'shipFee' => (int)$s['shipping_fee'],
        'opt' => ['opened' => '라이브 개봉', 'unopened' => '미개봉 발송'],
    ];
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#141011"><title>' . ($title ? e($title) . ' · ' : '') . '깅모지 LIVE</title><meta name="description" content="깅모지 라이브 상품 주문 사이트"><link rel="stylesheet" href="' . $css . '"><meta name="csrf" content="' . e(csrf_token()) . '"></head><body class="' . ($title === '' ? 'has-cartbar' : '') . '">';
    echo '<header class="top"><div class="wrap top-in"><a class="brand" href="/">깅모지<span class="q">♥</span></a><div class="top-actions"><span class="live-pill ' . ($s['live_on'] ? 'on' : '') . '" data-live-pill>' . ($s['live_on'] ? 'LIVE' : 'OFF') . '</span><a class="icon-btn" href="' . ($user ? '/my' : '/login') . '" aria-label="' . ($user ? '마이페이지' : '로그인') . '">◎</a><button class="icon-btn" type="button" data-open-cart aria-label="장바구니">🛒<span class="badge" data-cart-count hidden>0</span></button></div></div></header>';
    if ($flash) {
        echo '<div class="toast show ' . ($flash[0] === 'error' ? 'error' : '') . '" data-flash role="status">' . e($flash[1]) . '</div>';
    }
    echo $body;
    echo '<footer class="foot"><div class="wrap"><div class="brand">깅모지</div><nav class="foot-links"><a href="/page/terms">이용약관</a><a href="/page/privacy">개인정보처리방침</a><a href="/page/refund">교환·환불 정책</a></nav><div class="biz"><span>상호 ' . e($s['biz_name']) . '</span><span>대표자 ' . e($s['biz_owner']) . '</span><br><span>사업자등록번호 ' . e($s['biz_reg_no']) . '</span><br><span>주소 ' . e($s['biz_address']) . '</span><br><span>연락처 ' . e($s['biz_phone']) . '</span></div></div></footer>';
    echo '<script>window.GM=' . json_encode($gm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script><script src="/static/js/shop.js?v=php4"></script>' . $extra . '</body></html>';
    exit;
}

function shop_body(string $content, bool $withCart = false): string
{
    $cart = $withCart ? '<div class="cartbar" data-cartbar><button class="btn big" type="button" data-open-cart>장바구니 보기 <span class="count num" data-cartbar-text>0개</span></button></div><section class="sheet" id="cartSheet" hidden><div class="sheet-head"><h2>장바구니</h2><button type="button" data-close-sheet>닫기</button></div><div class="sheet-body" data-cart-body></div><div class="sheet-foot" data-cart-foot><div class="sum-row total"><span>상품금액</span><span data-cart-total>0원</span></div><a class="btn big block" href="/checkout" data-go-checkout>결제하러 가기</a></div></section><div class="sheet-backdrop" data-backdrop></div>' : '';
    return $content . $cart;
}

function handle_shop(string $path): never
{
    $s = setting_values();

    if ($path === '/') {
        $products = product_rows();
        $showPrice = (bool)$s['live_on'] || (bool)$s['always_show_price'];
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
        $body .= '</main>';
        page('', shop_body($body, true));
    }

    if (preg_match('#^/p/(\d+)$#', $path, $match)) {
        $product = one_product((int)$match[1]);
        if (!$product || !(int)$product['is_visible']) {
            http_response_code(404);
            page('상품 없음', '<main class="wrap page"><div class="panel"><h1>상품을 찾을 수 없습니다.</h1><a class="btn" href="/">홈으로</a></div></main>');
        }
        $showPrice = (bool)$s['live_on'] || (bool)$s['always_show_price'];
        $gallery = '';
        foreach ($product['images'] as $image) {
            $gallery .= '<div class="thumb"><img src="/img/' . (int)$image['id'] . '" alt="' . e($product['name']) . '"></div>';
        }
        if (!$gallery) {
            $gallery = '<div class="thumb"><span class="ph">이미지 없음</span></div>';
        }
        $body = '<main class="wrap page"><div class="page-title"><a class="back" href="/">←</a></div><div class="panel"><div class="p-gallery">' . $gallery . '</div><h1 class="p-name">' . e($product['name']) . '</h1><div class="' . ($showPrice ? 'p-price' : 'price hidden') . '">' . ($showPrice ? won($product['price']) : '가격은 방송 중 공개됩니다') . '</div><div class="p-meta">';
        if ((int)$product['is_soldout'] || (int)$product['stock'] <= 0) {
            $body .= '<span class="chip gray">품절</span>';
        } elseif ($s['show_stock']) {
            $body .= '<span class="chip">재고 ' . (int)$product['stock'] . '개</span>';
        }
        $body .= '</div>';
        if ($product['description']) {
            $body .= '<div class="p-desc">' . nl2br(e($product['description'])) . '</div>';
        }
        $body .= '<div class="opt-box"><label>수량 <input id="detailQty" type="number" min="1" max="' . (int)$product['stock'] . '" value="1"></label><button class="btn big" type="button" id="detailAdd">장바구니 담기</button></div></div></main>';
        $body .= '<script>(function(){var b=document.getElementById("detailAdd");if(!b)return;b.addEventListener("click",function(){var q=Math.max(1,parseInt(document.getElementById("detailQty").value||"1",10));var c=[];try{c=JSON.parse(localStorage.getItem("gm_cart_v1"))||[]}catch(e){}var l=c.find(function(x){return Number(x.productId)===' . (int)$product['id'] . '});if(!l){l={productId:' . (int)$product['id'] . ',name:' . json_encode($product['name'], JSON_UNESCAPED_UNICODE) . ',price:' . (int)$product['price'] . ',imageId:' . (!empty($product['images'][0]['id']) ? (int)$product['images'][0]['id'] : 'null') . ',option:false,qty:0}}l.qty=(l.qty||0)+q;c=c.filter(function(x){return Number(x.productId)!==' . (int)$product['id'] . '});c.push(l);localStorage.setItem("gm_cart_v1",JSON.stringify(c));location.href="/checkout";});})();</script>';
        page($product['name'], $body);
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
    return '<a class="card ' . ($soldout ? 'soldout' : '') . '" href="/p/' . (int)$product['id'] . '" data-product="' . (int)$product['id'] . '"><div class="thumb">' . (!empty($product['image_id']) ? '<img src="/img/' . (int)$product['image_id'] . '" alt="" loading="lazy">' : '<span class="ph">이미지</span>') . '</div><div class="name">' . e($product['name']) . '</div><div class="row"><div><div class="price ' . ($showPrice ? '' : 'hidden') . '" data-price-label>' . ($showPrice ? won($product['price']) : '방송 중 공개') . '</div><div class="stock-tag" data-stock-label>' . e($stockLabel) . '</div></div><span class="add-dot">+</span></div></a>';
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

function handle_api(string $path): never
{
    if ($path === '/api/live') {
        $s = setting_values();
        $products = [];
        foreach (product_rows() as $product) {
            $products[] = ['id' => (int)$product['id'], 'price' => ($s['live_on'] || $s['always_show_price']) ? (int)$product['price'] : null, 'stock' => (int)$product['stock'], 'soldout' => (bool)$product['is_soldout'] || (int)$product['stock'] <= 0];
        }
        json_out(['ok' => true, 'live' => (bool)$s['live_on'], 'showPrice' => (bool)$s['live_on'] || (bool)$s['always_show_price'], 'showStock' => (bool)$s['show_stock'], 'products' => $products]);
    }

    if (preg_match('#^/api/products/(\d+)$#', $path, $match)) {
        $s = setting_values();
        $product = one_product((int)$match[1]);
        if (!$product || !(int)$product['is_visible']) {
            json_out(['ok' => false, 'message' => '상품을 찾을 수 없습니다.'], 404);
        }
        json_out(['ok' => true, 'live' => (bool)$s['live_on'], 'product' => ['id' => (int)$product['id'], 'name' => $product['name'], 'description' => $product['description'] ?? '', 'price' => ($s['live_on'] || $s['always_show_price']) ? (int)$product['price'] : null, 'stock' => (int)$product['stock'], 'showStock' => (bool)$s['show_stock'], 'soldout' => (bool)$product['is_soldout'] || (int)$product['stock'] <= 0, 'useOpenOption' => (bool)$product['use_open_option'], 'images' => array_map(static fn(array $image): int => (int)$image['id'], $product['images'])]]);
    }

    if ($path === '/api/cart/quote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        json_out(cart_quote(request_json()));
    }
    if ($path === '/api/orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        create_order(request_json());
    }
    json_out(['ok' => false, 'message' => 'API를 찾을 수 없습니다.'], 404);
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
    foreach ((array)($input['lines'] ?? []) as $line) {
        $id = (int)($line['productId'] ?? 0);
        if (!isset($byId[$id])) continue;
        $product = $byId[$id];
        $qty = (int)($product['use_open_option'] ? ($line['opened'] ?? 0) + ($line['unopened'] ?? 0) : ($line['qty'] ?? 0));
        if ($qty < 1) continue;
        $problem = '';
        if (!$settings['live_on']) $problem = '방송이 종료되었습니다.';
        elseif ((int)$product['is_soldout']) $problem = '품절된 상품입니다.';
        elseif ($qty > (int)$product['stock']) $problem = '재고가 부족합니다.';
        if ($problem) $hasProblem = true;
        $image = one_product((int)$product['id']);
        $imageId = $image && $image['images'] ? (int)$image['images'][0]['id'] : null;
        $lineAmount = (int)$product['price'] * $qty;
        $itemsAmount += $lineAmount;
        $lines[] = ['productId' => $id, 'name' => $product['name'], 'unitPrice' => (int)$product['price'], 'qty' => $qty, 'opened' => (int)($line['opened'] ?? 0), 'unopened' => (int)($line['unopened'] ?? 0), 'useOpenOption' => (bool)$product['use_open_option'], 'imageId' => $imageId, 'lineAmount' => $lineAmount, 'stock' => (int)$product['stock'], 'problem' => $problem];
    }
    $delivery = ($input['deliveryType'] ?? 'direct') === 'keep' ? 'keep' : 'direct';
    $shipping = $delivery === 'keep' ? 0 : ($itemsAmount >= (int)$settings['free_shipping_threshold'] ? 0 : (int)$settings['shipping_fee']);
    $user = current_user();
    $pointUse = max(0, (int)($input['pointUse'] ?? 0));
    $maxPoints = 0;
    if ($user && $settings['point_use_enabled']) {
        $maxPoints = min((int)$user['point_balance'], max(0, $itemsAmount + $shipping));
        $maxPoints = (int)(floor($maxPoints / max(1, (int)$settings['point_use_unit'])) * (int)$settings['point_use_unit']);
        $pointUse = min($pointUse, $maxPoints);
        $pointUse = (int)(floor($pointUse / max(1, (int)$settings['point_use_unit'])) * (int)$settings['point_use_unit']);
    } else {
        $pointUse = 0;
    }
    $total = max(0, $itemsAmount + $shipping - $pointUse);
    return ['ok' => true, 'live' => (bool)$settings['live_on'], 'lines' => $lines, 'hasProblem' => $hasProblem, 'summary' => ['itemsAmount' => $itemsAmount, 'shippingFee' => $shipping, 'deliveryType' => $delivery, 'pointUsed' => $pointUse, 'total' => $total, 'maxPoints' => $maxPoints, 'remainingForFree' => max(0, (int)$settings['free_shipping_threshold'] - $itemsAmount)]];
}

function create_order(array $input): never
{
    $user = require_user();
    $settings = setting_values();
    if (!$settings['live_on']) {
        json_out(['ok' => false, 'message' => '방송 중에만 주문할 수 있습니다.'], 400);
    }
    $payment = ($input['paymentMethod'] ?? '') === 'bank' ? 'bank' : '';
    if ($payment !== 'bank') {
        json_out(['ok' => false, 'message' => '현재 계좌이체 주문만 가능합니다.'], 400);
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
        $delivery = $quote['summary']['deliveryType'];
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
            if (!$row || $row['is_soldout'] || (int)$row['stock'] < (int)$line['qty']) throw new RuntimeException('재고가 변경되어 주문을 다시 시도해 주세요.');
            $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$line['qty'], $line['productId']]);
        }
        $stmt = $pdo->prepare('INSERT INTO orders (order_no,user_id,status,payment_method,delivery_type,items_amount,shipping_fee,point_used,total_amount,recipient_name,recipient_phone,zipcode,address1,address2,memo,depositor_name,cash_receipt_type,cash_receipt_value,cash_receipt_status,youtube_nickname,reserve_expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$orderNo, $user['id'], 'pending', 'bank', $delivery, $quote['summary']['itemsAmount'], $quote['summary']['shippingFee'], $pointUsed, $quote['summary']['total'], $name, $phone, (string)($recipient['zipcode'] ?? ''), $address1, trim((string)($recipient['address2'] ?? '')), trim((string)($recipient['memo'] ?? '')), trim((string)($input['depositorName'] ?? '')), $receiptType, $receiptValue, $receiptType === 'none' ? 'none' : 'requested', $user['youtube_nickname'] ?? '', $expires]);
        $orderId = (int)$pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,unit_price,qty,qty_opened,qty_unopened,line_amount) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($quote['lines'] as $line) {
            $itemStmt->execute([$orderId, $line['productId'], $line['name'], $line['unitPrice'], $line['qty'], $line['opened'], $line['unopened'], $line['lineAmount']]);
        }
        $pdo->prepare('INSERT INTO payments (target_type,target_id,method,amount,status) VALUES (?,?,?,?,?)')->execute(['order', $orderId, 'bank', $quote['summary']['total'], 'ready']);
        if (!empty($input['saveAddress'])) {
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
        if (!preg_match('/^[a-z0-9_]{4,50}$/', $login) || strlen($password) < 8 || $name === '') {
            flash('error', '아이디, 8자 이상 비밀번호, 성함을 확인해 주세요.');
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO users (login_id,password_hash,name,phone,zipcode,address1,address2,youtube_nickname,profile_completed) VALUES (?,?,?,?,?,?,?,?,1)');
                $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT), $name, post_value('phone'), post_value('zipcode'), post_value('address1'), post_value('address2'), post_value('youtube_nickname')]);
                $_SESSION['user_id'] = (int)db()->lastInsertId();
                redirect_to('/');
            } catch (Throwable $error) {
                flash('error', '이미 사용 중인 아이디일 수 있습니다.');
            }
        }
    }
    $body = '<main class="wrap page"><div class="panel auth"><h1>회원가입</h1><p class="hint">카카오·구글 가입은 OAuth 키를 받은 뒤 연결합니다.</p><form method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><label class="field"><span>아이디</span><input name="login_id" pattern="[a-z0-9_]{4,50}" required></label><label class="field"><span>비밀번호</span><input name="password" type="password" minlength="8" required></label><label class="field"><span>성함</span><input name="name" required></label><label class="field"><span>휴대폰</span><input name="phone" required></label><label class="field"><span>유튜브 닉네임</span><input name="youtube_nickname" required></label><label class="field"><span>주소</span><input name="zipcode"><input name="address1" placeholder="주소"><input name="address2" placeholder="상세주소"></label><button class="btn big block">가입하기</button></form></div></main>';
    page('회원가입', $body);
}

function render_checkout(): never
{
    $user = require_user();
    $settings = setting_values();
    $pointGuide = e((string)$settings['point_guide_text']);
    $body = '<main class="wrap page"><div class="page-title"><h1>주문서</h1></div><div class="panel"><div data-co-lines></div><div class="summary"><div class="sum-row"><span>상품금액</span><span data-sum-items>-</span></div><div class="sum-row"><span>배송비</span><span data-sum-ship>-</span></div><div class="sum-row" data-sum-point-row hidden><span>포인트</span><span data-sum-point>-</span></div><div class="sum-row total"><span>결제금액</span><span data-sum-total>-</span></div></div><p class="hint" data-free-hint></p><form data-addr-form><h2>배송 정보</h2><label class="field"><span>받는 분</span><input name="name" value="' . e($user['name']) . '"></label><label class="field"><span>휴대폰</span><input name="phone" value="' . e($user['phone']) . '"></label><label class="field"><span>우편번호</span><input name="zipcode" value="' . e($user['zipcode']) . '"></label><label class="field"><span>주소</span><input name="address1" value="' . e($user['address1']) . '"><input name="address2" value="' . e($user['address2']) . '" placeholder="상세주소"></label><label class="field"><span>배송 메모</span><input name="memo"></label><label><input type="checkbox" name="saveAddress" checked> 다음에도 이 주소 사용</label><button type="button" class="btn sm" data-edit-addr hidden>주소 수정</button><h2>배송 방법</h2><label><input type="radio" name="delivery" value="direct" checked> 바로배송</label><label><input type="radio" name="delivery" value="keep"> 킵 보관</label><h2>결제 수단</h2><label><input type="radio" name="payment" value="bank" checked> 계좌이체</label><input name="depositorName" placeholder="입금자명"><div data-cash-receipt><h3>현금영수증</h3><label><input type="radio" name="cashReceiptType" value="none" checked> 신청 안 함</label><label><input type="radio" name="cashReceiptType" value="income"> 소득공제용</label><label><input type="radio" name="cashReceiptType" value="expense"> 지출증빙용</label><input name="cashReceiptValue" data-cash-receipt-value placeholder="휴대폰 번호 또는 사업자등록번호" hidden></div><h2>포인트</h2><p class="hint">' . $pointGuide . '</p><input data-point-input name="pointUse" inputmode="numeric" placeholder="사용할 포인트"><button type="button" class="btn sm" data-point-max>최대 사용</button><p class="hint" data-point-hint></p><button type="button" class="btn big block pink" data-place-order>주문하기</button></form></div></main><script>window.GM.freeShip=' . (int)$settings['free_shipping_threshold'] . ';window.GM.shipFee=' . (int)$settings['shipping_fee'] . ';</script>';
    page('주문서', $body, false, ['/static/js/checkout.js?v=php2']);
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
    $rows = '';
    foreach ($items as $item) {
        $rows .= '<li>' . e($item['product_name']) . ' × ' . (int)$item['qty'] . ' <span>' . won($item['line_amount']) . '</span></li>';
    }
    $settings = setting_values();
    $receipt = $order['cash_receipt_type'] === 'none' ? '신청 안 함' : ($order['cash_receipt_type'] === 'income' ? '소득공제용' : '지출증빙용');
    $body = '<main class="wrap page"><div class="panel"><h1>주문 완료</h1><p>주문번호 <strong>' . e($order['order_no']) . '</strong></p><p>상태: ' . e(order_status_label($order)) . '</p><ul class="order-items">' . $rows . '</ul><div class="sum-row total"><span>결제금액</span><span>' . won($order['total_amount']) . '</span></div><div class="notice">입금 계좌: ' . e($settings['bank_name']) . ' ' . e($settings['bank_account']) . ' (' . e($settings['bank_holder']) . ')<br>입금자명: ' . e($order['depositor_name']) . '<br>현금영수증: ' . e($receipt) . '</div><a class="btn block" href="/my/orders">주문 내역 보기</a></div></main>';
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
    $stmt = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 100');
    $stmt->execute([$user['id']]);
    $rows = '';
    foreach ($stmt as $order) $rows .= '<tr><td><a href="/orders/' . e($order['order_no']) . '">' . e($order['order_no']) . '</a></td><td>' . e(dt($order['created_at'])) . '</td><td>' . e(order_status_label($order)) . '</td><td>' . won($order['total_amount']) . '</td></tr>';
    page('마이페이지', '<main class="wrap page"><div class="panel"><h1>' . e($user['name'] ?: $user['login_id']) . '님</h1><p><a class="btn sm" href="/my/points">포인트 보기</a> <a class="btn sm" href="/logout">로그아웃</a></p><table><tbody>' . $rows . '</tbody></table></div></main>');
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
        $counts = ['products' => (int)db()->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL')->fetchColumn(), 'orders' => (int)db()->query('SELECT COUNT(*) FROM orders WHERE status = "pending"')->fetchColumn(), 'users' => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()];
        $s = setting_values();
        admin_shell('대시보드', '<div class="page-head"><h1>대시보드</h1></div><div class="stats"><div class="stat"><b>' . $counts['products'] . '</b><span>상품</span></div><div class="stat"><b>' . $counts['orders'] . '</b><span>입금 대기</span></div><div class="stat"><b>' . $counts['users'] . '</b><span>회원</span></div></div><div class="card"><h2>방송 상태</h2><p>' . ($s['live_on'] ? 'LIVE' : 'OFF') . '</p><form method="post" action="/admin/live"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="on" value="' . ($s['live_on'] ? '0' : '1') . '"><button class="btn pink">' . ($s['live_on'] ? '방송 종료' : '방송 시작') . '</button></form></div>');
    }

    if ($path === '/admin/live' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        save_settings(['live_on' => post_value('on') === '1']);
        flash('ok', '방송 상태를 저장했습니다.');
        redirect_to('/admin');
    }

    if ($path === '/admin/products') {
        $products = product_rows(true);
        $rows = '';
        foreach ($products as $product) {
            $rows .= '<tr><td>' . e($product['name']) . '</td><td>' . num($product['price']) . '원</td><td>' . (int)$product['stock'] . '</td><td>' . ($product['is_visible'] ? '노출' : '숨김') . '</td><td><a class="btn sm" href="/admin/products/' . (int)$product['id'] . '">수정</a></td></tr>';
        }
        admin_shell('상품 관리', '<div class="page-head"><h1>상품·재고</h1><a class="btn pink" href="/admin/products/new">상품 등록</a></div><div class="table-wrap"><table><thead><tr><th>상품</th><th>가격</th><th>재고</th><th>노출</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    }

    if ($path === '/admin/products/new' || preg_match('#^/admin/products/(\d+)$#', $path, $match)) {
        admin_product($path === '/admin/products/new' ? 0 : (int)$match[1]);
    }

    if ($path === '/admin/settings') {
        admin_settings();
    }

    if ($path === '/admin/orders') {
        $orders = db()->query('SELECT o.*, u.login_id FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 200')->fetchAll();
        $rows = '';
        foreach ($orders as $order) $rows .= '<tr><td><a href="/admin/orders/' . (int)$order['id'] . '">' . e($order['order_no']) . '</a></td><td>' . e($order['login_id']) . '</td><td>' . e(order_status_label($order)) . '</td><td>' . won($order['total_amount']) . '</td><td>' . e(dt($order['created_at'])) . '</td></tr>';
        admin_shell('주문 관리', '<div class="page-head"><h1>주문 관리</h1></div><div class="table-wrap"><table><thead><tr><th>주문번호</th><th>회원</th><th>상태</th><th>금액</th><th>일시</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
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

function admin_product(int $id): never
{
    $product = $id ? one_product($id) : ['id' => 0, 'name' => '', 'description' => '', 'section' => 'live', 'price' => 0, 'stock' => 0, 'use_open_option' => 1, 'is_visible' => 1, 'is_soldout' => 0, 'images' => []];
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
        $data = [trim((string)post_value('name')), trim((string)post_value('description')), post_value('section') === 'sample' ? 'sample' : 'live', max(0, (int)post_value('price')), max(0, (int)post_value('stock')), isset($_POST['use_open_option']) ? 1 : 0, isset($_POST['is_visible']) ? 1 : 0, isset($_POST['is_soldout']) ? 1 : 0];
        if ($id) {
            db()->prepare('UPDATE products SET name=?,description=?,section=?,price=?,stock=?,use_open_option=?,is_visible=?,is_soldout=? WHERE id=?')->execute([...$data, $id]);
        } else {
            $stmt = db()->prepare('INSERT INTO products (name,description,section,price,stock,use_open_option,is_visible,is_soldout,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
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
    $html = '<div class="page-head"><h1>사이트 설정</h1></div><form method="post" class="card"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><h2>고객 화면</h2><label><input type="checkbox" name="live_on" ' . $checkbox('live_on') . '> 방송 중</label><label><input type="checkbox" name="always_show_price" ' . $checkbox('always_show_price') . '> 방송 전에도 가격 표시</label><label><input type="checkbox" name="show_stock" ' . $checkbox('show_stock') . '> 고객에게 재고 수량 표시</label><label class="field"><span>공지 문구</span><textarea name="notice_text">' . e($s['notice_text']) . '</textarea></label><label class="field"><span>이벤트 문구</span><input name="event_notice_text" value="' . e($s['event_notice_text']) . '"></label><h2>배송</h2><label class="field"><span>무료배송 기준</span><input type="number" name="free_shipping_threshold" value="' . (int)$s['free_shipping_threshold'] . '"></label><label class="field"><span>배송비</span><input type="number" name="shipping_fee" value="' . (int)$s['shipping_fee'] . '"></label><h2>포인트</h2><label class="field"><span>적립 방식</span><select name="point_earn_mode"><option value="off" ' . ($s['point_earn_mode'] === 'off' ? 'selected' : '') . '>사용 안 함</option><option value="flat" ' . ($s['point_earn_mode'] === 'flat' ? 'selected' : '') . '>단일 비율</option><option value="tier" ' . ($s['point_earn_mode'] === 'tier' ? 'selected' : '') . '>당일 합산 2단계</option></select></label><label class="field"><span>30만원 미만 적립률</span><input name="point_earn_under_rate" type="number" step="0.1" value="' . e($s['point_earn_under_rate']) . '"></label><label class="field"><span>30만원 이상 적립률</span><input name="point_earn_over_rate" type="number" step="0.1" value="' . e($s['point_earn_over_rate']) . '"></label><label class="field"><span>등급 기준 금액</span><input name="point_earn_threshold" type="number" value="' . e($s['point_earn_threshold']) . '"></label><label><input type="checkbox" name="point_use_enabled" ' . $checkbox('point_use_enabled') . '> 포인트 사용 허용</label><label class="field"><span>최소 보유 포인트</span><input name="point_min_balance" type="number" value="' . e($s['point_min_balance']) . '"></label><label class="field"><span>사용 단위</span><input name="point_use_unit" type="number" value="' . e($s['point_use_unit']) . '"></label><label class="field"><span>포인트 안내</span><textarea name="point_guide_text">' . e($s['point_guide_text']) . '</textarea></label><h2>현금영수증</h2><label><input type="checkbox" name="cash_receipt_enabled" ' . $checkbox('cash_receipt_enabled') . '> 신청 기능 사용</label><h2>사업자 정보</h2><label class="field"><span>상호</span><input name="biz_name" value="' . e($s['biz_name']) . '"></label><label class="field"><span>대표자</span><input name="biz_owner" value="' . e($s['biz_owner']) . '"></label><label class="field"><span>사업자등록번호</span><input name="biz_reg_no" value="' . e($s['biz_reg_no']) . '"></label><label class="field"><span>주소</span><input name="biz_address" value="' . e($s['biz_address']) . '"></label><label class="field"><span>전화</span><input name="biz_phone" value="' . e($s['biz_phone']) . '"></label><label class="field"><span>이메일</span><input name="biz_email" value="' . e($s['biz_email']) . '"></label><button class="btn pink">저장</button></form>';
    $html = str_replace('<h2>배송</h2>', '<h2>입금 안내</h2><label class="field"><span>은행명</span><input name="bank_name" value="' . e($s['bank_name']) . '"></label><label class="field"><span>계좌번호</span><input name="bank_account" value="' . e($s['bank_account']) . '"></label><label class="field"><span>예금주</span><input name="bank_holder" value="' . e($s['bank_holder']) . '"></label><h2>배송</h2>', $html);
    admin_shell('사이트 설정', $html);
}

function admin_order(int $id): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_order_action($id, (string)post_value('action'));
    }
    $stmt = db()->prepare('SELECT o.*,u.login_id FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) admin_shell('주문 없음', '<div class="card">주문을 찾을 수 없습니다.</div>');
    $items = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
    $items->execute([$id]);
    $list = '';
    foreach ($items as $item) $list .= '<li>' . e($item['product_name']) . ' × ' . (int)$item['qty'] . ' · ' . won($item['line_amount']) . '</li>';
    $html = '<div class="page-head"><h1>주문 ' . e($order['order_no']) . '</h1></div><div class="card"><p>회원: ' . e($order['login_id']) . '</p><p>상태: ' . e(order_status_label($order)) . '</p><p>받는 분: ' . e($order['recipient_name']) . ' / ' . e($order['recipient_phone']) . '</p><p>주소: ' . e($order['address1']) . ' ' . e($order['address2']) . '</p><p>현금영수증: ' . e((string)$order['cash_receipt_type']) . ' / ' . e((string)$order['cash_receipt_value']) . '</p><ul>' . $list . '</ul><div class="sum-row total">' . won($order['total_amount']) . '</div><form method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button class="btn pink" name="action" value="confirm">입금 확인·포인트 적립</button> <button class="btn danger" name="action" value="cancel">주문 취소</button></form></div>';
    admin_shell('주문 상세', $html);
}

function admin_order_action(int $id, string $action): never
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) throw new RuntimeException('주문을 찾을 수 없습니다.');
        if ($action === 'confirm' && $order['status'] === 'pending') {
            $policy = point_policy(setting_values());
            $earned = 0;
            if ($policy['mode'] === 'flat') {
                $earned = points_for((float)$order['items_amount'], (float)$order['point_used'], (float)$policy['rate']);
            } elseif ($policy['mode'] === 'tier') {
                $prior = $pdo->prepare("SELECT COALESCE(SUM(items_amount-point_used),0) FROM orders WHERE user_id=? AND payment_method='bank' AND status<>'cancelled' AND paid_at>=?");
                $prior->execute([$order['user_id'], date('Y-m-d 00:00:00')]);
                $dayTotal = (float)$prior->fetchColumn() + (float)$order['items_amount'] - (float)$order['point_used'];
                $rate = $dayTotal >= (float)$policy['threshold'] ? (float)$policy['over'] : (float)$policy['under'];
                $earned = points_for((float)$order['items_amount'], (float)$order['point_used'], $rate);
            }
            $pdo->prepare("UPDATE orders SET status='paid',paid_at=NOW(),reserve_expires_at=NULL,point_earned=? WHERE id=?")->execute([$earned,$id]);
            if ($earned > 0) {
                $balance = $pdo->prepare('SELECT point_balance FROM users WHERE id=?');
                $balance->execute([$order['user_id']]);
                $after = (int)$balance->fetchColumn() + $earned;
                $pdo->prepare('UPDATE users SET point_balance=? WHERE id=?')->execute([$after,$order['user_id']]);
                $pdo->prepare('INSERT INTO point_ledger (user_id,type,amount,balance_after,order_id,memo) VALUES (?,?,?,?,?,?)')->execute([$order['user_id'],'earn',$earned,$after,$id,'주문 적립']);
            }
            $pdo->prepare('UPDATE payments SET status="paid",paid_at=NOW() WHERE target_type="order" AND target_id=?')->execute([$id]);
        } elseif ($action === 'cancel' && $order['status'] !== 'cancelled') {
            $items = $pdo->prepare('SELECT product_id,qty FROM order_items WHERE order_id=?');
            $items->execute([$id]);
            foreach ($items as $item) $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$item['qty'],$item['product_id']]);
            $balance = $pdo->prepare('SELECT point_balance FROM users WHERE id=?');
            $balance->execute([$order['user_id']]);
            $after = (int)$balance->fetchColumn();
            if ((int)$order['point_used'] > 0) {
                $after += (int)$order['point_used'];
                $pdo->prepare('INSERT INTO point_ledger (user_id,type,amount,balance_after,order_id,memo) VALUES (?,?,?,?,?,?)')->execute([$order['user_id'],'refund',$order['point_used'],$after,$id,'주문 취소 포인트 환불']);
            }
            if ((int)$order['point_earned'] > 0) {
                $after -= (int)$order['point_earned'];
                $pdo->prepare('INSERT INTO point_ledger (user_id,type,amount,balance_after,order_id,memo) VALUES (?,?,?,?,?,?)')->execute([$order['user_id'],'revoke',-(int)$order['point_earned'],$after,$id,'주문 취소 적립 회수']);
            }
            $pdo->prepare('UPDATE users SET point_balance=? WHERE id=?')->execute([$after,$order['user_id']]);
            $pdo->prepare("UPDATE orders SET status='cancelled',cancelled_at=NOW(),point_earned=0 WHERE id=?")->execute([$id]);
        }
        $pdo->commit();
        flash('ok', '주문을 처리했습니다.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $error->getMessage());
    }
    redirect_to('/admin/orders/' . $id);
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
