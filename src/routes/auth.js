const crypto = require('crypto');
const express = require('express');
const config = require('../config');
const users = require('../services/users');
const { wrap, flash, loginLimiter } = require('../middleware/common');

const router = express.Router();

function safeReturn(url) {
  return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') ? url : '/';
}

// 세션 고정 방지: 로그인 시 세션 재발급
function loginAs(req, user) {
  const returnTo = safeReturn(req.session.returnTo);
  return new Promise((resolve, reject) => {
    req.session.regenerate((err) => {
      if (err) return reject(err);
      req.session.userId = user.id;
      const complete = users.isComplete(user);
      if (!complete) req.session.returnTo = returnTo;
      req.session.save((e) => (e ? reject(e) : resolve(complete ? returnTo : '/signup/profile')));
    });
  });
}

router.get('/login', (req, res) => {
  if (req.user) return res.redirect('/');
  if (req.query.next) req.session.returnTo = safeReturn(req.query.next);
  res.render('shop/login', { title: '로그인', form: {}, error: null });
});

router.post('/login', loginLimiter, wrap(async (req, res) => {
  const u = await users.loginLocal(req.body.login_id, req.body.password);
  if (!u) return res.status(400).render('shop/login', { title: '로그인', form: { login_id: req.body.login_id }, error: '아이디 또는 비밀번호가 맞지 않습니다' });
  res.redirect(await loginAs(req, u));
}));

router.get('/signup', (req, res) => {
  if (req.user) return res.redirect('/');
  res.render('shop/signup', { title: '회원가입', form: {}, error: null });
});

router.post('/signup', loginLimiter, wrap(async (req, res) => {
  if (!req.body.agree) {
    return res.status(400).render('shop/signup', { title: '회원가입', form: req.body, error: '이용약관과 개인정보처리방침에 동의해 주세요' });
  }
  try {
    const u = await users.createLocal(req.body);
    res.redirect(await loginAs(req, u));
  } catch (e) {
    if (e.name !== 'PolicyError') throw e;
    res.status(400).render('shop/signup', { title: '회원가입', form: req.body, error: e.message });
  }
}));

// 소셜 로그인 후 비어 있는 정보 1회 입력
router.get('/signup/profile', (req, res) => {
  if (!req.user) return res.redirect('/login');
  res.render('shop/profile-setup', { title: '정보 입력', form: req.user, error: null });
});

router.post('/signup/profile', wrap(async (req, res) => {
  if (!req.user) return res.redirect('/login');
  if (!req.body.agree && !users.isComplete(req.user)) {
    return res.status(400).render('shop/profile-setup', { title: '정보 입력', form: { ...req.user, ...req.body }, error: '이용약관과 개인정보처리방침에 동의해 주세요' });
  }
  try {
    await users.updateProfile(req.user.id, req.body);
  } catch (e) {
    if (e.name !== 'PolicyError') throw e;
    return res.status(400).render('shop/profile-setup', { title: '정보 입력', form: { ...req.user, ...req.body }, error: e.message });
  }
  const to = safeReturn(req.session.returnTo);
  delete req.session.returnTo;
  res.redirect(to);
}));

router.post('/logout', (req, res) => {
  req.session.destroy(() => res.redirect('/'));
});

// ===== OAuth 공통 =====

function startOAuth(provider, buildUrl) {
  return (req, res) => {
    if (req.query.next) req.session.returnTo = safeReturn(req.query.next);
    const state = crypto.randomBytes(16).toString('hex');
    req.session.oauthState = state;
    req.session.save(() => res.redirect(buildUrl(state)));
  };
}

async function postForm(url, data) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=utf-8' },
    body: new URLSearchParams(data).toString(),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`토큰 발급 실패: ${json.error_description || json.error || res.status}`);
  return json;
}

async function getJson(url, token) {
  const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`사용자 정보 조회 실패: ${res.status}`);
  return json;
}

function checkState(req) {
  const ok = req.query.state && req.query.state === req.session.oauthState;
  delete req.session.oauthState;
  return ok;
}

function oauthFail(req, res, msg) {
  flash(req, 'error', msg);
  res.redirect('/login');
}

// ===== 카카오 =====
const kakaoRedirect = () => `${config.baseUrl}/auth/kakao/callback`;

router.get('/auth/kakao', (req, res, next) => {
  if (!config.kakao.enabled) return oauthFail(req, res, '카카오 로그인이 아직 설정되지 않았습니다');
  return startOAuth('kakao', (state) =>
    `https://kauth.kakao.com/oauth/authorize?${new URLSearchParams({
      client_id: config.kakao.restKey,
      redirect_uri: kakaoRedirect(),
      response_type: 'code',
      state,
    })}`
  )(req, res, next);
});

router.get('/auth/kakao/callback', wrap(async (req, res) => {
  if (req.query.error) return oauthFail(req, res, '카카오 로그인이 취소되었습니다');
  if (!checkState(req)) return oauthFail(req, res, '로그인 요청이 만료되었습니다. 다시 시도해 주세요');
  const token = await postForm('https://kauth.kakao.com/oauth/token', {
    grant_type: 'authorization_code',
    client_id: config.kakao.restKey,
    ...(config.kakao.clientSecret ? { client_secret: config.kakao.clientSecret } : {}),
    redirect_uri: kakaoRedirect(),
    code: String(req.query.code || ''),
  });
  const me = await getJson('https://kapi.kakao.com/v2/user/me', token.access_token);
  const acc = me.kakao_account || {};
  const u = await users.findOrCreateSocial('kakao', me.id, {
    email: acc.email,
    name: acc.name || (acc.profile && acc.profile.nickname),
  });
  res.redirect(await loginAs(req, u));
}));

// ===== 구글 =====
const googleRedirect = () => `${config.baseUrl}/auth/google/callback`;

router.get('/auth/google', (req, res, next) => {
  if (!config.google.enabled) return oauthFail(req, res, '구글 로그인이 아직 설정되지 않았습니다');
  return startOAuth('google', (state) =>
    `https://accounts.google.com/o/oauth2/v2/auth?${new URLSearchParams({
      client_id: config.google.clientId,
      redirect_uri: googleRedirect(),
      response_type: 'code',
      scope: 'openid email profile',
      state,
      prompt: 'select_account',
    })}`
  )(req, res, next);
});

router.get('/auth/google/callback', wrap(async (req, res) => {
  if (req.query.error) return oauthFail(req, res, '구글 로그인이 취소되었습니다');
  if (!checkState(req)) return oauthFail(req, res, '로그인 요청이 만료되었습니다. 다시 시도해 주세요');
  const token = await postForm('https://oauth2.googleapis.com/token', {
    grant_type: 'authorization_code',
    client_id: config.google.clientId,
    client_secret: config.google.clientSecret,
    redirect_uri: googleRedirect(),
    code: String(req.query.code || ''),
  });
  const me = await getJson('https://openidconnect.googleapis.com/v1/userinfo', token.access_token);
  const u = await users.findOrCreateSocial('google', me.sub, { email: me.email, name: me.name });
  res.redirect(await loginAs(req, u));
}));

module.exports = router;
