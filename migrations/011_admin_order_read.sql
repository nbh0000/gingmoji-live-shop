ALTER TABLE orders
  ADD COLUMN admin_checked_at DATETIME NULL AFTER admin_memo,
  ADD KEY idx_orders_admin_checked (admin_checked_at, status, id);

UPDATE orders
SET admin_checked_at = COALESCE(updated_at, created_at)
WHERE admin_checked_at IS NULL;
