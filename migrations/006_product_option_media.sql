ALTER TABLE products
  ADD COLUMN full_box_image_id INT UNSIGNED NULL AFTER loose_price,
  ADD COLUMN loose_image_id INT UNSIGNED NULL AFTER full_box_image_id,
  ADD COLUMN expected_shipping_text VARCHAR(100) NULL AFTER loose_image_id,
  ADD KEY idx_products_full_box_image (full_box_image_id),
  ADD KEY idx_products_loose_image (loose_image_id),
  ADD CONSTRAINT fk_products_full_box_image FOREIGN KEY (full_box_image_id) REFERENCES product_images (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_products_loose_image FOREIGN KEY (loose_image_id) REFERENCES product_images (id) ON DELETE SET NULL;
