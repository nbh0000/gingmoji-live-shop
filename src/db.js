const mysql = require('mysql2/promise');
const config = require('./config');

// 저사양 호스팅 기준으로 커넥션 수를 작게 유지. 플랜 상향 시 늘리면 됩니다.
const pool = mysql.createPool({
  ...config.db,
  charset: 'utf8mb4',
  connectionLimit: 8,
  waitForConnections: true,
  queueLimit: 0,
  dateStrings: false,
  timezone: '+09:00',
  decimalNumbers: true,
  supportBigNumbers: true,
});

// DB 서버 시간대와 무관하게 NOW()/CURRENT_TIMESTAMP 가 한국 시간이 되도록 세션 시간대 고정
pool.pool.on('connection', (conn) => {
  conn.query("SET time_zone = '+09:00'");
});

async function query(sql, params) {
  const [rows] = await pool.query(sql, params);
  return rows;
}

async function one(sql, params) {
  const rows = await query(sql, params);
  return rows[0] || null;
}

// 트랜잭션 헬퍼: fn(conn) 안에서 conn.query 사용. 예외 시 롤백.
async function tx(fn) {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const result = await fn(conn);
    await conn.commit();
    return result;
  } catch (err) {
    try { await conn.rollback(); } catch (_) { /* ignore */ }
    throw err;
  } finally {
    conn.release();
  }
}

module.exports = { pool, query, one, tx };
