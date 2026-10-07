const crypto = require('crypto');
const db = require('../db');
const { PolicyError } = require('./pricing');

// ===== 비밀번호 (Node 내장 scrypt, 네이티브 모듈 없음) =====

function hashPassword(pw) {
  const salt = crypto.randomBytes(16);
  const hash = crypto.scryptSync(String(pw), salt, 32, { N: 16384, r: 8, p: 1 });
  return `scrypt$${salt.toString('base64')}$${hash.toString('base64')}`;
}

function verifyPassword(pw, stored) {
  if (!stored || !stored.startsWith('scrypt$')) return false;
  const [, saltB64, hashB64] = stored.split('$');
  const expected = Buffer.from(hashB64, 'base64');
  const actual = crypto.scryptSync(String(pw), Buffer.from(saltB64, 'base64'), expected.length, { N: 16384, r: 8, p: 1 });
  return crypto.timingSafeEqual(actual, expected);
}

// ===== 프로필 =====

function cleanProfile(input) {
  return {
    name: String(input.name || '').trim().slice(0, 50),
    phone: String(input.phone || '').replace(/[^0-9]/g, '').slice(0, 20),
    zipcode: String(input.zipcode || '').trim().slice(0, 10),
    address1: String(input.address1 || '').trim().slice(0, 255),
    address2: String(input.address2 || '').trim().slice(0, 255),
    youtube_nickname: String(input.youtube_nickname || '').trim().slice(0, 100),
  };
}

function validateProfile(p) {
  if (!p.name) throw new PolicyError('이름을 입력해 주세요');
  if (!/^01[0-9]{8,9}$/.test(p.phone)) throw new PolicyError('휴대폰 번호를 정확히 입력해 주세요');
  if (!p.address1) throw new PolicyError('주소를 입력해 주세요');
  if (!p.youtube_nickname) throw new PolicyError('유튜브 닉네임을 입력해 주세요');
}

function isComplete(u) {
  return Boolean(u && u.name && u.phone && u.address1 && u.youtube_nickname);
}

async function getById(id) {
  return db.one('SELECT * FROM users WHERE id = ?', [id]);
}

async function createLocal(input) {
  const loginId = String(input.login_id || '').trim().toLowerCase();
  if (!/^[a-z0-9_]{4,20}$/.test(loginId)) throw new PolicyError('아이디는 영문 소문자, 숫자, _ 로 4~20자입니다');
  const pw = String(input.password || '');
  if (pw.length < 6) throw new PolicyError('비밀번호는 6자 이상으로 입력해 주세요');
  if (input.password_confirm !== undefined && input.password_confirm !== pw) throw new PolicyError('비밀번호 확인이 일치하지 않습니다');
  const p = cleanProfile(input);
  validateProfile(p);
  const exists = await db.one('SELECT id FROM users WHERE login_id = ?', [loginId]);
  if (exists) throw new PolicyError('이미 사용 중인 아이디입니다');
  const [r] = await db.pool.query(
    `INSERT INTO users (login_id, password_hash, name, phone, zipcode, address1, address2, youtube_nickname, profile_completed)
     VALUES (?,?,?,?,?,?,?,?,1)`,
    [loginId, hashPassword(pw), p.name, p.phone, p.zipcode, p.address1, p.address2, p.youtube_nickname]
  );
  return getById(r.insertId);
}

async function loginLocal(loginId, password) {
  const u = await db.one('SELECT * FROM users WHERE login_id = ?', [String(loginId || '').trim().toLowerCase()]);
  if (!u || !verifyPassword(password, u.password_hash)) return null;
  return u;
}

/** 소셜 로그인: provider = 'kakao' | 'google' */
async function findOrCreateSocial(provider, providerId, { email, name } = {}) {
  const col = provider === 'kakao' ? 'kakao_id' : 'google_id';
  const found = await db.one(`SELECT * FROM users WHERE ${col} = ?`, [String(providerId)]);
  if (found) return found;
  const [r] = await db.pool.query(`INSERT INTO users (${col}, email, name) VALUES (?, ?, ?)`, [
    String(providerId),
    email ? String(email).slice(0, 191) : null,
    name ? String(name).slice(0, 50) : null,
  ]);
  return getById(r.insertId);
}

async function updateProfile(userId, input) {
  const p = cleanProfile(input);
  validateProfile(p);
  await db.query(
    `UPDATE users SET name=?, phone=?, zipcode=?, address1=?, address2=?, youtube_nickname=?, profile_completed=1 WHERE id=?`,
    [p.name, p.phone, p.zipcode, p.address1, p.address2, p.youtube_nickname, userId]
  );
  return getById(userId);
}

async function changePassword(userId, current, next) {
  const u = await getById(userId);
  if (!u.login_id) throw new PolicyError('소셜 로그인 회원은 비밀번호가 없습니다');
  if (!verifyPassword(current, u.password_hash)) throw new PolicyError('현재 비밀번호가 맞지 않습니다');
  if (String(next || '').length < 6) throw new PolicyError('새 비밀번호는 6자 이상으로 입력해 주세요');
  await db.query('UPDATE users SET password_hash = ? WHERE id = ?', [hashPassword(next), userId]);
}

module.exports = {
  hashPassword,
  verifyPassword,
  cleanProfile,
  validateProfile,
  isComplete,
  getById,
  createLocal,
  loginLocal,
  findOrCreateSocial,
  updateProfile,
  changePassword,
};
