<?php
declare(strict_types=1);

// 킵 월 2회 정기 출고 · 바로배송 출고 예정일 · 쿠폰 · 관리자 배송/쿠폰 관리

const KEEP_RELEASE_WEEKS = [2, 4];   // 둘째 주, 넷째 주가 끝난 다음 월요일
const BROADCAST_DAY_CUTOFF_HOUR = 6; // 방송이 새벽까지 이어지므로 오전 6시 전 주문은 전날 방송으로 본다
const KEEP_COUPON_VALID_DAYS = 90;

/** 그 달의 킵 정기 출고일. 주는 월요일부터 세고(1일이 든 주가 첫째 주), n번째 주 일요일의 다음 날 월요일 */
function keep_release_dates_for_month(int $year, int $month): array
{
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $firstWeekday = (int)$first->format('N'); // 1=월 … 7=일
    $dates = [];
    foreach (KEEP_RELEASE_WEEKS as $week) {
        $sunday = 1 + (7 - $firstWeekday) + 7 * ($week - 1);
        $dates[] = $first->modify('+' . $sunday . ' days')->format('Y-m-d');
    }
    return $dates;
}

/** 이 시각에 킵 결제한 상품이 나가는 출고일(그 월요일 0시 전까지 결제분이 해당) */
function keep_cycle_for(?int $time = null): string
{
    $time ??= time();
    $base = new DateTimeImmutable('@' . $time);
    $base = $base->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $candidates = [];
    for ($i = -1; $i <= 2; $i++) {
        $month = $base->modify('first day of this month')->modify(($i >= 0 ? '+' : '') . $i . ' month');
        $candidates = array_merge($candidates, keep_release_dates_for_month((int)$month->format('Y'), (int)$month->format('n')));
    }
    sort($candidates);
    foreach ($candidates as $date) {
        if (strtotime($date . ' 00:00:00') > $time) return $date;
    }
    return end($candidates);
}

function weekday_ko(string $date): string
{
    return ['일', '월', '화', '수', '목', '금', '토'][(int)date('w', strtotime($date))];
}

function short_date(string $date): string
{
    return date('n/j', strtotime($date)) . '(' . weekday_ko($date) . ')';
}

/** 바로배송 출고 예정일: 수→금, 목→토, 금·토→월, 그 밖의 요일은 이틀 뒤 */
function direct_ship_date(string $createdAt): string
{
    $broadcast = strtotime($createdAt) - BROADCAST_DAY_CUTOFF_HOUR * 3600;
    $weekday = (int)date('N', $broadcast); // 1=월 … 7=일
    $add = [3 => 2, 4 => 2, 5 => 3, 6 => 2][$weekday] ?? 2;
    return date('Y-m-d', strtotime(date('Y-m-d', $broadcast) . ' +' . $add . ' days'));
}

function order_ship_date(array $order): string
{
    if ($order['delivery_type'] === 'keep') {
        return (string)($order['keep_cycle'] ?: keep_cycle_for(strtotime((string)($order['paid_at'] ?: $order['created_at']))));
    }
    return direct_ship_date((string)$order['created_at']);
}

/** 킵 배송비: 이번 출고일까지의 첫 킵 결제만 받는다(그 주문만으로 무료배송 기준 이상이면 받지 않음) */
function keep_shipping_fee(?int $userId, int $itemsAmount, array $settings): array
{
    $cycle = keep_cycle_for();
    $fee = (int)$settings['shipping_fee'];
    if ($itemsAmount >= (int)$settings['free_shipping_threshold']) return ['fee' => 0, 'cycle' => $cycle, 'reason' => 'free'];
    if ($userId) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND delivery_type='keep' AND keep_cycle=? AND status IN ('pending','kept')");
        $stmt->execute([$userId, $cycle]);
        if ((int)$stmt->fetchColumn() > 0) return ['fee' => 0, 'cycle' => $cycle, 'reason' => 'paid'];
    }
    return ['fee' => $fee, 'cycle' => $cycle, 'reason' => 'first'];
}

// ---------- 쿠폰 ----------

function usable_coupons(int $userId, int $orderAmount = PHP_INT_MAX): array
{
    $stmt = db()->prepare("SELECT * FROM coupons WHERE user_id=? AND used_order_id IS NULL AND revoked_at IS NULL AND starts_at<=NOW() AND (expires_at IS NULL OR expires_at>NOW()) AND min_order_amount<=? ORDER BY amount DESC, expires_at IS NULL, expires_at, id");
    $stmt->execute([$userId, $orderAmount]);
    return $stmt->fetchAll();
}

