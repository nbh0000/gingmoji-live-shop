-- 상품 구분: live(라이브 상품) / sample(샘플). 홈에서 구역을 나눠 노출
-- TODO(정책 확인): 샘플 상품의 주문 방식이 라이브 상품과 다르면 여기 구분값으로 분기
ALTER TABLE products ADD COLUMN section VARCHAR(20) NOT NULL DEFAULT 'live' AFTER description;
