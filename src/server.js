process.env.TZ = process.env.TZ || 'Asia/Seoul';

const config = require('./config');
const db = require('./db');
const settings = require('./settings');
const { createApp } = require('./app');
const { migrate } = require('./migrate');
const jobs = require('./jobs');
const defaultPages = require('./defaults/pages');

async function seedPages() {
  for (const p of defaultPages) {
    await db.query('INSERT IGNORE INTO pages (slug, title, content) VALUES (?, ?, ?)', [p.slug, p.title, p.content]);
  }
}

async function main() {
  if (typeof fetch !== 'function') {
    console.warn('[warn] Node 18 이상이 필요합니다 (OAuth/포트원 API 호출에 fetch 사용)');
  }
  // 배포 후 첫 기동 시 자동 마이그레이션 (수동: npm run migrate)
  await migrate({ silent: false });
  await settings.load();
  await seedPages();

  const app = createApp();
  app.listen(config.port, () => {
    console.log(`깅모지 라이브샵 실행 중: port ${config.port} (${config.baseUrl})`);
    if (!config.admin.password) console.warn('[warn] ADMIN_PASSWORD 가 비어 있어 관리자 로그인이 막혀 있습니다');
    if (!config.portone.enabled) console.warn('[info] 포트원 키가 없어 카드결제가 비활성화됩니다 (계좌이체만 가능)');
  });
  jobs.start();
}

main().catch((err) => {
  console.error('서버 시작 실패:', err);
  process.exit(1);
});
