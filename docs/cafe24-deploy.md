# Cafe24 배포 메모

도메인: `gingmoji00.mycafe24.com`

이 프로젝트는 Cafe24 일반 PHP 호스팅 기준으로 동작합니다. GitHub는 버전 관리용으로 유지하고, Cafe24에는 FTP로 PHP 파일을 배포합니다.

## Cafe24 서버 값

- FTP 주소: `gingmoji00.mycafe24.com`
- FTP 아이디: `gingmoji00`
- FTP 포트: `21`
- DB 호스트: `localhost`
- DB 포트: `3306`
- DB 아이디: `gingmoji00`
- DB 종류: MariaDB
- DB 이름: Cafe24 DB 관리 화면에서 실제 이름 확인 필요

비밀번호는 저장소, 문서, 배포 스크립트에 기록하지 않습니다.

## 서버에 올릴 파일

FTP로 Cafe24 웹 루트에 아래 항목을 업로드합니다.

- `index.php`
- `.htaccess`
- `php/`
- `migrations/`
- `src/public/`
- 서버용 `.env`

Node 전용 파일인 `node_modules/`, `src/server/`, `package-lock.json`은 PHP 실행에 필요하지 않습니다.

서버 `.env` 예시는 `.env.cafe24.example`을 참고합니다. 최소 DB 값은 다음과 같습니다.

```dotenv
DB_HOST=localhost
DB_PORT=3306
DB_USER=gingmoji00
DB_PASSWORD=카페24_DB_비밀번호
DB_NAME=카페24_DB_이름
ADMIN_ID=admin
ADMIN_PASSWORD=관리자_비밀번호
```

첫 접속 시 `migrate_schema()`가 `migrations/`의 SQL을 순서대로 적용합니다. DB 이름이 틀리면 화면에 연결 오류가 표시됩니다.

## 운영 확인

1. `https://gingmoji00.mycafe24.com` 접속
2. `/admin/login` 접속
3. 관리자 설정에서 방송 상태, 재고 노출, 이벤트 문구, 포인트, 현금영수증, 사업자 정보를 확인
4. 상품 등록에서 대표/상세 이미지를 업로드하고 설명을 저장
5. 테스트 회원으로 주문 생성
6. 관리자 주문 상세에서 현금영수증 신청 내역과 입금 확인을 테스트

## Git 버전 관리

```powershell
git add -A
git commit -m "Prepare Cafe24 PHP deployment"
git push origin main
```

GitHub push 이후 변경된 PHP 파일만 FTP로 다시 올리면 됩니다. Cafe24 일반 PHP 호스팅은 이 프로젝트의 Node.js 서버를 직접 실행하는 방식이 아니므로 PHP 진입점인 `index.php`가 웹 루트에 있어야 합니다.
