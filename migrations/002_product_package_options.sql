ALTER TABLE products
  ADD COLUMN use_package_option TINYINT(1) NOT NULL DEFAULT 0 AFTER use_open_option,
  ADD COLUMN full_box_price INT NOT NULL DEFAULT 0 AFTER price,
  ADD COLUMN loose_price INT NOT NULL DEFAULT 0 AFTER full_box_price;

ALTER TABLE order_items
  ADD COLUMN package_type VARCHAR(10) NOT NULL DEFAULT 'standard' AFTER product_name;
