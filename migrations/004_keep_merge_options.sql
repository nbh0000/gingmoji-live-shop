-- 추가 주문에서 기존 킵 상품을 함께 배송할지 저장
ALTER TABLE orders
  ADD COLUMN keep_merge_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_type,
  ADD COLUMN keep_merge_amount INT NOT NULL DEFAULT 0 AFTER keep_merge_requested;

UPDATE orders SET status='kept' WHERE delivery_type='keep' AND status='paid';
