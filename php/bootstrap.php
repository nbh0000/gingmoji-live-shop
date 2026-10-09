<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('gm_sid');
    session_start();
}

function env_file(string $path): array
{
    $values = [];
    if (!is_file($path)) return $values;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) {
            $value = trim($value, "\"'");
        }
        $values[$key] = $value;
    }
    return $values;
}

$GLOBALS['gm_env'] = array_merge(env_file(__DIR__ . '/../.env'), $_ENV);

function cfg(string $key, string $fallback = ''): string
{
    return (string)($GLOBALS['gm_env'][$key] ?? $fallback);
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('DB_HOST', 'localhost'), (int)cfg('DB_PORT', '3306'), cfg('DB_NAME', 'gingmoji00'));
    $pdo = new PDO($dsn, cfg('DB_USER', 'gingmoji00'), cfg('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    return $pdo;
}

function default_settings(): array
{
    return [
        'live_on' => false,
        'always_show_price' => false,
        'bank_name' => '케이뱅크',
        'bank_account' => '100301443318',
        'bank_holder' => '김민정(김모지)',
        'card_hold_minutes' => 10,
        'bank_auto_cancel_hours' => 24,
        'free_shipping_threshold' => 80000,
        'shipping_fee' => 4000,
        'show_stock' => false,
        'notice_text' => '',
        'event_notice_text' => '5만 원 이상 구매 시 뽑기 1회 제공',
        'point_earn_mode' => 'flat',
        'point_earn_rate' => 1,
        'point_earn_threshold' => 0,
        'point_earn_under_rate' => 1,
        'point_earn_over_rate' => 1,
        'point_use_enabled' => true,
        'point_min_balance' => 10000,
        'point_use_unit' => 10000,
        'point_max_ratio' => 100,
        'point_guide_text' => '포인트는 결제 금액의 1%가 자동 적립되며, 보유 10,000P 이상부터 결제 시 10,000P 단위로 자동 사용됩니다.',
        'cash_receipt_enabled' => true,
        'biz_name' => '깅모지',
        'biz_owner' => '김민정',
        'biz_reg_no' => '326-23-02307',
        'biz_mail_order_no' => '제0000-서울서초-0000호',
        'biz_address' => '서울특별시 서초구 방배천로2길 21, 4층 495호(방배동)',
        'biz_phone' => '010-0000-0000',
        'biz_email' => 'gingmoji@example.com',
        'biz_privacy_officer' => '김민정',
    ];
}

function setting_values(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $cache = default_settings();
    try {
        foreach (db()->query('SELECT `key`, `value` FROM settings') as $row) {
            if (!array_key_exists($row['key'], $cache)) continue;
            $default = $cache[$row['key']];
            if (is_bool($default)) $cache[$row['key']] = in_array((string)$row['value'], ['1', 'true', 'on'], true);
            elseif (is_int($default) || is_float($default)) $cache[$row['key']] = is_numeric($row['value']) ? (float)$row['value'] : $default;
            else $cache[$row['key']] = (string)$row['value'];
        }
    } catch (Throwable $e) {
        // DB 초기화 전에 기본값으로 화면을 렌더링할 수 있도록 합니다.
    }
    // 포인트 정책은 카드/현금 구분 없이 1% 자동 적립, 10,000P 이상 자동 사용으로 고정합니다.
    $cache['point_earn_mode'] = 'flat';
    $cache['point_earn_rate'] = 1;
    $cache['point_earn_threshold'] = 0;
    $cache['point_earn_under_rate'] = 1;
    $cache['point_earn_over_rate'] = 1;
    $cache['point_use_enabled'] = true;
    $cache['point_min_balance'] = 10000;
    $cache['point_use_unit'] = 10000;
    $cache['point_max_ratio'] = 100;
    $cache['point_guide_text'] = '포인트는 결제 금액의 1%가 자동 적립되며, 보유 10,000P 이상부터 결제 시 10,000P 단위로 자동 사용됩니다.';
    return $cache;
}

function save_settings(array $values): void
{
    $defaults = default_settings();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach ($values as $key => $value) {
        if (!array_key_exists($key, $defaults)) continue;
        if (is_bool($defaults[$key])) $value = in_array((string)$value, ['1', 'on', 'true'], true) ? '1' : '0';
        $stmt->execute([$key, is_array($value) ? '' : (string)$value]);
    }
}

function migrate_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(191) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(__DIR__ . '/../migrations/[0-9]*_*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) continue;
        $sql = file_get_contents($file);
        if ($sql === false) continue;
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
        $stmt->execute([$name]);
    }
    seed_default_pages($pdo);
    $done = true;
}

