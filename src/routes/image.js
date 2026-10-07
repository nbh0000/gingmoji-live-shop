// DB에 저장된 상품 이미지 제공. 이미지 id 는 업로드마다 새로 생기므로 길게 캐시해도 안전.
const products = require('../services/products');

module.exports = async function image(req, res, next) {
  try {
    const id = parseInt(req.params.id, 10);
    if (!id) return res.status(404).end();
    if (req.headers['if-none-match'] === `"img-${id}"`) return res.status(304).end();
    const img = await products.getImage(id);
    if (!img) return res.status(404).end();
    res.set({
      'Content-Type': img.mime,
      'Cache-Control': 'public, max-age=31536000, immutable',
      ETag: `"img-${id}"`,
    });
    res.end(img.data);
  } catch (e) {
    next(e);
  }
};
