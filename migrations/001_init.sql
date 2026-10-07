-- 깅모지 라이브샵 초기 스키마
-- 카페24 MariaDB 호환: 구버전(10.1) 기준으로 utf8mb4 인덱스 컬럼은 191자 이하, JSON 타입 대신 TEXT 사용

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  login_id VARCHAR(50) NULL,
  password_hash VARCHAR(255) NULL,
  kakao_id VARCHAR(64) NULL,
  google_id VARCHAR(64) NULL,
  email VARCHAR(191) NULL,
  name VARCHAR(50) NULL,
  phone VARCHAR(20) NULL,
  zipcode VARCHAR(10) NULL,
  address1 VARCHAR(255) NULL,
  address2 VARCHAR(255) NULL,
  youtube_nickname VARCHAR(100) NULL,
  point_balance INT NOT NULL DEFAULT 0,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  admin_memo TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_login (login_id),
  UNIQUE KEY uq_users_kakao (kakao_id),
  UNIQUE KEY uq_users_google (google_id),
  KEY idx_users_nick (youtube_nickname),
  KEY idx_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  description TEXT NULL,
  price INT NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  use_open_option TINYINT(1) NOT NULL DEFAULT 1,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  is_soldout TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_list (deleted_at, is_visible, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 이미지는 DB에 저장: 카페24는 git 배포라 서버에 업로드한 파일이 재배포 때 사라질 수 있음.
-- 업로드 전 브라우저에서 1000px 내외로 줄여 보내므로 장당 100~300KB 수준.
CREATE TABLE product_images (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  mime VARCHAR(50) NOT NULL,
  data MEDIUMBLOB NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pimg_product (product_id, sort_order),
  CONSTRAINT fk_pimg_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shipment_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_no VARCHAR(20) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  -- pending_payment: 배송비 결제 대기 / requested: 출고 요청 완료 / preparing / shipped / cancelled
  status VARCHAR(20) NOT NULL,
  kept_amount INT NOT NULL DEFAULT 0,
  shipping_fee INT NOT NULL DEFAULT 0,
  payment_method VARCHAR(10) NULL,
  depositor_name VARCHAR(50) NULL,
  recipient_name VARCHAR(50) NOT NULL,
  recipient_phone VARCHAR(20) NOT NULL,
  zipcode VARCHAR(10) NULL,
  address1 VARCHAR(255) NOT NULL,
  address2 VARCHAR(255) NULL,
  memo VARCHAR(255) NULL,
  courier VARCHAR(50) NULL,
  tracking_no VARCHAR(50) NULL,
  reserve_expires_at DATETIME NULL,
  paid_at DATETIME NULL,
  shipped_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ship_no (request_no),
  KEY idx_ship_user (user_id, status),
  KEY idx_ship_status (status, created_at),
  CONSTRAINT fk_ship_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(20) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  -- pending(입금대기/결제대기) / paid(결제완료) / kept(킵보관) / preparing(배송준비) / shipped(발송완료) / cancelled(취소)
  status VARCHAR(20) NOT NULL,
  payment_method VARCHAR(10) NOT NULL, -- card | bank
  delivery_type VARCHAR(10) NOT NULL,  -- direct | keep
  items_amount INT NOT NULL,
  shipping_fee INT NOT NULL DEFAULT 0,
  point_used INT NOT NULL DEFAULT 0,
  total_amount INT NOT NULL,
  point_earned INT NOT NULL DEFAULT 0,
  recipient_name VARCHAR(50) NOT NULL,
  recipient_phone VARCHAR(20) NOT NULL,
  zipcode VARCHAR(10) NULL,
  address1 VARCHAR(255) NOT NULL,
  address2 VARCHAR(255) NULL,
  memo VARCHAR(255) NULL,
  depositor_name VARCHAR(50) NULL,
  youtube_nickname VARCHAR(100) NULL, -- 주문 시점 닉네임 (관리자 목록 표시용)
  reserve_expires_at DATETIME NULL,
  paid_at DATETIME NULL,
  shipped_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(100) NULL,
  shipment_request_id INT UNSIGNED NULL,
  courier VARCHAR(50) NULL,
  tracking_no VARCHAR(50) NULL,
  admin_memo TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_no (order_no),
  KEY idx_orders_user (user_id, status),
  KEY idx_orders_status (status, created_at),
  KEY idx_orders_expire (status, reserve_expires_at),
  KEY idx_orders_created (created_at),
  KEY idx_orders_ship (shipment_request_id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_orders_ship FOREIGN KEY (shipment_request_id) REFERENCES shipment_requests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(200) NOT NULL,
  unit_price INT NOT NULL,
  qty INT NOT NULL,
  qty_opened INT NOT NULL DEFAULT 0,
  qty_unopened INT NOT NULL DEFAULT 0,
  line_amount INT NOT NULL,
  KEY idx_oi_order (order_id),
  KEY idx_oi_product (product_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  target_type VARCHAR(10) NOT NULL, -- order | shipment
  target_id INT UNSIGNED NOT NULL,
  method VARCHAR(10) NOT NULL,      -- card | bank
  pg_payment_id VARCHAR(64) NULL,   -- 포트원 paymentId (카드)
  amount INT NOT NULL,
  status VARCHAR(20) NOT NULL,      -- ready | paid | failed | cancelled
  raw_json MEDIUMTEXT NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_pg (pg_payment_id),
  KEY idx_pay_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  webhook_id VARCHAR(100) NOT NULL,
  event_type VARCHAR(50) NULL,
  payload MEDIUMTEXT NULL,
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_webhook (webhook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE point_ledger (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  -- earn(적립) / use(사용) / revoke(회수) / refund(사용 취소 환원) / admin_add / admin_sub
  type VARCHAR(20) NOT NULL,
  amount INT NOT NULL,
  balance_after INT NOT NULL,
  order_id INT UNSIGNED NULL,
  memo VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_point_user (user_id, created_at),
  KEY idx_point_order (order_id),
  CONSTRAINT fk_point_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  `key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `value` TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pages (
  slug VARCHAR(50) NOT NULL PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
  sid VARCHAR(128) NOT NULL PRIMARY KEY,
  expires INT UNSIGNED NOT NULL,
  data MEDIUMTEXT NULL,
  KEY idx_sessions_expires (expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
