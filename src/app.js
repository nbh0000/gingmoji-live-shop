const path = require('path');
const express = require('express');
const session = require('express-session');
const config = require('./config');
const DbStore = require('./middleware/sessionStore');
const { csrf, locals } = require('./middleware/common');

function createApp() {
  const app = express();
  app.set('views', path.join(__dirname, 'views')); // 카페24에서는 절대경로 지정 필요
  app.set('view engine', 'ejs');
  app.set('trust proxy', 1); // 카페24 프록시 뒤에서 secure 쿠키/IP 인식
  app.disable('x-powered-by');

  app.use((req, res, next) => {
    res.set('X-Content-Type-Options', 'nosniff');
    res.set('Referrer-Policy', 'strict-origin-when-cross-origin');
    res.set('X-Frame-Options', 'SAMEORIGIN');
    next();
  });

  app.use('/static', express.static(path.join(__dirname, 'public'), { maxAge: config.isProd ? '7d' : 0 }));
  app.get('/favicon.ico', (req, res) => res.redirect(301, '/static/img/favicon.svg'));

  // 포트원 웹훅: 서명 검증에 원문 바디가 필요하므로 세션/CSRF/JSON 파서보다 먼저
  app.post('/api/payments/webhook', express.raw({ type: '*/*', limit: '1mb' }), require('./routes/webhook'));

  // 상품 이미지 (DB 저장, 세션 불필요)
  app.get('/img/:id', require('./routes/image'));

  app.use(express.urlencoded({ extended: false, limit: '200kb' }));
  app.use(express.json({ limit: '200kb' }));

  app.use(
    session({
      name: 'gm.sid',
      secret: config.sessionSecret,
      store: new DbStore(),
      resave: false,
      saveUninitialized: false,
      rolling: true,
      cookie: {
        httpOnly: true,
        sameSite: 'lax',
        secure: config.baseUrl.startsWith('https://'),
        maxAge: 1000 * 60 * 60 * 24 * 30,
      },
    })
  );

  app.use(locals);
  app.use(csrf);

  app.use(require('./routes/auth'));
  app.use(require('./routes/api'));
  app.use(require('./routes/shop'));
  app.use(require('./routes/mypage'));
  app.use('/admin', require('./routes/admin'));

  app.use((req, res) => {
    res.status(404);
    if (req.path.startsWith('/api/')) return res.json({ ok: false, message: '요청한 주소를 찾을 수 없습니다' });
    res.render('shop/message', { title: '페이지를 찾을 수 없어요', message: '주소를 다시 확인해 주세요.', link: '/' });
  });

  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    const status = err.status || 500;
    if (status >= 500) console.error('[error]', req.method, req.originalUrl, err);
    const message = status >= 500 ? '일시적인 오류가 발생했습니다. 잠시 후 다시 시도해 주세요' : err.message;
    res.status(status);
    if (req.path.startsWith('/api/') || req.xhr || (req.get('accept') || '').includes('application/json')) {
      return res.json({ ok: false, message, code: err.code });
    }
    if (res.locals.s === undefined) return res.type('text').send(message);
    res.render('shop/message', { title: '문제가 생겼어요', message, link: req.get('referer') || '/' });
  });

  return app;
}

module.exports = { createApp };
