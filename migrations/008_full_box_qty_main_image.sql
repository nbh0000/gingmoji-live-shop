ALTER TABLE products
  ADD COLUMN full_box_qty INT NOT NULL DEFAULT 1 AFTER loose_price,
  ADD COLUMN main_image_id INT UNSIGNED NULL AFTER loose_image_id,
  ADD KEY idx_products_main_image (main_image_id),
  ADD CONSTRAINT fk_products_main_image FOREIGN KEY (main_image_id) REFERENCES product_images (id) ON DELETE SET NULL;

ALTER TABLE order_items
  ADD COLUMN stock_qty INT NOT NULL DEFAULT 0 AFTER qty;

UPDATE products p SET p.main_image_id = (
  SELECT i.id FROM product_images i
  WHERE i.product_id = p.id AND i.id <> COALESCE(p.full_box_image_id, 0) AND i.id <> COALESCE(p.loose_image_id, 0)
  ORDER BY i.sort_order, i.id LIMIT 1
);