function coupon_status(array $coupon): array
{
    if ($coupon['revoked_at']) return ['revoked', '회수'];
    if ($coupon['used_order_id']) return ['used', '사용함'];
    if ($coupon['expires_at'] && strtotime((string)$coupon['expires_at']) <= time()) return ['expired', '기간 만료'];
    if (strtotime((string)$coupon['starts_at']) > time()) return ['scheduled', '사용 전'];
    return ['active', '사용 가능'];
}

function coupon_period(array $coupon): string
{
    return date('Y.m.d', strtotime((string)$coupon['starts_at'])) . ' ~ ' . ($coupon['expires_at'] ? date('Y.m.d', strtotime((string)$coupon['expires_at'])) : '기한 없음');
}

/** 주문 취소 시 그 주문에 쓴 쿠폰을 되돌린다 */
function restore_order_coupon(PDO $pdo, int $orderId): void
{
    $pdo->prepare('UPDATE coupons SET used_order_id=NULL,used_at=NULL WHERE used_order_id=?')->execute([$orderId]);
}

function keep_cycle_totals(PDO $pdo, int $userId, string $cycle): array
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(items_amount),0) AS items, COALESCE(SUM(shipping_fee),0) AS fee FROM orders WHERE user_id=? AND delivery_type='keep' AND keep_cycle=? AND status IN ('kept','paid','preparing','shipped')");
    $stmt->execute([$userId, $cycle]);
    $row = $stmt->fetch();
    return ['items' => (int)$row['items'], 'fee' => (int)$row['fee']];
}

/** 킵 누적이 무료배송 기준 이상이 되면, 이번 출고분에 낸 킵 배송비만큼 쿠폰을 한 번 발행한다 */
function issue_keep_coupon(PDO $pdo, int $userId, string $cycle, array $settings): void
{
    $totals = keep_cycle_totals($pdo, $userId, $cycle);
    if ($totals['fee'] <= 0 || $totals['items'] < (int)$settings['free_shipping_threshold']) return;
    $pdo->prepare("INSERT IGNORE INTO coupons (user_id,name,amount,starts_at,expires_at,source,keep_cycle,memo) VALUES (?,?,?,NOW(),?,?,?,?)")
        ->execute([$userId, '킵 무료배송 쿠폰', $totals['fee'], date('Y-m-d 23:59:59', strtotime('+' . KEEP_COUPON_VALID_DAYS . ' days')), 'keep', $cycle, short_date($cycle) . ' 출고분 킵 ' . won($totals['items']) . ' 달성']);
}

/** 킵 주문 취소로 기준 밑으로 내려가면 아직 안 쓴 킵 쿠폰은 회수한다 */
function revoke_keep_coupon_if_needed(PDO $pdo, int $userId, string $cycle, array $settings): void
{
    $totals = keep_cycle_totals($pdo, $userId, $cycle);
    if ($totals['items'] >= (int)$settings['free_shipping_threshold'] && $totals['fee'] > 0) return;
    $pdo->prepare("UPDATE coupons SET revoked_at=NOW(),memo=CONCAT(COALESCE(memo,''),' · 킵 취소로 회수') WHERE user_id=? AND source='keep' AND keep_cycle=? AND used_order_id IS NULL AND revoked_at IS NULL")->execute([$userId, $cycle]);
}

// ---------- 킵 자동 출고 전환 ----------

