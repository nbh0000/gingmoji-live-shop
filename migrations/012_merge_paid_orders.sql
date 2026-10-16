-- 발송 대기(결제완료·바로배송) 주문에 추가 주문을 붙여 같은 택배로 보내는 경우 대표 주문 id를 저장
ALTER TABLE orders
  ADD COLUMN merge_order_id INT UNSIGNED NULL AFTER keep_merge_amount,
  ADD KEY idx_orders_merge (merge_order_id),
  ADD CONSTRAINT fk_orders_merge FOREIGN KEY (merge_order_id) REFERENCES orders (id) ON DELETE SET NULL;
