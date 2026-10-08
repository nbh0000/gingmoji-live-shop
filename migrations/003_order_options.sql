-- 주문별 현금영수증 신청 정보
ALTER TABLE orders
  ADD COLUMN cash_receipt_type VARCHAR(20) NULL AFTER depositor_name,
  ADD COLUMN cash_receipt_value VARCHAR(30) NULL AFTER cash_receipt_type,
  ADD COLUMN cash_receipt_status VARCHAR(20) NOT NULL DEFAULT 'requested' AFTER cash_receipt_value;
