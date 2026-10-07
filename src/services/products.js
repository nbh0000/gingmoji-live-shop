const db = require('../db');
const { PolicyError } = require('./pricing');

const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

/** 고객 화면용 목록 (첫 이미지 id 포함) */
async function listForShop() {
  return db.query(
    `SELECT p.id, p.name, p.description, p.price, p.stock, p.use_open_option, p.is_soldout, p.sort_order,
            (SELECT i.id FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_id
       FROM products p
      WHERE p.deleted_at IS NULL AND p.is_visible = 1
      ORDER BY p.sort_order, p.id`
  );
}

async function listForAdmin() {
  return db.query(
    `SELECT p.*,
            (SELECT i.id FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_id,
            (SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.product_id = p.id AND o.status = 'pending') AS reserved_qty
       FROM products p
      WHERE p.deleted_at IS NULL
      ORDER BY p.sort_order, p.id`
  );
}

async function get(id, { withImages = true } = {}) {
  const p = await db.one('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [id]);
  if (!p) return null;
  if (withImages) p.images = await db.query('SELECT id, sort_order FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [id]);
  return p;
}

function clean(input) {
  const int = (v, d = 0) => {
    const n = parseInt(String(v === undefined ? '' : v).replace(/[^0-9-]/g, ''), 10);
    return Number.isFinite(n) ? n : d;
  };
  const bool = (v) => v === true || v === '1' || v === 'on' || v === 'true' || v === 1;
  const out = {
    name: String(input.name || '').trim().slice(0, 200),
    description: String(input.description || '').slice(0, 20000),
    price: Math.max(0, int(input.price)),
    stock: Math.max(0, int(input.stock)),
    use_open_option: bool(input.use_open_option) ? 1 : 0,
    is_visible: bool(input.is_visible) ? 1 : 0,
    is_soldout: bool(input.is_soldout) ? 1 : 0,
  };
  if (!out.name) throw new PolicyError('상품명을 입력해 주세요');
  return out;
}

async function create(input) {
  const p = clean(input);
  const [[mx]] = await db.pool.query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM products WHERE deleted_at IS NULL');
  const [r] = await db.pool.query(
    `INSERT INTO products (name, description, price, stock, use_open_option, is_visible, is_soldout, sort_order)
     VALUES (?,?,?,?,?,?,?,?)`,
    [p.name, p.description, p.price, p.stock, p.use_open_option, p.is_visible, p.is_soldout, mx.m + 10]
  );
  return r.insertId;
}

async function update(id, input) {
  const p = clean(input);
  await db.query(
    `UPDATE products SET name=?, description=?, price=?, stock=?, use_open_option=?, is_visible=?, is_soldout=? WHERE id=? AND deleted_at IS NULL`,
    [p.name, p.description, p.price, p.stock, p.use_open_option, p.is_visible, p.is_soldout, id]
  );
}

/** 재고만 빠르게 조정 (방송 중 관리자 화면에서) */
async function setStock(id, stock) {
  const n = Math.max(0, parseInt(stock, 10) || 0);
  await db.query('UPDATE products SET stock = ? WHERE id = ?', [n, id]);
}

async function setFlag(id, field, value) {
  if (!['is_visible', 'is_soldout', 'use_open_option'].includes(field)) throw new Error('bad field');
  await db.query(`UPDATE products SET ${field} = ? WHERE id = ?`, [value ? 1 : 0, id]);
}

// 주문 내역 보존을 위해 soft delete
async function remove(id) {
  await db.query('UPDATE products SET deleted_at = NOW(), is_visible = 0 WHERE id = ?', [id]);
}

/** ids 순서대로 sort_order 재지정 */
async function reorder(ids) {
  await db.tx(async (conn) => {
    let n = 10;
    for (const id of ids) {
      await conn.query('UPDATE products SET sort_order = ? WHERE id = ?', [n, Number(id)]);
      n += 10;
    }
  });
}

async function addImage(productId, file) {
  if (!ALLOWED_MIME.includes(file.mimetype)) throw new PolicyError('jpg, png, webp, gif 이미지만 올릴 수 있습니다');
  const [[mx]] = await db.pool.query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM product_images WHERE product_id = ?', [productId]);
  await db.query('INSERT INTO product_images (product_id, mime, data, sort_order) VALUES (?,?,?,?)', [productId, file.mimetype, file.buffer, mx.m + 1]);
}

async function removeImage(imageId) {
  await db.query('DELETE FROM product_images WHERE id = ?', [imageId]);
}

async function makeImageFirst(imageId) {
  const img = await db.one('SELECT product_id FROM product_images WHERE id = ?', [imageId]);
  if (!img) return;
  await db.query('UPDATE product_images SET sort_order = sort_order + 1 WHERE product_id = ?', [img.product_id]);
  await db.query('UPDATE product_images SET sort_order = 0 WHERE id = ?', [imageId]);
}

async function getImage(imageId) {
  return db.one('SELECT id, mime, data FROM product_images WHERE id = ?', [imageId]);
}

module.exports = { listForShop, listForAdmin, get, create, update, setStock, setFlag, remove, reorder, addImage, removeImage, makeImageFirst, getImage };
