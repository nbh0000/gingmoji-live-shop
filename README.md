# 깅모지 LIVE 주문 사이트

유튜브 라이브 방송 중 시청자가 휴대폰으로 빠르게 주문·결제하는 전용 쇼핑몰입니다.

- Node.js 18+ / Express 4 / EJS(서버 렌더링) / 바닐라 JS / MariaDB(MySQL)
- 의존성 5개(`express`, `ejs`, `mysql2`, `express-session`, `multer`, `dotenv`) — 네이티브 모듈 없음
- 세션·상품 이미지까지 모두 DB에 저장 → 카페24 git 재배포 시 파일 유실 없음

## 화면 미리보기 (GitHub Pages)

`docs/` 는 실제 화면을 정적 페이지로 떠 둔 **미리보기 데모**입니다. 서버·DB가 없으므로 주문·결제·저장은 동작하지 않고, 데모 스크립트(`scripts/demo/demo.js`)가 API 응답을 흉내 냅니다. 오른쪽 위 "DEMO · 화면 목록" 버튼으로 고객·관리자 화면을 둘러볼 수 있습니다.

다시 만들기: 로컬 서버를 켠 상태에서 `node scripts/build-demo.js` → `docs/` 커밋.

## 폴더 구조

```
web.js                  카페24 진입 파일 (포트 8001)
migrations/             SQL 마이그레이션 (001_init.sql …)
src/
  server.js             기동: 자동 마이그레이션 → 설정 로드 → 약관 기본값 → 서버 → 만료 작업
  app.js                Express 구성 (보안 헤더, 세션, CSRF, 라우트)
  config.js             .env 로드
  db.js                 커넥션 풀 + 트랜잭션 헬퍼
  settings.js           관리자 설정값 (기본값은 여기 DEFAULTS)
  jobs.js               1분마다 결제 대기 만료 처리
  services/
    pricing.js          배송비·포인트·옵션 계산 (순수 함수, 테스트 대상)
    orders.js           재고 원자적 차감, 주문 생성, 입금 확인, 카드 결제 반영, 취소, 만료
    shipments.js        킵 보관함, 출고 요청, 배송비 결제
    points.js           포인트 원장
    portone.js          포트원 V2 결제 조회/취소/웹훅 서명 검증
    users.js, products.js, live.js
  routes/               shop, api, auth(일반/카카오/구글), mypage, admin, webhook, image
  views/                EJS (shop/, admin/, partials/)
  public/               css, js, img
test/                   node:test 테스트
```

## 로컬 실행

1. MariaDB 10.x 또는 MySQL 8 준비 후 DB 생성
   ```sql
   CREATE DATABASE gingmoji CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE gingmoji_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; -- 테스트용
   ```
2. `.env.example` 을 `.env` 로 복사해서 DB 접속 정보와 `ADMIN_PASSWORD` 입력 (`BASE_URL=http://localhost:8001`)
3. 실행
   ```bash
   npm install
   npm start          # 첫 기동 시 마이그레이션 자동 적용
   ```
4. 접속: 고객 `http://localhost:8001` / 관리자 `http://localhost:8001/admin`

포트원·카카오·구글 키가 비어 있으면 해당 기능만 꺼집니다 (카드결제 버튼 비활성, 계좌이체만 가능).

### 테스트

`.env.test` 에 테스트 DB 정보를 넣고 실행합니다. 테스트는 **테스트 DB의 테이블을 모두 지우고 다시 만듭니다**(운영 DB 이름을 넣지 마세요).

```bash
npm test
```

검증 항목: 재고 5개에 동시 주문 20건 → 정확히 5건 성공 / 다상품 주문 롤백 / 라이브 개봉·미개봉 발송 수량 저장 / 바로배송·킵 배송비 / 킵 누적 금액으로 출고 배송비 결정 / 계좌이체만 입금 확인 시 포인트 적립, 카드 미적립, 취소 시 회수 / 카드 미결제 만료·재고 복구 / 만료 직후 결제 시 재고 재확보 또는 환불 / 웹훅 중복 처리 방지 / 금액 위변조 차단

## DB 마이그레이션

- `migrations/NNN_설명.sql` 파일을 추가하면 다음 기동 때 자동 적용됩니다 (`schema_migrations` 테이블에 기록).
- 수동 실행: `npm run migrate`
- 이미 적용된 파일은 수정하지 말고 새 번호 파일을 만드세요.
- 카페24 MariaDB 호환을 위해 utf8mb4 인덱스 컬럼은 191자 이하, JSON 타입·트리거·이벤트는 쓰지 않습니다.

## 카페24 배포

> 카페24 Node.js 호스팅은 FTP 업로드 대신 **git push** 로 배포하고, 진입 파일 `web.js` / 포트 `8001` 을 사용합니다.

1. **카페24 관리 콘솔**에서 Node.js 앱 생성, MariaDB 생성(DB명·아이디·비밀번호 확인), git 저장소 주소 확인
2. **`.env` 작성** (`.env.example` 참고)
   - `PORT=8001`, `NODE_ENV=production`
   - `BASE_URL=http://아이디.cafe24app.com` (카페24 기본 주소, 끝에 / 없이)
   - `DB_HOST=localhost` + 카페24 DB 정보
   - `SESSION_SECRET` 은 긴 랜덤 문자열, `ADMIN_PASSWORD` 는 강한 비밀번호
