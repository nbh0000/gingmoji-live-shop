ALTER TABLE orders
  ADD COLUMN live_session_started_at DATETIME NULL AFTER user_id,
  ADD KEY idx_orders_live_session (user_id, live_session_started_at, status);

INSERT IGNORE INTO settings (`key`, `value`)
SELECT 'live_started_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
FROM settings
WHERE `key` = 'live_on' AND `value` = '1';

INSERT INTO settings (`key`, `value`) VALUES
  ('point_earn_mode', 'tier'),
  ('point_earn_rate', ''),
  ('point_earn_threshold', '300000'),
  ('point_earn_under_rate', '1'),
  ('point_earn_over_rate', '3'),
  ('point_guide_text', '라이브 누적 결제금액 30만 원 미만은 1%, 30만 원 이상이면 해당 라이브의 결제금액 전체에 3%가 자동 적립됩니다. 보유 10,000P 이상부터 결제 시 사용할 수 있습니다.')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
