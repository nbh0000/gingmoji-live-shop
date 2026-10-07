const path = require('path');

// 테스트에서는 .env.test 를 우선 사용
const envFile = process.env.NODE_ENV === 'test' ? '.env.test' : '.env';
require('dotenv').config({ path: path.join(__dirname, '..', envFile), quiet: true });

function env(name, fallback = '') {
  const v = process.env[name];
  return v === undefined || v === '' ? fallback : v;
}

const config = {
  port: Number(env('PORT', 8001)),
  isProd: env('NODE_ENV') === 'production',
  baseUrl: env('BASE_URL', 'http://localhost:8001').replace(/\/$/, ''),
  sessionSecret: env('SESSION_SECRET', 'dev-secret-change-me'),
  db: {
    host: env('DB_HOST', '127.0.0.1'),
    port: Number(env('DB_PORT', 3306)),
    user: env('DB_USER', 'root'),
    password: env('DB_PASSWORD'),
    database: env('DB_NAME', 'gingmoji'),
  },
  admin: {
    id: env('ADMIN_ID', 'admin'),
    password: env('ADMIN_PASSWORD'),
  },
  kakao: {
    restKey: env('KAKAO_REST_KEY'),
    clientSecret: env('KAKAO_CLIENT_SECRET'),
  },
  google: {
    clientId: env('GOOGLE_CLIENT_ID'),
    clientSecret: env('GOOGLE_CLIENT_SECRET'),
  },
  portone: {
    storeId: env('PORTONE_STORE_ID'),
    channelKey: env('PORTONE_CHANNEL_KEY'),
    apiSecret: env('PORTONE_API_SECRET'),
    webhookSecret: env('PORTONE_WEBHOOK_SECRET'),
  },
};

config.portone.enabled = Boolean(config.portone.storeId && config.portone.channelKey && config.portone.apiSecret);
config.kakao.enabled = Boolean(config.kakao.restKey);
config.google.enabled = Boolean(config.google.clientId && config.google.clientSecret);

module.exports = config;
