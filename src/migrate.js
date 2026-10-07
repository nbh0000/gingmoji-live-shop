// 간단한 SQL 마이그레이션 실행기
// migrations/ 폴더의 NNN_이름.sql 파일을 이름순으로 한 번씩 실행하고 schema_migrations 에 기록합니다.
// 사용: npm run migrate
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const config = require('./config');

const DIR = path.join(__dirname, '..', 'migrations');

async function migrate({ silent = false } = {}) {
  const log = silent ? () => {} : console.log;
  const conn = await mysql.createConnection({ ...config.db, charset: 'utf8mb4', multipleStatements: true });
  try {
    await conn.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
      name VARCHAR(191) NOT NULL PRIMARY KEY,
      applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
    const [done] = await conn.query('SELECT name FROM schema_migrations');
    const applied = new Set(done.map((r) => r.name));
    const files = fs.readdirSync(DIR).filter((f) => /^\d+_.+\.sql$/.test(f)).sort();
    let count = 0;
    for (const file of files) {
      if (applied.has(file)) continue;
      const sql = fs.readFileSync(path.join(DIR, file), 'utf8');
      log(`→ ${file}`);
      await conn.query(sql);
      await conn.query('INSERT INTO schema_migrations (name) VALUES (?)', [file]);
      count++;
    }
    log(count ? `마이그레이션 ${count}개 적용 완료` : '적용할 마이그레이션이 없습니다');
  } finally {
    await conn.end();
  }
}

if (require.main === module) {
  migrate().catch((err) => {
    console.error('마이그레이션 실패:', err.message);
    process.exit(1);
  });
}

module.exports = { migrate };
