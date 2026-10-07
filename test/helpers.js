// 통합 테스트 공통: .env.test 의 테스트 DB를 비우고 마이그레이션 후 시드
process.env.NODE_ENV = 'test';
process.env.TZ = 'Asia/Seoul';
const mysql = require('mysql2/promise');
const config = require('../src/config');

async function resetDb() {
  const conn = await mysql.createConnection({ ...config.db, multipleStatements: true });
  try {
    const [tables] = await conn.query('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [config.db.database]);
    if (tables.length) {
      await conn.query(`SET FOREIGN_KEY_CHECKS = 0; ${tables.map((r) => `DROP TABLE \`${r.t}\``).join('; ')}; SET FOREIGN_KEY_CHECKS = 1;`);
    }
  } finally {
    await conn.end();
  }
  await require('../src/migrate').migrate({ silent: true });
  await require('../src/settings').load();
}

async function makeProduct(db, { name = '테스트 상품', price = 10000, stock = 10, option = 1 } = {}) {
  const [r] = await db.pool.query(
    'INSERT INTO products (name, price, stock, use_open_option, is_visible) VALUES (?, ?, ?, ?, 1)',
    [name, price, stock, option]
  );
  return r.insertId;
}

let userSeq = 0;
async function makeUser(db, extra = {}) {
  userSeq++;
  const [r] = await db.pool.query(
    `INSERT INTO users (login_id, name, phone, address1, youtube_nickname, profile_completed, point_balance)
     VALUES (?, ?, '01012345678', '서울시 테스트로 1', ?, 1, ?)`,
    [`tester${userSeq}${Date.now() % 100000}`, `테스터${userSeq}`, `닉네임${userSeq}`, extra.points || 0]
  );
  return r.insertId;
}

const recipient = { name: '받는분', phone: '010-1234-5678', zipcode: '06000', address1: '서울시 서초구 테스트로 1', address2: '101호' };

module.exports = { resetDb, makeProduct, makeUser, recipient };
