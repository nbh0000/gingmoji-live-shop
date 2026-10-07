// express-session 용 MariaDB 저장소 (외부 패키지 대신 직접 구현: 의존성/메모리 최소화)
const session = require('express-session');
const db = require('../db');

class DbStore extends session.Store {
  constructor({ ttlSec = 60 * 60 * 24 * 30 } = {}) {
    super();
    this.ttlSec = ttlSec;
    this.timer = setInterval(() => this.cleanup().catch(() => {}), 15 * 60 * 1000);
    this.timer.unref();
  }

  expiresOf(sess) {
    const ms = sess && sess.cookie && sess.cookie.expires ? new Date(sess.cookie.expires).getTime() : Date.now() + this.ttlSec * 1000;
    return Math.floor(ms / 1000);
  }

  get(sid, cb) {
    db.one('SELECT data, expires FROM sessions WHERE sid = ?', [sid])
      .then((row) => {
        if (!row || row.expires < Math.floor(Date.now() / 1000)) return cb(null, null);
        cb(null, JSON.parse(row.data));
      })
      .catch(cb);
  }

  set(sid, sess, cb) {
    db.query(
      'INSERT INTO sessions (sid, expires, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE expires = VALUES(expires), data = VALUES(data)',
      [sid, this.expiresOf(sess), JSON.stringify(sess)]
    )
      .then(() => cb && cb(null))
      .catch((e) => cb && cb(e));
  }

  touch(sid, sess, cb) {
    db.query('UPDATE sessions SET expires = ? WHERE sid = ?', [this.expiresOf(sess), sid])
      .then(() => cb && cb(null))
      .catch((e) => cb && cb(e));
  }

  destroy(sid, cb) {
    db.query('DELETE FROM sessions WHERE sid = ?', [sid])
      .then(() => cb && cb(null))
      .catch((e) => cb && cb(e));
  }

  cleanup() {
    return db.query('DELETE FROM sessions WHERE expires < ?', [Math.floor(Date.now() / 1000)]);
  }
}

module.exports = DbStore;