function seed_default_pages(PDO $pdo): void
{
    $pages = [
        ['terms', '이용약관', '## 주문 및 결제\n상품 주문과 결제에 동의합니다.\n\n## 문의\n{{상호}} 고객센터 {{연락처}} / {{이메일}}'],
        ['privacy', '개인정보처리방침', '## 수집하는 정보\n주문 처리에 필요한 이름, 휴대폰, 주소, 유튜브 닉네임을 수집합니다.\n\n## 개인정보 보호책임자\n{{개인정보책임자}} / {{연락처}}'],
        ['refund', '교환·환불 정책', '## 주문 취소\n결제 전 주문은 마이페이지에서 취소할 수 있습니다.\n\n## 문의\n{{상호}} 고객센터 {{연락처}} / {{이메일}}'],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO pages (slug, title, content) VALUES (?, ?, ?)');
    foreach ($pages as $page) $stmt->execute($page);
}

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function won(mixed $value): string
{
    return number_format((float)($value ?? 0), 0) . '원';
}

function num(mixed $value): string
{
    return number_format((float)($value ?? 0), 0);
}

function dt(mixed $value): string
{
    if (!$value) return '';
    $time = strtotime((string)$value);
    return $time ? date('Y.m.d H:i', $time) : '';
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(18));
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return;
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['_csrf'] ?? '';
    if (!hash_equals(csrf_token(), (string)$token)) {
        http_response_code(403);
        exit('보안 토큰이 만료되었습니다. 페이지를 새로고침해 주세요.');
    }
}

function current_user(): ?array
{
    static $user;
    if (array_key_exists('user', $GLOBALS)) return $GLOBALS['user'];
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([(int)$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            $user = null;
        }
    }
    $GLOBALS['user'] = $user;
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) redirect_to('/login');
    return $user;
}

function require_admin(): void
{
    if (empty($_SESSION['is_admin'])) redirect_to('/admin/login');
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function post_value(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function order_status_label(array $o): string
{
    if (($o['status'] ?? '') === 'pending') return ($o['payment_method'] ?? '') === 'card' ? '카드 결제대기' : '입금대기';
    return ['paid' => '결제완료 · 발송대기', 'kept' => '결제완료 · 킵보관', 'preparing' => '배송준비중', 'shipped' => '배송완료 · 판매완료', 'cancelled' => '취소·반품'][$o['status'] ?? ''] ?? (string)($o['status'] ?? '');
}

function order_event_label(array $event): string
{
    return [
        'created' => '주문 생성',
        'imported' => '기존 주문 기록 생성',
        'payment_confirmed' => '입금 확인 · 결제 완료',
        'keep_merge' => '킵 합배송 연결',
        'shipped' => '판매 완료 · 발송 완료',
        'cancelled' => '주문 취소 · 환불',
        'return_completed' => '반품 · 환불 처리',
        'status_changed' => '주문 상태 변경',
    ][(string)($event['event_type'] ?? '')] ?? (string)($event['event_type'] ?? '주문 처리');
}

function log_order_event(PDO $pdo, int $orderId, string $eventType, ?string $fromStatus, ?string $toStatus, string $memo = '', int $refundAmount = 0, string $actorType = 'system', ?int $actorId = null): void
{
    $pdo->prepare('INSERT INTO order_events (order_id,actor_type,actor_id,event_type,from_status,to_status,memo,refund_amount) VALUES (?,?,?,?,?,?,?,?)')->execute([
        $orderId, $actorType, $actorId, $eventType, $fromStatus, $toStatus, $memo, $refundAmount,
    ]);
}

function point_policy(array $s): array
{
    return ['mode' => 'flat', 'rate' => 1];
}

function points_for(float $items, float $used, float $rate): int
{
    return (int)floor(max(0, $items - $used) * $rate / 100);
}

function product_rows(bool $admin = false): array
{
    $sql = $admin
        ? "SELECT p.*, (SELECT i.id FROM product_images i WHERE i.product_id=p.id ORDER BY i.sort_order,i.id LIMIT 1) image_id, (SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status='pending') reserved_qty FROM products p WHERE p.deleted_at IS NULL ORDER BY p.sort_order,p.id"
        : "SELECT p.id,p.name,p.description,p.section,p.price,p.full_box_price,p.loose_price,p.stock,p.use_package_option,p.use_open_option,p.is_soldout,p.sort_order,(SELECT i.id FROM product_images i WHERE i.product_id=p.id ORDER BY i.sort_order,i.id LIMIT 1) image_id FROM products p WHERE p.deleted_at IS NULL AND p.is_visible=1 ORDER BY p.sort_order,p.id";
    return db()->query($sql)->fetchAll();
}

function one_product(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE id=? AND deleted_at IS NULL');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $stmt = db()->prepare('SELECT id,sort_order,MD5(data) AS image_hash FROM product_images WHERE product_id=? ORDER BY sort_order,id');
    $stmt->execute([$id]);
    $row['images'] = $stmt->fetchAll();
    return $row;
}
