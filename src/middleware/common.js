const crypto = require('crypto');
const config = require('../config');
const settings = require('../settings');
const users = require('../services/users');
const fmt = require('../views/helpers');

// async 라우트 에러를 next 로 전달
const wrap = (fn) => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);

// ===== CSRF: 세션 토큰을 폼(_csrf) 또는 헤더(x-csrf-token)로 확인 =====
function csrf(req, res, next) {
  if (!req.session.csrf) req.session.csrf = crypto.randomBytes(18).toString('base64url');
  res.locals.csrf = req.session.csrf;
  if (['GET', 'HEAD', 'OPTIONS'].includes(req.method)) return next();
  // multipart 폼(이미지 업로드)은 본문 파싱 전이라 쿼리스트링(?_csrf=)으로도 받음
  const token = (req.body && req.body._csrf) || req.get('x-csrf-token') || req.query._csrf;
  if (token && token === req.session.csrf) return next();
  const err = new Error('보안 토큰이 만료되었습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요');
  err.status = 403;
  next(err);
}

// ===== 공통 locals: 로그인 회원, 설정, 포맷 헬퍼, flash =====
async function locals(req, res, next) {
  try {
    await settings.fresh();
    res.locals.s = settings.all();
    res.locals.fmt = fmt;
    res.locals.config = { portone: { enabled: config.portone.enabled }, kakao: config.kakao.enabled, google: config.google.enabled };
    res.locals.path = req.path;
    res.locals.flash = req.session.flash || null;
    delete req.session.flash;
    res.locals.user = null;
    if (req.session.userId) {
      const u = await users.getById(req.session.userId);
      if (u) res.locals.user = u;
      else delete req.session.userId;
    }
    req.user = res.locals.user;
    res.locals.isAdmin = Boolean(req.session.isAdmin);
    next();
  } catch (e) {
    next(e);
  }
}

function flash(req, type, message) {
  req.session.flash = { type, message };
}

// 로그인 필요. 프로필 미완성이면 1회 입력 화면으로
function requireUser(req, res, next) {
  if (!req.user) {
    if (req.accepts(['html', 'json']) === 'json' || req.xhr || req.path.startsWith('/api/')) {
      return res.status(401).json({ ok: false, message: '로그인이 필요합니다', login: true });
    }
    req.session.returnTo = req.originalUrl;
    return res.redirect('/login');
  }
  if (!users.isComplete(req.user)) {
    if (req.path.startsWith('/api/')) return res.status(400).json({ ok: false, message: '회원 정보를 먼저 입력해 주세요', profile: true });
    req.session.returnTo = req.session.returnTo || req.originalUrl;
    return res.redirect('/signup/profile');
  }
  next();
}

function requireAdmin(req, res, next) {
  if (req.session.isAdmin) return next();
  if (req.path.startsWith('/api/') || req.xhr) return res.status(401).json({ ok: false, message: '관리자 로그인이 필요합니다' });
  return res.redirect('/admin/login');
}

// 간단한 메모리 기반 로그인 시도 제한 (IP 기준 10분 20회)
const attempts = new Map();
function loginLimiter(req, res, next) {
  const key = req.ip;
  const now = Date.now();
  const rec = attempts.get(key) || { n: 0, at: now };
  if (now - rec.at > 10 * 60 * 1000) { rec.n = 0; rec.at = now; }
  rec.n++;
  attempts.set(key, rec);
  if (attempts.size > 5000) attempts.clear();
  if (rec.n > 20) {
    const err = new Error('로그인 시도가 너무 많습니다. 잠시 후 다시 시도해 주세요');
    err.status = 429;
    return next(err);
  }
  next();
}

module.exports = { wrap, csrf, locals, flash, requireUser, requireAdmin, loginLimiter };
