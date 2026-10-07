// 포트원(PortOne) V2 서버 연동: 결제 조회, 결제 취소, 웹훅 서명 검증
// 문서: https://developers.portone.io/api/rest-v2
const crypto = require('crypto');
const config = require('../config');

const API = 'https://api.portone.io';

async function call(method, path, body) {
  if (!config.portone.apiSecret) throw new Error('PORTONE_API_SECRET 이 설정되지 않았습니다');
  const res = await fetch(API + path, {
    method,
    headers: {
      Authorization: `PortOne ${config.portone.apiSecret}`,
      'Content-Type': 'application/json',
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch (_) { data = { raw: text }; }
  if (!res.ok) {
    const err = new Error(`PortOne API ${res.status}: ${(data && (data.message || data.type)) || text}`);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

/** 결제 단건 조회. status: READY | PAID | FAILED | CANCELLED | PARTIAL_CANCELLED | VIRTUAL_ACCOUNT_ISSUED ... */
async function getPayment(paymentId) {
  return call('GET', `/payments/${encodeURIComponent(paymentId)}`);
}

/** 결제 전액 취소 */
async function cancelPayment(paymentId, reason) {
  return call('POST', `/payments/${encodeURIComponent(paymentId)}/cancel`, { reason: reason || '주문 취소' });
}

/**
 * 웹훅 서명 검증 (Standard Webhooks 규격)
 * 헤더: webhook-id, webhook-timestamp, webhook-signature ("v1,<base64>" 공백 구분 여러 개 가능)
 * 서명 = base64(HMAC-SHA256(secret, `${id}.${timestamp}.${rawBody}`)), secret 은 "whsec_" 뒤 base64 디코드
 */
function verifyWebhook(rawBody, headers, secret = config.portone.webhookSecret, toleranceSec = 300) {
  if (!secret) throw new Error('PORTONE_WEBHOOK_SECRET 이 설정되지 않았습니다');
  const id = headers['webhook-id'];
  const ts = headers['webhook-timestamp'];
  const sigHeader = headers['webhook-signature'];
  if (!id || !ts || !sigHeader) throw new Error('웹훅 헤더 누락');
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - Number(ts)) > toleranceSec) throw new Error('웹훅 타임스탬프 만료');
  const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64');
  const expected = crypto.createHmac('sha256', key).update(`${id}.${ts}.${rawBody}`).digest();
  const ok = String(sigHeader)
    .split(' ')
    .map((s) => s.split(',')[1])
    .filter(Boolean)
    .some((sig) => {
      const buf = Buffer.from(sig, 'base64');
      return buf.length === expected.length && crypto.timingSafeEqual(buf, expected);
    });
  if (!ok) throw new Error('웹훅 서명 불일치');
  return { id, timestamp: Number(ts) };
}

function newPaymentId(prefix) {
  return `${prefix}-${Date.now().toString(36)}-${crypto.randomBytes(4).toString('hex')}`;
}

module.exports = { getPayment, cancelPayment, verifyWebhook, newPaymentId };
