-- 주문 생성부터 결제, 발송, 취소·반품까지 주문별 처리 이력
CREATE TABLE order_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  actor_type VARCHAR(20) NOT NULL DEFAULT 'system',
  actor_id INT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NULL,
  memo VARCHAR(255) NULL,
  refund_amount INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order_events_order (order_id, created_at),
  KEY idx_order_events_type (event_type, created_at),
  CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기존 주문도 판매 기록 화면에서 주문 생성 이력을 확인할 수 있도록 초기 이벤트를 남긴다.
INSERT INTO order_events (order_id, actor_type, event_type, from_status, to_status, memo, created_at)
SELECT id, 'system', 'imported', NULL, status, '기존 주문 이력 가져오기', created_at
FROM orders;
