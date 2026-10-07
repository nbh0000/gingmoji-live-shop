// 템플릿 포맷 헬퍼

function won(n) {
  return `${Number(n || 0).toLocaleString('ko-KR')}원`;
}

function num(n) {
  return Number(n || 0).toLocaleString('ko-KR');
}

function pad(n) {
  return String(n).padStart(2, '0');
}

// DB 에서 온 Date(KST 세션) 표시
function dt(d) {
  if (!d) return '';
  const x = d instanceof Date ? d : new Date(d);
  if (Number.isNaN(x.getTime())) return '';
  return `${x.getFullYear()}.${pad(x.getMonth() + 1)}.${pad(x.getDate())} ${pad(x.getHours())}:${pad(x.getMinutes())}`;
}

function date(d) {
  if (!d) return '';
  const x = d instanceof Date ? d : new Date(d);
  return `${x.getFullYear()}.${pad(x.getMonth() + 1)}.${pad(x.getDate())}`;
}

function phone(p) {
  const s = String(p || '').replace(/[^0-9]/g, '');
  if (s.length === 11) return `${s.slice(0, 3)}-${s.slice(3, 7)}-${s.slice(7)}`;
  if (s.length === 10) return `${s.slice(0, 3)}-${s.slice(3, 6)}-${s.slice(6)}`;
  return s;
}

function esc(s) {
  return String(s === null || s === undefined ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** 약관 본문: "## 제목" → h2, 빈 줄 → 문단, {{키}} → 사업자 정보 */
function policy(text, s) {
  const map = {
    상호: s.biz_name,
    대표자: s.biz_owner,
    연락처: s.biz_phone,
    이메일: s.biz_email,
    주소: s.biz_address,
    개인정보책임자: s.biz_privacy_officer || s.biz_owner,
  };
  const filled = String(text || '').replace(/\{\{(.+?)\}\}/g, (m, k) => (k in map ? map[k] : m));
  return filled
    .split(/\n{2,}/)
    .map((block) => {
      const lines = block.split('\n');
      const out = [];
      let para = [];
      const flush = () => {
        if (para.length) out.push(`<p>${para.map(esc).join('<br>')}</p>`);
        para = [];
      };
      for (const line of lines) {
        if (line.startsWith('## ')) {
          flush();
          out.push(`<h2>${esc(line.slice(3))}</h2>`);
        } else para.push(line);
      }
      flush();
      return out.join('');
    })
    .join('');
}

/** 설명 텍스트: 줄바꿈 유지 */
function nl2br(s) {
  return esc(s).replace(/\n/g, '<br>');
}

// 개봉/미개봉 옵션 표시명: 라이브 방송에서 개봉 / 미개봉 상태로 발송
const OPT = { opened: '라이브 개봉', unopened: '미개봉 발송', short: { opened: '개봉', unopened: '미개봉' } };

function optText(it) {
  if (!it.qty_opened && !it.qty_unopened) return '';
  const parts = [];
  if (it.qty_opened) parts.push(OPT.opened + ' ' + it.qty_opened);
  if (it.qty_unopened) parts.push(OPT.unopened + ' ' + it.qty_unopened);
  return parts.join(' · ');
}

module.exports = { OPT, optText, won, num, dt, date, phone, esc, policy, nl2br };
