// 포인트 원장. 잔액 변경은 반드시 이 함수로(트랜잭션 conn 안에서) 처리합니다.

const TYPE_LABELS = {
  earn: '적립',
  adjust: '적립 조정',
  use: '사용',
  revoke: '적립 회수',
  refund: '사용 취소',
  admin_add: '관리자 지급',
  admin_sub: '관리자 차감',
};

/**
 * @param conn 트랜잭션 커넥션
 * @param delta 양수=증가, 음수=감소
 * 잔액이 마이너스가 될 수 있음(적립 회수 시 이미 사용한 경우).
 * TODO(정책 확인): 마이너스 잔액 허용 여부. 현재는 허용하고 다음 적립에서 상쇄
 */
async function applyPoints(conn, { userId, type, delta, orderId = null, memo = null }) {
  if (!delta) return null;
  await conn.query('UPDATE users SET point_balance = point_balance + ? WHERE id = ?', [delta, userId]);
  const [[u]] = await conn.query('SELECT point_balance FROM users WHERE id = ?', [userId]);
  await conn.query(
    'INSERT INTO point_ledger (user_id, type, amount, balance_after, order_id, memo) VALUES (?, ?, ?, ?, ?, ?)',
    [userId, type, delta, u.point_balance, orderId, memo]
  );
  return u.point_balance;
}

module.exports = { applyPoints, TYPE_LABELS };