3. **배포용 커밋에 `.env`, `node_modules` 포함**
   카페24는 서버에서 환경변수를 따로 넣기 어렵고, `npm install` 을 자동으로 하지 않는 경우가 있습니다. 이 프로젝트는 네이티브 모듈이 없어서 로컬(Windows)의 `node_modules` 를 그대로 올려도 동작합니다.
   ```bash
   npm install --omit=dev
   git checkout -b deploy
   git add -f .env node_modules
   git commit -m "deploy"
   git remote add cafe24 <카페24 git 주소>
   git push cafe24 deploy:master
   ```
   `deploy` 브랜치는 카페24 저장소에만 올리고, GitHub 등 외부 저장소에는 올리지 마세요(.env 에 비밀키가 있음).
4. 카페24 콘솔에서 앱 **재시작** → 첫 기동 시 테이블이 자동 생성됩니다.
5. `http://아이디.cafe24app.com/admin` 에서 관리자 로그인 → 설정에서 사업자 정보 확인 → 상품 등록

**업데이트 배포**: 코드 수정 → `deploy` 브랜치에 병합/커밋 → `git push cafe24 deploy:master` → 앱 재시작.

**플랜 상향 시**: `src/db.js` 의 `connectionLimit`(기본 8)을 늘리면 됩니다. 폴링 응답은 2초 메모리 캐시라 동시접속이 늘어도 DB 조회는 2초에 1번입니다.

## 외부 서비스 설정

### 카카오 로그인
developers.kakao.com → 내 애플리케이션 → 카카오 로그인 활성화
- Redirect URI: `{BASE_URL}/auth/kakao/callback`
- `.env`: `KAKAO_REST_KEY` (REST API 키), 보안 탭에서 Client Secret 사용 시 `KAKAO_CLIENT_SECRET`

### 구글 로그인
Google Cloud Console → API 및 서비스 → 사용자 인증 정보 → OAuth 클라이언트 ID(웹 애플리케이션)
- 승인된 리디렉션 URI: `{BASE_URL}/auth/google/callback`
- `.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`

### 포트원(PortOne) V2 카드결제
admin.portone.io → 결제 연동
1. **테스트 채널** 추가 후 `PORTONE_STORE_ID`, `PORTONE_CHANNEL_KEY`, `PORTONE_API_SECRET`(V2 API Secret) 입력
2. 웹훅: URL `{BASE_URL}/api/payments/webhook`, 버전 2024-04-25, 시크릿을 `PORTONE_WEBHOOK_SECRET` 에 입력
3. PG 심사 통과 후 **실 채널 키로 `.env` 의 `PORTONE_CHANNEL_KEY` 만 교체**하고 재시작

결제 흐름: 주문 생성(재고 선점) → 브라우저 SDK 결제창 → 서버가 포트원 API로 결제를 조회해 금액·상태 확인 후 결제완료. 모바일 리다이렉트(`/pay/return`), PC 콜백, 웹훅, 만료 작업 모두 같은 함수를 거치며 중복 호출에 안전합니다.

## 운영 정책 (관리자 설정값)

| 항목 | 기본값 | 위치 |
|---|---|---|
| 방송 중/종료 | 종료 | 대시보드 큰 버튼 |
| 가격 항상 노출 (PG 심사용) | 꺼짐 | 대시보드 / 설정 |
| 입금 계좌 | 케이뱅크 100301443318 김민정(김모지) | 설정 |
| 카드 미결제 자동 취소 | 10분 | 설정 |
| 계좌이체 입금 기한 | 24시간 (0 = 자동 취소 안 함) **TODO** | 설정 |
| 무료배송 기준 / 배송비 | 80,000원 / 4,000원 | 설정 |
| 포인트 적립률 (계좌이체만) | 비어 있음 = 적립 안 함 **TODO** | 설정 |
| 포인트 사용 허용·조건 | 꺼짐 **TODO** | 설정 |
| 사업자 정보 | 사업자등록증 기준, 통신판매업 번호·연락처·이메일은 플레이스홀더 | 설정 |
| 약관·개인정보처리방침·교환환불 | 기본 템플릿 **검토 필요** | 약관·정책 |

### 코드에 TODO 로 남긴 정책
- 계좌이체 자동 취소 시간 (`src/settings.js`)
- 포인트 적립 기준 금액 = 상품금액 − 사용 포인트, 배송비 제외 (`src/services/pricing.js`)
- 포인트 회수 시 잔액 부족하면 마이너스 허용 (`src/services/points.js`)
- 킵 출고는 보관 중인 주문 전체를 한 번에 (`src/services/shipments.js`)
- 개봉/미개봉 상품별 교환·환불 기준 (`src/defaults/pages.js`)

## 주문 상태

| DB 값 | 화면 | 설명 |
|---|---|---|
| pending | 입금대기 / 결제대기 | 재고 선점 중 (계좌/카드) |
| paid | 결제완료 | 바로배송 |
| kept | 킵보관 | 킵 주문 결제 완료, 보관함 누적 |
| preparing | 배송준비 | 바로배송 처리 중 또는 출고 요청에 포함된 킵 주문 |
| shipped | 발송완료 | 송장 입력 |
| cancelled | 취소 | 재고 복구, 포인트 환원/회수, 카드는 포트원 환불 |
