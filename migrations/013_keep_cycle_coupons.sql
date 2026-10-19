-- 킵 월 2회 정기 출고: 킵 주문이 속한 출고일(keep_cycle)과 자동 출고 전환 시각
-- 쿠폰: 관리자 발행 쿠폰 + 킵 8만원 달성 배송비 쿠폰, 주문에 사용한 쿠폰과 할인액
ALTER TABLE orders
  ADD COLUMN keep_cycle DATE NULL AFTER merge_order_id,
  ADD COLUMN keep_released_at DATETIME NULL AFTER keep_cycle,
  ADD COLUMN coupon_id INT UNSIGNED NULL AFTER point_used,
  ADD COLUMN coupon_discount INT NOT NULL DEFAULT 0 AFTER coupon_id,
  ADD KEY idx_orders_keep_cycle (status, keep_cycle);

CREATE TABLE coupons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  amount INT NOT NULL,
  min_order_amount INT NOT NULL DEFAULT 0,
  starts_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'admin',
  keep_cycle DATE NULL,
  memo VARCHAR(255) NULL,
  used_order_id INT UNSIGNED NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coupons_user (user_id, used_order_id, revoked_at),
  UNIQUE KEY uq_coupons_keep_cycle (user_id, source, keep_cycle),
  CONSTRAINT fk_coupons_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 이미 킵 보관 중인 주문은 결제일 기준 출고일을 채운다(PHP가 첫 요청 때 계산해서 넣음)