/** 출고일이 된 킵 보관 주문을 고객별로 묶어 발송 대기로 바꾼다. 관리자 화면을 열 때마다 확인한다. */
function release_due_keep_orders(?int $onlyUserId = null, bool $force = false): int
{
    $pdo = db();
    // 정책 변경 전(7일 킵)에 보관된 주문은 keep_cycle이 없다. 자동 전환하지 않고 관리자가 '지금 출고 전환'으로 보낸다.
    $sql = "SELECT * FROM orders WHERE status='kept' AND shipment_request_id IS NULL" . ($force ? '' : ' AND keep_cycle IS NOT NULL AND keep_cycle<=CURDATE()') . ($onlyUserId ? ' AND user_id=' . (int)$onlyUserId : '') . ' ORDER BY user_id, paid_at, id';
    $due = $pdo->query($sql)->fetchAll();
    if (!$due) return 0;
    $byUser = [];
    foreach ($due as $order) $byUser[(int)$order['user_id']][] = $order;
    $released = 0;
    foreach ($byUser as $userId => $orders) {
        try {
            $pdo->beginTransaction();
            // 이미 발송 대기 중인 주문이 있으면 그 상자에 같이 넣는다.
            $parentStmt = $pdo->prepare("SELECT id FROM orders WHERE user_id=? AND status='paid' AND merge_order_id IS NULL AND (delivery_type='direct' OR keep_released_at IS NOT NULL) ORDER BY id LIMIT 1 FOR UPDATE");
            $parentStmt->execute([$userId]);
            $parentId = (int)($parentStmt->fetchColumn() ?: 0);
            foreach ($orders as $order) {
                $id = (int)$order['id'];
                $lock = $pdo->prepare("SELECT status FROM orders WHERE id=? FOR UPDATE");
                $lock->execute([$id]);
                if ($lock->fetchColumn() !== 'kept') continue;
                $mergeTo = $parentId && $parentId !== $id ? $parentId : null;
                $pdo->prepare("UPDATE orders SET status='paid',keep_released_at=NOW(),merge_order_id=? WHERE id=?")->execute([$mergeTo, $id]);
                log_order_event($pdo, $id, 'keep_released', 'kept', 'paid', ($force ? '킵 출고 전환(관리자)' : '킵 정기 출고일 ' . short_date((string)$order['keep_cycle']) . ' 자동 출고 전환') . ($mergeTo ? ' · 발송 대기 주문과 합배송' : ''), 0, $force ? 'admin' : 'system');
                if (!$parentId) $parentId = $id;
                $released++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[gingmoji] keep release failed: ' . $error->getMessage());
        }
    }
    return $released;
}

function order_is_shippable_root(array $order): bool
{
    return $order['delivery_type'] === 'direct' || !empty($order['keep_released_at']);
}

// ---------- 관리자: 배송 관리 ----------

function admin_shipping(): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_value('action') === 'release') {
        $userId = (int)post_value('user_id');
        $count = $userId > 0 ? release_due_keep_orders($userId, true) : 0;
        flash($count ? 'ok' : 'error', $count ? '킵 상품 ' . num($count) . '건을 발송 대기로 전환했습니다.' : '전환할 킵 상품이 없습니다.');
        redirect_to('/admin/shipping');
    }
    release_due_keep_orders();
    $pdo = db();
    $today = date('Y-m-d');
    $members = $pdo->query("SELECT o.*, u.login_id, u.name AS user_name, u.youtube_nickname AS user_youtube_nickname,
        (SELECT GROUP_CONCAT(oi.product_name, IF(oi.qty>1, CONCAT(' ×', oi.qty), '') ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id=o.id) AS item_names
        FROM orders o JOIN users u ON u.id=o.user_id
        WHERE o.status IN ('paid','preparing','pending') AND (o.merge_order_id IS NULL OR o.merge_order_id IN (SELECT id FROM orders WHERE status IN ('paid','preparing')))
        ORDER BY o.id")->fetchAll();
    $groups = [];
    foreach ($members as $member) {
        $rootId = (int)($member['merge_order_id'] ?: $member['id']);
        $groups[$rootId][] = $member;
    }
    $rows = [];
    foreach ($groups as $rootId => $list) {
        $root = null;
        foreach ($list as $member) if ((int)$member['id'] === $rootId) $root = $member;
        if (!$root || !in_array($root['status'], ['paid', 'preparing'], true)) continue;
        $active = array_values(array_filter($list, static fn(array $m): bool => $m['status'] !== 'pending'));
        $pending = count($list) - count($active);
        $due = min(array_map('order_ship_date', $active));
        $kinds = [];
        foreach ($active as $member) $kinds[$member['delivery_type'] === 'keep' ? 'keep' : 'direct'] = true;
        $rows[] = ['root' => $root, 'members' => $active, 'pending' => $pending, 'due' => $due, 'kinds' => array_keys($kinds), 'status' => in_array('paid', array_column($active, 'status'), true) ? 'paid' : 'preparing'];
    }
    usort($rows, static fn(array $a, array $b): int => [$a['due'], (string)$a['root']['paid_at'], (int)$a['root']['id']] <=> [$b['due'], (string)$b['root']['paid_at'], (int)$b['root']['id']]);

    $csrf = '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="return_to" value="/admin/shipping">';
    $sections = '';
    $currentSection = null;
    $seq = 0;
    $overdue = 0;
    $todayCount = 0;
    foreach ($rows as $row) {
        $root = $row['root'];
        $section = $row['due'] < $today ? 'late' : ($row['due'] === $today ? 'today' : $row['due']);
        if ($section === 'late') $overdue++;
        if ($section === 'today') $todayCount++;
        if ($section !== $currentSection) {
            if ($currentSection !== null) $sections .= '</tbody></table></div></section>';
            $title = $section === 'late' ? '출고일 지남' : ($section === 'today' ? '오늘 출고 · ' . short_date($today) : short_date($section) . ' 출고 예정');
            $sections .= '<section class="card ship-day ship-day-' . ($section === 'late' || $section === 'today' ? $section : 'later') . '"><h2>' . e($title) . '</h2><div class="table-wrap"><table class="orders-table ship-table"><thead><tr><th class="ship-check"><span class="sr-only">선택</span></th><th>순번</th><th>주문 · 받는 분</th><th>상품</th><th>구분</th><th>상태</th><th>처리</th></tr></thead><tbody>';
            $currentSection = $section;
        }
        $seq++;
        $memberName = trim((string)($root['user_name'] ?: $root['login_id']));
        $youtube = trim((string)($root['youtube_nickname'] ?: $root['user_youtube_nickname']));
        $items = implode(', ', array_filter(array_map(static fn(array $m): string => (string)$m['item_names'], $row['members'])));
        $kindLabel = [];
        if (in_array('direct', $row['kinds'], true)) $kindLabel[] = '<span class="chip paid">바로배송</span>';
        if (in_array('keep', $row['kinds'], true)) $kindLabel[] = '<span class="chip kept">킵 정기출고</span>';
        $count = count($row['members']);
        $sub = $count > 1 ? '<span class="table-sub merge-sub">묶음배송 ' . num($count) . '건</span>' : '';
        if ($row['pending']) $sub .= '<span class="table-sub">입금 대기 추가 주문 ' . num($row['pending']) . '건</span>';
        $address = trim((string)$root['address1'] . ' ' . (string)$root['address2']);
        $action = $row['status'] === 'paid'
            ? '<form method="post" action="/admin/orders/' . (int)$root['id'] . '" class="table-action">' . $csrf . '<button class="btn sm ok" name="action" value="prepare">배송 준비중</button></form>'
            : '<form method="post" action="/admin/orders/' . (int)$root['id'] . '" class="table-action">' . $csrf . '<button class="btn sm" name="action" value="ship">배송 완료</button></form>';
        $sections .= '<tr><td class="ship-check"><input type="checkbox" name="ids[]" value="' . (int)$root['id'] . '" form="shipLabelForm" aria-label="' . e($root['order_no']) . ' 송장 선택"></td><td class="ship-seq num">' . $seq . '</td><td class="member-cell"><a class="order-no" href="/admin/orders/' . (int)$root['id'] . '">' . e($root['order_no']) . '</a><strong>' . e($root['recipient_name']) . '</strong><span class="table-sub">' . e($memberName) . ($youtube !== '' ? ' · 유튜브 ' . e($youtube) : '') . '</span><span class="table-sub">' . e($address) . '</span>' . $sub . '</td><td class="ship-items">' . e($items ?: '-') . '</td><td>' . implode(' ', $kindLabel) . '</td><td><span class="chip ' . e($row['status']) . '">' . ($row['status'] === 'paid' ? '발송 대기' : '배송 준비중') . '</span></td><td><div class="table-actions"><a class="btn sm soft" href="/admin/orders/labels?ids%5B%5D=' . (int)$root['id'] . '" target="_blank" rel="noopener">송장 출력</a>' . $action . '</div></td></tr>';
    }
    if ($currentSection !== null) $sections .= '</tbody></table></div></section>';
    if ($sections === '') $sections = '<section class="card"><p class="empty-cell">지금 보낼 주문이 없습니다.</p></section>';

    // 킵 보관 현황
    $nextCycle = keep_cycle_for();
    $kept = $pdo->query("SELECT o.user_id, u.login_id, u.name AS user_name, u.youtube_nickname, IF(SUM(o.keep_cycle IS NULL)>0, NULL, MIN(o.keep_cycle)) AS keep_cycle, COUNT(*) AS cnt, SUM(o.items_amount) AS items, SUM(o.shipping_fee) AS fee FROM orders o JOIN users u ON u.id=o.user_id WHERE o.status='kept' AND o.shipment_request_id IS NULL GROUP BY o.user_id, u.login_id, u.name, u.youtube_nickname ORDER BY keep_cycle IS NOT NULL, keep_cycle, items DESC")->fetchAll();
    $keptRows = '';
    $keptTotal = 0;
    foreach ($kept as $row) {
        $keptTotal += (int)$row['items'];
        $keptRows .= '<tr><td><strong>' . e($row['user_name'] ?: $row['login_id']) . '</strong><span class="table-sub">' . e($row['youtube_nickname'] ?: '-') . '</span></td><td>' . ($row['keep_cycle'] ? e(short_date((string)$row['keep_cycle'])) : '<span class="chip pending">기존 킵 · 직접 출고</span>') . '</td><td class="num">' . num($row['cnt']) . '건</td><td class="amount">' . won($row['items']) . '</td><td>' . ((int)$row['fee'] > 0 ? won($row['fee']) : '없음') . '</td><td><form method="post" action="/admin/shipping" class="table-action"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="release"><input type="hidden" name="user_id" value="' . (int)$row['user_id'] . '"><button class="btn sm ghost" data-confirm-dialog="' . e(($row['user_name'] ?: $row['login_id']) . '님의 킵 상품을 정기 출고일 전에 발송 대기로 바꿀까요?') . '">지금 출고 전환</button></form></td></tr>';
    }
    $keptTable = '<section class="card"><div class="card-heading"><div><h2>킵 보관 현황</h2><p>다음 정기 출고일은 <strong>' . e(short_date($nextCycle)) . '</strong>입니다. 출고일 0시가 지나면 고객별로 묶여 위 목록의 발송 대기로 자동 전환됩니다.</p></div></div><div class="table-wrap"><table class="orders-table"><thead><tr><th>고객</th><th>출고일</th><th>주문</th><th>킵 금액</th><th>낸 배송비</th><th>처리</th></tr></thead><tbody>' . ($keptRows ?: '<tr><td colspan="6" class="empty-cell">보관 중인 킵 상품이 없습니다.</td></tr>') . '</tbody></table></div></section>';

    $upcoming = [];
    foreach ([0, 1, 2] as $offset) {
        $month = (new DateTimeImmutable('first day of this month'))->modify('+' . $offset . ' month');
        foreach (keep_release_dates_for_month((int)$month->format('Y'), (int)$month->format('n')) as $date) if ($date >= $today) $upcoming[] = $date;
    }
    $upcoming = array_slice(array_values(array_unique($upcoming)), 0, 4);
    $stats = '<div class="stats"><div class="stat' . ($overdue ? ' alert' : '') . '"><span class="k">출고일 지남</span><b class="num">' . num($overdue) . '건</b><span class="s">먼저 보내야 할 주문</span></div><div class="stat"><span class="k">오늘 출고</span><b class="num">' . num($todayCount) . '건</b><span class="s">' . e(short_date($today)) . '</span></div><div class="stat"><span class="k">발송 대상 전체</span><b class="num">' . num(count($rows)) . '건</b><span class="s">발송 대기 + 배송 준비중</span></div><div class="stat soft"><span class="k">킵 보관</span><b class="num">' . won($keptTotal) . '</b><span class="s">다음 출고 ' . e(short_date($nextCycle)) . '</span></div></div>';
    $rules = '<section class="card ship-rules"><h2>출고 기준</h2><ul><li>바로배송: 수요일 방송 → 금요일, 목요일 → 토요일, 금·토요일 → 월요일 출고 (오전 6시 전 주문은 전날 방송으로 계산)</li><li>킵: 매달 둘째 주·넷째 주가 끝난 다음 월요일 일괄 출고 · 예정 ' . e(implode(', ', array_map('short_date', $upcoming))) . '</li><li>같은 고객의 발송 대기 주문이 있으면 킵 상품을 그 상자에 합배송으로 묶습니다.</li></ul></section>';
    $labelForm = '<form id="shipLabelForm" method="get" action="/admin/orders/labels" target="_blank"></form>';
    admin_shell('배송 관리', '<div class="page-head"><div><h1>배송 관리</h1><p class="page-sub">보낼 주문을 출고 예정일 순서대로 보여 줍니다. 위에서부터 차례로 송장을 출력하고 처리하세요.</p></div><button class="btn pink" type="submit" form="shipLabelForm">선택 송장 출력</button></div>' . $labelForm . $stats . $sections . $keptTable . $rules);
}

// ---------- 관리자: 쿠폰 관리 ----------

function admin_coupons(string $path): never
{
    $pdo = db();
    if (preg_match('#^/admin/coupons/(\d+)/revoke$#', $path, $match) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->prepare('UPDATE coupons SET revoked_at=NOW() WHERE id=? AND used_order_id IS NULL AND revoked_at IS NULL')->execute([(int)$match[1]]);
        flash('ok', '쿠폰을 회수했습니다.');
        redirect_to('/admin/coupons');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])))));
        $name = trim((string)post_value('name'));
        $amount = (int)post_value('amount');
        $minOrder = max(0, (int)post_value('min_order_amount'));
        $start = (string)post_value('starts_on');
        $end = (string)post_value('expires_on');
        $memo = trim((string)post_value('memo'));
        $error = !$userIds ? '쿠폰을 받을 고객을 한 명 이상 선택해 주세요.' : ($name === '' ? '쿠폰 이름을 입력해 주세요.' : ($amount <= 0 ? '할인 금액을 입력해 주세요.' : (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? '사용 시작일을 입력해 주세요.' : ($end !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) ? '사용 종료일을 확인해 주세요.' : ''))));
        if ($error) {
            flash('error', $error);
            redirect_to('/admin/coupons');
        }
        $stmt = $pdo->prepare('INSERT INTO coupons (user_id,name,amount,min_order_amount,starts_at,expires_at,source,memo) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($userIds as $userId) $stmt->execute([$userId, mb_substr($name, 0, 100), $amount, $minOrder, $start . ' 00:00:00', $end !== '' ? $end . ' 23:59:59' : null, 'admin', $memo !== '' ? mb_substr($memo, 0, 255) : null]);
        flash('ok', num(count($userIds)) . '명에게 쿠폰을 발행했습니다.');
        redirect_to('/admin/coupons');
    }

    $filter = (string)($_GET['state'] ?? 'all');
    $users = $pdo->query('SELECT id,login_id,name,youtube_nickname FROM users ORDER BY name, login_id')->fetchAll();
    $userOptions = '';
    foreach ($users as $user) {
        $label = trim((string)($user['name'] ?: $user['login_id'])) . ' · ' . ($user['login_id'] ?: '소셜') . ($user['youtube_nickname'] ? ' · ' . $user['youtube_nickname'] : '');
        $userOptions .= '<label class="coupon-user" data-coupon-user="' . e(mb_strtolower($label)) . '"><input type="checkbox" name="user_ids[]" value="' . (int)$user['id'] . '"> ' . e($label) . '</label>';
    }
    $form = '<form class="card coupon-issue" method="post" action="/admin/coupons"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><h2>쿠폰 발행</h2><div class="filter-grid"><label class="field"><span>쿠폰 이름</span><input name="name" required maxlength="100" placeholder="예: 단골 감사 쿠폰"></label><label class="field"><span>할인 금액(원)</span><input type="number" name="amount" min="100" step="100" required></label><label class="field"><span>최소 주문금액(원)</span><input type="number" name="min_order_amount" min="0" step="100" value="0"></label><label class="field"><span>사용 시작일</span><input type="date" name="starts_on" value="' . e(date('Y-m-d')) . '" required></label><label class="field"><span>사용 종료일 <small>(비우면 기한 없음)</small></span><input type="date" name="expires_on" value="' . e(date('Y-m-d', strtotime('+30 days'))) . '"></label><label class="field"><span>메모 <small>(관리자만 봄)</small></span><input name="memo" maxlength="255"></label></div><div class="field"><span>받을 고객 <small data-coupon-selected>0명 선택</small></span><input type="search" placeholder="이름·아이디·유튜브 닉네임 검색" data-coupon-search autocomplete="off"><div class="coupon-user-list" data-coupon-users>' . ($userOptions ?: '<p class="empty-cell">회원이 없습니다.</p>') . '</div></div><button class="btn pink">쿠폰 발행</button></form>';

    $where = '';
    if ($filter === 'active') $where = 'WHERE c.used_order_id IS NULL AND c.revoked_at IS NULL AND (c.expires_at IS NULL OR c.expires_at>NOW())';
    elseif ($filter === 'used') $where = 'WHERE c.used_order_id IS NOT NULL';
    elseif ($filter === 'ended') $where = 'WHERE c.used_order_id IS NULL AND (c.revoked_at IS NOT NULL OR c.expires_at<=NOW())';
    $coupons = $pdo->query("SELECT c.*, u.login_id, u.name AS user_name, o.order_no FROM coupons c JOIN users u ON u.id=c.user_id LEFT JOIN orders o ON o.id=c.used_order_id $where ORDER BY c.id DESC LIMIT 500")->fetchAll();
    $rows = '';
    foreach ($coupons as $coupon) {
        [$state, $stateLabel] = coupon_status($coupon);
        $revoke = in_array($state, ['active', 'scheduled'], true) ? '<form method="post" action="/admin/coupons/' . (int)$coupon['id'] . '/revoke" class="table-action"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button class="btn sm ghost" data-confirm-dialog="이 쿠폰을 회수할까요?">회수</button></form>' : '';
        $rows .= '<tr><td><strong>' . e($coupon['user_name'] ?: $coupon['login_id']) . '</strong><span class="table-sub">' . e($coupon['login_id'] ?: '-') . '</span></td><td><strong>' . e($coupon['name']) . '</strong><span class="table-sub">' . ($coupon['source'] === 'keep' ? '킵 8만원 달성 자동 발행' : '관리자 발행') . ($coupon['memo'] ? ' · ' . e($coupon['memo']) : '') . '</span></td><td class="amount">' . won($coupon['amount']) . '</td><td>' . ((int)$coupon['min_order_amount'] > 0 ? won($coupon['min_order_amount']) . ' 이상' : '조건 없음') . '</td><td>' . e(coupon_period($coupon)) . '</td><td><span class="chip coupon-' . e($state) . '">' . e($stateLabel) . '</span>' . ($coupon['order_no'] ? '<a class="table-sub" href="/admin/orders/' . (int)$coupon['used_order_id'] . '">' . e($coupon['order_no']) . '</a>' : '') . '</td><td>' . $revoke . '</td></tr>';
    }
    $tabs = '';
    foreach (['all' => '전체', 'active' => '사용 가능', 'used' => '사용함', 'ended' => '만료·회수'] as $key => $label) $tabs .= '<a class="btn sm ' . ($filter === $key ? 'pink' : 'ghost') . '" href="/admin/coupons?state=' . $key . '">' . $label . '</a>';
    $list = '<section class="card"><div class="card-heading"><div><h2>발행한 쿠폰</h2><p>킵 누적이 ' . won(setting_values()['free_shipping_threshold']) . ' 이상이 되면 이번 출고분에 낸 킵 배송비만큼 쿠폰이 자동 발행됩니다.</p></div><div class="table-actions">' . $tabs . '</div></div><div class="table-wrap"><table class="orders-table"><thead><tr><th>고객</th><th>쿠폰</th><th>할인</th><th>조건</th><th>사용 기간</th><th>상태</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="7" class="empty-cell">쿠폰이 없습니다.</td></tr>') . '</tbody></table></div></section>';
    $script = '<script>(function(){var s=document.querySelector("[data-coupon-search]"),c=document.querySelector("[data-coupon-selected]"),b=document.querySelector("[data-coupon-users]");if(!s||!b)return;s.addEventListener("input",function(){var q=s.value.trim().toLowerCase();b.querySelectorAll("[data-coupon-user]").forEach(function(l){l.hidden=q!==""&&l.getAttribute("data-coupon-user").indexOf(q)<0;});});b.addEventListener("change",function(){c.textContent=b.querySelectorAll("input:checked").length+"명 선택";});})();</script>';
    admin_shell('쿠폰 관리', '<div class="page-head"><div><h1>쿠폰 관리</h1><p class="page-sub">원하는 고객에게 쿠폰을 발행하고, 사용·만료 상태를 확인합니다. 고객은 주문서에서 쿠폰을 골라 쓸 수 있어요.</p></div></div>' . $form . $list . $script);
}
